<?php
// ================================================================
//  CANAOPTICALCLINIC — api/previous_records/save.php
//  Admin, staff and doctors. Adds or updates a Previous Clinic Record:
//  exam results / a prescription a patient brings from another optical
//  clinic, as uploaded PDF or scanned images (JPG / PNG) plus any values
//  typed in from it so doctors can compare at the next exam.
//
//  The files themselves are sent first, in pieces, to upload.php; this
//  attaches the finished ones (`uploads`) together with the record.
//
//  POST JSON { id?, patientId, clinicName, optometrist?, recordDate?,
//              recordType: exam|prescription|other,
//              recordTypeOther? (required for 'other': "Referral letter"),
//              od: {sph,cyl,axis,add,va}, os: {...}, pd?, diagnosis?,
//              notes?, removeFileIds?: [fileId], uploads?: [uploadId] }
//  → { success:true, record }
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

$b    = getBody();
$text = fn($v, int $max) => mb_substr(trim(is_scalar($v) ? (string)$v : ''), 0, $max);

$recordId  = (int)($b['id'] ?? 0);
$patientId = $text($b['patientId'] ?? '', 10);
$clinic    = $text($b['clinicName'] ?? '', 150);
$optom     = $text($b['optometrist'] ?? '', 150);
$date      = $text($b['recordDate'] ?? '', 10);
$type      = in_array($b['recordType'] ?? '', PREV_RECORD_TYPES, true) ? $b['recordType'] : 'exam';
// "Other" must say what it is, like every "Other (please specify)" choice.
$typeOther = $type === 'other' ? $text($b['recordTypeOther'] ?? '', 60) : '';
$eye = function ($e) use ($text) {
    $e = is_array($e) ? $e : [];
    return [
        'sph'  => $text($e['sph']  ?? '', 10),
        'cyl'  => $text($e['cyl']  ?? '', 10),
        'axis' => $text($e['axis'] ?? '', 10),
        'add'  => $text($e['add']  ?? '', 10),
        'va'   => $text($e['va']   ?? '', 10),
    ];
};
$od    = $eye($b['od'] ?? []);
$os    = $eye($b['os'] ?? []);
$pd    = $text($b['pd'] ?? '', 20);
$dx    = $text($b['diagnosis'] ?? '', 255);
$notes = $text($b['notes'] ?? '', 2000);
$removeIds = array_values(array_filter(array_map('intval', is_array($b['removeFileIds'] ?? null) ? $b['removeFileIds'] : [])));
$uploadIds = array_values(array_unique(array_filter(is_array($b['uploads'] ?? null) ? $b['uploads'] : [],
    fn($u) => is_string($u) && preg_match('/^[a-f0-9]{32}$/', $u))));

// ── Field checks ─────────────────────────────────────────────────
if ($patientId === '') jsonResponse(['success' => false, 'message' => 'Patient is required.']);
if ($clinic === '')    jsonResponse(['success' => false, 'message' => 'Please enter the name of the previous clinic.']);
if ($type === 'other' && $typeOther === '') jsonResponse(['success' => false, 'message' => 'Please specify the document type.']);
if ($date !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) jsonResponse(['success' => false, 'message' => 'Please enter a valid exam date.']);
    if ($date > date('Y-m-d'))               jsonResponse(['success' => false, 'message' => 'The exam date can\'t be in the future.']);
}
// Powers as printed on a prescription: -1.25, +0.50, 0.00, Plano / PL, DS.
$powerOk = fn(string $v) => $v === '' || preg_match('/^(?:[+-]?\d{1,2}(?:\.\d{1,2})?|pl(?:ano)?|ds)$/i', $v);
$axisOk  = fn(string $v) => $v === '' || (ctype_digit($v) && (int)$v <= 180);
foreach ([['Right eye (OD)', $od], ['Left eye (OS)', $os]] as [$label, $e]) {
    foreach (['sph' => 'sphere', 'cyl' => 'cylinder', 'add' => 'ADD'] as $k => $name) {
        if (!$powerOk($e[$k])) jsonResponse(['success' => false, 'message' => "$label $name should look like -1.25, +0.50 or Plano."]);
    }
    if (!$axisOk($e['axis'])) jsonResponse(['success' => false, 'message' => "$label axis should be a whole number from 0 to 180."]);
}
$hasValues = $dx !== '' || $pd !== '' || array_filter($od, fn($v) => $v !== '') || array_filter($os, fn($v) => $v !== '');

// ── Finished uploads to attach (sent earlier to upload.php) ──────
$uid    = (int)$_SESSION['user_id'];
$staged = [];
foreach ($uploadIds as $u) {
    $m = prevRecordStagedMeta($u);
    if (!$m || empty($m['done']) || (int)($m['userId'] ?? 0) !== $uid || !isset(PREV_RECORD_MIME_EXT[$m['mime'] ?? ''])
        || !is_file(prevRecordStagingDir() . "/$u.part")) {
        jsonResponse(['success' => false, 'message' => 'One of the files didn\'t finish uploading. Remove it, attach it again and save.']);
    }
    // upload.php already held each file to its limit; checked again here.
    $max = $m['mime'] === 'application/pdf' ? PREV_RECORD_MAX_BYTES : PREV_RECORD_MAX_IMAGE_BYTES;
    if ((int)$m['size'] > $max) {
        jsonResponse(['success' => false, 'message' => "\"{$m['name']}\" is larger than " . uploadSizeLabel($max) . '. Remove it and attach a smaller copy.']);
    }
    $staged[] = ['id' => $u, 'name' => (string)$m['name'], 'mime' => $m['mime'], 'size' => (int)$m['size']];
}

try {
    $pdo = getDB();
    ensurePreviousRecordTables($pdo);
    $hasArchive = previousRecordArchiveColumn($pdo);   // before the transaction: may add the column

    $pt = $pdo->prepare('SELECT id, first_name, last_name FROM patients WHERE id = ? AND archived_at IS NULL LIMIT 1');
    $pt->execute([$patientId]);
    $pt = $pt->fetch();
    if (!$pt) jsonResponse(['success' => false, 'message' => 'Patient not found.']);
    $ptName = trim($pt['first_name'] . ' ' . $pt['last_name']);

    $keptFiles = 0;
    $keptBytes = 0;
    if ($recordId) {
        $rs = $pdo->prepare('SELECT id' . ($hasArchive ? ', archived_at' : '') . ' FROM previous_clinic_records WHERE id = ? AND patient_id = ? LIMIT 1');
        $rs->execute([$recordId, $patientId]);
        $existing = $rs->fetch();
        if (!$existing) jsonResponse(['success' => false, 'message' => 'That record no longer exists. Refresh the page and try again.']);
        if (!empty($existing['archived_at'])) jsonResponse(['success' => false, 'message' => 'That record was moved to Archives, so it can\'t be edited. An admin can restore it from Settings > Archives.']);
        $fs = $pdo->prepare('SELECT id, size_bytes FROM previous_clinic_files WHERE record_id = ?');
        $fs->execute([$recordId]);
        $sizes       = $fs->fetchAll(PDO::FETCH_KEY_PAIR);   // id => bytes
        $existingIds = array_map('intval', array_keys($sizes));
        $removeIds   = array_values(array_intersect($removeIds, $existingIds));
        $keptFiles   = count($existingIds) - count($removeIds);
        foreach ($sizes as $fid => $bytes) if (!in_array((int)$fid, $removeIds, true)) $keptBytes += (int)$bytes;
    }
    // Only when files are being added, so a record saved under an older,
    // higher limit can still be edited.
    if ($staged && $keptFiles + count($staged) > PREV_RECORD_MAX_FILES) {
        jsonResponse(['success' => false, 'message' => 'A record can have up to ' . PREV_RECORD_MAX_FILES . ' files. Remove some, or add the rest as a second record.']);
    }
    if ($staged && $keptBytes + array_sum(array_column($staged, 'size')) > PREV_RECORD_MAX_TOTAL) {
        jsonResponse(['success' => false, 'message' => 'The files of one record can add up to ' . uploadSizeLabel(PREV_RECORD_MAX_TOTAL) . '. Remove some, or add the rest as a second record.']);
    }
    if ($keptFiles + count($staged) === 0 && !$hasValues) {
        jsonResponse(['success' => false, 'message' => 'Attach the PDF or scanned document, or type in the prescription values.']);
    }

    $actor = sessionActorName($pdo);
    $nz    = fn(string $v) => $v === '' ? null : $v;   // not ?: — an axis of "0" is a real value
    $cols  = [
        'clinic_name' => $clinic, 'optometrist' => $nz($optom), 'record_date' => $nz($date), 'record_type' => $type,
        'od_sph' => $nz($od['sph']), 'od_cyl' => $nz($od['cyl']), 'od_axis' => $nz($od['axis']), 'od_add' => $nz($od['add']), 'od_va' => $nz($od['va']),
        'os_sph' => $nz($os['sph']), 'os_cyl' => $nz($os['cyl']), 'os_axis' => $nz($os['axis']), 'os_add' => $nz($os['add']), 'os_va' => $nz($os['va']),
        'pd' => $nz($pd), 'diagnosis' => $nz($dx), 'notes' => $nz($notes),
    ];
    if (previousRecordExtraColumns($pdo)['record_type_other']) $cols['record_type_other'] = $nz($typeOther);

    $moved = [];          // [staging path, final path] — put back if saving fails
    $removedNames = [];
    $dir = previousRecordsDir($patientId);
    $isNew = !$recordId;
    $pdo->beginTransaction();
    try {
        if ($recordId) {
            $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($cols)));
            $pdo->prepare("UPDATE previous_clinic_records SET $set, updated_by = ?, updated_at = NOW() WHERE id = ?")
                ->execute([...array_values($cols), $actor, $recordId]);
            if ($removeIds) {
                $in = implode(',', array_fill(0, count($removeIds), '?'));
                $st = $pdo->prepare("SELECT stored_name FROM previous_clinic_files WHERE record_id = ? AND id IN ($in)");
                $st->execute([$recordId, ...$removeIds]);
                $removedNames = $st->fetchAll(PDO::FETCH_COLUMN);
                $pdo->prepare("DELETE FROM previous_clinic_files WHERE record_id = ? AND id IN ($in)")->execute([$recordId, ...$removeIds]);
            }
        } else {
            $names = implode(', ', array_map(fn($c) => "`$c`", array_keys($cols)));
            $marks = implode(', ', array_fill(0, count($cols), '?'));
            $pdo->prepare("INSERT INTO previous_clinic_records (patient_id, $names, created_by) VALUES (?, $marks, ?)")
                ->execute([$patientId, ...array_values($cols), $actor]);
            $recordId = (int)$pdo->lastInsertId();
        }

        if ($staged) {
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Could not create the patient\'s upload folder.');
            }
            $ins = $pdo->prepare('INSERT INTO previous_clinic_files (record_id, patient_id, stored_name, original_name, mime_type, size_bytes) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($staged as $f) {
                $stored = $recordId . '-' . bin2hex(random_bytes(12)) . '.' . PREV_RECORD_MIME_EXT[$f['mime']];
                $from   = prevRecordStagingDir() . '/' . $f['id'] . '.part';
                if (!@rename($from, "$dir/$stored")) {
                    throw new RuntimeException('Could not save "' . $f['name'] . '".');
                }
                $moved[] = [$from, "$dir/$stored"];
                $ins->execute([$recordId, $patientId, $stored, $f['name'], $f['mime'], $f['size']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($moved as [$from, $to]) @rename($to, $from);   // keep the uploads for another try
        $msg = $e instanceof RuntimeException ? $e->getMessage() . ' Check the server\'s folder permissions.' : 'Database error. Please try again.';
        jsonResponse(['success' => false, 'message' => $msg], 500);
    }

    foreach ($staged as $f) prevRecordClearStaged($f['id']);   // its .part already moved; drops the .json
    foreach ($removedNames as $n) @unlink($dir . '/' . basename($n));

    $fileNote = $staged ? ' (' . count($staged) . ' file' . (count($staged) === 1 ? '' : 's') . ' uploaded)' : '';
    logActivity($pdo, ($isNew ? 'Added a previous clinic record from ' : 'Updated the previous clinic record from ')
        . "$clinic for $ptName ($patientId)$fileNote", 'patient');

    $record = null;
    foreach (previousRecordsByPatient($pdo, $patientId)[$patientId] ?? [] as $r) {
        if ($r['id'] === $recordId) { $record = $r; break; }
    }
    jsonResponse(['success' => true, 'record' => $record]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
