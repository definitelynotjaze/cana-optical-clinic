<?php
// ================================================================
//  CANAOPTICALCLINIC — api/doctors/block-date.php
//  POST { doctorId, date, reason } — admin/staff only.
//  Marks a single date as unavailable for a doctor (leave, conference,
//  holiday, etc.) — separate from their recurring weekly schedule.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'staff'], true)) {
    jsonResponse(['success' => false, 'message' => 'Only admin or staff may block dates.'], 403);
}

$b         = getBody();
$doctorId  = trim($b['doctorId']  ?? '');
$date      = trim($b['date']      ?? '');
$reason    = trim($b['reason']    ?? '');
$blockedBy = trim($b['blockedBy'] ?? '') ?: 'Staff';

if (!$doctorId || !$date) {
    jsonResponse(['success' => false, 'message' => 'doctorId and date are required.']);
}
if (!$reason) {
    jsonResponse(['success' => false, 'message' => 'A reason is required to block this date.']);
}

$d = DateTime::createFromFormat('Y-m-d', $date);
if (!$d || $d->format('Y-m-d') !== $date) {
    jsonResponse(['success' => false, 'message' => 'Invalid date format.']);
}

try {
    $pdo = getDB();

    $chk = $pdo->prepare('SELECT id FROM doctors WHERE id = ? LIMIT 1');
    $chk->execute([$doctorId]);
    if (!$chk->fetch()) {
        jsonResponse(['success' => false, 'message' => 'Doctor not found.'], 404);
    }

    // Blocking a date that already has approved appointments would silently
    // strand those patients — the doctor becomes "unavailable" underneath
    // visits they already committed to, with nothing warning either the
    // patient or the staff who eventually notices. Require those be
    // cancelled/rescheduled first instead of letting the block succeed
    // unchecked. Scoped to 'approved' only (not 'pending') — nothing was
    // promised to the patient yet for a still-pending request.
    // Pending AND approved appointments on that date must be moved or
    // cancelled first — a blocked date can't keep appointments. The list
    // comes back so staff can see exactly which ones.
    $onDate = upcomingDoctorAppointments($pdo, $doctorId, $date);
    if ($onDate) {
        $n = count($onDate);
        jsonResponse(['success' => false, 'blocked' => true,
            'conflicts' => array_map(fn($r) => _affectedAppt($r, 'Booked on this date'), $onDate),
            'message' => "This date has {$n} pending or approved appointment" . ($n === 1 ? '' : 's')
                       . '. Reschedule or cancel ' . ($n === 1 ? 'it' : 'them') . ' before blocking this date.']);
    }

    $s = $pdo->prepare(
        'INSERT INTO blocked_dates (doctor_id, date, reason, created_by) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE reason = VALUES(reason)'
    );
    $s->execute([$doctorId, $date, $reason, $blockedBy]);

    jsonResponse(['success' => true]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
