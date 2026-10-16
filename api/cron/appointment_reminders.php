<?php
// ================================================================
//  CANAOPTICALCLINIC — api/cron/appointment_reminders.php
//  Not user-facing. Meant to be hit on a schedule (every 5 minutes) by an
//  external scheduler — Railway's own cron/scheduled-job feature,
//  a pinger like cron-job.org, or a scheduled GitHub Action — since this
//  repo has no in-process job runner. Auth via a shared secret in the
//  `key` query param, set as the CRON_SECRET environment variable.
//
//  GET/POST /api/cron/appointment_reminders.php?key=...
//
//  Each run does three independent jobs:
//   1. Send the day-before reminder at the admin-configured reminder time
//      (Clinic Settings → Scheduling Rules) for approved appointments
//      happening tomorrow that haven't been reminded yet.
//   1b. Send a second reminder at the follow-up time to anyone reminded
//      earlier that day who still hasn't confirmed. Confirming is
//      informational only — an unconfirmed appointment is never cancelled.
//   2. Auto-decline appointment requests still stuck at "pending" once
//      their appointment time arrives — staff/admin never acted on them at
//      all, so nothing else in the app was ever going to resolve them.
//      Declined (kept on record), never deleted.
//   3. Keep patients.age current (age is also computed from DOB on read).
//   4. Unsaved exam drafts: remind the doctor + admins once the appointment
//      date has passed; expire drafts untouched for 30 days.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

$providedKey = $_GET['key'] ?? $_POST['key'] ?? '';
$cronSecret  = getenv('CRON_SECRET') ?: 'cana-local-dev-cron-key';
if (!$providedKey || !hash_equals($cronSecret, $providedKey)) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
}

// Every patient-facing notice below previously only ever reached the
// in-app inbox via createNotification() — nothing outside the app itself
// (email, let alone SMS) ever told a patient their appointment needed
// confirming, so anyone not actively logged in when the cron fired would
// just miss it. _emailPatientNotice() (api/helpers.php) sends the same
// notice by email too.
$result = ['success' => true, 'remindersSent' => 0, 'followupRemindersSent' => 0, 'pendingAutoDisapproved' => 0];

try {
    $pdo = getDB();

    $reminderHourStr = settingTimeTo24h(reminderTimeSetting($pdo));
    $followupHourStr = settingTimeTo24h(followupReminderTimeSetting($pdo));

    // ── Job 1: send day-before reminder ─────────────────────────────
    $dueReminders = $pdo->prepare(
        "SELECT id, patient_id, doctor_name, date, time, type FROM appointments
         WHERE status = 'approved' AND date = CURDATE() + INTERVAL 1 DAY
           AND reminder_sent_at IS NULL AND CURTIME() >= ?"
    );
    $dueReminders->execute([$reminderHourStr]);
    $reminderRows = $dueReminders->fetchAll();

    foreach ($reminderRows as $r) {
        $pdo->prepare('UPDATE appointments SET reminder_sent_at = NOW() WHERE id = ? AND reminder_sent_at IS NULL')
            ->execute([$r['id']]);

        if (!$r['patient_id']) continue;
        $ps = $pdo->prepare('SELECT user_id FROM patients WHERE id = ? LIMIT 1');
        $ps->execute([$r['patient_id']]);
        $userId = $ps->fetchColumn();
        if ($userId) {
            [$title, $noticeMsg] = buildReminderNotice($pdo, $r, 1);
            createNotification($pdo, (int)$userId, 'reminder', $title, $noticeMsg, $r['id']);
            _emailPatientNotice($pdo, (int)$userId, $title, $noticeMsg, appointmentConfirmUrl($r['id']), 'Confirm Appointment', $r['date']);
        }
    }
    $result['remindersSent'] = count($reminderRows);

    // ── Job 1b: second reminder for anyone still unconfirmed ─────────
    // Only for reminders that went out BEFORE today's follow-up time — an
    // appointment approved later in the day only just got its first
    // reminder, so a second one straight after it would be noise.
    $dueFollowups = $pdo->prepare(
        "SELECT id, patient_id, doctor_name, date, time, type FROM appointments
         WHERE status = 'approved' AND date = CURDATE() + INTERVAL 1 DAY
           AND reminder_sent_at IS NOT NULL AND confirmed_at IS NULL
           AND followup_reminder_sent_at IS NULL
           AND reminder_sent_at < TIMESTAMP(CURDATE(), ?)
           AND CURTIME() >= ?"
    );
    $dueFollowups->execute([$followupHourStr, $followupHourStr]);
    $followupRows = $dueFollowups->fetchAll();

    foreach ($followupRows as $r) {
        $upd = $pdo->prepare('UPDATE appointments SET followup_reminder_sent_at = NOW() WHERE id = ? AND followup_reminder_sent_at IS NULL AND confirmed_at IS NULL');
        $upd->execute([$r['id']]);
        if ($upd->rowCount() === 0 || !$r['patient_id']) continue;
        $ps = $pdo->prepare('SELECT user_id FROM patients WHERE id = ? LIMIT 1');
        $ps->execute([$r['patient_id']]);
        $userId = $ps->fetchColumn();
        if ($userId) {
            [$title, $noticeMsg] = buildReminderNotice($pdo, $r, 2);
            createNotification($pdo, (int)$userId, 'reminder', $title, $noticeMsg, $r['id']);
            _emailPatientNotice($pdo, (int)$userId, $title, $noticeMsg, appointmentConfirmUrl($r['id']), 'Confirm Appointment', $r['date']);
        }
    }
    $result['followupRemindersSent'] = count($followupRows);

    // ── Job 2: auto-decline pending requests whose time has arrived ────
    // The "staff never responded at all" case — see expireUnactionedPending()
    // (helpers.php), which also runs on every appointments load.
    $result['pendingAutoDisapproved'] = expireUnactionedPending($pdo);

    // ── Job 4: unsaved exam drafts — reminders + 30-day expiry ─────────
    $drafts = processExamDrafts($pdo);
    $result['examDraftReminders'] = $drafts['reminded'];
    $result['examDraftsExpired']  = $drafts['expired'];

    // ── Job 3: keep the stored age current ─────────────────────────────
    // Every screen already computes age from the DOB (patientAge(),
    // helpers.php); this just keeps the patients.age column itself right
    // for exports/snapshots, rolling it over on each patient's birthday.
    $result['agesUpdated'] = $pdo->exec(
        'UPDATE patients SET age = TIMESTAMPDIFF(YEAR, dob, CURDATE())
         WHERE dob IS NOT NULL AND age <> TIMESTAMPDIFF(YEAR, dob, CURDATE())'
    );

    jsonResponse($result);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error.'], 500);
}
