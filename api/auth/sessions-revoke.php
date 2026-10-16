<?php
// ================================================================
//  CANAOPTICALCLINIC — api/auth/sessions-revoke.php
//  POST { id }  — id is the short, opaque handle listSessions() (see
//  api/auth/sessions.php) returned for one row, not a real session id.
//  Logs that one device out immediately. Scoped to the caller's own
//  account throughout — there is no way to revoke another account's
//  session, even by guessing an id.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}

$userId = (int)$_SESSION['user_id'];
$b      = getBody();
$id     = trim($b['id'] ?? '');

if (!$id) {
    jsonResponse(['success' => false, 'message' => 'id is required.']);
}

// Revoking the device making this exact request doesn't make sense here
// (that's what Log Out is for) — and would actually get silently undone
// anyway: PHP's session write still fires at the end of THIS request,
// re-creating the row that was just deleted. The Active Sessions UI never
// offers a Log Out button for "This device" in the first place; this is
// just a defensive backend guard against a stale/tampered request.
if ($id === _sessionShortId(session_id())) {
    jsonResponse(['success' => false, 'message' => 'Use Log Out to sign out of this device.']);
}

try {
    $pdo = getDB();

    // "d<id>" = a remembered device (listSessions()): sign it out everywhere
    // and forget it, so it needs the OTP on its next sign-in. Never the
    // device making this request — that one uses Log Out instead.
    if (preg_match('/^d(\d+)$/', $id, $m)) {
        $own = $pdo->prepare('SELECT device_token FROM known_devices WHERE id = ? AND users_id = ? LIMIT 1');
        $own->execute([(int)$m[1], $userId]);
        $tok = $own->fetchColumn();
        if ($tok !== false && $tok !== null && $tok === deviceToken()) {
            jsonResponse(['success' => false, 'message' => 'Use Log Out to sign out of this device.']);
        }
        $n = removeTrustedDevice($pdo, $userId, (int)$m[1]);
        if ($n === 0) {
            jsonResponse(['success' => false, 'message' => 'That device was not found — it may already have been removed.']);
        }
        jsonResponse(['success' => true, 'revokedCount' => 1, 'removedDevice' => true]);
    }

    $revokedCount = revokeSessionByShortId($pdo, $userId, $id);
    if ($revokedCount === 0) {
        jsonResponse(['success' => false, 'message' => 'That session was not found — it may already be signed out.']);
    }
    jsonResponse(['success' => true, 'revokedCount' => $revokedCount]);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error.'], 500);
}
