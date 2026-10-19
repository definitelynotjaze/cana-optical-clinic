<?php
// ================================================================
//  CANAOPTICALCLINIC — api/previous_records/archive.php
//  Admin, staff and doctors. Moves a Previous Clinic Record to Archives
//  (wrong patient, duplicate upload…). It leaves the profile, the exam
//  wizard and the medical record, but nothing is lost: an admin can
//  restore it, or delete it for good, from Settings > Archives. The
//  same archive-first rule as patients, accounts and services.
//
//  POST { id, reason } → { success:true, record:{ archived_records entry } }
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
if (!in_array($_SESSION['role'] ?? '', ['admin', 'staff', 'doctor'], true)) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$b      = getBody();
$id     = (int)($b['id'] ?? 0);
$reason = mb_substr(trim((string)($b['reason'] ?? '')), 0, 400);
if (!$id) jsonResponse(['success' => false, 'message' => 'id is required.']);
if ($reason === '') jsonResponse(['success' => false, 'message' => 'Give a reason for archiving this record.']);

try {
    $pdo = getDB();
    if (!previousRecordArchiveColumn($pdo)) {
        jsonResponse(['success' => false, 'message' => 'Archiving needs a database update first: run database/previous_records_columns.sql.'], 500);
    }

    $st = $pdo->prepare(
        'SELECT r.*, p.first_name, p.last_name
           FROM previous_clinic_records r
           LEFT JOIN patients p ON p.id = r.patient_id
          WHERE r.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $rec = $st->fetch();
    if (!$rec) jsonResponse(['success' => false, 'message' => 'That record no longer exists.']);
    if (!empty($rec['archived_at'])) jsonResponse(['success' => false, 'message' => 'That record is already in Archives.']);

    // A full copy for the Archives view (values, notes, file list), so the
    // admin can see exactly what was archived before restoring or deleting.
    $fs = $pdo->prepare('SELECT id, original_name, mime_type, size_bytes FROM previous_clinic_files WHERE record_id = ? ORDER BY id');
    $fs->execute([$id]);
    $ptName   = trim(($rec['first_name'] ?? '') . ' ' . ($rec['last_name'] ?? ''));
    $snapshot = previousRecordShape($rec, $fs->fetchAll()) + ['patientName' => $ptName];

    $actor = sessionActorName($pdo);
    $arcId = 'AR' . date('YmdHis') . random_int(10, 99);
    $date  = date('M j, Y');
    $name  = mb_substr($rec['clinic_name'] . ' · ' . ($ptName ?: $rec['patient_id']), 0, 255);

    $pdo->beginTransaction();
    $pdo->prepare('UPDATE previous_clinic_records SET archived_at = NOW() WHERE id = ?')->execute([$id]);
    $pdo->prepare(
        'INSERT INTO archived_records (id, type, name, ref_id, archived_by, reason, data_json, date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$arcId, PREV_RECORD_ARCHIVE_TYPE, $name, (string)$id, $actor, $reason,
                json_encode($snapshot, JSON_UNESCAPED_UNICODE), $date]);
    $pdo->commit();

    logActivity($pdo, "Archived the previous clinic record from {$rec['clinic_name']} for $ptName ({$rec['patient_id']}). Reason: $reason", 'patient');

    jsonResponse(['success' => true, 'record' => [
        'id' => $arcId, 'type' => PREV_RECORD_ARCHIVE_TYPE, 'name' => $name, 'refId' => (string)$id,
        'archivedBy' => $actor, 'reason' => $reason, 'date' => $date, 'data' => $snapshot,
    ]]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
