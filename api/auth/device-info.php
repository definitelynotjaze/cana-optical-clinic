<?php
// ================================================================
//  CANAOPTICALCLINIC — api/auth/device-info.php
//  POST { model, platformVersion }
//  The signed-in browser reports its device model (User-Agent Client
//  Hints: navigator.userAgentData.getHighEntropyValues) so Sessions &
//  Sign-in can name devices like Google does ("Samsung Galaxy S23",
//  "Windows 11 PC") — modern Chrome on Android hides the model from the
//  plain User-Agent. Saved on this session and on this trusted device
//  (cana_dev cookie). See deviceDisplayName() in helpers.php.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}

$b = getBody();
// Plain model codes/names only ("SM-A546E", "Pixel 8", "Redmi Note 13").
$clean = fn($v, $max) => substr(preg_replace('/[^A-Za-z0-9 ._()+\-]/', '', trim((string)$v)), 0, $max);
$model    = $clean($b['model'] ?? '', 60);
$platform = $clean($b['platformVersion'] ?? '', 20);

try {
    $pdo = getDB();
    $pdo->prepare('UPDATE sessions SET device_model = ?, platform_version = ? WHERE id = ?')
        ->execute([$model ?: null, $platform ?: null, session_id()]);

    $tok = deviceToken();
    if ($tok) {
        $pdo->prepare('UPDATE known_devices SET device_model = ?, platform_version = ? WHERE users_id = ? AND device_token = ?')
            ->execute([$model ?: null, $platform ?: null, (int)$_SESSION['user_id'], $tok]);
    }
    jsonResponse(['success' => true]);
} catch (PDOException $e) {
    jsonResponse(['success' => false], 500);
}
