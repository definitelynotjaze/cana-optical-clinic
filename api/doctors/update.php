<?php
// ================================================================
//  CANAOPTICALCLINIC — api/doctors/update.php
//  POST { doctorId, days:[], dayHours:{Mon:{start,end}|null,…}, workHours, available }
//  Updates a doctor's weekly availability pattern (which days they
//  work, each day's own hours, and whether they're marked available at all).
//  Distinct from blocked_dates, which handles one-off date blocks.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
if (!in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
    jsonResponse(['success' => false, 'message' => 'Only admin or staff may edit doctor schedules.'], 403);
}

$b         = getBody();
$doctorId  = trim($b['doctorId'] ?? '');
$workHours = trim($b['workHours'] ?? '');
$available = !empty($b['available']);
$days      = is_array($b['days'] ?? null) ? $b['days'] : [];

$validDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$days      = array_values(array_intersect($days, $validDays));

if (!$doctorId) {
    jsonResponse(['success' => false, 'message' => 'doctorId is required.']);
}

// Per-day hours: { Mon: {start:'9:00 AM', end:'12:00 PM'} | null, … }.
// A missing/null day = the clinic's full hours that day (stored as NULL so
// it follows Consultation Settings if the clinic hours ever change).
$dayHoursIn = is_array($b['dayHours'] ?? null) ? $b['dayHours'] : [];
$toMin = function (string $t): ?int {
    if (!preg_match('/^(1[0-2]|[1-9]):([0-5]\d) (AM|PM)$/', $t, $m)) return null;
    $h = (int)$m[1] % 12 + ($m[3] === 'PM' ? 12 : 0);
    return $h * 60 + (int)$m[2];
};
$dayHours = [];
foreach ($days as $day) {
    $h = $dayHoursIn[$day] ?? null;
    if (!is_array($h)) { $dayHours[$day] = null; continue; }
    $start = trim((string)($h['start'] ?? ''));
    $end   = trim((string)($h['end']   ?? ''));
    $sMin  = $toMin($start);
    $eMin  = $toMin($end);
    if ($sMin === null || $eMin === null) {
        jsonResponse(['success' => false, 'message' => "Please pick a valid start and end time for {$day}."]);
    }
    if ($eMin <= $sMin) {
        jsonResponse(['success' => false, 'message' => "{$day}: end time must be later than the start time."]);
    }
    $dayHours[$day] = ['start' => $start, 'end' => $end];
}

try {
    $pdo = getDB();

    $chk = $pdo->prepare('SELECT id FROM doctors WHERE id = ? LIMIT 1');
    $chk->execute([$doctorId]);
    if (!$chk->fetch()) {
        jsonResponse(['success' => false, 'message' => 'Doctor not found.'], 404);
    }

    // Check existing appointments before changing anything. Upcoming
    // pending/approved appointments that the new days/hours would leave out
    // — or, when the doctor is being marked unavailable, all upcoming ones —
    // are sent back for staff to review; nothing is saved unless they
    // confirm with force:true ("Save & Keep These Bookings" — the bookings
    // stay valid and are flagged "Outside Schedule" in Appointments).
    if (empty($b['force'])) {
        // Only what THIS change breaks: appointments already outside the
        // current schedule (from an earlier edit) aren't re-flagged on
        // every save.
        $curDaysSt = $pdo->prepare('SELECT day_of_week FROM doctor_days WHERE doctor_id = ?');
        $curDaysSt->execute([$doctorId]);
        $alreadyOff = array_column(scheduleChangeConflicts($pdo, $doctorId,
            $curDaysSt->fetchAll(PDO::FETCH_COLUMN), doctorDayHoursMap($pdo, $doctorId)[$doctorId] ?? []), 'id');
        $conflicts = array_values(array_filter(scheduleChangeConflicts($pdo, $doctorId, $days, $dayHours),
            fn($c) => !in_array($c['id'], $alreadyOff, true)));
        $wasAvail  = (bool)$pdo->query('SELECT available FROM doctors WHERE id = ' . $pdo->quote($doctorId))->fetchColumn();
        if (!$available && $wasAvail) {
            $seen = array_column($conflicts, 'id');
            foreach (upcomingDoctorAppointments($pdo, $doctorId) as $r) {
                if (!in_array($r['id'], $seen, true)) $conflicts[] = _affectedAppt($r, 'Upcoming while marked unavailable');
            }
        }
        if ($conflicts) {
            jsonResponse(['success' => false, 'needsConfirm' => true, 'conflicts' => $conflicts,
                'message' => count($conflicts) . ' upcoming appointment' . (count($conflicts) === 1 ? ' is' : 's are') . ' affected by this change.']);
        }
    }

    $pdo->beginTransaction();

    $pdo->prepare('UPDATE doctors SET available = ?, work_hours = ? WHERE id = ?')
        ->execute([$available ? 1 : 0, $workHours ?: null, $doctorId]);

    $pdo->prepare('DELETE FROM doctor_days WHERE doctor_id = ?')->execute([$doctorId]);
    if ($days) {
        $ins = $pdo->prepare('INSERT INTO doctor_days (doctor_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)');
        foreach ($days as $day) {
            $ins->execute([$doctorId, $day, $dayHours[$day]['start'] ?? null, $dayHours[$day]['end'] ?? null]);
        }
    }

    $pdo->commit();

    jsonResponse(['success' => true]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
