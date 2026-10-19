<?php
// ================================================================
//  CANAOPTICALCLINIC — api/media/video.php
//  Serves a video file with real HTTP Range Request support (206 Partial
//  Content). This exists because PHP's built-in dev server (php -S, what
//  this app's Railway deployment runs — see nixpacks.toml) does NOT honor
//  Range headers for static files at all: it always returns the whole
//  file with 200 OK regardless of what Range was requested. Without Range
//  support, a browser can't seek to an arbitrary timestamp — dragging the
//  scrubber or tapping skip ±10s either does nothing, hangs re-downloading
//  the whole file, or resets to the start. Works fine on Apache (localhost
//  via XAMPP), which supports Range natively for static files, which is
//  why this only ever showed up on the hosted deployment.
//
//  Public — the clinic video is shown on the pre-login landing page, same
//  as when it was served as a plain static file.
//
//  GET ?src=<filename inside assets/videos/>
// ================================================================

// Explicit rather than relying on whatever the host's default happens to
// be — makes sure a disconnected client (e.g. one that just abandoned this
// response for a new seek) actually terminates the script instead of
// running to completion regardless, which is what the connection_aborted()
// check further down depends on.
ignore_user_abort(false);

define('VIDEO_DIR', realpath(__DIR__ . '/../../assets/videos'));

if (VIDEO_DIR === false) {
    http_response_code(404);
    exit;
}

// basename() strips any directory traversal attempt outright; realpath()
// below is the actual security check (it collapses any remaining ../
// tricks and resolves symlinks, so the strpos() containment check that
// follows can't be fooled).
$requested = basename($_GET['src'] ?? '');
$path      = VIDEO_DIR . DIRECTORY_SEPARATOR . $requested;
$realPath  = $requested !== '' ? realpath($path) : false;

if (!$realPath || strpos($realPath, VIDEO_DIR) !== 0 || !is_file($realPath)) {
    http_response_code(404);
    exit;
}

$mimeTypes = ['mp4' => 'video/mp4', 'webm' => 'video/webm'];
$ext  = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

$size  = filesize($realPath);
$start = 0;
$end   = $size - 1;

header('Accept-Ranges: bytes');
header('Content-Type: ' . $mime);
// The video file only ever changes via re-upload (which overwrites the
// same filename — see upload_video.php), so long-term caching is safe;
// a hard refresh or new deploy is the only time this URL's content changes.
header('Cache-Control: public, max-age=31536000, immutable');

// Railway runs PHP's built-in dev server (php -S — see nixpacks.toml),
// which is single-threaded: it serves exactly one request at a time for
// the whole app. Browsers almost always request video with an open-ended
// Range ("bytes=1234567-", no end given) — both during normal playback
// buffering and after every seek — so responding to that literally (stream
// all the way to end-of-file) meant a single request could tie up the
// *entire* server for the rest of the video's duration. Tapping skip then
// had to wait for that still-running response to finish before the new
// seek's request could even start, which is exactly the delay this app saw
// only on the hosted deployment (localhost's Apache is multi-threaded, so
// this never showed up there). Capping every response to a bounded chunk
// keeps each request short-lived regardless of what the client asked for —
// the browser transparently issues another Range request for more once it
// needs it, which is normal, well-supported behaviour.
const MAX_CHUNK_BYTES = 6 * 1024 * 1024; // ~6MB per response

$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
    if ($m[1] === '' && $m[2] !== '') {
        // Suffix range — "bytes=-500" means "the LAST 500 bytes", not the
        // first 500. Safari relies on this form to fetch the tail of a
        // file when probing for metadata, so getting it backwards (as an
        // earlier version of this script did) can break playback in ways
        // that only show up on Safari/iOS.
        $suffixLen = (int)$m[2];
        $start     = max(0, $size - $suffixLen);
        $end       = $size - 1;
    } else {
        $start = $m[1] === '' ? 0 : (int)$m[1];
        $end   = $m[2] === '' ? min($size - 1, $start + MAX_CHUNK_BYTES - 1) : min((int)$m[2], $size - 1);
    }

    if ($start > $end || $start >= $size) {
        http_response_code(416); // Range Not Satisfiable
        header("Content-Range: bytes */{$size}");
        exit;
    }

    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
} else {
    // No Range header at all. A 206/Content-Range response is only valid
    // as the answer to an actual Range request — sending one unprompted
    // (as this branch used to, to cap the response size) is a genuine HTTP
    // spec violation. Chrome/Firefox mostly shrug it off, but Safari's
    // media pipeline treats an unsolicited 206 as a corrupt response and
    // refuses to play the video at all — this is very likely why playback
    // failed specifically on Apple devices. Once Accept-Ranges: bytes is
    // advertised (above), browsers — including Safari — switch to Range
    // requests for all subsequent chunks/seeks anyway, so this plain,
    // correct 200 response only ever really applies to that first probe
    // request, which connection_aborted() below cuts short as soon as the
    // client has what it needs.
    http_response_code(200);
}

$length = $end - $start + 1;
header('Content-Length: ' . $length);

$fh = fopen($realPath, 'rb');
if (!$fh) {
    http_response_code(500);
    exit;
}
fseek($fh, $start);

// Stream in chunks rather than reading the whole file into memory at once
// (uploads are capped at UPLOAD_MAX_VIDEO). 256KB (rather than a tiny 8KB)
// keeps memory use trivial while drastically cutting the number of
// fread()/flush() round-trips needed per request.
$chunkSize = 262144;
$bytesLeft = $length;
while ($bytesLeft > 0 && !feof($fh)) {
    // Bail out the instant the client disconnects (e.g. it just issued a
    // new seek elsewhere and abandoned this response) instead of wastefully
    // continuing to read/flush to a socket nobody's listening to anymore —
    // every millisecond this script keeps running is a millisecond the
    // single-threaded server can't accept the client's next request.
    if (connection_aborted()) break;
    $readSize = (int)min($chunkSize, $bytesLeft);
    echo fread($fh, $readSize);
    flush();
    $bytesLeft -= $readSize;
}
fclose($fh);
