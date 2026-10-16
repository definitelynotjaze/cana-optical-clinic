<?php
// ================================================================
//  CANAOPTICALCLINIC — api/backup/_lib.php
//  Shared helpers for the whole-system Backup & Restore feature
//  (export.php, inspect.php, import.php). Not web-accessible itself —
//  every caller requires it, none of them route to it directly.
//
//  BACKUP FORMAT (a single .zip):
//    manifest.json  — format/schema version, timestamps, table row
//                      counts, file inventory, SHA-256 checksums of the
//                      two data files below (tamper/corruption check).
//    database.sql   — a standard, human-readable SQL dump (CREATE TABLE
//                      + INSERT statements). This is the portable,
//                      industry-standard piece: if this app is ever
//                      completely down, an admin with raw DB access can
//                      restore from this file alone via the mysql CLI
//                      or phpMyAdmin, with no dependency on this app's
//                      own restore code working.
//    database.json  — the SAME data as database.sql, but structured
//                      (table => array of rows) instead of raw SQL text.
//                      This is what THIS app's own import.php actually
//                      restores from — reading JSON and inserting rows
//                      through ordinary PDO prepared statements (the
//                      same pattern every other endpoint in this app
//                      already uses) is unambiguous and injection-safe,
//                      where re-parsing arbitrary SQL text back apart
//                      into statements would not be.
//    files/…        — a mirror of every upload directory (logo, hero
//                      image/video, about-page gallery, profile photos),
//                      at the same relative path they live at under
//                      assets/, so restore can extract them straight
//                      back into place.
// ================================================================

const BACKUP_FORMAT_VERSION = 1;

// Tables intentionally left OUT of the backup — every one of these is
// transient, short-lived security/session state that's meaningless (or
// actively wrong) to replay onto another system or a later point in
// time: active login sessions, in-flight OTP/reset tokens, unfinished
// self-registrations, and the IP+endpoint throttle counters. None of
// this is "clinic data" — restoring old rows here would just mean
// stale session/OTP rows a real login would immediately re-create
// correctly anyway. Everything else in the database (SHOW TABLES,
// introspected fresh every time — never hand-maintained) is included,
// so a newly added table is backed up automatically without this file
// needing an update.
const BACKUP_EXCLUDED_TABLES = [
    'sessions', 'password_resets', 'pending_registrations',
    'email_changes', 'rate_limits', 'login_device_otps',
];

// Backed up (they're real, meaningful history worth having a copy of),
// but never RESTORED — see backupRestorableTables() below. Wholesale
// replacing an audit trail with an older snapshot of itself silently
// erases everything genuinely recorded since that snapshot, which
// defeats the entire point of an audit log (found the hard way: a
// "Downloaded a full system backup" entry existed, then a later Restore
// truncated `activity_log` back to a backup taken before that download,
// and the download's own record was just gone — nothing wrong had
// happened, the restore just did exactly what a wholesale restore does).
const BACKUP_NON_RESTORABLE_TABLES = ['activity_log', 'qr_scan_log'];

// The subset of backupIncludedTables() that a restore is actually
// allowed to touch — everything included in a backup, minus the ones
// above. Import.php intersects this against what a given backup file
// actually contains (never trusting arbitrary table names out of an
// uploaded file), so this is the other half of that whitelist.
function backupRestorableTables(PDO $pdo): array {
    return array_values(array_diff(backupIncludedTables($pdo), BACKUP_NON_RESTORABLE_TABLES));
}

// Zip-internal path => real filesystem path, for every directory the
// app ever writes an uploaded file into (see api/clinic/upload_logo.php,
// upload_hero.php, upload_video.php, gallery.php, api/users/upload_photo.php).
function backupFileDirs(): array {
    $root = __DIR__ . '/../../';
    return [
        'files/images/logo'     => $root . 'assets/images/logo',
        'files/images/hero'     => $root . 'assets/images/hero',
        'files/images/about'    => $root . 'assets/images/about',
        'files/images/profiles' => $root . 'assets/images/profiles', // legacy path some old rows still point at
        'files/videos'          => $root . 'assets/videos',
        'files/uploads/profiles'=> $root . 'assets/uploads/profiles',
    ];
}

function backupIncludedTables(PDO $pdo): array {
    $all = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_diff($all, BACKUP_EXCLUDED_TABLES));
}

// SQL-escapes a value for embedding directly in an INSERT statement
// (used only for the portable database.sql text — the actual restore
// path uses PDO prepared statements via backupInsertRow() instead, this
// is purely for the human-readable / external-tool-facing file).
function backupSqlLiteral(PDO $pdo, $value): string {
    if ($value === null) return 'NULL';
    if (is_int($value) || is_float($value)) return (string)$value;
    return $pdo->quote((string)$value);
}

// Builds the full dump: returns ['sql' => string, 'json' => array,
// 'counts' => [table => rowCount]]. $pdo is read-only here — nothing in
// this function ever writes to the database.
function backupBuildDump(PDO $pdo): array {
    $tables = backupIncludedTables($pdo);
    $sqlParts = [];
    $sqlParts[] = "-- ================================================================\n"
        . "--  Cana Optical Clinic — full system backup\n"
        . "--  Generated: " . date('Y-m-d H:i:s') . " (Asia/Manila)\n"
        . "--  Format version: " . BACKUP_FORMAT_VERSION . "\n"
        . "--\n"
        . "--  Portable SQL dump — restorable with `mysql < database.sql` or\n"
        . "--  phpMyAdmin's Import, independent of this app's own code. Tables\n"
        . "--  intentionally excluded (transient session/OTP/throttle state,\n"
        . "--  not clinic data): " . implode(', ', BACKUP_EXCLUDED_TABLES) . "\n"
        . "-- ================================================================\n\n"
        . "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $json = [];
    $counts = [];

    foreach ($tables as $table) {
        $createRow = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch();
        $createSql = $createRow['Create Table'] ?? null;

        $sqlParts[] = "-- ── Table: `$table` ──────────────────────────────────────────\n";
        $sqlParts[] = "DROP TABLE IF EXISTS `$table`;\n";
        if ($createSql) $sqlParts[] = $createSql . ";\n\n";

        $rows = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll();
        $counts[$table] = count($rows);
        $json[$table] = $rows;

        foreach (array_chunk($rows, 200) as $chunk) {
            if (!$chunk) continue;
            $cols = array_keys($chunk[0]);
            $colList = '`' . implode('`, `', $cols) . '`';
            $valueRows = [];
            foreach ($chunk as $row) {
                $vals = array_map(fn($c) => backupSqlLiteral($pdo, $row[$c]), $cols);
                $valueRows[] = '(' . implode(', ', $vals) . ')';
            }
            $sqlParts[] = "INSERT INTO `$table` ($colList) VALUES\n  " . implode(",\n  ", $valueRows) . ";\n";
        }
        $sqlParts[] = "\n";
    }

    $sqlParts[] = "SET FOREIGN_KEY_CHECKS = 1;\n";

    return ['sql' => implode('', $sqlParts), 'json' => $json, 'counts' => $counts, 'tables' => $tables];
}

// Recursively lists every regular file under $dir (skips dotfiles), as
// absolute filesystem paths. Returns [] if the directory doesn't exist
// (a fresh install may not have created e.g. assets/videos/ yet).
function backupListFiles(string $dir): array {
    if (!is_dir($dir)) return [];
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile()) $out[] = $file->getPathname();
    }
    return $out;
}

function backupSanitizeFilenamePart(string $s): string {
    $s = preg_replace('/[^A-Za-z0-9_-]+/', '-', $s);
    return trim($s, '-') ?: 'backup';
}

// Wipes and repopulates exactly the given $tables from $jsonData (the
// same table => rows shape database.json uses), via ordinary prepared
// statements — never raw SQL text. Shared by import.php for both the
// real restore and, if that fails partway, a same-shaped rollback call
// using the pre-restore safety dump instead. FK checks must already be
// disabled by the caller (restoring is inherently going to violate
// referential integrity mid-way through, however the tables are
// ordered, until every table is back in place).
//
// Returns ['restored' => [table => rowCount]] on full success. Throws a
// RuntimeException naming the table it was on when something failed —
// the caller decides what to do about that (import.php attempts a
// same-function rollback call using the pre-restore dump).
function backupApplyJsonToDb(PDO $pdo, array $tables, array $jsonData): array {
    $restored = [];
    foreach ($tables as $table) {
        $rows = $jsonData[$table] ?? [];
        try {
            $pdo->exec('TRUNCATE TABLE `' . $table . '`');
            if ($rows) {
                $cols = array_keys($rows[0]);
                $colList = '`' . implode('`, `', $cols) . '`';
                $placeholders = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
                $stmt = $pdo->prepare("INSERT INTO `$table` ($colList) VALUES $placeholders");
                foreach ($rows as $row) {
                    $stmt->execute(array_map(fn($c) => $row[$c] ?? null, $cols));
                }
            }
            $restored[$table] = count($rows);
        } catch (PDOException $e) {
            throw new RuntimeException("Failed restoring table `$table`: " . $e->getMessage());
        }
    }
    return $restored;
}

// Extracts every files/… entry from an already-open ZipArchive back into
// its real directory (see backupFileDirs()). Best-effort per file — one
// unwritable/corrupt entry doesn't abort the rest. Returns [restored, failed] counts.
function backupRestoreFiles(ZipArchive $zip): array {
    $dirs = backupFileDirs();
    $restored = 0;
    $failed = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false || substr($name, -1) === '/' || strpos($name, 'files/') !== 0) continue;

        $destPath = null;
        foreach ($dirs as $zipPrefix => $realDir) {
            if (strpos($name, $zipPrefix . '/') === 0) {
                $rel = substr($name, strlen($zipPrefix) + 1);
                $destPath = rtrim($realDir, '/') . '/' . $rel;
                break;
            }
        }
        if (!$destPath) { $failed++; continue; }

        $destDir = dirname($destPath);
        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) { $failed++; continue; }

        $contents = $zip->getFromIndex($i);
        if ($contents === false || @file_put_contents($destPath, $contents) === false) { $failed++; continue; }
        $restored++;
    }
    return ['restored' => $restored, 'failed' => $failed];
}
