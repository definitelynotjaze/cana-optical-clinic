<?php
// ================================================================
//  CANAOPTICALCLINIC — api/patients/request-email-change.php
//  POST { newEmail }
//
//  Step 1 of the patient email-change flow: sends a 6-digit OTP to the
//  NEW address to prove the patient actually owns it, before
//  users.email is touched at all. Mirrors api/auth/forgot-password.php's
//  OTP+cooldown pattern, but scoped to an authenticated users_id instead
//  of a bare email, and the code goes to the address being ADDED, not an
//  existing one.
//
//  Used by every role for their OWN email (patient Settings, and the
//  admin/staff/doctor My Profile pages) — api/users/update_profile.php no
//  longer changes email directly. Lives under api/patients/ only because
//  it started as patient-only; email_changes is keyed by users.id, so it
//  works the same for any account.
//
//  → { success:true, cooldownSeconds }
//  → { success:false, cooldown:true, retryAfter } | { success:false, message }
// ================================================================

require_once '../../config/db.php';
require_once '../../config/smtp.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
if (!in_array($_SESSION['role'] ?? '', ['patient', 'doctor', 'staff', 'admin'], true)) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$userId    = (int)$_SESSION['user_id'];
$b         = getBody();
$newEmail  = strtolower(trim($b['newEmail'] ?? ''));

if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'message' => 'Please enter a valid email address.']);
}

// Per-user limit — keeps one account's retries from starving another
// account's budget on the same IP.
rateLimit('request-email-change:' . $userId, 5, 900); // 5 per IP+user per 15 min
// Broader per-IP safety net.
rateLimit('request-email-change-ip', 30, 900); // 30 per IP per 15 min

$resendCooldownSeconds = 60;

try {
    $pdo = getDB();

    // Probabilistic GC, same pattern as password_resets/pending_registrations.
    if (mt_rand(1, 10) === 1) {
        $pdo->prepare(
            'DELETE FROM email_changes
              WHERE expires_at < NOW() - INTERVAL 1 DAY
                AND (blocked_until IS NULL OR blocked_until < NOW())'
        )->execute();
    }

    $cur = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
    $cur->execute([$userId]);
    $user = $cur->fetch();
    if (!$user) {
        jsonResponse(['success' => false, 'message' => 'Account not found.'], 404);
    }
    if (strtolower($user['email']) === $newEmail) {
        jsonResponse(['success' => false, 'message' => 'That\'s the email address you already have on file.']);
    }

    // Block if the new address already belongs to a different account.
    $chk = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? AND id != ? LIMIT 1');
    $chk->execute([$newEmail, $userId]);
    if ($chk->fetch()) {
        jsonResponse(['success' => false, 'message' => 'An account with this email already exists.']);
    }

    // Block check — 1-hour ban after too many wrong OTP guesses on this user.
    $blk = $pdo->prepare('SELECT blocked_until FROM email_changes WHERE users_id = ? AND blocked_until IS NOT NULL AND blocked_until > NOW() ORDER BY id DESC LIMIT 1');
    $blk->execute([$userId]);
    if ($blk->fetch()) {
        jsonResponse(['success' => false, 'banned' => true,
            'message' => 'Too many failed attempts. Please try again in 1 hour.']);
    }

    // Carry total_attempts forward + enforce the 60s resend cooldown,
    // both read straight from this user's most recent row — not
    // something the client can bypass by refreshing or reopening the modal.
    $prev = $pdo->prepare('SELECT total_attempts, created_at FROM email_changes WHERE users_id = ? ORDER BY id DESC LIMIT 1');
    $prev->execute([$userId]);
    $prevRow      = $prev->fetch();
    $carriedTotal = $prevRow ? (int)$prevRow['total_attempts'] : 0;

    if ($prevRow) {
        $elapsed = time() - strtotime($prevRow['created_at']);
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
    }

    // Invalidate any previous unused codes for this user.
    $pdo->prepare('UPDATE email_changes SET used = 1 WHERE users_id = ? AND used = 0')
        ->execute([$userId]);

    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $pdo->prepare(
        'INSERT INTO email_changes (users_id, new_email, otp, total_attempts, expires_at)
         VALUES (?, ?, ?, ?, NOW() + INTERVAL 5 MINUTE)'
    )->execute([$userId, $newEmail, $otp, $carriedTotal]);

    sendEmail(
        $newEmail, '',
        'Confirm Your New Email — Cana Optical Clinic',
        emailBody($otp, $newEmail),
        "Your Cana Optical Clinic email-change verification code is: $otp\n\nThis code expires in 5 minutes. If you did not request this, you can safely ignore this email — your account email will not change."
    );

    jsonResponse(['success' => true, 'cooldownSeconds' => $resendCooldownSeconds]);

} catch (\Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Failed to send email. Please try again later.'], 500);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Server error. Please try again.'], 500);
}

function emailBody(string $otp, string $newEmail): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Confirm Your New Email</title>
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
          <td style="background:#E8760A;padding:32px 40px 28px;text-align:center;">
            <div style="font-size:22px;font-weight:800;color:#ffffff;letter-spacing:1px;line-height:1;margin-bottom:4px;">Cana Optical Clinic</div>
          </td>
        </tr>

        <tr>
          <td style="padding:40px 40px 0;text-align:center;">
            <h1 style="margin:0 0 12px;font-size:20px;font-weight:700;color:#1C1C1C;letter-spacing:-0.3px;">Confirm Your New Email Address</h1>
            <p style="margin:0 auto;font-size:14px;color:#6b7280;line-height:1.7;max-width:380px;">
              We received a request to change the email address on a Cana Optical Clinic account to <strong style="color:#1C1C1C">{$newEmail}</strong>.
              Use the verification code below to confirm. It is valid for
              <strong style="color:#1C1C1C;font-weight:600;">5 minutes</strong> only.
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
                  If you did not request this change, please ignore this email. Your account's email address will remain unchanged. Never share this code with anyone.
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
