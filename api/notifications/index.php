<?php
// ================================================================
//  CANAOPTICALCLINIC — api/notifications/index.php
//  GET → returns notifications for the current user
//  Query params:
//    ?limit=N  (default 20; pass 0 for all)
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

// Answered as a 200 with authenticated:false rather than a 401 — this is
// polled every 30s, and a poll that lands during logout/login (or after a
// remote sign-out) would otherwise print a red "Failed to load resource"
// error in the browser console. The client (auth.js _syncNotifications)
// reads authenticated:false as the same "session ended" signal.
if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'authenticated' => false, 'message' => 'Not authenticated.'], 200);
}

$userId = (int)$_SESSION['user_id'];
$role   = $_SESSION['role'] ?? '';
$limit  = (int)($_GET['limit'] ?? 20);

try {
    $pdo = getDB();

    // Patients never receive contact_message notifications — replies are
    // delivered by email (reply.php sends via PHPMailer). Showing them
    // in-app would link to the admin-only contact-messages page.
    $excludeType = ($role === 'patient') ? " AND type != 'contact_message'" : '';

    $sql  = "SELECT * FROM notifications WHERE user_id = ?{$excludeType} ORDER BY created_at DESC";
    if ($limit > 0) $sql .= ' LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    $notifications = array_map(function (array $r): array {
        return [
            'id'        => (int)$r['id'],
            'type'      => $r['type'],
            'title'     => $r['title'],
            'body'      => $r['body'],
            'relatedId' => $r['related_id'] ?? null,
            'isRead'    => (bool)$r['is_read'],
            'createdAt' => $r['created_at'],
        ];
    }, $rows);

    $unreadCount = count(array_filter($notifications, fn($n) => !$n['isRead']));

    jsonResponse([
        'success'      => true,
        'notifications' => $notifications,
        'unread_count'  => $unreadCount,
    ]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error.'], 500);
}
