<?php
// ================================================================
//  CANAOPTICALCLINIC — api/patients/lookup.php
//
//  GET ?qr=<qr_data>           → single patient by qr_data
//  GET ?id=<patient_id>        → single patient by id (e.g. P001)
//  GET ?name=<search>&limit=6  → up to N patients matching name/id
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

// Must be authenticated as admin, staff, or doctor to look up patients
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'staff', 'doctor'], true)) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 401);
}

function buildPatientRow(array $row, string $email = ''): array {
    $name = trim($row['first_name'] . ' ' . $row['last_name']);
    return [
        'id'        => $row['id'],
        'name'      => $name,
        'firstName' => $row['first_name'],
        'lastName'  => $row['last_name'],
        'gender'    => $row['gender']    ?? '',
        'age'       => patientAge($row['dob'] ?? null, $row['age'] ?? 0),
        'email'     => $email,
        'contact'   => $row['contact']   ?? '',
        'address'   => $row['address']   ?? '',
        'dob'       => $row['dob']       ?? '',
        'qrData'    => $row['qr_data']   ?? '',
        'lastVisit' => $row['last_visit'] ?: '—',
        'status'    => $row['status']    ?? 'active',
        'examinations' => [],
    ];
}

try {
    $pdo = getDB();

    // ── Single lookup by QR code ─────────────────────────────────
    if (isset($_GET['qr']) && $_GET['qr'] !== '') {
        $s = $pdo->prepare('
            SELECT p.*, u.email
            FROM patients p
            LEFT JOIN users u ON u.id = p.user_id
            WHERE p.qr_data = ?
            LIMIT 1
        ');
        $s->execute([trim($_GET['qr'])]);
        $row = $s->fetch();
        if (!$row) {
            jsonResponse(['success' => false, 'message' => 'Patient not found.']);
        }
        jsonResponse(['success' => true, 'patient' => buildPatientRow($row, $row['email'])]);
    }

    // ── Single lookup by patient ID ──────────────────────────────
    if (isset($_GET['id']) && $_GET['id'] !== '') {
        $s = $pdo->prepare('
            SELECT p.*, u.email
            FROM patients p
            LEFT JOIN users u ON u.id = p.user_id
            WHERE p.id = ?
            LIMIT 1
        ');
        $s->execute([strtoupper(trim($_GET['id']))]);
        $row = $s->fetch();
        if (!$row) {
            jsonResponse(['success' => false, 'message' => 'Patient not found.']);
        }
        jsonResponse(['success' => true, 'patient' => buildPatientRow($row, $row['email'])]);
    }

    // ── Search by name or patient ID ─────────────────────────────
    if (isset($_GET['name']) && $_GET['name'] !== '') {
        $q     = '%' . trim($_GET['name']) . '%';
        $limit = min((int)($_GET['limit'] ?? 6), 50);
        $s     = $pdo->prepare('
            SELECT p.*, u.email
            FROM patients p
            LEFT JOIN users u ON u.id = p.user_id
            WHERE p.first_name LIKE ?
               OR p.last_name  LIKE ?
               OR CONCAT(p.first_name, " ", p.last_name) LIKE ?
               OR p.id LIKE ?
            ORDER BY p.last_name, p.first_name
            LIMIT ' . $limit
        );
        $s->execute([$q, $q, $q, $q]);
        $rows = $s->fetchAll();
        jsonResponse([
            'success'  => true,
            'patients' => array_map(fn($r) => buildPatientRow($r, $r['email']), $rows)
        ]);
    }

    jsonResponse(['success' => false, 'message' => 'No search parameter provided.']);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error.'], 500);
}
