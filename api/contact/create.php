<?php
// ================================================================
//  CANAOPTICALCLINIC — api/contact/create.php
//  POST — public endpoint, no auth required.
//  Body: { name, email, service, message }
//  → { success:true, id:N }
// ================================================================

require_once '../../config/db.php';
require_once '../../config/smtp.php';
require_once '../helpers.php';

requireMethod('POST');
rateLimit('contact', 5, 600); // 5 per IP per 10 min — public spam target

$b = getBody();

$name    = trim($b['name']    ?? '');
$email   = trim($b['email']   ?? '');
$service = trim($b['service'] ?? '');
$message = trim($b['message'] ?? '');

if (!$name || !$email || !$service || !$message) {
    jsonResponse(['success' => false, 'message' => 'Name, email, inquiry type and message are required.']);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'message' => 'Please enter a valid email address.']);
}

if (mb_strlen($name) > 150 || mb_strlen($email) > 150 || mb_strlen($service) > 100) {
    jsonResponse(['success' => false, 'message' => 'One of the fields is too long.']);
}

try {
    $pdo = getDB();

    $pdo->prepare(
        'INSERT INTO contact_messages (name, email, service, message) VALUES (?, ?, ?, ?)'
    )->execute([$name, $email, $service ?: null, $message]);

    $newId = (int)$pdo->lastInsertId();

    $excerpt = mb_strlen($message) > 80 ? mb_substr($message, 0, 80) . '…' : $message;
    notifyAdminStaff($pdo, 'contact_message', 'New Contact Message',
        "{$name} sent a message: \"{$excerpt}\""
    );

    // Email alert — non-critical, never blocks the response. Goes to the
    // clinic email AND each active admin/staff account's own email: when the
    // clinic email is the same Gmail the system sends from, Gmail files the
    // message under Sent / All Mail only, never the Inbox, so it looked like
    // nothing arrived. Reply-To is the sender, so "Reply" answers them.
    try {
        $clinicRow  = $pdo->query('SELECT email FROM clinic_settings WHERE id = 1 LIMIT 1')->fetch();
        $recipients = [($clinicRow['email'] ?? '') ?: SMTP_FROM];
        foreach ($pdo->query("SELECT email FROM users WHERE role IN ('admin','staff') AND is_active = 1 AND email <> ''")->fetchAll(PDO::FETCH_COLUMN) as $e) {
            $recipients[] = $e;
        }
        // Each send takes a few seconds; more admin/staff accounts must not
        // push the request past PHP's default 30s limit.
        @set_time_limit(30 + 15 * count($recipients));
        $seen = [];
        foreach ($recipients as $to) {
            $key = strtolower(trim($to));
            if ($key === '' || isset($seen[$key]) || !filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
            $seen[$key] = true;
            try {
                sendEmailLater(
                    $to, 'Cana Optical Clinic',
                    "New Contact Message from {$name}",
                    _contactEmailBody($name, $email, $service, $message),
                    "From: {$name} <{$email}>\nService: " . ($service ?: 'Not specified') . "\n\n{$message}",
                    $email
                );
            } catch (\Throwable $e) {
                error_log("[contact/create] email to {$to} failed: " . $e->getMessage());
            }
        }
    } catch (\Throwable $e) {
        // Message is already saved and the in-app notification sent.
        error_log('[contact/create] email alert failed: ' . $e->getMessage());
    }

    // Only answered once the emails have actually gone out, so "Sending…"
    // on the form lasts as long as the real delivery does.
    jsonResponse(['success' => true, 'id' => $newId]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}

// Same layout as every other system email (systemEmailBody(), config/smtp.php;
// the patient's "Reply to Your Message" uses it too): orange header with a
// "NEW CONTACT MESSAGE" label, sender details in the main box, and their
// message in its own highlighted box below.
function _contactEmailBody(string $name, string $email, string $service, string $message): string {
    $details = "{$name} sent a new message through the Contact Us page. You can answer it from Contact Messages in the clinic system.\n\n"
             . "Name: {$name}\n"
             . "Email: {$email}\n"
             . "Inquiry type: " . ($service ?: 'Not specified') . "\n"
             . "Received: " . date('F j, Y \a\t g:i A');
    return systemEmailBody('Clinic Team', 'New Contact Message', $details, '', '', '', 'Their Message', $message, 'neutral', '', 'New Contact Message');
}
