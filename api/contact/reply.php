<?php
// ================================================================
//  CANAOPTICALCLINIC — api/contact/reply.php
//  POST { id, reply } — admin/staff only.
//  Saves the reply and emails the original sender via SMTP (best-effort —
//  the reply is still saved even if the email fails to send). Email is
//  the only delivery channel; there is no in-app patient inbox.
// ================================================================

require_once '../../config/db.php';
require_once '../../config/smtp.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'staff'], true)) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
}

// Same reasoning as patients/email.php — each hit sends a real email.
rateLimit('contact-reply:' . $_SESSION['user_id'], 10, 300);

$b     = getBody();
$id    = (int)($b['id'] ?? 0);
$reply = trim($b['reply'] ?? '');

if (!$id || !$reply) {
    jsonResponse(['success' => false, 'message' => 'Reply text is required.']);
}

try {
    $pdo = getDB();

    $s = $pdo->prepare('SELECT * FROM contact_messages WHERE id = ? LIMIT 1');
    $s->execute([$id]);
    $msg = $s->fetch();
    if (!$msg) {
        jsonResponse(['success' => false, 'message' => 'Message not found.'], 404);
    }

    $userId      = (int)$_SESSION['user_id'];
    $profileInfo = loadUserProfile($pdo, $userId, $role);
    $replierName = 'Cana Optical Clinic';
    if ($profileInfo && !empty($profileInfo['profile'])) {
        $p    = $profileInfo['profile'];
        $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
        if ($name) $replierName = $name;
    }

    $pdo->prepare('UPDATE contact_messages SET reply = ?, replied_by = ?, replied_at = NOW() WHERE id = ?')
        ->execute([$reply, $replierName, $id]);

    // Email the reply to the original sender (best-effort — don't fail the request if email is down)
    $emailSent = false;
    try {
        sendEmail(
            $msg['email'], $msg['name'],
            'Re: Your message to Cana Optical Clinic',
            replyEmailBody($msg['name'], $msg['message'], $reply, $replierName),
            "Hi {$msg['name']},\n\n{$reply}\n\n— {$replierName}, Cana Optical Clinic"
        );
        $emailSent = true;
    } catch (\Exception $e) {
        // Non-fatal — reply is already saved
    }

    jsonResponse(['success' => true, 'emailSent' => $emailSent]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error.'], 500);
}

// Same layout as every other email a patient receives from the clinic
// (systemEmailBody(), config/smtp.php): the clinic's reply sits in the
// highlighted box, and their own original message is quoted underneath for
// context. Only this patient-facing reply uses it — the admin/staff
// "New Contact Message" alert (contact/create.php) keeps its own layout.
function replyEmailBody(string $name, string $original, string $reply, string $replierName): string {
    $message = "{$replierName} from Cana Optical Clinic replied to your message. You can find their reply below."
             . "\n\nYour message:\n\u{201C}" . trim($original) . "\u{201D}";
    return systemEmailBody(
        $name,
        'Reply to Your Message',
        $message,
        '', '', '',
        'Our Reply', $reply, 'neutral',
        '',
        'Message Reply'
    );
}
