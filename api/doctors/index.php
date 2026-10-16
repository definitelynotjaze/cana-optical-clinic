<?php
// ================================================================
//  CANAOPTICALCLINIC — api/doctors/index.php
//  GET — authenticated endpoint (admin/staff/doctor only).
//  Returns all doctors with schedule, availability, and photo.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'staff', 'doctor'], true)) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
}

try {
    $pdo = getDB();

    $rows = $pdo->query(
        'SELECT d.id, d.first_name, d.middle_name, d.last_name, d.specialization, d.work_hours,
                d.available, d.status, d.contact, u.id AS user_id, u.email,
                GROUP_CONCAT(dd.day_of_week
                    ORDER BY FIELD(dd.day_of_week,"Mon","Tue","Wed","Thu","Fri","Sat","Sun")
                    SEPARATOR ","
                ) AS days
         FROM doctors d
         LEFT JOIN users u ON u.id = d.user_id
         LEFT JOIN doctor_days dd ON dd.doctor_id = d.id
         WHERE d.archived_at IS NULL
         GROUP BY d.id, d.first_name, d.middle_name, d.last_name, d.specialization,
                  d.work_hours, d.available, d.status, d.contact, u.id, u.email
         ORDER BY d.sort_order ASC, d.first_name ASC'
    )->fetchAll();

    // Fetch photos separately so a missing column never breaks this endpoint
    $photoMap = [];
    try {
        $userIds = array_filter(array_column($rows, 'user_id'));
        if ($userIds) {
            $in  = implode(',', array_map('intval', $userIds));
            $prs = $pdo->query("SELECT id, photo_url FROM users WHERE id IN ($in)")->fetchAll();
            foreach ($prs as $pr) { $photoMap[(int)$pr['id']] = $pr['photo_url']; }
        }
    } catch (PDOException) { /* photo_url column not yet migrated — skip */ }

    // Fetch PRC license and degree separately so missing columns never break this endpoint
    $licenseMap = [];
    $degreeMap  = [];
    $orderMap   = [];
    try {
        $lRows = $pdo->query('SELECT id, prc_license, degree, sort_order FROM doctors')->fetchAll();
        foreach ($lRows as $lr) {
            $licenseMap[$lr['id']] = $lr['prc_license'];
            $degreeMap[$lr['id']]  = $lr['degree'] ?? 'OD';
            $orderMap[$lr['id']]   = (int)($lr['sort_order'] ?? 0);
        }
    } catch (PDOException) { /* columns not yet migrated — skip */ }

    // Secondary credential (e.g. "Ocular Pharmacologist" + its own PRC
    // accreditation number) — its own try/catch, same reasoning as above,
    // so a database that has prc_license/degree but hasn't yet run this
    // newer migration still gets everything else instead of a 500.
    $secCredMap = [];
    $secPrcMap  = [];
    try {
        $sRows = $pdo->query('SELECT id, secondary_credential, secondary_prc FROM doctors')->fetchAll();
        foreach ($sRows as $sr) {
            $secCredMap[$sr['id']] = $sr['secondary_credential'] ?? '';
            $secPrcMap[$sr['id']]  = $sr['secondary_prc'] ?? '';
        }
    } catch (PDOException) { /* columns not yet migrated — skip */ }

    $dayHoursMap = doctorDayHoursMap($pdo);

    // Blocked dates — one-off unavailability, separate from the weekly pattern.
    // Only future/today dates are useful to the frontend calendars.
    $blockedMap = [];
    try {
        $bRows = $pdo->query(
            'SELECT doctor_id, date, reason FROM blocked_dates WHERE date >= CURDATE() ORDER BY date'
        )->fetchAll();
        foreach ($bRows as $br) {
            $blockedMap[$br['doctor_id']][] = ['date' => $br['date'], 'reason' => $br['reason'] ?? ''];
        }
    } catch (PDOException) { /* table not yet migrated — skip */ }

    // Clinic-wide closures show as a blocked date for every doctor, so all
    // calendars grey them out. Flagged clinicClosure so they can't be
    // unblocked per doctor (managed in Clinic Closures instead).
    $closures = upcomingClinicClosures($pdo);

    $doctors = array_map(fn($r) => [
        'id'             => $r['id'],
        'name'           => trim('Dr. ' . $r['first_name'] . _mi($r['middle_name'] ?? '') . ' ' . $r['last_name']),
        'firstName'      => $r['first_name'],
        'middleName'     => $r['middle_name'] ?? '',
        'lastName'       => $r['last_name'],
        'email'          => $r['email'] ?? '',
        'specialization' => $r['specialization'] ?: 'Optometrist',
        'degree'         => $degreeMap[$r['id']]  ?? 'OD',
        'prcLicense'     => $licenseMap[$r['id']] ?? '',
        'secondaryCredential' => $secCredMap[$r['id']] ?? '',
        'secondaryPrc'        => $secPrcMap[$r['id']]  ?? '',
        'sortOrder'      => $orderMap[$r['id']]   ?? 0,
        'workHours'      => $r['work_hours'] ?: '',
        'hours'          => $r['work_hours'] ?: '',
        'days'           => $r['days'] ? explode(',', $r['days']) : [],
        // Per-day hours (null day = clinic hours); `hours` above is now
        // just a display summary of these.
        'dayHours'       => (object)($dayHoursMap[$r['id']] ?? []),
        'availableDays'  => $r['days'] ? explode(',', $r['days']) : [],
        'available'      => (bool)$r['available'],
        'status'         => $r['status'],
        'contact'        => $r['contact'] ?? '',
        'photoUrl'       => $photoMap[(int)($r['user_id'] ?? 0)] ?? null,
        'blockedDates'   => (function () use ($blockedMap, $closures, $r) {
            $list = array_values(array_filter($blockedMap[$r['id']] ?? [], fn($b) => !isset($closures[$b['date']])));
            foreach ($closures as $cd => $cr) $list[] = ['date' => $cd, 'reason' => 'Clinic closed' . ($cr ? ': ' . $cr : ''), 'clinicClosure' => true, 'closureReason' => $cr];
            usort($list, fn($a, $b) => strcmp($a['date'], $b['date']));
            return $list;
        })(),
    ], $rows);

    jsonResponse(['success' => true, 'doctors' => $doctors]);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'doctors' => []], 500);
}
