<?php
// ================================================================
//  CANAOPTICALCLINIC — api/auth/verify-login-device.php
//  POST { email, otp }
//  Second step of login.php's device gate (see its own header comment) —
//  same OTP-attempts/lockout shape as verify-email-otp.php/
//  verify-otp.php. On success, completes the login exactly as login.php
//  would have if the device had already been recognized — see
//  completeLogin() (api/helpers.php), shared by both.
//  → { success:true, role, user }
//  → { success:false, attemptsLeft?, locked?, banned?, message }
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');

$b     = getBody();
$email = strtolower(trim($b['email'] ?? ''));
$otp   = trim($b['otp'] ?? '');

// Same two-layer rate limit as forgot-password's verify-otp.php: per
// IP+email, plus a wider per-IP cap across all emails.
rateLimit('verify-login-device:' . substr(md5($email), 0, 12), 15, 600);
rateLimit('verify-login-device-ip', 60, 600);

if (!$email || !preg_match('/^\d{6}$/', $otp)) {
    jsonResponse(['success' => false, 'message' => 'Please enter the complete 6-digit code.']);
}

const MAX_LDO_ATTEMPTS  = 5;  // wrong tries per code — a resend resets this
const MAX_LDO_TOTAL     = 10; // wrong tries across all resends → 1-hour lockout (LDO_BLOCK_MINUTES)

try {
    $pdo = getDB();

    $u = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $u->execute([$email]);
    $user = $u->fetch();
    if (!$user) {
        jsonResponse(['success' => false, 'message' => 'Invalid or expired code. Please try logging in again.']);
    }

    $s = $pdo->prepare(
        'SELECT *, TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS secs_left
           FROM login_device_otps WHERE users_id = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1'
    );
    $s->execute([(int)$user['id']]);
    $row = $s->fetch();

    if (!$row) {
        jsonResponse(['success' => false, 'message' => 'Invalid or expired code. Please try logging in again.']);
    }

    // Locked out after MAX_LDO_TOTAL wrong codes — see ldoBlockRow()
    if (ldoIsBlocked($row)) {
        jsonResponse(['success' => false, 'banned' => true, 'message' => ldoBlockedMessage($row)]);
    }

    // Already burned all per-OTP attempts → need a fresh code (Resend)
    if ((int)$row['attempts'] >= MAX_LDO_ATTEMPTS) {
        jsonResponse(['success' => false, 'locked' => true,
            'message' => 'Too many wrong attempts. Request a new code to try again.']);
    }

    if (!hash_equals((string)$row['otp'], $otp)) {
        $newAttempts = (int)$row['attempts'] + 1;
        $newTotal    = (int)$row['total_attempts'] + 1;

        // Hard limit reached — lock this device verification for an hour,
        // same as forgot-password's own verify-otp.php. Kept as a row
        // (not deleted) so login.php can refuse to issue a fresh code until
        // the lock expires — deleting it let a new login start over with
        // another 10 guesses immediately.
        if ($newTotal >= MAX_LDO_TOTAL) {
            ldoBlockRow($pdo, (int)$row['id']);
            jsonResponse(['success' => false, 'banned' => true,
                'message' => 'Too many failed attempts. For your security, sign-in from this new device is locked for ' . LDO_BLOCK_MINUTES . ' minutes.']);
        }

        $pdo->prepare('UPDATE login_device_otps SET attempts = ?, total_attempts = ? WHERE id = ?')
            ->execute([$newAttempts, $newTotal, $row['id']]);

        if ($newAttempts >= MAX_LDO_ATTEMPTS) {
            jsonResponse(['success' => false, 'locked' => true,
                'totalLeft' => MAX_LDO_TOTAL - $newTotal,
                'message' => 'Too many wrong attempts. Request a new code to try again.']);
        }

        $left = MAX_LDO_ATTEMPTS - $newAttempts;
        jsonResponse(['success' => false, 'attemptsLeft' => $left,
            'message' => 'Incorrect code. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' remaining.']);
    }

    // Correct — consume the code and complete the login. Every login gets
    // the same 30-day rolling session now (SESSION_DAYS), "Remember me" or not.
    $pdo->prepare('DELETE FROM login_device_otps WHERE id = ?')->execute([$row['id']]);

    startSession(SESSION_DAYS);   // 30 days, rolling (see helpers.php)

    jsonResponse(completeLogin($pdo, $user));

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Server error. Please try again.'], 500);
}
