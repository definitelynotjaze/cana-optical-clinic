<?php
// ================================================================
//  CANAOPTICALCLINIC — api/doctors/public.php
//  GET — public endpoint, no auth required.
//  Returns active doctors with schedule and photo URL.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $pdo = getDB();

    $rows = $pdo->query(
        'SELECT d.id, d.first_name, d.middle_name, d.last_name, d.specialization, d.work_hours,
                d.available, d.status, u.id AS user_id,
                GROUP_CONCAT(dd.day_of_week
                    ORDER BY FIELD(dd.day_of_week,"Mon","Tue","Wed","Thu","Fri","Sat","Sun")
                    SEPARATOR ","
                ) AS days
         FROM doctors d
         JOIN users u ON u.id = d.user_id
         LEFT JOIN doctor_days dd ON dd.doctor_id = d.id
         WHERE d.status = "active" AND u.is_active = 1
         GROUP BY d.id, d.first_name, d.middle_name, d.last_name, d.specialization, d.work_hours,
                  d.available, d.status, u.id
         ORDER BY d.sort_order ASC, d.first_name ASC'
    )->fetchAll();

    // Fetch photos separately so a missing column never breaks this endpoint
    $photoMap = [];
    try {
        $userIds = array_column($rows, 'user_id');
        if ($userIds) {
            $in   = implode(',', array_map('intval', $userIds));
            $prs  = $pdo->query("SELECT id, photo_url FROM users WHERE id IN ($in)")->fetchAll();
            foreach ($prs as $pr) { $photoMap[(int)$pr['id']] = $pr['photo_url']; }
        }
    } catch (PDOException) { /* photo_url column not yet migrated — skip */ }

    // Fetch PRC license, degree, and sort_order separately so missing columns never break this endpoint
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

    // Blocked dates — so the patient booking calendar can grey these out too.
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

    $dayHoursMap = doctorDayHoursMap($pdo);

    $seen    = [];
    $doctors = [];
    foreach ($rows as $r) {
        $key = strtolower($r['first_name'] . ' ' . ($r['middle_name'] ?? '') . ' ' . $r['last_name']);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $doctors[] = [
            'id'             => $r['id'],
            'name'           => trim('Dr. ' . $r['first_name'] . _mi($r['middle_name'] ?? '') . ' ' . $r['last_name']),
            'firstName'      => $r['first_name'],
            'middleName'     => $r['middle_name'] ?? '',
            'lastName'       => $r['last_name'],
            'specialization' => $r['specialization'] ?: 'Optometrist',
            'degree'         => $degreeMap[$r['id']]  ?? 'OD',
            'prcLicense'     => $licenseMap[$r['id']] ?? '',
            'sortOrder'      => $orderMap[$r['id']]   ?? 0,
            'workHours'      => $r['work_hours'] ?: '',
            'hours'          => $r['work_hours'] ?: '',
            'days'           => $r['days'] ? explode(',', $r['days']) : [],
            'availableDays'  => $r['days'] ? explode(',', $r['days']) : [],
            'dayHours'       => (object)($dayHoursMap[$r['id']] ?? []),
            'available'      => (bool)$r['available'],
            'status'         => $r['status'],
            'photoUrl'       => $photoMap[(int)$r['user_id']] ?? null,
            'blockedDates'   => (function () use ($blockedMap, $closures, $r) {
            $list = array_values(array_filter($blockedMap[$r['id']] ?? [], fn($b) => !isset($closures[$b['date']])));
            foreach ($closures as $cd => $cr) $list[] = ['date' => $cd, 'reason' => 'Clinic closed' . ($cr ? ': ' . $cr : ''), 'clinicClosure' => true, 'closureReason' => $cr];
            usort($list, fn($a, $b) => strcmp($a['date'], $b['date']));
            return $list;
        })(),
        ];
    }

    jsonResponse(['success' => true, 'doctors' => $doctors]);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'doctors' => []], 500);
}
