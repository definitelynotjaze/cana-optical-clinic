<?php
// ================================================================
//  CANAOPTICALCLINIC — api/users/update_profile.php
//
//  POST { firstName, middleName, lastName, phone }
//  Authenticated admin / staff / doctor only.
//  Email is NOT changed here any more — it goes through the OTP-verified
//  flow (api/patients/request-email-change.php + verify-email-change.php,
//  now open to every role), so a code sent to the new address has to be
//  entered before users.email changes.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}

$role = $_SESSION['role'] ?? '';
$tableMap = ['admin' => 'admins', 'staff' => 'staff', 'doctor' => 'doctors'];
if (!array_key_exists($role, $tableMap)) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$userId = (int)$_SESSION['user_id'];
$b      = getBody();
$fn     = trim($b['firstName']  ?? '');
$mn     = trim($b['middleName'] ?? '');
$ln     = trim($b['lastName']   ?? '');
$phone  = trim($b['phone']      ?? '');

if (!$fn || !$ln) {
    jsonResponse(['success' => false, 'message' => 'First and last name are required.']);
}
if ($phone && !isValidContact($phone)) {
    jsonResponse(['success' => false, 'message' => 'Please enter a valid 11-digit contact number.']);
}

$table = $tableMap[$role];

try {
    $pdo = getDB();

    $pdo->prepare(
        "UPDATE `{$table}` SET first_name = ?, middle_name = ?, last_name = ?, contact = ? WHERE user_id = ?"
    )->execute([$fn, $mn ?: null, $ln, $phone, $userId]);

    jsonResponse(['success' => true]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
