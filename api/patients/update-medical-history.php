<?php
// ================================================================
//  CANAOPTICALCLINIC — api/patients/update-medical-history.php
//  POST { patientId, medicalHistory }
//  → { success:true, medicalHistory } | { success:false, message }
//
//  Updates ONLY a patient's medical history. Doctors can't use
//  admin_update.php (it edits the whole profile and can create the
//  patient's login), but they're the ones taking the history during a
//  consultation, so they get this narrow endpoint instead. Admin/staff
//  may use it too (same field they can already edit via Edit Patient).
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
if (!in_array($_SESSION['role'] ?? '', ['doctor', 'admin', 'staff'], true)) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
}

rateLimit('medical-history:' . (int)$_SESSION['user_id'], 60, 300);

$b         = getBody();
$patientId = trim($b['patientId'] ?? '');
$history   = trim((string)($b['medicalHistory'] ?? ''));

if (!$patientId) {
    jsonResponse(['success' => false, 'message' => 'patientId is required.']);
}
if (mb_strlen($history) > 2000) {
    jsonResponse(['success' => false, 'message' => 'Medical history is too long (max 2,000 characters).']);
}

try {
    $pdo = getDB();
    $chk = $pdo->prepare('SELECT id FROM patients WHERE id = ? AND archived_at IS NULL LIMIT 1');
    $chk->execute([$patientId]);
    if (!$chk->fetchColumn()) {
        jsonResponse(['success' => false, 'message' => 'Patient not found.'], 404);
    }

    // A clinic edit means the history has now been reviewed — it's no
    // longer "Reported by patient".
    $pdo->prepare('UPDATE patients SET medical_history = ?, medical_history_by_patient = 0 WHERE id = ?')
        ->execute([$history !== '' ? $history : null, $patientId]);

    jsonResponse(['success' => true, 'medicalHistory' => $history]);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
