<?php
// ================================================================
//  CANAOPTICALCLINIC — api/backup/status.php
//  GET — Admin only. When the system was last backed up and restored
//  (from the Activity Log entries export.php and import.php write), and
//  how much the optional homepage video adds, for the Backup & Restore page.
//  → { success, lastBackup:{at, by, records, files}|null, lastRestore:{at, by}|null, videoBytes }
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
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
}
session_write_close();

try {
    $pdo  = getDB();
    $last = function (string $prefix) use ($pdo): ?array {
        $s = $pdo->prepare("SELECT user_name, action, timestamp FROM activity_log
                             WHERE type = 'settings' AND action LIKE ? ORDER BY timestamp DESC LIMIT 1");
        $s->execute([$prefix . '%']);
        $r = $s->fetch();
        return $r ? ['at' => $r['timestamp'], 'by' => $r['user_name'] ?? '', 'action' => $r['action']] : null;
    };

    $backup = $last('Downloaded a full system backup');
    if ($backup) {
        // "… (1240 records, 38 files)"
        preg_match('/\((\d+) records, (\d+) files\)/', $backup['action'], $m);
        $backup['records'] = isset($m[1]) ? (int)$m[1] : null;
        $backup['files']   = isset($m[2]) ? (int)$m[2] : null;
        unset($backup['action']);
    }
    $restore = $last('Restored the system from a backup');
    if ($restore) unset($restore['action']);

    $videoBytes = 0;
    foreach (backupListFiles(backupFileDirs()['files/videos']) as $f) $videoBytes += (int)@filesize($f);

    jsonResponse(['success' => true, 'lastBackup' => $backup, 'lastRestore' => $restore, 'videoBytes' => $videoBytes]);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Could not read the backup history.'], 500);
}
