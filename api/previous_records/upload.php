<?php
// ================================================================
//  CANAOPTICALCLINIC — api/previous_records/upload.php
//  Admin, staff and doctors. Receives one Previous Clinic Record file
//  (PDF up to 20 MB, JPG / PNG up to 10 MB) in 2 MB pieces, one after another.
//  Pieces build up in storage/upload_staging/ until save.php attaches the
//  finished file to a record (or a day passes and it's cleaned up).
//  Small pieces mean a big scan uploads even when the server's PHP upload
//  limit is low, and a dropped connection only re-sends one piece.
//
//  POST multipart/form-data
//    uploadId  32 hex chars, made by the browser for this file
//    index     0-based piece number   total  number of pieces
//    size      whole file size (bytes) name   file name
//    chunk     the piece
//  → { success:true, next, done, name, mime, size }
//    { success:false, code:'order', next }  piece out of turn: send `next`
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
$uid = (int)$_SESSION['user_id'];
session_write_close();   // nothing below writes to the session

$uploadId = (string)($_POST['uploadId'] ?? '');
$index    = (int)($_POST['index'] ?? -1);
$total    = (int)($_POST['total'] ?? 0);
$size     = (int)($_POST['size']  ?? 0);
$clean    = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', (string)($_POST['name'] ?? ''))));
$name     = mb_substr($clean ?? '', 0, 255) ?: 'document';

if (!preg_match('/^[a-f0-9]{32}$/', $uploadId) || $index < 0 || $total < 1 || $size < 1
    || $total !== (int)ceil($size / PREV_RECORD_CHUNK_BYTES) || $index >= $total) {
    jsonResponse(['success' => false, 'message' => 'Invalid upload. Please attach the file again.'], 400);
}
if ($size > PREV_RECORD_MAX_BYTES) {
    jsonResponse(['success' => false, 'message' => "\"$name\" is larger than " . uploadSizeLabel(PREV_RECORD_MAX_BYTES) . '.']);
}

$piece = $_FILES['chunk'] ?? null;
$err   = $piece['error'] ?? UPLOAD_ERR_NO_FILE;
if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
    jsonResponse(['success' => false, 'message' => 'This server only accepts uploads under 2 MB. Raise upload_max_filesize in the hosting panel\'s PHP settings.']);
}
if (!$piece || $err !== UPLOAD_ERR_OK || !is_uploaded_file($piece['tmp_name'])) {
    // Retryable on the browser's side (it re-sends this piece).
    jsonResponse(['success' => false, 'message' => "Part of \"$name\" didn't arrive."], 500);
}
$expected = $index < $total - 1 ? PREV_RECORD_CHUNK_BYTES : $size - PREV_RECORD_CHUNK_BYTES * ($total - 1);
if ((int)filesize($piece['tmp_name']) !== $expected) {
    jsonResponse(['success' => false, 'message' => "Part of \"$name\" arrived incomplete."], 500);
}

$dir = prevRecordStagingDir();
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    jsonResponse(['success' => false, 'message' => 'Could not create the upload folder. Check the server\'s folder permissions.'], 500);
}
$part     = "$dir/$uploadId.part";
$metaFile = "$dir/$uploadId.json";
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$meta     = prevRecordStagedMeta($uploadId);

if (!$meta) {
    if ($index !== 0) {
        jsonResponse(['success' => false, 'message' => "The upload of \"$name\" expired. Please attach it again."]);
    }
    prevRecordCleanStaging();
    // The first piece already shows what the file really is: stop a wrong
    // file here instead of after uploading all of it.
    $firstMime = $finfo->file($piece['tmp_name']) ?: '';
    if (!isset(PREV_RECORD_MIME_EXT[$firstMime])) {
        jsonResponse(['success' => false, 'message' => "\"$name\" isn't a PDF, JPG or PNG file."]);
    }
    // Photos have the lower limit (the browser shrinks them first).
    if ($firstMime !== 'application/pdf' && $size > PREV_RECORD_MAX_IMAGE_BYTES) {
        jsonResponse(['success' => false, 'message' => "\"$name\" is larger than " . uploadSizeLabel(PREV_RECORD_MAX_IMAGE_BYTES) . '. Take the photo a little farther away, or scan the page as a PDF.']);
    }
    $mine = 0;
    foreach (glob("$dir/*.json") ?: [] as $mf) {
        $mm = json_decode((string)@file_get_contents($mf), true);
        if ((int)($mm['userId'] ?? 0) === $uid && empty($mm['done'])) $mine++;
    }
    if ($mine >= 30) {
        jsonResponse(['success' => false, 'message' => 'Too many unfinished uploads. Save or close the open record form first.']);
    }
    $meta = ['userId' => $uid, 'name' => $name, 'size' => $size, 'total' => $total, 'next' => 0, 'done' => false, 'created' => time()];
    @unlink($part);
}

if ((int)$meta['userId'] !== $uid || (int)$meta['size'] !== $size || (int)$meta['total'] !== $total) {
    jsonResponse(['success' => false, 'message' => "The upload of \"$name\" doesn't match. Please attach it again."]);
}
if (!empty($meta['done'])) {
    jsonResponse(['success' => true, 'next' => $total, 'done' => true, 'name' => $meta['name'], 'mime' => $meta['mime'] ?? '', 'size' => $size]);
}
if ($index !== (int)$meta['next']) {
    // E.g. a piece the browser re-sent after a timeout had in fact arrived.
    jsonResponse(['success' => false, 'code' => 'order', 'next' => (int)$meta['next']]);
}

$data = (string)file_get_contents($piece['tmp_name']);
if (file_put_contents($part, $data, FILE_APPEND | LOCK_EX) !== strlen($data)) {
    jsonResponse(['success' => false, 'message' => "Could not save part of \"$name\"."], 500);
}
clearstatcache(true, $part);
if ((int)filesize($part) !== min(($index + 1) * PREV_RECORD_CHUNK_BYTES, $size)) {
    prevRecordClearStaged($uploadId);
    jsonResponse(['success' => false, 'message' => "\"$name\" got mixed up while uploading. Please attach it again."]);
}

$meta['next'] = $index + 1;
if ($meta['next'] === $total) {
    $mime = $finfo->file($part) ?: '';
    if (!isset(PREV_RECORD_MIME_EXT[$mime])) {
        prevRecordClearStaged($uploadId);
        jsonResponse(['success' => false, 'message' => "\"$name\" isn't a PDF, JPG or PNG file."]);
    }
    $meta['done'] = true;
    $meta['mime'] = $mime;
}
file_put_contents($metaFile, json_encode($meta), LOCK_EX);

jsonResponse(['success' => true, 'next' => $meta['next'], 'done' => $meta['done'], 'name' => $meta['name'], 'mime' => $meta['mime'] ?? '', 'size' => $size]);
