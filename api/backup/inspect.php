<?php
// ================================================================
//  CANAOPTICALCLINIC — api/backup/inspect.php
//  POST multipart/form-data { backup: File } — Admin only.
//  Read-only preview/validation step for the Restore flow: unpacks the
//  uploaded .zip just far enough to check it's actually a backup this
//  app made (manifest present, checksums match) and returns a summary
//  for the confirmation screen. Never touches the database — the real
//  restore only happens in import.php, and only after the admin
//  confirms what this endpoint shows them.
//  → { success:true, manifest:{...} }
//  → { success:false, message }
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

rateLimit('backup-inspect:' . $_SESSION['user_id'], 15, 3600);

if (empty($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['success' => false, 'message' => 'No backup file was uploaded.']);
}
if (!class_exists('ZipArchive')) {
    jsonResponse(['success' => false, 'message' => 'The server\'s PHP zip extension is not available, so a backup file can\'t be read.'], 500);
}

$zip = new ZipArchive();
if ($zip->open($_FILES['backup']['tmp_name']) !== true) {
    jsonResponse(['success' => false, 'message' => 'That file isn\'t a valid .zip archive.']);
}

$manifestRaw = $zip->getFromName('manifest.json');
$sqlRaw      = $zip->getFromName('database.sql');
$jsonRaw     = $zip->getFromName('database.json');
$zip->close();

if ($manifestRaw === false || $jsonRaw === false) {
    jsonResponse(['success' => false, 'message' => 'This doesn\'t look like a Cana Optical Clinic backup: manifest.json or database.json is missing from the archive.']);
}

$manifest = json_decode($manifestRaw, true);
if (!is_array($manifest) || ($manifest['app'] ?? '') !== 'Cana Optical Clinic') {
    jsonResponse(['success' => false, 'message' => 'This doesn\'t look like a Cana Optical Clinic backup file.']);
}
if ((int)($manifest['formatVersion'] ?? 0) > BACKUP_FORMAT_VERSION) {
    jsonResponse(['success' => false, 'message' => 'This backup was made by a newer version of the system than this one supports. Update the app before restoring it.']);
}

$warnings = [];
$expectedJsonHash = $manifest['checksums']['databaseJson'] ?? null;
if ($expectedJsonHash && hash('sha256', $jsonRaw) !== $expectedJsonHash) {
    jsonResponse(['success' => false, 'message' => 'This backup file failed its integrity check (checksum mismatch): it may be corrupted or was edited after export. Restoring it has been blocked.']);
}
if ($sqlRaw === false) {
    $warnings[] = 'The portable database.sql file is missing from this archive. The app-level restore can still proceed from database.json, but this backup can\'t be used with an external mysql/phpMyAdmin import.';
} elseif (($manifest['checksums']['databaseSql'] ?? null) && hash('sha256', $sqlRaw) !== $manifest['checksums']['databaseSql']) {
    $warnings[] = 'database.sql failed its integrity check. The portable SQL copy may be corrupted, though the app-level restore (database.json) checked out fine and can still proceed.';
}

jsonResponse([
    'success'  => true,
    'manifest' => [
        'createdAt'      => $manifest['createdAt']      ?? null,
        'createdBy'      => $manifest['createdBy']      ?? null,
        'formatVersion'  => $manifest['formatVersion']  ?? null,
        'tables'         => $manifest['tables']          ?? [],
        'excludedTables' => $manifest['excludedTables']  ?? [],
        'fileCount'      => $manifest['fileCount']       ?? 0,
        'totalRecords'   => array_sum($manifest['tables'] ?? []),
    ],
    'warnings' => $warnings,
]);
