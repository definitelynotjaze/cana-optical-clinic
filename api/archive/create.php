<?php
// ================================================================
//  CANAOPTICALCLINIC — api/archive/create.php
//  Archives a user account, patient, service, or examination record:
//  flags the underlying row as archived (blocking login for accounts) and
//  stores a restorable snapshot in archived_records.
//
//  POST { profileId, role, type, name, reason, archivedBy }
//   - role: 'Admin' | 'Staff' | 'Doctor' | 'Patient'  (Account/Patient only)
//   - type: 'Account' | 'Patient' | 'Service'  (defaults to 'Account';
//     'Examination' is refused — exam records can't be archived)
//
//  Admin only.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}

$b          = getBody();
$profileId  = trim($b['profileId']  ?? '');
$role       = trim($b['role']       ?? '');
$type       = trim($b['type']       ?? 'Account');
$name       = trim($b['name']       ?? '');
$reason     = trim($b['reason']     ?? '') ?: 'No reason provided';
$archivedBy = trim($b['archivedBy'] ?? '') ?: 'Admin';

// ── Examinations can't be archived: they're permanent medical records
// (and part of the clinic's record-keeping duty), so the request is refused
// for every role. ──────────────────────────────────────────────────────
if ($type === 'Examination') {
    jsonResponse(['success' => false, 'message' => 'Examination records are permanent medical records and can\'t be archived.'], 403);
}

if ($_SESSION['role'] !== 'admin') {
    jsonResponse(['success' => false, 'message' => 'Only admins may archive records.'], 403);
}

// Services aren't user accounts (no `role`/login to touch) — archive them
// straight from clinic_services instead of the Admin/Staff/Doctor/Patient
// role-table path below.
if ($type === 'Service') {
    if (!$profileId) {
        jsonResponse(['success' => false, 'message' => 'profileId is required.']);
    }
    try {
        $pdo = getDB();

        $s = $pdo->prepare('SELECT * FROM clinic_services WHERE id = ? LIMIT 1');
        $s->execute([$profileId]);
        $svc = $s->fetch();
        if (!$svc) {
            jsonResponse(['success' => false, 'message' => 'Service not found.']);
        }

        $snapshot = [
            'id' => (int)$svc['id'], 'name' => $svc['name'], 'description' => $svc['description'],
            'status' => $svc['status'], 'icon' => $svc['icon'],
        ];

        $pdo->prepare("UPDATE clinic_services SET status = 'inactive' WHERE id = ?")->execute([$profileId]);

        $id   = 'AR' . date('YmdHis') . random_int(10, 99);
        $date = date('M j, Y');
        $pdo->prepare(
            'INSERT INTO archived_records (id, type, name, ref_id, archived_by, reason, data_json, date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, 'Service', $name ?: $svc['name'], $profileId, $archivedBy, $reason, json_encode($snapshot), $date]);

        jsonResponse(['success' => true, 'record' => [
            'id' => $id, 'type' => 'Service', 'name' => $name ?: $svc['name'], 'refId' => $profileId,
            'archivedBy' => $archivedBy, 'reason' => $reason, 'date' => $date, 'data' => $snapshot,
        ]]);
    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
    }
}

$tableMap = ['Admin' => 'admins', 'Staff' => 'staff', 'Doctor' => 'doctors', 'Patient' => 'patients'];
$table    = $tableMap[$role] ?? null;

if (!$profileId || !$table || !in_array($type, ['Account', 'Patient'], true)) {
    jsonResponse(['success' => false, 'message' => 'profileId, a valid role and type are required.']);
}

try {
    $pdo = getDB();

    $s = $pdo->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1");
    $s->execute([$profileId]);
    $row = $s->fetch();
    if (!$row) {
        jsonResponse(['success' => false, 'message' => 'Record not found.']);
    }

    $email    = '';
    $photoUrl = null;
    $userId   = $row['user_id'] ?? null;
    if ($userId) {
        $us = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
        $us->execute([$userId]);
        $email = $us->fetchColumn() ?: '';

        // Own try/catch, same as loadUserProfile()'s own photo_url fetch
        // (helpers.php) — a pre-migration DB missing this column should
        // never break archiving itself, just leave the snapshot's photo
        // blank.
        try {
            $ps = $pdo->prepare('SELECT photo_url FROM users WHERE id = ? LIMIT 1');
            $ps->execute([$userId]);
            $photoUrl = $ps->fetchColumn() ?: null;
        } catch (PDOException) {
            $photoUrl = null;
        }
    }

    // Build a restorable snapshot matching the frontend's object shape.
    // photoUrl included so the Archived Record modal can show the actual
    // person instead of just a generic archive-box icon (viewArchivedRecord(),
    // main.js) — a snapshot taken at archive time, same as every other field
    // here, so a record archived before this existed simply has none.
    $snapshot = [
        'id'        => $row['id'],
        'firstName' => $row['first_name'],
        'lastName'  => $row['last_name'],
        'name'      => ($role === 'Doctor' ? 'Dr. ' : '') . $row['first_name'] . ' ' . $row['last_name'],
        'email'     => $email,
        'contact'   => $row['contact'] ?? '',
        'status'    => $row['status']  ?? 'active',
        'role'      => $role,
        'photoUrl'  => $photoUrl,
    ];
    if ($role === 'Doctor') {
        $snapshot['specialization'] = $row['specialization'] ?? 'Optometrist';
        $snapshot['available']      = (bool)($row['available'] ?? true);
        $snapshot['hours']          = $row['work_hours'] ?? '';
    }
    if ($role === 'Patient') {
        $snapshot['gender']         = $row['gender']          ?? '';
        $snapshot['dob']            = $row['dob']              ?? '';
        $snapshot['age']            = (int)($row['age']        ?? 0);
        $snapshot['address']        = $row['address']          ?? '';
        $snapshot['occupation']     = $row['occupation']       ?? '';
        $snapshot['medicalHistory'] = $row['medical_history']  ?? '';
    }

    // Flag as archived and block login
    $pdo->prepare("UPDATE `{$table}` SET archived_at = NOW() WHERE id = ?")->execute([$profileId]);
    if ($userId) {
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$userId]);
    }
    // Archiving a patient IS how their own self-service deletion request
    // (api/patients/request-deletion.php) actually gets fulfilled — clear
    // the pending flag so it doesn't keep showing as an open request once
    // this action, which already accomplishes what they asked for, has
    // been taken.
    if ($role === 'Patient') {
        $pdo->prepare(
            'UPDATE patients SET deletion_requested_at = NULL, deletion_request_reason = NULL WHERE id = ?'
        )->execute([$profileId]);
    }

    $id = 'AR' . date('YmdHis') . random_int(10, 99);
    $date = date('M j, Y');

    $pdo->prepare(
        'INSERT INTO archived_records (id, type, name, ref_id, archived_by, reason, data_json, date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $id, $type, $name ?: $snapshot['name'], $profileId, $archivedBy, $reason,
        json_encode($snapshot), $date,
    ]);

    jsonResponse(['success' => true, 'record' => [
        'id' => $id, 'type' => $type, 'name' => $name ?: $snapshot['name'], 'refId' => $profileId,
        'archivedBy' => $archivedBy, 'reason' => $reason, 'date' => $date, 'data' => $snapshot,
    ]]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
