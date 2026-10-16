<?php
// ================================================================
//  CANAOPTICALCLINIC — api/auth/login.php
//  POST { email, password }
//  → 200 { success:true,  role, user }
//  → 200 { success:false, message }
//  → 200 { success:false, requiresDeviceVerification:true, email, message }
//    (every role — patients, doctors, staff and admins alike)
//      — password was correct, but this device has never signed in on
//        this account before; an OTP was just emailed to it. See
//        verify-login-device.php, which is what actually completes the
//        login once that code is confirmed.
// ================================================================

require_once '../../config/db.php';
require_once '../../config/smtp.php';
require_once '../helpers.php';

requireMethod('POST');
rateLimit('login', 60, 900); // 60 attempts per IP per 15 min

$body     = getBody();
$email    = trim($body['email']    ?? '');
$pass     = trim($body['password'] ?? '');
$remember = !empty($body['remember']);

startSession(SESSION_DAYS);   // 30 days, rolling — "Remember me" only remembers the email now

if (!$email || !$pass) {
    jsonResponse(['success' => false, 'message' => 'Email and password are required.']);
}

$maxAttempts = 5;
$lockMinutes = 15;

try {
    $pdo  = getDB();
    // Fetch without is_active filter so we can give specific inactive messages
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}

// Account temporarily locked from too many failed attempts
if ($user && $user['locked_until'] && new DateTime($user['locked_until']) > new DateTime()) {
    $minutesLeft = (int)ceil((strtotime($user['locked_until']) - time()) / 60);
    jsonResponse(['success' => false, 'message' =>
        "Too many failed attempts. Please try again in {$minutesLeft} minute" . ($minutesLeft === 1 ? '' : 's') . "."]);
}

if (!$user || !password_verify($pass, $user['password_hash'])) {
    if ($user) {
        $attempts = (int)$user['failed_attempts'] + 1;
        if ($attempts >= $maxAttempts) {
            $lockedUntil = (new DateTime())->modify("+{$lockMinutes} minutes")->format('Y-m-d H:i:s');
            $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = ? WHERE id = ?')
                ->execute([$lockedUntil, (int)$user['id']]);
            jsonResponse(['success' => false, 'message' =>
                "Too many failed attempts. Your account has been locked for {$lockMinutes} minutes."]);
        }
        $pdo->prepare('UPDATE users SET failed_attempts = ? WHERE id = ?')->execute([$attempts, (int)$user['id']]);
    }
    jsonResponse(['success' => false, 'message' => 'The email or password you entered is incorrect. Please try again.']);
}

// Correct password — reset any prior failed-attempt tracking
if ((int)$user['failed_attempts'] > 0 || $user['locked_until']) {
    $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')
        ->execute([(int)$user['id']]);
}


// No automatic deactivation for time away: a patient may come back to the
// clinic after 2–3 years and should still be able to sign in. (The old
// rule switched off any account unused for a year.) Only an account an
// admin deliberately deactivated is blocked below.
if (!$user['is_active']) {
    jsonResponse(['success' => false, 'message' => 'Your account is inactive. Please contact the clinic to have it reactivated.']);
}

// New/untrusted device — same signal Active Sessions and the "New
// Sign-in Detected" notification already use (checkKnownDevice(),
// api/helpers.php), just checked BEFORE completing the login instead of
// only notifying about it after. A known device (or the account's
// very first-ever sign-in) skips straight to completeLogin() below,
// same as before this existed. Applies to every role: clinic accounts
// (admin/staff/doctor) can see every patient's records, so a stolen
// password matters even more there than on a patient account.
$ua     = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
$device = checkKnownDevice($pdo, (int)$user['id'], $ua);

if (!$device['known'] && !$device['firstEver']) {
    issueDeviceOtp($pdo, $user, $remember);
    // issueDeviceOtp() always calls jsonResponse() itself and exits.
}

jsonResponse(completeLogin($pdo, $user));

// Generates and emails a device-verification OTP, reusing any still-valid
// one instead of spamming a fresh email on every retry within its
// resend-cooldown window (a mistyped password before this point never
// reaches here at all, so this only replays on a genuine repeat
// correct-password submission — a slow double-click, a page refresh
// mid-flow, etc.) — same shape as forgot-password.php's own cooldown.
function issueDeviceOtp(PDO $pdo, array $user, bool $remember): void {
    $resendCooldownSeconds = 60;
    $userId = (int)$user['id'];

    // Elapsed/remaining measured by the DB's own clock — see
    // resend-login-device-otp.php for why not PHP's time().
    $prev = $pdo->prepare(
        'SELECT otp, expires_at > NOW() AS live,
                TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed,
                TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS secs_left
           FROM login_device_otps WHERE users_id = ? ORDER BY id DESC LIMIT 1'
    );
    $prev->execute([$userId]);
    $prevRow = $prev->fetch();

    if ($prevRow && $prevRow['live']) {
        // Locked after 10 wrong codes (verify-login-device.php) — no new
        // code until the hour is up, otherwise signing in again would just
        // hand out another 10 guesses.
        if (ldoIsBlocked($prevRow)) {
            jsonResponse(['success' => false, 'deviceLocked' => true, 'message' => ldoBlockedMessage($prevRow)]);
        }

        $elapsed = max(0, (int)$prevRow['elapsed']);
        if ($elapsed < $resendCooldownSeconds) {
            // Still-valid code from a moment ago — reopen the entry screen
            // for it instead of emailing another one. The remaining expiry
            // and cooldown come along so the screen's timers match reality.
            jsonResponse([
                'success'                    => false,
                'requiresDeviceVerification' => true,
                'email'                      => $user['email'],
                'cooldown'                   => true,
                'retryAfter'                 => $resendCooldownSeconds - $elapsed,
                'expiresIn'                  => max(0, (int)$prevRow['secs_left']),
                'message'                    => 'We already sent a verification code to your email for this device.',
            ]);
        }
    }

    // Only one live code per account at a time — same reasoning as every
    // other OTP table in this app (a fresh attempt invalidates whatever
    // code was issued before it).
    $pdo->prepare('DELETE FROM login_device_otps WHERE users_id = ?')->execute([$userId]);

    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare(
        'INSERT INTO login_device_otps (users_id, otp, remember, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL 5 MINUTE)'
    )->execute([$userId, $otp, $remember ? 1 : 0]);

    $deviceLabel = parseDeviceLabel(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255));
    $ip = clientIp();

    try {
        sendEmail(
            $user['email'], '',
            'Verify This Device for Cana Optical Clinic',
            deviceOtpEmailBody($otp, $deviceLabel, $ip),
            "A sign-in to your Cana Optical Clinic account was attempted from a new device ({$deviceLabel}, IP: {$ip}).\n\nYour verification code is: $otp\n\nThis code expires in 5 minutes. If this wasn't you, do not share this code, and consider changing your password."
        );
    } catch (\Throwable $e) {
        // Non-fatal — same convention as every other OTP-issuing endpoint
        // in this app (register.php, forgot-password.php): the frontend
        // still moves to the "enter code" screen either way, since a
        // Resend option is always available there if the first send
        // genuinely failed.
        error_log('[email] Device verification code failed to send to ' . $user['email'] . ': ' . $e->getMessage());
    }

    // The real time left, read AFTER sending — the 5 minutes started when
    // the code was saved, and sending the email takes a few seconds, so a
    // flat 300 would make the on-screen countdown run behind the server.
    $left = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) FROM login_device_otps WHERE users_id = ? ORDER BY id DESC LIMIT 1');
    $left->execute([$userId]);
    $expiresIn = max(0, (int)$left->fetchColumn());

    jsonResponse([
        'success'                    => false,
        'requiresDeviceVerification' => true,
        'email'                      => $user['email'],
        'cooldownSeconds'            => $resendCooldownSeconds,
        'expiresIn'                  => $expiresIn,
        'message'                    => 'We don\'t recognize this device. Enter the verification code we just emailed you to continue.',
    ]);
}

function deviceOtpEmailBody(string $otp, string $deviceLabel, string $ip): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Verify This Device</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body style="margin:0;padding:0;background:#f0f1f5;font-family:'Poppins','Segoe UI',Arial,sans-serif;">

  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f0f1f5;padding:48px 16px;">
    <tr><td align="center">

      <table width="520" cellpadding="0" cellspacing="0" role="presentation"
             style="background:#ffffff;border-radius:16px;overflow:hidden;
                    box-shadow:0 4px 24px rgba(0,0,0,0.09),0 1px 4px rgba(0,0,0,0.05);
                    max-width:520px;width:100%;">

        <!-- Header -->
        <tr>
          <td style="background:#E8760A;
                     padding:32px 40px 28px;text-align:center;">
            <div style="font-size:22px;font-weight:800;color:#ffffff;letter-spacing:1px;line-height:1;margin-bottom:4px;">
              Cana Optical Clinic
            </div>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:40px 40px 0;text-align:center;">
            <h1 style="margin:0 0 12px;font-size:20px;font-weight:700;color:#1C1C1C;letter-spacing:-0.3px;">
              Verify This Device
            </h1>
            <p style="margin:0 auto;font-size:14px;color:#6b7280;line-height:1.7;max-width:380px;">
              We noticed a sign-in attempt on your Cana Optical Clinic account from a device we don't recognize:
              <strong style="color:#1C1C1C;font-weight:600;">{$deviceLabel}</strong> (IP: {$ip}).
              Enter the code below to confirm it's really you. Valid for
              <strong style="color:#1C1C1C;font-weight:600;">5 minutes</strong> only.
            </p>
          </td>
        </tr>

        <!-- OTP block -->
        <tr>
          <td style="padding:32px 40px 28px;">
            <p style="margin:0 0 14px;font-size:10px;font-weight:700;letter-spacing:2.5px;
                      text-transform:uppercase;color:#9ca3af;text-align:center;">
              Your Verification Code
            </p>

            <div style="background:#FFF8F0;border:2px solid #FFD9A8;border-radius:12px;padding:24px 32px;text-align:center;">
              <span style="font-size:42px;font-weight:800;letter-spacing:14px;color:#E8760A;line-height:1;display:block;">
                {$otp}
              </span>
            </div>

            <p style="margin:14px 0 0;font-size:12px;color:#9ca3af;text-align:center;">
              This code expires in 5 minutes.
            </p>
          </td>
        </tr>

        <!-- Divider -->
        <tr><td style="padding:0 40px;"><div style="border-top:1px solid #f0f0f4;"></div></td></tr>

        <!-- Security notice -->
        <tr>
          <td style="padding:24px 40px;">
            <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
              <tr>
                <td style="width:32px;vertical-align:top;padding-top:2px;">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#E8760A"
                       stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                  </svg>
                </td>
                <td style="font-size:13px;color:#6b7280;line-height:1.65;">
                  If this wasn't you, do not share this code with anyone. We'd also recommend changing your
                  password from Settings once you're signed in, since whoever attempted this sign-in already
                  has it correct.
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#f8f8fb;padding:20px 40px;border-top:1px solid #f0f0f4;text-align:center;">
            <p style="margin:0;font-size:11px;color:#b0b7c3;">
              &copy; Cana Optical Clinic &nbsp;&bull;&nbsp; Do not reply to this email
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>

</body>
</html>
HTML;
}
