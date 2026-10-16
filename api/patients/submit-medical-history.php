<?php
// ================================================================
//  CANAOPTICALCLINIC — api/patients/submit-medical-history.php
//  POST { medicalHistory }
//  → { success:true, medicalHistory } | { success:false, message }
//
//  A patient's ONE-TIME self-report of their medical history. Allowed
//  only while nothing is on file AND the patient hasn't already used
//  their one entry (medical_history_patient_at). After that the record
//  is clinic-managed — doctors/admin/staff edit it through
//  update-medical-history.php / admin_update.php. The text is flagged
//  medical_history_by_patient = 1 so the clinic sees it as "Reported by
//  patient" until one of them reviews/edits it.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
if (($_SESSION['role'] ?? '') !== 'patient') {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
}

rateLimit('submit-medical-history:' . (int)$_SESSION['user_id'], 10, 600);

$b       = getBody();
$history = trim((string)($b['medicalHistory'] ?? ''));
// { none:true } = "I have no medical history" — uses up the one-time entry
// with nothing on file, so the dashboard stops prompting and the clinic
// sees "Patient reported no known medical history" instead of a blank.
$none    = !empty($b['none']);
if ($none) $history = '';
if (!$none && $history === '') {
    jsonResponse(['success' => false, 'message' => 'Please enter your medical history.']);
}
if (mb_strlen($history) > 2000) {
    jsonResponse(['success' => false, 'message' => 'Medical history is too long (max 2,000 characters).']);
}

try {
    $pdo = getDB();
    $patientId = $_SESSION['profile_id'] ?? '';

    // Single conditional UPDATE — only succeeds while the record is still
    // empty and the one-time entry is unused, so two quick submits (or a
    // clinic edit landing at the same moment) can't both go through.
    $upd = $pdo->prepare(
        "UPDATE patients
            SET medical_history = ?, medical_history_by_patient = 1, medical_history_patient_at = NOW()
          WHERE id = ? AND medical_history_patient_at IS NULL
            AND (medical_history IS NULL OR medical_history = '')"
    );
    $upd->execute([$none ? null : $history, $patientId]);
    if ($none) {
        // Nothing to flag as patient-reported text — just the one-time answer
        $pdo->prepare('UPDATE patients SET medical_history_by_patient = 0 WHERE id = ?')->execute([$patientId]);
    }

    if ($upd->rowCount() === 0) {
        jsonResponse(['success' => false, 'locked' => true,
            'message' => 'Your medical history is already on file. To update it, please inform your doctor at your next visit or contact the clinic.']);
    }

    $pt = $pdo->prepare('SELECT first_name, last_name FROM patients WHERE id = ? LIMIT 1');
    $pt->execute([$patientId]);
    $pt = $pt->fetch();
    $name = $pt ? $pt['first_name'] . ' ' . $pt['last_name'] : $patientId;

    $logId = 'L' . date('YmdHis') . rand(100, 999);
    $pdo->prepare(
        'INSERT IGNORE INTO activity_log (id, user_name, role, action, timestamp, type)
         VALUES (?,?,?,?,NOW(),?)'
    )->execute([substr($logId, 0, 20), $name, 'Patient',
        $none ? "Reported no known medical history ({$patientId})"
              : "Added their medical history (patient-reported, for doctor review) ({$patientId})", 'patient']);

    jsonResponse(['success' => true, 'medicalHistory' => $history]);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
