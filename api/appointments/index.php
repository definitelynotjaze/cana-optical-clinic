<?php
// ================================================================
//  CANAOPTICALCLINIC — api/appointments/index.php
//  GET → returns appointments filtered by caller's role:
//    admin/staff  → all appointments
//    doctor       → only their appointments
//    patient      → only their appointments
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}

$role      = $_SESSION['role']       ?? '';
$profileId = $_SESSION['profile_id'] ?? '';

function mapRow(array $r): array {
    $rr = json_decode($r['reschedule_request'] ?? '', true);
    return [
        'id'                  => $r['id'],
        'patientId'           => $r['patient_id'],
        'patientName'         => $r['patient_name'] ?? '',
        'doctorId'            => $r['doctor_id'],
        'doctorName'          => $r['doctor_name']  ?? '',
        'date'                => $r['date'],
        'time'                => $r['time'],
        'type'                => $r['type']  ?? '',
        'status'              => $r['status'],
        'source'              => $r['source'] ?? 'online',
        'notes'               => $r['notes'] ?? '',
        'cancellationReason'  => $r['cancellation_reason']  ?? null,
        'disapprovalReason'   => $r['disapproval_reason']   ?? null,
        'rescheduleNote'      => $r['reschedule_note']      ?? null,
        'rescheduleRequest'   => is_array($rr) ? $rr : null,
        // Clinic-proposed new slot awaiting the patient's accept/decline.
        'rescheduleProposal'  => (is_array($rp = json_decode($r['reschedule_proposal'] ?? '', true)) ? $rp : null),
        'reminderSentAt'      => $r['reminder_sent_at'] ?? null,
        'confirmedAt'         => $r['confirmed_at']     ?? null,
        'rescheduledAt'       => $r['rescheduled_at']   ?? null,
        'noShowRevertedAt'    => $r['no_show_reverted_at']   ?? null,
        'noShowRevertReason'  => $r['no_show_revert_reason'] ?? null,
    ];
}

try {
    $pdo = getDB();

    // Pending requests nobody approved or declined before their appointment
    // time → auto-declined (helpers.php). Runs here too, not only in the
    // cron, so it still happens if the scheduler isn't running.
    expireUnactionedPending($pdo);

    // Unsaved exam drafts: remind once their appointment date has passed,
    // and expire stale ones (helpers.php). Before the no-show pass below, so
    // an appointment whose draft just expired is no longer shielded by it.
    processExamDrafts($pdo);

    // Clinic-proposed reschedules the patient never answered before the
    // proposed time arrived simply lapse — the original slot stands.
    try {
        foreach ($pdo->query('SELECT id, reschedule_proposal FROM appointments WHERE reschedule_proposal IS NOT NULL')->fetchAll() as $pr) {
            $p  = json_decode($pr['reschedule_proposal'] ?? '', true);
            $dt = is_array($p) ? DateTime::createFromFormat('Y-m-d g:i A', ($p['date'] ?? '') . ' ' . ($p['time'] ?? '')) : false;
            if (!$dt || $dt->getTimestamp() <= time()) {
                $pdo->prepare('UPDATE appointments SET reschedule_proposal = NULL WHERE id = ?')->execute([$pr['id']]);
            }
        }
    } catch (PDOException) { /* column not migrated yet */ }

    // Same for a patient's own reschedule request the clinic never acted on:
    // once the preferred time (or, without one, the whole preferred day) has
    // passed, it lapses — the held slot is released and the appointment
    // stays as it was. The patient is told, so it doesn't just vanish.
    foreach ($pdo->query("SELECT id, patient_id, date, time, reschedule_request FROM appointments
                          WHERE reschedule_request IS NOT NULL AND status IN ('pending','approved')")->fetchAll() as $rq) {
        $q = json_decode($rq['reschedule_request'] ?? '', true);
        if (!is_array($q) || empty($q['preferredDate'])) continue;   // no preferred slot → nothing to lapse
        $limit = !empty($q['preferredTime'])
            ? DateTime::createFromFormat('Y-m-d g:i A', $q['preferredDate'] . ' ' . $q['preferredTime'])
            : DateTime::createFromFormat('Y-m-d H:i:s', $q['preferredDate'] . ' 23:59:59');
        if (!$limit || $limit->getTimestamp() > time()) continue;
        $upd = $pdo->prepare('UPDATE appointments SET reschedule_request = NULL WHERE id = ? AND reschedule_request IS NOT NULL');
        $upd->execute([$rq['id']]);
        if ($upd->rowCount() === 0 || !$rq['patient_id']) continue;
        $ps = $pdo->prepare('SELECT user_id FROM patients WHERE id = ? LIMIT 1');
        $ps->execute([$rq['patient_id']]);
        if ($puid = $ps->fetchColumn()) {
            createNotification($pdo, (int)$puid, 'reschedule_request', 'Reschedule Request Expired',
                'Your reschedule request expired because your preferred time passed before the clinic could act on it. '
                . 'Your appointment stays on ' . date('M j, Y', strtotime($rq['date'])) . " at {$rq['time']}. You can send a new request anytime.",
                $rq['id']);
        }
    }

    // Auto-transition approved appointments whose date has passed to 'no-show'.
    // Read the affected rows first (not just the count) so each one can be
    // charged against its patient's no-show tally — the WHERE clause only
    // ever matches a row once (it flips out of 'approved' immediately after),
    // so this can't double-count a patient across repeated poll requests.
    $dueNoShow = $pdo->query(
        "SELECT id, patient_id, doctor_id, doctor_name, date, time FROM appointments
         WHERE status = 'approved' AND date < CURDATE() AND no_show_reverted_at IS NULL"
    )->fetchAll();

    // Appointments with an auto-saved exam draft (exam_drafts) were seen —
    // the doctor started the exam, it just never got saved. Never charge
    // those as a no-show: they stay approved, flagged "Unsaved exam draft"
    // (examDraftAt below), until the exam is finished and saved. Own
    // try/catch so a database without exam_drafts yet works as before.
    $draftMap = [];
    try {
        foreach ($pdo->query("SELECT patient_id, appt_id, saved_by, updated_at FROM exam_drafts WHERE appt_id <> ''")->fetchAll() as $dr) {
            $draftMap[$dr['appt_id']] = $dr;
        }
    } catch (PDOException) { /* exam_drafts not migrated yet */ }
    $dueNoShow = array_values(array_filter($dueNoShow,
        fn($r) => !(isset($draftMap[$r['id']]) && $draftMap[$r['id']]['patient_id'] === $r['patient_id'])));

    if ($dueNoShow) {
        // Only the rows read above — a no-show an admin already reverted
        // (no_show_reverted_at set) stays approved so its exam can still be
        // recorded.
        $ids = array_column($dueNoShow, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare(
            "UPDATE appointments SET status = 'no-show'
             WHERE status = 'approved' AND id IN ($in)"
        )->execute($ids);
        foreach ($dueNoShow as $missed) {
            if (!$missed['patient_id']) continue;
            $justRestricted = recordNoShow($pdo, $missed['patient_id']);
            $ps = $pdo->prepare('SELECT user_id FROM patients WHERE id = ? LIMIT 1');
            $ps->execute([$missed['patient_id']]);
            $puid = $ps->fetchColumn();
            if ($puid) notifyPatientNoShow($pdo, (int)$puid, $missed, $justRestricted);
        }
    }

    if (in_array($role, ['admin', 'staff'], true)) {
        $stmt = $pdo->query('SELECT * FROM appointments ORDER BY date DESC, time ASC');
    } elseif ($role === 'doctor') {
        $stmt = $pdo->prepare('SELECT * FROM appointments WHERE doctor_id = ? ORDER BY date DESC, time ASC');
        $stmt->execute([$profileId]);
    } elseif ($role === 'patient') {
        $stmt = $pdo->prepare('SELECT * FROM appointments WHERE patient_id = ? ORDER BY date DESC, time ASC');
        $stmt->execute([$profileId]);
    } else {
        jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
        return; // unreachable but satisfies static analysis
    }

    $rows = $stmt->fetchAll();
    $out  = array_map('mapRow', $rows);
    // Flag appointments with an unsaved exam draft (staff/doctor/admin
    // views only — patients don't see clinic-internal drafts).
    if ($role !== 'patient') {
        foreach ($out as &$o) {
            $dr = $draftMap[$o['id']] ?? null;
            if ($dr && $dr['patient_id'] === $o['patientId']) {
                $o['examDraftAt'] = $dr['updated_at'];
                $o['examDraftBy'] = $dr['saved_by'] ?? '';
            }
        }
        unset($o);
    }
    jsonResponse(['success' => true, 'appointments' => $out]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error.'], 500);
}
