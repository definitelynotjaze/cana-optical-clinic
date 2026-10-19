<?php
// ================================================================
//  CANAOPTICALCLINIC — api/backup/upload.php
//  Admin only. Receives a backup .zip for Restore in pieces (see
//  _lib.php, "Restore uploads"): a big backup uploads even when the
//  server's PHP upload limit is low, the browser can show progress, and
//  a dropped connection only re-sends one piece. inspect.php and
//  import.php then read the finished file by its uploadId.
//
//  POST multipart/form-data
//    action=start   name, size            → { success, uploadId, chunkSize, total }
//    (a piece)      uploadId, index, chunk → { success, next, done }
//                   piece out of turn:       { success:false, code:'order', next }
//    action=discard uploadId              → { success }
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';
require_once '_lib.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    jsonResponse(['success' => false, 'message' => 'Only admins can restore a system backup.'], 403);
}
$uid = (int)$_SESSION['user_id'];
session_write_close();   // nothing below writes to the session

$dir    = backupIncomingDir();
$action = (string)($_POST['action'] ?? '');

if ($action === 'start') {
    rateLimit('backup-upload:' . $uid, 20, 3600);
    $size = (int)($_POST['size'] ?? 0);
    $name = basename(str_replace('\\', '/', (string)($_POST['name'] ?? '')));
    if (!preg_match('/\.zip$/i', $name)) {
        jsonResponse(['success' => false, 'message' => 'Please choose a .zip backup file.']);
    }
    if ($size < 22) {
        jsonResponse(['success' => false, 'message' => 'That file is empty, so it can\'t be a backup.']);
    }
    if ($size > UPLOAD_MAX_BACKUP) {
        jsonResponse(['success' => false, 'message' => 'That backup file is too large. The limit is ' . uploadSizeLabel(UPLOAD_MAX_BACKUP) . '.']);
    }
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        jsonResponse(['success' => false, 'message' => 'Could not create the upload folder. Check the server\'s folder permissions.'], 500);
    }
    backupCleanIncoming();
    // One restore upload per admin: an older one (another tab, a file
    // picked before this one) is dropped.
    foreach (glob("$dir/*.json") ?: [] as $mf) {
        $m = json_decode((string)@file_get_contents($mf), true);
        if ((int)($m['userId'] ?? 0) === $uid) backupDiscardUpload(basename($mf, '.json'));
    }
    // Room for the upload plus the safety copy a restore writes first.
    $free = @disk_free_space($dir);
    if ($free !== false && $free < $size * 2 + 50 * 1048576) {
        jsonResponse(['success' => false, 'message' => 'The server doesn\'t have enough free disk space to receive this backup.']);
    }
    $id    = bin2hex(random_bytes(16));
    $chunk = backupChunkBytes();
    $meta  = ['userId' => $uid, 'name' => $name, 'size' => $size, 'chunk' => $chunk,
              'total' => (int)ceil($size / $chunk), 'next' => 0, 'done' => false, 'created' => time()];
    if (!backupSaveUploadMeta($id, $meta)) {
        jsonResponse(['success' => false, 'message' => 'Could not start the upload. Check the server\'s folder permissions.'], 500);
    }
    jsonResponse(['success' => true, 'uploadId' => $id, 'chunkSize' => $chunk, 'total' => $meta['total']]);
}

$id   = (string)($_POST['uploadId'] ?? '');
$meta = backupUploadMeta($id);
$mine = $meta && (int)$meta['userId'] === $uid;

if ($action === 'discard') {
    if ($mine) backupDiscardUpload($id);
    jsonResponse(['success' => true]);
}

// ── A piece ──────────────────────────────────────────────────────
if (!$mine) {
    jsonResponse(['success' => false, 'code' => 'expired', 'message' => 'The upload expired. Please choose the backup file again.']);
}
$index = (int)($_POST['index'] ?? -1);
$total = (int)$meta['total'];
$size  = (int)$meta['size'];
$chunk = (int)$meta['chunk'];
if (!empty($meta['done'])) {
    jsonResponse(['success' => true, 'next' => $total, 'done' => true]);
}
if ($index !== (int)$meta['next']) {
    // E.g. a piece the browser re-sent after a timeout had in fact arrived.
    jsonResponse(['success' => false, 'code' => 'order', 'next' => (int)$meta['next']]);
}

$piece = $_FILES['chunk'] ?? null;
$err   = $piece['error'] ?? UPLOAD_ERR_NO_FILE;
if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
    backupDiscardUpload($id);
    jsonResponse(['success' => false, 'message' => 'This server only accepts uploads under ' . uploadSizeLabel(serverUploadLimit()) . '. Raise upload_max_filesize in the hosting panel\'s PHP settings.']);
}
if (!$piece || $err !== UPLOAD_ERR_OK || !is_uploaded_file($piece['tmp_name'])) {
    // Retryable on the browser's side (it re-sends this piece).
    jsonResponse(['success' => false, 'message' => 'Part of the backup didn\'t arrive.'], 500);
}
$expected = $index < $total - 1 ? $chunk : $size - $chunk * ($total - 1);
if ((int)filesize($piece['tmp_name']) !== $expected) {
    jsonResponse(['success' => false, 'message' => 'Part of the backup arrived incomplete.'], 500);
}
// The first bytes already show whether this is a zip at all.
if ($index === 0 && !in_array((string)file_get_contents($piece['tmp_name'], false, null, 0, 4), ["PK\x03\x04", "PK\x05\x06"], true)) {
    backupDiscardUpload($id);
    jsonResponse(['success' => false, 'message' => 'That file isn\'t a backup .zip file.']);
}

$zipPath = backupUploadPath($id);
$data    = (string)file_get_contents($piece['tmp_name']);
if (file_put_contents($zipPath, $data, FILE_APPEND | LOCK_EX) !== strlen($data)) {
    jsonResponse(['success' => false, 'message' => 'Could not save part of the backup. Check the server\'s free disk space.'], 500);
}
clearstatcache(true, $zipPath);
if ((int)filesize($zipPath) !== min(($index + 1) * $chunk, $size)) {
    backupDiscardUpload($id);
    jsonResponse(['success' => false, 'message' => 'The backup got mixed up while uploading. Please choose it again.']);
}

$meta['next'] = $index + 1;
$meta['done'] = $meta['next'] === $total;
backupSaveUploadMeta($id, $meta);

jsonResponse(['success' => true, 'next' => $meta['next'], 'done' => $meta['done']]);
