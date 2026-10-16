<?php
// ================================================================
//  CANAOPTICALCLINIC — api/appointments/confirm.php
//  Patient-only. Confirms attendance after a reminder was sent, so the
//  clinic knows to expect them (informational — nothing is auto-cancelled).
//  POST { id }
//  → { success:true }
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
if (($_SESSION['role'] ?? '') !== 'patient') {
    jsonResponse(['success' => false, 'message' => 'Only patients may confirm their own appointment.'], 403);
}

$patientId = $_SESSION['profile_id'] ?? '';
$b  = getBody();
$id = trim($b['id'] ?? '');

if (!$id) {
    jsonResponse(['success' => false, 'message' => 'id is required.']);
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare('SELECT * FROM appointments WHERE id = ? AND patient_id = ? LIMIT 1');
    $stmt->execute([$id, $patientId]);
    $appt = $stmt->fetch();
    if (!$appt) {
        jsonResponse(['success' => false, 'message' => 'Appointment not found.']);
    }
    // Same rules as the one-tap email link (confirm-link.php)
    [$ok, $msg] = confirmAppointmentAttendance($pdo, $appt);
    jsonResponse($ok ? ['success' => true] : ['success' => false, 'message' => $msg]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
