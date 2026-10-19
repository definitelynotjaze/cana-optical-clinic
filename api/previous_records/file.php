<?php
// ================================================================
//  CANAOPTICALCLINIC — api/previous_records/file.php
//  Admin, staff and doctors. Serves one uploaded Previous Clinic Record
//  file (PDF / JPG / PNG) from storage/, which is never web-accessible
//  directly (storage/.htaccess).
//
//  GET ?id=<file id>        → shown in the browser (PDF viewer / image)
//  GET ?id=<file id>&dl=1   → downloaded under its original file name
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

startSession();

$fail = function (int $code, string $msg) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($msg);
};

if (!isset($_SESSION['user_id'])) $fail(401, 'Please sign in to view this file.');
if (!in_array($_SESSION['role'] ?? '', ['admin', 'staff', 'doctor'], true)) $fail(403, 'You don\'t have access to this file.');
// Nothing below writes to the session; release it so several images on
// one page load side by side.
session_write_close();

$id = (int)($_GET['id'] ?? 0);
try {
    $pdo = getDB();
    // A file of an archived record only opens for an admin (Settings >
    // Archives); for everyone else that record is gone from the profile.
    $archivedCol = previousRecordArchiveColumn($pdo) ? 'r.archived_at' : 'NULL';
    $st = $pdo->prepare("SELECT f.patient_id, f.stored_name, f.original_name, f.mime_type, $archivedCol AS archived_at
                           FROM previous_clinic_files f JOIN previous_clinic_records r ON r.id = f.record_id
                          WHERE f.id = ? LIMIT 1");
    $st->execute([$id]);
    $f = $st->fetch();
} catch (PDOException) {
    $f = null;
}
if (!$f || !isset(PREV_RECORD_MIME_EXT[$f['mime_type']])) $fail(404, 'File not found.');
if ($f['archived_at'] && ($_SESSION['role'] ?? '') !== 'admin') $fail(404, 'File not found.');

try { $path = previousRecordsDir($f['patient_id']) . '/' . basename($f['stored_name']); }
catch (InvalidArgumentException) { $fail(404, 'File not found.'); }
if (!is_file($path)) $fail(404, 'This file is missing from the server. Upload it again from the patient\'s profile.');

$name  = $f['original_name'] ?: ('document.' . PREV_RECORD_MIME_EXT[$f['mime_type']]);
$ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $name);   // plain fallback for old browsers
$disp  = (($_GET['dl'] ?? '') === '1') ? 'attachment' : 'inline';

while (ob_get_level()) ob_end_clean();
header('Content-Type: ' . $f['mime_type']);
header('Content-Length: ' . filesize($path));
header("Content-Disposition: $disp; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');
readfile($path);
exit;
