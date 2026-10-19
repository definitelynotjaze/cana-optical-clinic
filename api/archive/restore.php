<?php
// ================================================================
//  CANAOPTICALCLINIC — api/archive/restore.php
//  Admin only. Restores an archived account/patient back to the
//  active list and re-enables login; a service, or a previous clinic
//  record (back onto its patient's profile).
//
//  POST { id }   — archived_records.id
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
if ($_SESSION['role'] !== 'admin') {
    jsonResponse(['success' => false, 'message' => 'Only admins may restore records.'], 403);
}

$b  = getBody();
$id = trim($b['id'] ?? '');
if (!$id) {
    jsonResponse(['success' => false, 'message' => 'id is required.']);
}

try {
    $pdo = getDB();

    $s = $pdo->prepare('SELECT * FROM archived_records WHERE id = ? LIMIT 1');
    $s->execute([$id]);
    $rec = $s->fetch();
    if (!$rec) {
        jsonResponse(['success' => false, 'message' => 'Archived record not found.']);
    }

    // Services aren't a user account/role row — just flip status back to active.
    if ($rec['type'] === 'Service') {
        $pdo->prepare("UPDATE clinic_services SET status = 'active' WHERE id = ?")->execute([$rec['ref_id']]);
        $pdo->prepare('DELETE FROM archived_records WHERE id = ?')->execute([$id]);
        jsonResponse(['success' => true, 'role' => 'Service']);
    }

    // A previous clinic record goes back onto its patient's profile.
    // (The Activity Log entry comes from the page, as for every restore.)
    if ($rec['type'] === PREV_RECORD_ARCHIVE_TYPE) {
        $rs = $pdo->prepare('SELECT id FROM previous_clinic_records WHERE id = ? LIMIT 1');
        $rs->execute([(int)$rec['ref_id']]);
        if (!$rs->fetch()) {
            jsonResponse(['success' => false, 'message' => 'The original record no longer exists.']);
        }
        $pdo->prepare('UPDATE previous_clinic_records SET archived_at = NULL WHERE id = ?')->execute([(int)$rec['ref_id']]);
        $pdo->prepare('DELETE FROM archived_records WHERE id = ?')->execute([$id]);
        jsonResponse(['success' => true, 'role' => PREV_RECORD_ARCHIVE_TYPE]);
    }

    // Examinations aren't a user account/role row either — unset the
    // archived flag on the exam and cascade to its linked consultation/
    // prescription too, mirroring the cascade archive/create.php applied.
    if ($rec['type'] === 'Examination') {
        $ex = $pdo->prepare('SELECT id, consultation_id FROM examinations WHERE id = ? LIMIT 1');
        $ex->execute([$rec['ref_id']]);
        $exam = $ex->fetch();
        if (!$exam) {
            jsonResponse(['success' => false, 'message' => 'The original examination no longer exists.']);
        }
        $pdo->prepare('UPDATE examinations SET archived_at = NULL WHERE id = ?')->execute([$rec['ref_id']]);
        if ($exam['consultation_id']) {
            $pdo->prepare('UPDATE consultations SET archived_at = NULL WHERE id = ?')->execute([$exam['consultation_id']]);
        }
        $pdo->prepare('UPDATE prescriptions SET archived_at = NULL WHERE exam_id = ?')->execute([$rec['ref_id']]);
        $pdo->prepare('DELETE FROM archived_records WHERE id = ?')->execute([$id]);
        jsonResponse(['success' => true, 'role' => 'Examination']);
    }

    $data  = $rec['data_json'] ? json_decode($rec['data_json'], true) : [];
    $role  = $data['role'] ?? ($rec['type'] === 'Patient' ? 'Patient' : null);
    $tableMap = ['Admin' => 'admins', 'Staff' => 'staff', 'Doctor' => 'doctors', 'Patient' => 'patients'];
    $table = $tableMap[$role] ?? null;

    if (!$table) {
        jsonResponse(['success' => false, 'message' => 'Cannot determine record type for restore.']);
    }

    $rs = $pdo->prepare("SELECT user_id FROM `{$table}` WHERE id = ? LIMIT 1");
    $rs->execute([$rec['ref_id']]);
    $row = $rs->fetch();
    if (!$row) {
        jsonResponse(['success' => false, 'message' => 'The original record no longer exists.']);
    }

    $pdo->prepare("UPDATE `{$table}` SET archived_at = NULL WHERE id = ?")->execute([$rec['ref_id']]);
    if ($row['user_id']) {
        // Reset last_login_at too, otherwise the stale value immediately
        // re-triggers the 1-year auto-deactivation on the next login.
        $pdo->prepare('UPDATE users SET is_active = 1, last_login_at = NOW() WHERE id = ?')
            ->execute([$row['user_id']]);
    }
    $pdo->prepare('DELETE FROM archived_records WHERE id = ?')->execute([$id]);

    jsonResponse(['success' => true, 'role' => $role]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
