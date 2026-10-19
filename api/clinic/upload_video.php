<?php
// ================================================================
//  CANAOPTICALCLINIC — api/clinic/upload_video.php
//  POST multipart/form-data { video: File } — admin only.
//  Saves to assets/videos/clinic-video.<ext>, updates clinic_settings.video_url.
//  DELETE — admin only. Removes the current video and clears video_url.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    jsonResponse(['success' => false, 'message' => 'Only admins may change the clinic video.'], 403);
}

define('VIDEO_DIR', __DIR__ . '/../../assets/videos/');

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    try {
        $pdo = getDB();
        foreach (['mp4', 'webm'] as $e) {
            $old = VIDEO_DIR . 'clinic-video.' . $e;
            if (file_exists($old)) @unlink($old);
        }
        $pdo->prepare('UPDATE clinic_settings SET video_url = NULL WHERE id = 1')->execute();
        jsonResponse(['success' => true]);
    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'message' => 'Database error.'], 500);
    }
}

requireMethod('POST');

// 100 MB: about 2 minutes of 1080p — plenty for a homepage clip, which
// visitors stream (a 2 GB file would stall the page on mobile data).
$file     = requireUploadedFile('video', UPLOAD_MAX_VIDEO, 'That video',
    'Keep it to about 2 minutes and export it at 1080p (H.264).');
$mimeType = mime_content_type($file['tmp_name']);
$allowed  = ['video/mp4', 'video/webm'];

if (!in_array($mimeType, $allowed, true)) {
    jsonResponse(['success' => false, 'message' => 'Invalid file type. Use MP4 or WebM.']);
}

$ext = $mimeType === 'video/webm' ? 'webm' : 'mp4';

// A file can be a perfectly valid MP4 *container* (passing the MIME check
// above) while the video stream inside it is encoded with a codec Safari/
// iOS can't decode — VP9-in-MP4 is the classic case: Chrome/Firefox play
// it fine since they support VP9 regardless of container, so it looks
// "fine" in testing right up until a Safari/iPhone visitor hits the
// landing page. ffprobe (if present — see nixpacks.toml) is the only
// reliable way to catch this at upload time rather than after the fact.
if ($ext === 'mp4') {
    $ffprobe = @shell_exec('which ffprobe 2>/dev/null');
    if ($ffprobe) {
        $codec = trim((string)@shell_exec(
            'ffprobe -v error -select_streams v:0 -show_entries stream=codec_name ' .
            '-of csv=p=0 ' . escapeshellarg($file['tmp_name']) . ' 2>/dev/null'
        ));
        $safariCompatible = ['h264', 'hevc'];
        if ($codec !== '' && !in_array($codec, $safariCompatible, true)) {
            jsonResponse([
                'success' => false,
                'message' => "This MP4's video track is encoded with \"$codec\", which Safari and " .
                    'iOS/iPadOS cannot play even though other browsers can. Please re-export it as ' .
                    'H.264 (or HEVC) video with AAC audio before uploading.',
            ]);
        }
    }
    // If ffprobe isn't available on this deployment, upload proceeds as
    // before rather than blocking admins — same behavior as prior to this
    // check.
}

if (!is_dir(VIDEO_DIR)) {
    mkdir(VIDEO_DIR, 0755, true);
}

foreach (['mp4', 'webm'] as $e) {
    $old = VIDEO_DIR . 'clinic-video.' . $e;
    if (file_exists($old)) @unlink($old);
}

$filename = 'clinic-video.' . $ext;
$destPath = VIDEO_DIR . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    jsonResponse(['success' => false, 'message' => 'Failed to save file. Check server permissions.'], 500);
}

$videoUrl = 'assets/videos/' . $filename;

try {
    $pdo = getDB();
    $pdo->prepare('UPDATE clinic_settings SET video_url = ? WHERE id = 1')
        ->execute([$videoUrl]);

    jsonResponse(['success' => true, 'videoUrl' => $videoUrl]);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error.'], 500);
}
