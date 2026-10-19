<?php
// ================================================================
//  CANAOPTICALCLINIC — api/clinic/upload_hero.php
//  POST multipart/form-data { hero: File } — admin only.
//  Saves to assets/images/hero/clinic-hero.<ext>, updates clinic_settings.hero_url.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    jsonResponse(['success' => false, 'message' => 'Only admins may change the hero background.'], 403);
}

$file     = requireUploadedFile('hero', UPLOAD_MAX_HERO, 'That image', 'Save it at 1920 × 1080 as a JPG.');
$mimeType = mime_content_type($file['tmp_name']);
$allowed  = ['image/jpeg', 'image/png', 'image/webp'];

if (!in_array($mimeType, $allowed, true)) {
    jsonResponse(['success' => false, 'message' => 'Invalid file type. Use PNG, JPEG, or WebP.']);
}

$ext = match ($mimeType) {
    'image/png'  => 'png',
    'image/webp' => 'webp',
    default      => 'jpg',
};

$uploadDir = __DIR__ . '/../../assets/images/hero/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

foreach (['jpg', 'jpeg', 'png', 'webp'] as $e) {
    $old = $uploadDir . 'clinic-hero.' . $e;
    if (file_exists($old)) @unlink($old);
}

$filename = 'clinic-hero.' . $ext;
$destPath = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    jsonResponse(['success' => false, 'message' => 'Failed to save file. Check server permissions.'], 500);
}

$heroUrl = 'assets/images/hero/' . $filename;

try {
    $pdo = getDB();
    $pdo->prepare('UPDATE clinic_settings SET hero_url = ? WHERE id = 1')
        ->execute([$heroUrl]);

    jsonResponse(['success' => true, 'heroUrl' => $heroUrl]);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error.'], 500);
}
