<?php
// ================================================================
//  CANAOPTICALCLINIC — api/backup/export.php
//  GET — Admin only. Builds and streams a full system backup .zip:
//  database (SQL + JSON), uploaded files, and a manifest. See _lib.php
//  for the exact format and what's intentionally left out.
//
//  On success: streams the .zip directly (Content-Type: application/zip)
//  and exits — no JSON envelope, since the browser is meant to save this
//  as a file. On failure: a normal { success:false, message } JSON body,
//  same as every other endpoint, so the frontend can tell the two apart
//  by Content-Type before deciding whether to trigger a file download.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';
require_once '_lib.php';

requireMethod('GET');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    jsonResponse(['success' => false, 'message' => 'Only admins can export a system backup.'], 403);
}

// Heavy, sensitive operation (the file contains every patient's full
// record) — scoped per admin account, generous enough for genuine
// back-to-back retries but not for scripted hammering.
rateLimit('backup-export:' . $_SESSION['user_id'], 5, 3600);

if (!class_exists('ZipArchive')) {
    jsonResponse(['success' => false, 'message' => 'The server\'s PHP zip extension is not available, so a backup file can\'t be built.'], 500);
}

try {
    $pdo = getDB();

    $adminRow = $pdo->prepare('SELECT first_name, last_name FROM admins WHERE user_id = ? LIMIT 1');
    $adminRow->execute([$_SESSION['user_id']]);
    $adminName = $adminRow->fetch();
    $adminDisplayName = $adminName ? trim($adminName['first_name'] . ' ' . $adminName['last_name']) : 'Administrator';

    $dump = backupBuildDump($pdo);

    // The hero video (assets/videos/) is opt-in, off by default — it's
    // large marketing content (this clinic's currently sits at ~150MB),
    // not clinical/patient data, and easily re-uploaded on its own via
    // Clinic Information > Upload Video if it's ever actually needed
    // again. Bundling it into every routine backup would make the
    // normal download/restore round-trip (and the two-step Restore
    // upload in particular, which re-sends the same file twice) far
    // heavier than the data that actually matters, and risks tripping
    // PHP's own upload size limits on the restore side for no benefit.
    // Every other upload directory (logo, hero image, gallery, patient/
    // user photos) is small and always included.
    $includeVideos = ($_GET['video'] ?? '') === '1';
    $fileDirs = backupFileDirs();
    if (!$includeVideos) unset($fileDirs['files/videos']);
    $fileInventory = [];
    foreach ($fileDirs as $zipPrefix => $realDir) {
        foreach (backupListFiles($realDir) as $path) {
            $rel = $zipPrefix . '/' . ltrim(str_replace('\\', '/', substr($path, strlen($realDir))), '/');
            $fileInventory[$rel] = $path;
        }
    }

    // Encoded ONCE, reused for both the checksum below and the actual
    // zip entry written further down — computing the checksum from a
    // separately-called json_encode() (even on the exact same data) is
    // not guaranteed to produce byte-identical output if the flags ever
    // drift between the two call sites, which would make every restore
    // fail its own integrity check against a perfectly valid backup.
    $databaseJsonEncoded = json_encode($dump['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $manifest = [
        'app'            => 'Cana Optical Clinic',
        'formatVersion'  => BACKUP_FORMAT_VERSION,
        'createdAt'      => date('c'),
        'createdBy'      => [
            'name' => $adminDisplayName,
            'role' => $_SESSION['role'] ?? 'admin',
        ],
        'tables'         => $dump['counts'],
        'excludedTables' => BACKUP_EXCLUDED_TABLES,
        'fileCount'      => count($fileInventory),
        'videosIncluded' => $includeVideos,
        'checksums'      => [
            'databaseSql'  => hash('sha256', $dump['sql']),
            'databaseJson' => hash('sha256', $databaseJsonEncoded),
        ],
    ];

    $tmpZip = tempnam(sys_get_temp_dir(), 'coc_backup_');
    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        jsonResponse(['success' => false, 'message' => 'Could not create the backup archive.'], 500);
    }

    $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->addFromString('database.sql', $dump['sql']);
    $zip->addFromString('database.json', $databaseJsonEncoded);
    foreach ($fileInventory as $rel => $realPath) {
        $zip->addFile($realPath, $rel);
    }
    $zip->close();

    $stamp = date('Y-m-d_His');
    $filename = "CanaOpticalClinic-Backup-{$stamp}.zip";

    // Best-effort audit trail — this file contains every patient's full
    // record, so who exported one and when is itself worth keeping, same
    // as every other settings-level action in this app.
    try {
        $pdo->prepare(
            'INSERT INTO activity_log (id, users_id, user_name, role, action, timestamp, type, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'L' . (int)round(microtime(true) * 1000),
            $_SESSION['user_id'],
            $manifest['createdBy']['name'],
            $manifest['createdBy']['role'],
            'Downloaded a full system backup (' . array_sum($dump['counts']) . ' records, ' . count($fileInventory) . ' files)',
            date('Y-m-d H:i:s'),
            'settings',
            clientIp(),
        ]);
    } catch (PDOException $e) {
        // Never let audit logging block the actual backup download.
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpZip));
    header('X-Content-Type-Options: nosniff');
    readfile($tmpZip);
    unlink($tmpZip);
    exit;

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error while building the backup.'], 500);
}
