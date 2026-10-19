<?php
// ================================================================
//  CANAOPTICALCLINIC — api/backup/import.php
//  POST multipart/form-data { uploadId (a finished upload.php upload) or
//  backup: File, password, confirmPhrase } —
//  Admin only. The actual Restore: wipes and repopulates every table
//  covered by the uploaded backup, restores uploaded files, forces a
//  global logout, and logs the event. See _lib.php for the exact
//  format and the reasoning behind each safeguard below.
//
//  Safeguards, in order: admin role, rate limit, the admin's own
//  current password re-entered (not just their already-open session),
//  a typed "RESTORE" confirmation phrase, backup file/checksum
//  validation (inspect.php's checks, re-run independently — this
//  endpoint never trusts a prior inspect call), an automatic
//  server-side safety backup of the CURRENT data taken immediately
//  before anything is touched, and a same-shaped rollback attempt from
//  that safety backup if the restore itself fails partway.
//
//  → { success:true, summary:{...} }
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
$uid = (int)$_SESSION['user_id'];
// Saved now, never again: written back at the end of the request, this
// session would reappear right after the restore signs everyone out.
session_write_close();

// Deliberately tight — this is the single most destructive action in
// the app (it replaces the entire database), so a handful of attempts
// per hour is plenty for genuine use and still stops a stolen/left-open
// admin session from being used to hammer it.
rateLimit('backup-import:' . $uid, 3, 3600);

// The file first: one PHP refuses as too big also empties the fields
// below, which would otherwise read as "type RESTORE".
$backupPath = backupRequestFile($uid);

$password      = (string)($_POST['password'] ?? '');
$confirmPhrase = trim((string)($_POST['confirmPhrase'] ?? ''));

if ($confirmPhrase !== 'RESTORE') {
    jsonResponse(['success' => false, 'message' => 'Please type RESTORE to confirm. This replaces all current data.']);
}

try {
    $pdo = getDB();

    // Re-verify the acting admin's CURRENT password — separate from (and
    // in addition to) their already-open session, since a session alone
    // just proves "someone is logged in on this browser right now", not
    // "the person at this keyboard is deliberately choosing to wipe the
    // live database". Also captured here, before anything downstream can
    // possibly change it: the admin's own display name, so the final log
    // entry (written AFTER the users/admins tables get replaced) still
    // correctly credits the person who ran the restore even if the
    // restored data no longer contains their account at all.
    $adminStmt = $pdo->prepare(
        'SELECT a.first_name, a.last_name, u.password_hash
           FROM admins a JOIN users u ON u.id = a.user_id
          WHERE a.user_id = ? LIMIT 1'
    );
    $adminStmt->execute([$uid]);
    $adminRow = $adminStmt->fetch();
    if (!$adminRow || !password_verify($password, $adminRow['password_hash'])) {
        jsonResponse(['success' => false, 'message' => 'Incorrect password.']);
    }
    $actingAdminName = trim($adminRow['first_name'] . ' ' . $adminRow['last_name']) ?: 'Administrator';

    // ── Validate the uploaded backup (same checks as inspect.php,
    //    independently re-run — this endpoint never assumes a prior
    //    inspect() call on the same file actually happened) ──────────
    $zip = backupZipOpen($backupPath);
    if (!$zip) {
        jsonResponse(['success' => false, 'message' => 'That file isn\'t a valid .zip archive.']);
    }
    $manifestRaw = $zip->getFromName('manifest.json');
    $jsonRaw     = $zip->getFromName('database.json');
    if ($manifestRaw === false || $jsonRaw === false) {
        $zip->close();
        jsonResponse(['success' => false, 'message' => 'This doesn\'t look like a Cana Optical Clinic backup: manifest.json or database.json is missing.']);
    }
    $manifest = json_decode($manifestRaw, true);
    if (!is_array($manifest) || ($manifest['app'] ?? '') !== 'Cana Optical Clinic') {
        $zip->close();
        jsonResponse(['success' => false, 'message' => 'This doesn\'t look like a Cana Optical Clinic backup file.']);
    }
    if ((int)($manifest['formatVersion'] ?? 0) > BACKUP_FORMAT_VERSION) {
        $zip->close();
        jsonResponse(['success' => false, 'message' => 'This backup was made by a newer version of the system than this one supports.']);
    }
    if (($manifest['checksums']['databaseJson'] ?? null) && hash('sha256', $jsonRaw) !== $manifest['checksums']['databaseJson']) {
        $zip->close();
        jsonResponse(['success' => false, 'message' => 'This backup file failed its integrity check: it may be corrupted or was edited after export. Restore has been blocked.']);
    }

    $backupData = json_decode($jsonRaw, true);
    if (!is_array($backupData)) {
        $zip->close();
        jsonResponse(['success' => false, 'message' => 'database.json inside the backup is not readable.']);
    }

    // Only tables that are ALL of: present in the backup, part of the
    // current schema, and actually allowed to be restored at all — never
    // trust arbitrary table names out of the uploaded file, never blank
    // out a table the backup has nothing to say about (e.g. one added by
    // an app version newer than the backup), and never let a restore
    // silently erase this system's own live audit trail with an older
    // one (activity_log/qr_scan_log — see BACKUP_NON_RESTORABLE_TABLES).
    // Tables this app creates on first use are made first (when the backup
    // has them), so they're part of "the current schema" here.
    backupPrepareSchema($pdo, array_keys($backupData));
    $currentTables = backupRestorableTables($pdo);
    $restoreTables = array_values(array_intersect(array_keys($backupData), $currentTables));

    // ── Automatic pre-restore safety backup — taken BEFORE anything is
    //    touched. If this step itself fails, abort rather than proceed
    //    without a way back. Database only (not uploaded files — the
    //    point of this copy is a fast DB rollback if the restore below
    //    fails partway, not a full archival backup; the admin's own
    //    downloaded backups remain the real disaster-recovery copies). ──
    $safetyDump = backupBuildDump($pdo);
    $safetyDir  = __DIR__ . '/../../storage/backups';
    if (!is_dir($safetyDir) && !@mkdir($safetyDir, 0755, true)) {
        $zip->close();
        jsonResponse(['success' => false, 'message' => 'Could not create a pre-restore safety backup, so the restore was not started.'], 500);
    }
    $safetyName = 'pre-restore-' . date('Y-m-d_His') . '.zip';
    $safetyPath = $safetyDir . '/' . $safetyName;
    $safetyZip  = backupZipCreate($safetyPath);
    $safetyOk   = $safetyZip
        && $safetyZip->addFromString('manifest.json', json_encode([
            'app' => 'Cana Optical Clinic', 'formatVersion' => BACKUP_FORMAT_VERSION,
            'createdAt' => date('c'), 'reason' => 'automatic-pre-restore-safety-copy',
        ], JSON_PRETTY_PRINT))
        && $safetyZip->addFromString('database.sql', $safetyDump['sql'])
        && $safetyZip->addFromString('database.json', json_encode($safetyDump['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (!$safetyZip || !$safetyZip->close() || !$safetyOk) {
        @unlink($safetyPath);
        $zip->close();
        jsonResponse(['success' => false, 'message' => 'Could not write a pre-restore safety backup, so the restore was not started. Check the server\'s free disk space.'], 500);
    }

    // Keep only the last 3 safety copies — this is a short-lived local
    // net for THIS operation, not long-term storage (and on Railway,
    // ephemeral anyway past the next deploy).
    $existing = glob($safetyDir . '/pre-restore-*.zip') ?: [];
    rsort($existing);
    foreach (array_slice($existing, 3) as $old) { @unlink($old); }

    // ── The restore itself ───────────────────────────────────────────
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        $restoredCounts = backupApplyJsonToDb($pdo, $restoreTables, $backupData, $skippedColumns);
    } catch (RuntimeException $e) {
        // Attempt a same-shaped rollback from the safety copy just taken,
        // restricted to whichever tables the failed restore may have
        // already partially touched.
        $rolledBack = false;
        try {
            backupApplyJsonToDb($pdo, $restoreTables, $safetyDump['json']);
            $rolledBack = true;
        } catch (RuntimeException $e2) {
            // Fall through — surfaced in the response either way below.
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $zip->close();
        jsonResponse(['success' => false, 'message' =>
            'Restore failed (' . $e->getMessage() . '). ' .
            ($rolledBack
                ? 'The database has been rolled back to its state immediately before this attempt. Nothing was lost.'
                : 'Automatic rollback also failed. The database may be in a partial state. A safety copy of the pre-restore data was saved server-side as ' . $safetyName . '.')
        ], 500);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    // ── Restore uploaded files (logo, gallery, hero, profile photos) ──
    $fileResult = backupRestoreFiles($zip);
    $zip->close();
    // The uploaded copy has served its purpose (it holds every patient's
    // record, so it doesn't linger on the server).
    backupDiscardUpload((string)($_POST['uploadId'] ?? ''));

    // Every session — including the one making this request — just had
    // its underlying users/passwords data replaced wholesale. Nobody's
    // login is trustworthy against the new data until they sign in
    // again against it.
    $pdo->exec('TRUNCATE TABLE sessions');

    // Logged AFTER the restore, against the now-restored activity_log
    // table, using the name captured at the very start of this request
    // (the restored data may not even contain this admin's account).
    try {
        // manifest.createdAt is a machine-readable ISO 8601 string
        // (date('c'), export.php) — fine for the JSON response, but
        // reads like a bug dropped raw into a plain-English sentence
        // here. A backup that somehow shipped a malformed/missing
        // createdAt still logs something sensible rather than a PHP
        // warning or blank gap.
        $backupDateLabel = 'an unknown date';
        if (!empty($manifest['createdAt'])) {
            $ts = strtotime($manifest['createdAt']);
            if ($ts !== false) $backupDateLabel = date('F j, Y, g:i A', $ts);
        }

        $pdo->prepare(
            'INSERT INTO activity_log (id, users_id, user_name, role, action, timestamp, type, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'L' . (int)round(microtime(true) * 1000),
            null, // the restored users table may not contain this id anymore — avoid a dangling FK
            $actingAdminName,
            'admin',
            'Restored the system from a backup dated ' . $backupDateLabel
                . ', originally created by ' . ($manifest['createdBy']['name'] ?? 'an admin')
                . ' (' . array_sum($restoredCounts) . ' records, ' . $fileResult['restored'] . ' files restored'
                . ($fileResult['failed'] ? ', ' . $fileResult['failed'] . ' files failed' : '') . ')',
            date('Y-m-d H:i:s'),
            'settings',
            clientIp(),
        ]);
    } catch (PDOException $e) {
        // Never let logging block the response — the restore itself already succeeded.
    }

    jsonResponse([
        'success' => true,
        'summary' => [
            'tables'         => $restoredCounts,
            'records'        => array_sum($restoredCounts),
            'filesRestored'  => $fileResult['restored'],
            'filesFailed'    => $fileResult['failed'],
            'skippedColumns' => $skippedColumns ?? [],
            'safetyBackup'   => $safetyName,
            'backupDate'     => $manifest['createdAt'] ?? null,
        ],
    ]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error during restore.'], 500);
}
