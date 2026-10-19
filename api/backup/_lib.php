<?php
// ================================================================
//  CANAOPTICALCLINIC — api/backup/_lib.php
//  Shared helpers for the whole-system Backup & Restore feature
//  (export.php, upload.php, inspect.php, import.php, status.php). Not
//  web-accessible itself — every caller requires it, none of them route
//  to it directly.
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
//                      image/video, about-page gallery, profile photos,
//                      previous clinic records), at the same relative
//                      path they live at, so restore can extract them
//                      straight back into place.
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
        // Patients' uploaded records from other clinics (api/previous_records/save.php)
        'files/previous_records'=> $root . 'storage/previous_records',
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

// Recursively lists every regular file under $dir, skipping dotfiles
// (.gitkeep, .htaccess), as absolute filesystem paths. Returns [] if the
// directory doesn't exist (a fresh install may not have created e.g.
// assets/videos/ yet).
function backupListFiles(string $dir): array {
    if (!is_dir($dir)) return [];
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile() && $file->getFilename()[0] !== '.') $out[] = $file->getPathname();
    }
    return $out;
}

function backupSanitizeFilenamePart(string $s): string {
    $s = preg_replace('/[^A-Za-z0-9_-]+/', '-', $s);
    return trim($s, '-') ?: 'backup';
}

// Creates the tables and columns this app otherwise adds on first use
// (Previous Clinic Records), so restoring a backup that has them onto a
// database that doesn't yet keeps those records instead of skipping them.
function backupPrepareSchema(PDO $pdo, array $backupTables): void {
    if (array_intersect($backupTables, ['previous_clinic_records', 'previous_clinic_files'])) {
        try {
            ensurePreviousRecordTables($pdo);
            previousRecordExtraColumns($pdo);
        } catch (PDOException $e) {
            // Restore what it can; those tables are then left out below.
        }
    }
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
// Only the columns each table has now are written: a backup from an older
// or newer version of the app can carry a column this database doesn't
// (listed in $skipped as "table.column"), and a column the backup lacks
// takes its default.
//
// Returns [table => rowCount] on full success. Throws a RuntimeException
// naming the table it was on when something failed — the caller decides
// what to do about that (import.php attempts a same-function rollback
// call using the pre-restore dump).
function backupApplyJsonToDb(PDO $pdo, array $tables, array $jsonData, ?array &$skipped = null): array {
    $restored = [];
    $skipped = [];
    foreach ($tables as $table) {
        $rows = $jsonData[$table] ?? [];
        try {
            $pdo->exec('TRUNCATE TABLE `' . $table . '`');
            if ($rows) {
                $have = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
                $cols = array_values(array_intersect(array_keys($rows[0]), $have));
                foreach (array_diff(array_keys($rows[0]), $have) as $c) $skipped[] = "$table.$c";
                if ($cols) {
                    $colList = '`' . implode('`, `', $cols) . '`';
                    $placeholders = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
                    $stmt = $pdo->prepare("INSERT INTO `$table` ($colList) VALUES $placeholders");
                    foreach ($rows as $row) {
                        $stmt->execute(array_map(fn($c) => $row[$c] ?? null, $cols));
                    }
                }
            }
            $restored[$table] = count($rows);
        } catch (PDOException $e) {
            throw new RuntimeException("Failed restoring table `$table`: " . $e->getMessage());
        }
    }
    return $restored;
}

// A file entry's path inside its folder, or null when it could climb out
// of that folder ("../"), is absolute, or isn't an image, video or PDF
// (the only things this app stores there). A crafted backup must never
// be able to write a script into the web root.
function backupSafeRelPath(string $rel): ?string {
    $rel = str_replace('\\', '/', $rel);
    if ($rel === '' || $rel[0] === '/' || strpos($rel, "\0") !== false
        || preg_match('#(^|/)\.#', $rel) || preg_match('/^[A-Za-z]:/', $rel)) return null;
    return preg_match('/\.(jpe?g|png|gif|webp|svg|ico|mp4|webm|ogg|mov|m4v|pdf)$/i', $rel) ? $rel : null;
}

// Extracts every files/… entry from an already-open archive back into
// its real directory (see backupFileDirs()). Best-effort per file — one
// unwritable/corrupt entry doesn't abort the rest; entries that aren't a
// safe media path (backupSafeRelPath) are skipped. Returns
// [restored, failed] counts.
function backupRestoreFiles($zip): array {
    $dirs = backupFileDirs();
    $restored = 0;
    $failed = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false || substr($name, -1) === '/' || strpos($name, 'files/') !== 0) continue;

        $destPath = null;
        foreach ($dirs as $zipPrefix => $realDir) {
            if (strpos($name, $zipPrefix . '/') === 0) {
                $rel = backupSafeRelPath(substr($name, strlen($zipPrefix) + 1));
                if ($rel !== null) $destPath = rtrim($realDir, '/') . '/' . $rel;
                break;
            }
        }
        if (!$destPath) continue;

        $destDir = dirname($destPath);
        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) { $failed++; continue; }

        if (backupZipExtract($zip, $i, $destPath)) $restored++;
        else $failed++;
    }
    return ['restored' => $restored, 'failed' => $failed];
}

// ── Zip archives ──────────────────────────────────────────────────
// PHP's ZipArchive when the server has the zip extension, otherwise the
// small built-in writer/reader below — so Backup & Restore works on any
// PHP host (XAMPP's php.ini, for one, can ship with extension=zip turned
// off). Both expose the few methods used here: addFromString / addFile /
// close for writing; getFromName / numFiles / getNameIndex / close plus
// backupZipExtract() for reading.
function backupZipCreate(string $path) {
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        return $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true ? $zip : null;
    }
    $zip = new BackupZipWriter();
    return $zip->open($path) ? $zip : null;
}

function backupZipOpen(string $path) {
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        return $zip->open($path) === true ? $zip : null;
    }
    $zip = new BackupZipReader();
    return $zip->open($path) ? $zip : null;
}

// Writes entry $i to $dest through a temporary file, streamed (the hero
// video is never held in memory whole), so a failed write never leaves a
// half-written copy in place of the file that was there.
function backupZipExtract($zip, int $i, string $dest): bool {
    $tmp = $dest . '.restoring';
    if ($zip instanceof ZipArchive) {
        $in  = $zip->getStream((string)$zip->getNameIndex($i));
        $out = $in ? @fopen($tmp, 'wb') : false;
        $ok  = $in && $out && stream_copy_to_stream($in, $out) !== false;
        if ($in)  fclose($in);
        if ($out) fclose($out);
    } else {
        $ok = $zip->extractTo($i, $tmp);
    }
    if ($ok && @rename($tmp, $dest)) return true;
    @unlink($tmp);
    return false;
}

// A standard zip, written without ZipArchive: each entry deflated when
// that makes it smaller, else stored; UTF-8 names; no Zip64, so up to
// 4 GB and 65,535 entries (a backup is capped at 2 GB).
final class BackupZipWriter {
    private $fh = null;
    private string $central = '';
    private int $count = 0;

    public function open(string $path): bool {
        $this->fh = @fopen($path, 'wb');
        return $this->fh !== false;
    }

    public function addFromString(string $name, string $data, ?int $mtime = null): bool {
        $packed  = function_exists('gzdeflate') ? gzdeflate($data, 6) : false;
        $deflate = $packed !== false && strlen($packed) < strlen($data);
        $body    = $deflate ? $packed : $data;
        return $this->header($name, $deflate ? 8 : 0, crc32($data), strlen($body), strlen($data), $mtime ?? time())
            && $this->write($body);
    }

    // Files up to 16 MB are compressed like a string; bigger ones (the hero
    // video) are already-compressed media, so they're stored and streamed.
    public function addFile(string $path, string $name): bool {
        $size  = @filesize($path);
        $mtime = @filemtime($path) ?: time();
        if ($size === false) return false;
        if ($size <= 16 * 1048576) {
            $data = @file_get_contents($path);
            return $data !== false && $this->addFromString($name, $data, $mtime);
        }
        $in = @fopen($path, 'rb');
        if (!$in) return false;
        $ok = $this->header($name, 0, (int)hexdec(hash_file('crc32b', $path)), $size, $size, $mtime)
            && stream_copy_to_stream($in, $this->fh) === $size;
        fclose($in);
        return $ok;
    }

    public function close(): bool {
        if (!$this->fh) return false;
        $offset = (int)ftell($this->fh);
        $ok = $this->count <= 0xFFFF && $offset <= 0xFFFFFFFF
            && $this->write($this->central)
            && $this->write(pack('VvvvvVVv', 0x06054b50, 0, 0, $this->count, $this->count, strlen($this->central), $offset, 0));
        $ok = fclose($this->fh) && $ok;
        $this->fh = null;
        return $ok;
    }

    private function header(string $name, int $method, int $crc, int $csize, int $usize, int $mtime): bool {
        $offset = ftell($this->fh);
        if ($offset === false || $offset > 0xFFFFFFFF || $csize > 0xFFFFFFFF || $usize > 0xFFFFFFFF) return false;
        $d    = getdate(max($mtime, 315532800));   // zip dates start at 1980
        $time = ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2);
        $date = (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];
        $this->central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $method, $time, $date,
            $crc, $csize, $usize, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        $this->count++;
        return $this->write(pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $time, $date,
            $crc, $csize, $usize, strlen($name), 0) . $name);
    }

    private function write(string $bytes): bool {
        return $bytes === '' || fwrite($this->fh, $bytes) === strlen($bytes);
    }
}

// Reads a standard zip without ZipArchive: stored or deflated entries,
// every one checked against its CRC-32. Opens zips made by ZipArchive or
// BackupZipWriter; not encrypted or Zip64 (over 4 GB) archives.
final class BackupZipReader {
    public int $numFiles = 0;
    private $fh = null;
    private array $entries = [];
    private array $byName = [];

    public function open(string $path): bool {
        $fh = @fopen($path, 'rb');
        if (!$fh) return false;
        if (!$this->readDirectory($fh)) {
            fclose($fh);
            $this->entries = $this->byName = [];
            return false;
        }
        $this->fh = $fh;
        $this->numFiles = count($this->entries);
        return true;
    }

    public function getNameIndex(int $i) {
        return $this->entries[$i]['name'] ?? false;
    }

    public function getFromName(string $name) {
        return isset($this->byName[$name]) ? $this->getFromIndex($this->byName[$name]) : false;
    }

    public function getFromIndex(int $i) {
        $e = $this->seekEntry($i);
        if (!$e) return false;
        $raw = $e['csize'] ? (string)fread($this->fh, $e['csize']) : '';
        if (strlen($raw) !== $e['csize']) return false;
        $data = $e['method'] === 8 ? ($e['usize'] ? @gzinflate($raw, $e['usize']) : '') : $raw;
        return $data !== false && strlen($data) === $e['usize'] && crc32($data) === $e['crc'] ? $data : false;
    }

    // Streams entry $i into the file $dest, 1 MB at a time.
    public function extractTo(int $i, string $dest): bool {
        $e   = $this->seekEntry($i);
        $out = $e ? @fopen($dest, 'wb') : false;
        if (!$out) return false;
        $inflate = $e['method'] === 8 ? inflate_init(ZLIB_ENCODING_RAW) : null;
        $crc     = hash_init('crc32b');
        $left    = $e['csize'];
        $written = 0;
        $ok      = true;
        while ($ok && $left > 0) {
            $chunk = (string)fread($this->fh, min($left, 1048576));
            if ($chunk === '') { $ok = false; break; }
            $left -= strlen($chunk);
            $plain = $inflate ? @inflate_add($inflate, $chunk, $left ? ZLIB_NO_FLUSH : ZLIB_FINISH) : $chunk;
            if ($plain === false || $written + strlen($plain) > $e['usize']) { $ok = false; break; }
            hash_update($crc, $plain);
            $written += strlen($plain);
            $ok = $plain === '' || fwrite($out, $plain) === strlen($plain);
        }
        fclose($out);
        return $ok && $written === $e['usize'] && (int)hexdec(hash_final($crc)) === $e['crc'];
    }

    public function close(): bool {
        if ($this->fh) fclose($this->fh);
        $this->fh = null;
        return true;
    }

    // The central directory at the end of the file: one record per entry.
    private function readDirectory($fh): bool {
        $size = (int)fstat($fh)['size'];
        if ($size < 22) return false;
        $tailLen = min($size, 22 + 0xFFFF);           // end record + longest comment
        fseek($fh, $size - $tailLen);
        $tail = (string)fread($fh, $tailLen);
        $at   = strrpos($tail, "PK\x05\x06");
        if ($at === false || strlen($tail) - $at < 22) return false;
        $end = unpack('vdisk/vcdDisk/vhere/ventries/VcdSize/VcdOffset', substr($tail, $at + 4, 16));
        if ($end['disk'] !== 0 || $end['entries'] === 0xFFFF || $end['cdOffset'] === 0xFFFFFFFF
            || $end['cdOffset'] + $end['cdSize'] > $size) return false;
        fseek($fh, $end['cdOffset']);
        $cd = $end['cdSize'] ? (string)fread($fh, $end['cdSize']) : '';
        if (strlen($cd) !== $end['cdSize']) return false;
        for ($p = 0, $i = 0; $i < $end['entries']; $i++) {
            if ($p + 46 > strlen($cd)) return false;
            $h = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLen/vextraLen/vcommentLen/vdisk/vinternal/Vexternal/Voffset', substr($cd, $p, 46));
            if ($h['sig'] !== 0x02014b50) return false;
            $name = substr($cd, $p + 46, $h['nameLen']);
            $this->entries[] = ['name' => $name, 'method' => $h['method'], 'flags' => $h['flags'],
                'crc' => $h['crc'], 'csize' => $h['csize'], 'usize' => $h['usize'], 'offset' => $h['offset']];
            $this->byName[$name] ??= $i;
            $p += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];
        }
        return true;
    }

    // Moves to the start of entry $i's data; null when it can't be read
    // here (encrypted, or compressed some way other than stored/deflated).
    private function seekEntry(int $i): ?array {
        $e = $this->entries[$i] ?? null;
        if (!$e || !$this->fh || ($e['flags'] & 1)
            || !($e['method'] === 0 || ($e['method'] === 8 && function_exists('inflate_init')))) return null;
        fseek($this->fh, $e['offset']);
        $lh = (string)fread($this->fh, 30);
        if (strlen($lh) !== 30) return null;
        $h = unpack('Vsig/x22/vnameLen/vextraLen', $lh);
        if ($h['sig'] !== 0x04034b50) return null;
        fseek($this->fh, $e['offset'] + 30 + $h['nameLen'] + $h['extraLen']);
        return $e;
    }
}

// ── Restore uploads ───────────────────────────────────────────────
// A backup reaches the server in pieces (upload.php), so its size isn't
// held to the hosting's PHP upload_max_filesize / post_max_size and the
// browser can show progress. The finished file waits in
// storage/backups/incoming/ (never web-accessible, never itself backed
// up) for inspect.php and then import.php, so confirming a restore
// doesn't send it a second time. It's removed after the restore, when
// the admin cancels or picks another file, or after 2 hours.
const BACKUP_UPLOAD_TTL = 7200;

function backupIncomingDir(): string {
    return __DIR__ . '/../../storage/backups/incoming';
}
function backupUploadPath(string $id): string {
    return backupIncomingDir() . "/$id.zip";
}
// The upload's bookkeeping (who, name, size, piece size, pieces received,
// done), or null for an unknown / malformed id.
function backupUploadMeta(string $id): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) return null;
    $f = backupIncomingDir() . "/$id.json";
    if (!is_file($f)) return null;
    $m = json_decode((string)file_get_contents($f), true);
    return is_array($m) ? $m : null;
}
function backupSaveUploadMeta(string $id, array $meta): bool {
    return file_put_contents(backupIncomingDir() . "/$id.json", json_encode($meta), LOCK_EX) !== false;
}
function backupDiscardUpload(string $id): void {
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) return;
    @unlink(backupUploadPath($id));
    @unlink(backupIncomingDir() . "/$id.json");
}
function backupCleanIncoming(): void {
    foreach (glob(backupIncomingDir() . '/*.{zip,json}', GLOB_BRACE) ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - BACKUP_UPLOAD_TTL) @unlink($f);
    }
}
// Upload piece size: 4 MB, or less when the hosting's PHP accepts less
// per request.
function backupChunkBytes(): int {
    return (int)max(512 * 1024, min(4 * 1048576, serverUploadLimit() - 65536));
}

// The backup file a request names: a finished upload of this admin's
// ($_POST uploadId), else a file sent with the request itself. A JSON
// error when the upload is unknown, someone else's, unfinished or expired.
function backupRequestFile(int $userId): string {
    $id = (string)($_POST['uploadId'] ?? '');
    if ($id === '') return requireUploadedFile('backup', UPLOAD_MAX_BACKUP, 'That backup file')['tmp_name'];
    $meta = backupUploadMeta($id);
    if (!$meta || (int)$meta['userId'] !== $userId || empty($meta['done']) || !is_file(backupUploadPath($id))) {
        jsonResponse(['success' => false, 'code' => 'expired', 'message' => 'That upload has expired. Please choose the backup file again.']);
    }
    return backupUploadPath($id);
}
