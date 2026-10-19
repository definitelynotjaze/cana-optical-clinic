<?php
// ================================================================
//  CANAOPTICALCLINIC — api/archive/delete.php
//  Admin only. Permanently deletes an archived account/patient —
//  removes the role row (cascading to appointments/examinations/
//  prescriptions/consultations), the login row, and the archive
//  entry itself — or an archived service or previous clinic record
//  (with its uploaded files). This is irreversible.
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
    jsonResponse(['success' => false, 'message' => 'Only admins may permanently delete records.'], 403);
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

    // Services have no FK references from appointments (type is stored as
    // plain text there), so a hard delete is safe — no cascade needed.
    if ($rec['type'] === 'Service') {
        $pdo->prepare('DELETE FROM clinic_services WHERE id = ?')->execute([$rec['ref_id']]);
        $pdo->prepare('DELETE FROM archived_records WHERE id = ?')->execute([$id]);
        jsonResponse(['success' => true]);
    }

    // A previous clinic record: its row and uploaded files go for good.
    // (The Activity Log entry comes from the page, as for every delete.)
    if ($rec['type'] === PREV_RECORD_ARCHIVE_TYPE) {
        deletePreviousRecordPermanently($pdo, (int)$rec['ref_id']);
        $pdo->prepare('DELETE FROM archived_records WHERE id = ?')->execute([$id]);
        jsonResponse(['success' => true]);
    }

    // Examination records are permanent medical records — never deleted.
    // One archived before this rule can still be restored from Archives.
    if ($rec['type'] === 'Examination') {
        jsonResponse(['success' => false, 'message' => 'Examination records are permanent medical records and can\'t be deleted. Restore it instead.'], 403);
    }

    $data  = $rec['data_json'] ? json_decode($rec['data_json'], true) : [];
    $role  = $data['role'] ?? ($rec['type'] === 'Patient' ? 'Patient' : null);
    $tableMap = ['Admin' => 'admins', 'Staff' => 'staff', 'Doctor' => 'doctors', 'Patient' => 'patients'];
    $table = $tableMap[$role] ?? null;

    if ($table) {
        $rs = $pdo->prepare("SELECT user_id FROM `{$table}` WHERE id = ? LIMIT 1");
        $rs->execute([$rec['ref_id']]);
        $row = $rs->fetch();

        // Their archived previous clinic records leave Archives too — the
        // records themselves are about to go with the patient.
        if ($table === 'patients') {
            try {
                $ids = $pdo->prepare('SELECT id FROM previous_clinic_records WHERE patient_id = ?');
                $ids->execute([$rec['ref_id']]);
                $ids = array_map('strval', $ids->fetchAll(PDO::FETCH_COLUMN));
                if ($ids) {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $pdo->prepare("DELETE FROM archived_records WHERE type = ? AND ref_id IN ($in)")
                        ->execute([PREV_RECORD_ARCHIVE_TYPE, ...$ids]);
                }
            } catch (PDOException) {}   // no previous_clinic_records table yet
        }

        // Cascades to appointments/examinations/prescriptions/consultations
        // (and previous clinic records, whose uploaded files go below)
        $pdo->prepare("DELETE FROM `{$table}` WHERE id = ?")->execute([$rec['ref_id']]);
        if ($table === 'patients') deletePreviousRecordFiles($rec['ref_id']);

        if ($row && $row['user_id']) {
            // Captured before the users row goes — this is the one thing
            // that still ties a self-registered account back to its
            // original pending_registrations row (kept, not deleted, once
            // verified — see verify-email-otp.php). Without deleting it
            // here too, "permanently" deleting a patient would leave
            // their dob/address/contact/password hash sitting untouched
            // in that second table forever — quietly undercutting what
            // this endpoint's own name promises, in an app whose
            // registration form has patients explicitly consent to
            // RA 10173 data processing. NULL for every non-self-
            // registered account (Add Patient/Add User never touch
            // pending_registrations), so this is a no-op for those.
            $pr = $pdo->prepare('SELECT pending_registration_id FROM users WHERE id = ? LIMIT 1');
            $pr->execute([$row['user_id']]);
            $pendingId = $pr->fetchColumn();

            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$row['user_id']]);

            if ($pendingId) {
                $pdo->prepare('DELETE FROM pending_registrations WHERE id = ?')->execute([$pendingId]);
            }
        }
    }

    $pdo->prepare('DELETE FROM archived_records WHERE id = ?')->execute([$id]);

    jsonResponse(['success' => true]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
