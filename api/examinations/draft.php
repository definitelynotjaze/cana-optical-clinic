<?php
// ================================================================
//  CANAOPTICALCLINIC — api/examinations/draft.php
//  Auto-saved drafts of the New Examination wizard (exam_drafts table),
//  one per patient + appointment, so an exam that never got saved (lost
//  connection, closed tab, crashed browser) isn't lost — including when the
//  appointment was auto-marked a no-show the next day and an admin reverts
//  it: the draft is still here to restore.
//
//  GET  ?patientId=P001&apptId=A123   → { success, draft: { data, savedBy, updatedAt } | null }
//  POST { patientId, apptId, data }    → { success, updatedAt }   (upsert)
//  POST { action:'delete', patientId, apptId } → { success }
//
//  Doctor / admin only (the roles that can create examinations).
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
$role   = $_SESSION['role'] ?? '';
$userId = (int)$_SESSION['user_id'];
$isGet = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
// Staff may only check whether a draft exists (Revert No-Show shows it as
// proof the patient was seen); saving/deleting stays doctor/admin only.
if (!in_array($role, ['doctor', 'admin'], true) && !($isGet && $role === 'staff')) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$b     = $isGet ? $_GET : getBody();
$patientId = trim((string)($b['patientId'] ?? ''));
$apptId    = trim((string)($b['apptId']    ?? ''));
if ($patientId === '' || strlen($patientId) > 10 || strlen($apptId) > 10) {
    jsonResponse(['success' => false, 'message' => 'patientId is required.']);
}

try {
    $pdo = getDB();

    if ($isGet) {
        $s = $pdo->prepare('SELECT data, saved_by, updated_at FROM exam_drafts WHERE patient_id = ? AND appt_id = ? LIMIT 1');
        $s->execute([$patientId, $apptId]);
        $row = $s->fetch();
        jsonResponse(['success' => true, 'draft' => $row ? [
            'data'      => json_decode($row['data'], true) ?: new stdClass(),
            'savedBy'   => $row['saved_by'] ?? '',
            'updatedAt' => $row['updated_at'],
        ] : null]);
    }

    if (($b['action'] ?? '') === 'delete') {
        $pdo->prepare('DELETE FROM exam_drafts WHERE patient_id = ? AND appt_id = ?')->execute([$patientId, $apptId]);
        jsonResponse(['success' => true]);
    }

    $data = $b['data'] ?? null;
    if (!is_array($data)) {
        jsonResponse(['success' => false, 'message' => 'Nothing to save.']);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > 200000) {
        jsonResponse(['success' => false, 'message' => 'Draft is too large to save.']);
    }

    // Who saved it, for "Draft saved by Dr. X at 3:14 PM".
    $table = $role === 'admin' ? 'admins' : 'doctors';
    $n = $pdo->prepare("SELECT first_name, last_name FROM `{$table}` WHERE user_id = ? LIMIT 1");
    $n->execute([$userId]);
    $nm = $n->fetch();
    $savedBy = $nm ? trim(($role === 'doctor' ? 'Dr. ' : '') . $nm['first_name'] . ' ' . $nm['last_name']) : '';

    $pdo->prepare(
        'INSERT INTO exam_drafts (patient_id, appt_id, data, saved_by, saved_by_user)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE data = VALUES(data), saved_by = VALUES(saved_by),
                                 saved_by_user = VALUES(saved_by_user), updated_at = CURRENT_TIMESTAMP'
    )->execute([$patientId, $apptId, $json, $savedBy, $userId]);

    jsonResponse(['success' => true, 'updatedAt' => date('Y-m-d H:i:s')]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Could not save the draft.'], 500);
}
