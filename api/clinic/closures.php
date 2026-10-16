<?php
// ================================================================
//  CANAOPTICALCLINIC — api/clinic/closures.php
//  Clinic-wide closures: one date closes the clinic for EVERY doctor
//  (sudden holiday, clinic outing, emergency, …) instead of blocking each
//  doctor one by one. Closed dates show as blocked for every doctor in all
//  calendars (merged in api/doctors/index.php + public.php) and every
//  booking/reschedule path refuses them (clinicClosureOn(), helpers.php).
//
//  GET                                   → { success, closures:[{id,date,reason,createdBy}] } (today on)
//  POST { action:'add', date, reason }   → admin/staff. Refused while the date
//                                          still has pending/approved
//                                          appointments (listed back), same as
//                                          blocking a doctor's date.
//  POST { action:'remove', id }          → admin/staff, reopens the date.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

startSession();
if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
$role = $_SESSION['role'] ?? '';

try {
    $pdo = getDB();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $rows = $pdo->query('SELECT id, date, reason, created_by FROM clinic_closures WHERE date >= CURDATE() ORDER BY date')->fetchAll();
        jsonResponse(['success' => true, 'closures' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'date' => $r['date'], 'reason' => $r['reason'] ?? '', 'createdBy' => $r['created_by'] ?? '',
        ], $rows)]);
    }

    if (!in_array($role, ['admin', 'staff'], true)) {
        jsonResponse(['success' => false, 'message' => 'Only admin or staff may close the clinic.'], 403);
    }
    $b      = getBody();
    $action = $b['action'] ?? '';

    if ($action === 'remove') {
        $id = (int)($b['id'] ?? 0);
        $pdo->prepare('DELETE FROM clinic_closures WHERE id = ?')->execute([$id]);
        jsonResponse(['success' => true]);
    }

    if ($action === 'add') {
        $date   = trim($b['date'] ?? '');
        $reason = mb_substr(trim($b['reason'] ?? ''), 0, 255);
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) jsonResponse(['success' => false, 'message' => 'Please choose a valid date.']);
        if ($date < date('Y-m-d'))               jsonResponse(['success' => false, 'message' => 'Please choose today or a future date.']);
        if ($reason === '')                       jsonResponse(['success' => false, 'message' => 'Please give a reason for closing the clinic.']);

        // Same rule as blocking a doctor's date: a closed day can't keep
        // appointments, so pending/approved ones (any doctor) must be moved
        // or cancelled first — they're listed back so staff can see which.
        $st = $pdo->prepare(
            "SELECT id, patient_name, date, time, status, type, doctor_name FROM appointments
              WHERE date = ? AND status IN ('pending','approved') ORDER BY time"
        );
        $st->execute([$date]);
        $now = time();
        $onDate = array_values(array_filter($st->fetchAll(), function ($r) use ($now) {
            $dt = DateTime::createFromFormat('Y-m-d g:i A', $r['date'] . ' ' . $r['time']);
            return !$dt || $dt->getTimestamp() > $now;
        }));
        if ($onDate) {
            $n = count($onDate);
            jsonResponse(['success' => false, 'blocked' => true,
                'conflicts' => array_map(fn($r) => _affectedAppt($r, 'Booked on this date' . ($r['doctor_name'] ? ' · ' . $r['doctor_name'] : '')), $onDate),
                'message' => "This date has {$n} pending or approved appointment" . ($n === 1 ? '' : 's')
                           . '. Reschedule or cancel ' . ($n === 1 ? 'it' : 'them') . ' before closing the clinic.']);
        }

        $by = trim($b['createdBy'] ?? '') ?: 'Staff';
        $pdo->prepare('INSERT INTO clinic_closures (date, reason, created_by) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE reason = VALUES(reason), created_by = VALUES(created_by)')
            ->execute([$date, $reason, $by]);
        jsonResponse(['success' => true]);
    }

    jsonResponse(['success' => false, 'message' => 'Unknown action.']);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please run database/clinic_closures.sql if you haven\'t yet.'], 500);
}
