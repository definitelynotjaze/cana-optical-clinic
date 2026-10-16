<?php
// ================================================================
//  CANAOPTICALCLINIC — api/auth/resend-login-device-otp.php
//  Resends the device-verification OTP issued by login.php's device
//  gate. POST { email }
//  → { success:true, cooldownSeconds } | { success:false, message }
//
//  Deliberately email-only, no password re-check — same shape as
//  send-email-verification.php's own registration resend. Not a gap:
//  this only ever does anything if login.php already issued a live
//  code for this email, which itself only happens after a correct
//  password — an attacker without the password can't make this
//  endpoint do anything but return "no pending verification found".
// ================================================================

require_once '../../config/db.php';
require_once '../../config/smtp.php';
require_once '../helpers.php';

requireMethod('POST');
rateLimit('resend-login-device-otp', 5, 900);

$b     = getBody();
$email = strtolower(trim($b['email'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'message' => 'Invalid email address.']);
}

$resendCooldownSeconds = 60;

try {
    $pdo = getDB();

    $u = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $u->execute([$email]);
    $userId = $u->fetchColumn();
    if (!$userId) {
        jsonResponse(['success' => false, 'message' => 'No pending device verification found for this email.']);
    }

    // Only resend if there's still a live (unexpired) code — once it's
    // expired or was never issued, login.php is the only thing that can
    // start a new one (it needs the password again first).
    // Elapsed time measured by the DB itself (same clock that wrote
    // created_at) — PHP's time() vs a TIMESTAMP column can disagree by the
    // server's timezone offset, which could make the cooldown never end.
    $s = $pdo->prepare(
        'SELECT id, otp, total_attempts,
                TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed,
                TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS secs_left
           FROM login_device_otps
          WHERE users_id = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1'
    );
    $s->execute([$userId]);
    $row = $s->fetch();

    if (!$row) {
        jsonResponse(['success' => false, 'expired' => true, 'message' => 'This verification has expired. Please go back and sign in again to get a new code.']);
    }

    if (ldoIsBlocked($row) || (int)$row['total_attempts'] >= 10) {
        jsonResponse(['success' => false, 'banned' => true, 'message' => ldoBlockedMessage($row)]);
    }

    // Same server-side cooldown as login.php's own initial send — a
    // resend click can't be used to spam the inbox any faster than the
    // original code could.
    $elapsed = max(0, (int)$row['elapsed']);
    if ($elapsed < $resendCooldownSeconds) {
        $retryAfter = $resendCooldownSeconds - $elapsed;
        header('Retry-After: ' . $retryAfter);
        jsonResponse([
            'success'    => false,
            'cooldown'   => true,
            'retryAfter' => $retryAfter,
            'message'    => 'Please wait before requesting another code.',
        ]);
    }

    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    // Reset per-OTP attempts but keep total_attempts running
    $pdo->prepare('UPDATE login_device_otps SET otp = ?, attempts = 0, expires_at = NOW() + INTERVAL 5 MINUTE, created_at = NOW() WHERE id = ?')
        ->execute([$otp, $row['id']]);

    $deviceLabel = parseDeviceLabel(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255));
    $ip = clientIp();

    sendEmail(
        $email, '',
        'Your New Cana Optical Clinic Verification Code',
        deviceOtpResendEmailBody($otp, $deviceLabel, $ip),
        "A sign-in to your Cana Optical Clinic account was attempted from a new device ({$deviceLabel}, IP: {$ip}).\n\nYour new verification code is: $otp\n\nExpires in 5 minutes."
    );

    // Real time left after sending (see login.php) — not a flat 300.
    $left = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) FROM login_device_otps WHERE id = ?');
    $left->execute([$row['id']]);
    jsonResponse(['success' => true, 'cooldownSeconds' => $resendCooldownSeconds, 'expiresIn' => max(0, (int)$left->fetchColumn())]);

} catch (\Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Failed to send email. Please try again later.'], 500);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Server error. Please try again.'], 500);
}

function deviceOtpResendEmailBody(string $otp, string $deviceLabel, string $ip): string {
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

        <tr>
          <td style="background:#E8760A;
                     padding:32px 40px 28px;text-align:center;">
            <div style="font-size:22px;font-weight:800;color:#ffffff;letter-spacing:1px;margin-bottom:4px;">Cana Optical Clinic</div>
          </td>
        </tr>

        <tr>
          <td style="padding:40px 40px 0;text-align:center;">
            <h1 style="margin:0 0 12px;font-size:20px;font-weight:700;color:#1C1C1C;">Verify This Device</h1>
            <p style="margin:0 auto;font-size:14px;color:#6b7280;line-height:1.7;max-width:380px;">
              Here is your new code for the sign-in attempt from
              <strong style="color:#1C1C1C;font-weight:600;">{$deviceLabel}</strong> (IP: {$ip}). Valid for
              <strong style="color:#1C1C1C;font-weight:600;">5 minutes</strong>.
            </p>
          </td>
        </tr>

        <tr>
          <td style="padding:32px 40px 28px;">
            <p style="margin:0 0 14px;font-size:10px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;color:#9ca3af;text-align:center;">Your Verification Code</p>
            <div style="background:#FFF8F0;border:2px solid #FFD9A8;border-radius:12px;padding:24px 32px;text-align:center;">
              <span style="font-size:42px;font-weight:800;letter-spacing:14px;color:#E8760A;line-height:1;display:block;">{$otp}</span>
            </div>
            <p style="margin:14px 0 0;font-size:12px;color:#9ca3af;text-align:center;">This code expires in 5 minutes.</p>
          </td>
        </tr>

        <tr><td style="padding:0 40px;"><div style="border-top:1px solid #f0f0f4;"></div></td></tr>

        <tr>
          <td style="padding:24px 40px;">
            <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
              <tr>
                <td style="width:32px;vertical-align:top;padding-top:2px;">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#E8760A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                  </svg>
                </td>
                <td style="font-size:13px;color:#6b7280;line-height:1.65;">
                  If this wasn't you, do not share this code with anyone, and consider changing your password once you're signed in.
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr>
          <td style="background:#f8f8fb;padding:20px 40px;border-top:1px solid #f0f0f4;text-align:center;">
            <p style="margin:0;font-size:11px;color:#b0b7c3;">&copy; Cana Optical Clinic &nbsp;&bull;&nbsp; Do not reply to this email</p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>

</body>
</html>
HTML;
}
