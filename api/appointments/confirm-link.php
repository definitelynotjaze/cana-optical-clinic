<?php
// ================================================================
//  CANAOPTICALCLINIC — api/appointments/confirm-link.php
//  The "Confirm Appointment" button in the reminder emails lands here.
//  Confirms attendance WITHOUT signing in — the signed token in the link
//  (appointmentConfirmToken(), api/helpers.php) proves it came from that
//  appointment's own email.
//
//  GET  ?id=A123&t=TOKEN  → shows the appointment + a "Yes, I'll be there"
//                            button (nothing is changed yet — email apps
//                            and link scanners open links automatically,
//                            and that must never count as confirming)
//  POST id, t             → confirms (same rules as the in-app button,
//                            confirmAppointmentAttendance()) and shows
//                            the result
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$id = trim((string)($isPost ? ($_POST['id'] ?? '') : ($_GET['id'] ?? '')));
$t  = trim((string)($isPost ? ($_POST['t']  ?? '') : ($_GET['t']  ?? '')));

$state = 'invalid';   // invalid | ask | confirmed | already | closed | error
$msg   = '';
$appt  = null;
$pdo   = null;

try {
    if ($isPost) rateLimit('confirm-link', 30, 900);
    $valid = $id !== '' && $t !== '' && hash_equals(appointmentConfirmToken($id), $t);
    if ($valid) {
        $pdo = getDB();
        $s = $pdo->prepare('SELECT * FROM appointments WHERE id = ? LIMIT 1');
        $s->execute([$id]);
        $appt = $s->fetch() ?: null;
    }

    if ($appt) {
        if ($appt['confirmed_at']) {
            $state = 'already';
        } elseif ($appt['status'] !== 'approved' || !$appt['reminder_sent_at']) {
            $state = 'closed';
            $msg = $appt['status'] === 'cancelled'
                ? 'This appointment has already been cancelled, so it can no longer be confirmed. You can book a new one anytime from your patient account.'
                : 'This appointment isn\'t waiting for confirmation right now.';
        } elseif ($isPost) {
            [$ok, $res] = confirmAppointmentAttendance($pdo, $appt);
            $state = $ok ? ($res === 'already' ? 'already' : 'confirmed') : 'closed';
            if (!$ok) $msg = $res;
        } else {
            $state = 'ask';
        }
    }
} catch (Throwable $e) {
    error_log('[confirm-link] ' . $e->getMessage());
    $state = 'error';
}

// ── Page ─────────────────────────────────────────────────────────
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$base     = appBaseUrl();
$portal   = $appt ? appointmentLinkUrl($appt['id']) : $base . '/app.html';
$clinic   = 'Cana Optical Clinic';
$logo     = $base . '/assets/images/logo/clinic-logo.png';

$details = '';
if ($appt) {
    $details = '<div class="card"><div class="doc">' . $e($appt['doctor_name'] ?: 'Your doctor') . '</div>'
             . '<div class="meta">' . $e(date('l, F j, Y', strtotime($appt['date']))) . ' at ' . $e($appt['time']) . '</div>'
             . '<div class="meta">' . $e($appt['type'] ?: 'Appointment') . '</div></div>';
}

switch ($state) {
    case 'ask':
        $icon = '?'; $tone = 'orange';
        $title = 'Confirm your appointment';
        // Online cancel/reschedule stays open until the visit time — only offer
        // the account link while it's still open; otherwise point to the clinic.
        $apptTs   = apptTimestamp($appt);
        $canClose = $apptTs !== null && time() < $apptTs - PATIENT_CHANGE_CUTOFF_HOURS * 3600;
        $body  = $details . '<p>Will you be attending? Confirming lets the clinic know to expect you.</p>'
               . '<form method="post"><input type="hidden" name="id" value="' . $e($id) . '"><input type="hidden" name="t" value="' . $e($t) . '">'
               . '<button type="submit" class="btn">Yes, I\'ll be there</button></form>'
               . ($canClose
                   ? '<a class="link" href="' . $e($portal) . '">Need to cancel or reschedule? Open your account</a>'
                   : '<p class="note">Can\'t make it? ' . $e(apptChangeGuidance($pdo, $appt)) . '</p>');
        break;
    case 'confirmed':
        $icon = '&#10003;'; $tone = 'green';
        $title = 'Appointment confirmed';
        $body  = $details . '<p>Thank you! The clinic has been notified. See you then.</p>'
               . '<a class="btn" href="' . $e($portal) . '">View in my account</a>';
        break;
    case 'already':
        $icon = '&#10003;'; $tone = 'green';
        $title = 'Already confirmed';
        $body  = $details . '<p>You\'ve already confirmed this appointment. No need to do anything else.</p>'
               . '<a class="btn" href="' . $e($portal) . '">View in my account</a>';
        break;
    case 'closed':
        $icon = '!'; $tone = 'gray';
        $title = 'Nothing to confirm';
        $body  = $details . '<p>' . $e($msg) . '</p><a class="btn" href="' . $e($portal) . '">Open my account</a>';
        break;
    case 'error':
        $icon = '!'; $tone = 'gray';
        $title = 'Something went wrong';
        $body  = '<p>We couldn\'t process this right now. Please try again in a moment, or confirm from your patient account.</p>'
               . '<a class="btn" href="' . $e($base . '/app.html') . '">Open my account</a>';
        break;
    default:
        $icon = '!'; $tone = 'gray';
        $title = 'This link isn\'t valid';
        $body  = '<p>This confirmation link is incomplete or has been changed. Please use the button in your latest reminder email, or confirm from your patient account.</p>'
               . '<a class="btn" href="' . $e($base . '/app.html') . '">Open my account</a>';
}
$tones = ['orange' => ['#FFF7ED', '#E8760A'], 'green' => ['#ECFDF5', '#16A34A'], 'gray' => ['#F3F4F6', '#6B7280']];
[$toneBg, $toneFg] = $tones[$tone];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $e($title) ?> | <?= $e($clinic) ?></title>
<link rel="icon" href="<?= $e($logo) ?>">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root { --orange:#E8760A; }
  * { box-sizing:border-box; }
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px 16px;
         background:#F4F1EC; font-family:'Poppins','Segoe UI',Arial,sans-serif; color:#1C1C1C; }
  .wrap { width:100%; max-width:440px; }
  .brand { display:flex; align-items:center; justify-content:center; gap:10px; margin-bottom:18px; font-weight:700; font-size:1rem; }
  .brand img { width:36px; height:36px; border-radius:50%; background:#fff; object-fit:contain; box-shadow:0 2px 8px rgba(60,30,0,.08); }
  .panel { background:#fff; border-radius:18px; padding:32px 28px; text-align:center;
           box-shadow:0 1px 2px rgba(60,30,0,.05), 0 12px 32px -12px rgba(60,30,0,.18); }
  .icon { width:56px; height:56px; border-radius:50%; margin:0 auto 16px; display:flex; align-items:center; justify-content:center;
          font-size:1.5rem; font-weight:700; background:<?= $toneBg ?>; color:<?= $toneFg ?>; }
  h1 { font-size:1.2rem; margin:0 0 16px; letter-spacing:-.01em; }
  .card { background:#F9FAFB; border:1px solid #F0F0F0; border-radius:12px; padding:14px 18px; margin-bottom:16px; text-align:left; }
  .doc { font-weight:600; font-size:.92rem; }
  .meta { font-size:.85rem; color:#6B7280; margin-top:3px; }
  p { font-size:.9rem; color:#4B5563; line-height:1.65; margin:0 0 20px; }
  form { margin:0; }
  .btn { display:inline-block; width:100%; border:0; border-radius:10px; padding:13px 20px; background:var(--orange); color:#fff;
         font-family:inherit; font-size:.95rem; font-weight:600; text-decoration:none; cursor:pointer;
         transition:opacity .2s ease, translate .2s ease; }
  @media (hover:hover) { .btn:hover { opacity:.88; translate:0 -1px; } }
  .btn:active { translate:0 0; }
  .btn:focus-visible, .link:focus-visible { outline:2px solid var(--orange); outline-offset:3px; }
  .link { display:inline-block; margin-top:16px; font-size:.82rem; color:#6B7280; text-decoration:underline; text-underline-offset:3px; }
  .link:hover { color:var(--orange); }
  .note { font-size:.8rem; color:#6B7280; margin:16px 0 0; line-height:1.55; }
  .foot { text-align:center; font-size:.75rem; color:#9CA3AF; margin-top:16px; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="brand"><img src="<?= $e($logo) ?>" alt=""><span><?= $e($clinic) ?></span></div>
    <div class="panel">
      <div class="icon" aria-hidden="true"><?= $icon ?></div>
      <h1><?= $e($title) ?></h1>
      <?= $body ?>
    </div>
    <div class="foot">&copy; <?= date('Y') ?> <?= $e($clinic) ?></div>
  </div>
</body>
</html>
