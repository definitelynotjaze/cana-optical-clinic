<?php
// ================================================================
//  CANAOPTICALCLINIC — api/patients/verify-email-change.php
//  POST { otp }
//  → { success:true, email }                        — OTP matched, users.email updated
//  → { success:false, attemptsLeft:N }               — wrong code
//  → { success:false, locked:true }                  — too many wrong tries
//  → { success:false, banned:true }                  — 1-hour lockout
//
//  Step 2 of the email-change flow (any role, own account) — see
//  request-email-change.php.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
if (!in_array($_SESSION['role'] ?? '', ['patient', 'doctor', 'staff', 'admin'], true)) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$userId = (int)$_SESSION['user_id'];
$b      = getBody();
$otp    = trim($b['otp'] ?? '');

if (strlen($otp) !== 6) {
    jsonResponse(['success' => false, 'message' => 'Invalid request.']);
}

// Per-user limit — same reasoning as verify-otp.php.
rateLimit('verify-email-change:' . $userId, 15, 600); // 15 per IP+user per 10 min
rateLimit('verify-email-change-ip', 60, 600);          // 60 per IP per 10 min, across all users

const MAX_OTP_ATTEMPTS = 5;
const MAX_TOTAL_ATTEMPTS = 10; // hard limit across all resends for this user

try {
    $pdo = getDB();

    $s = $pdo->prepare(
        'SELECT id, new_email, otp, attempts, total_attempts FROM email_changes
          WHERE users_id = ? AND used = 0 AND expires_at > NOW()
          ORDER BY id DESC LIMIT 1'
    );
    $s->execute([$userId]);
    $row = $s->fetch();

    if (!$row) {
        jsonResponse(['success' => false, 'message' => 'Invalid or expired code. Please request a new one.']);
    }

    if ((int)$row['attempts'] >= MAX_OTP_ATTEMPTS) {
        jsonResponse(['success' => false, 'locked' => true,
            'message' => 'Too many wrong attempts. Please request a new code.']);
    }

    if ($row['otp'] !== $otp) {
        $newAttempts      = (int)$row['attempts'] + 1;
        $newTotalAttempts = (int)$row['total_attempts'] + 1;

        if ($newTotalAttempts >= MAX_TOTAL_ATTEMPTS) {
            $pdo->prepare('UPDATE email_changes SET attempts = ?, total_attempts = ?, blocked_until = NOW() + INTERVAL 1 HOUR, used = 1 WHERE id = ?')
                ->execute([$newAttempts, $newTotalAttempts, $row['id']]);
            jsonResponse(['success' => false, 'banned' => true,
                'message' => 'Too many failed attempts. Please try again in 1 hour.']);
        }

        if ($newAttempts >= MAX_OTP_ATTEMPTS) {
            $pdo->prepare('UPDATE email_changes SET attempts = ?, total_attempts = ?, used = 1 WHERE id = ?')
                ->execute([$newAttempts, $newTotalAttempts, $row['id']]);
            jsonResponse(['success' => false, 'locked' => true,
                'message' => 'Too many wrong attempts. Please request a new code.']);
        }

        $pdo->prepare('UPDATE email_changes SET attempts = ?, total_attempts = ? WHERE id = ?')
            ->execute([$newAttempts, $newTotalAttempts, $row['id']]);

        $left = MAX_OTP_ATTEMPTS - $newAttempts;
        jsonResponse(['success' => false, 'attemptsLeft' => $left,
            'message' => 'Incorrect code. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' remaining.']);
    }

    // Correct OTP. Re-check the new address hasn't been claimed by someone
    // else in the meantime (the window between request and verify), then
    // apply it.
    $chk = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? AND id != ? LIMIT 1');
    $chk->execute([strtolower($row['new_email']), $userId]);
    if ($chk->fetch()) {
        $pdo->prepare('UPDATE email_changes SET used = 1 WHERE id = ?')->execute([$row['id']]);
        jsonResponse(['success' => false, 'message' => 'This email was just claimed by another account. Please start over with a different address.']);
    }

    $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$row['new_email'], $userId]);
    $pdo->prepare('UPDATE email_changes SET used = 1 WHERE id = ?')->execute([$row['id']]);

    jsonResponse(['success' => true, 'email' => $row['new_email']]);

} catch (PDOException $e) {
    // Duplicate-key race on users.email, if it has a unique constraint.
    if ((int)$e->getCode() === 23000) {
        jsonResponse(['success' => false, 'message' => 'This email was just claimed by another account. Please start over with a different address.']);
    }
    jsonResponse(['success' => false, 'message' => 'Server error. Please try again.'], 500);
}
