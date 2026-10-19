<?php
// ================================================================
//  CANAOPTICALCLINIC — api/clinic/gallery.php
//  GET (public)   — list {id, caption, filename, sortOrder}
//  POST (admin)   — upload {imageData: base64DataUrl, caption}
//  DELETE (admin) — remove {id}
//  PATCH (admin)  — reorder {order: [id,...]}
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

startSession();

define('GALLERY_DIR', __DIR__ . '/../../assets/images/about/');

try {
    $pdo = getDB();

    // ── GET — public ───────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $maxPhotos = 0;
        try {
            $cs = $pdo->query('SELECT gallery_max_photos FROM clinic_settings WHERE id = 1 LIMIT 1')->fetch();
            $maxPhotos = $cs ? max(0, (int)($cs['gallery_max_photos'] ?? 0)) : 0;
        } catch (PDOException $e) {}

        $rows = [];
        try {
            $isAdmin = isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin';
            $sql = 'SELECT id, caption, filename, sort_order FROM about_gallery ORDER BY sort_order ASC, id ASC';
            if ($maxPhotos > 0 && !$isAdmin) $sql .= ' LIMIT ' . $maxPhotos;
            $rows = $pdo->query($sql)->fetchAll();
        } catch (PDOException $e) {}

        jsonResponse(['success' => true,
            'maxPhotos' => $maxPhotos ?: null,
            'images'    => array_map(fn($r) => [
                'id'        => (int)$r['id'],
                'caption'   => $r['caption'],
                'sortOrder' => (int)$r['sort_order'],
                'filename'  => $r['filename'],
            ], $rows),
        ]);
    }

    // ── POST / DELETE / PATCH — admin only ────────────────────
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
    }
    if (($_SESSION['role'] ?? '') !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Admin only.'], 403);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $b         = getBody();
        $dataUrl   = $b['imageData'] ?? '';
        $caption   = trim($b['caption'] ?? '');
        $sortOrder = (int)($b['sortOrder'] ?? 0);

        if (!preg_match('/^data:([\w\/\-\+]+);base64,(.+)$/s', $dataUrl, $m)) {
            jsonResponse(['success' => false, 'message' => 'Invalid file data.']);
        }
        // The gallery's photo count (Clinic Information > Gallery > Show
        // max) — the page also checks, but several photos can be sent at once.
        try {
            $cap = (int)($pdo->query('SELECT gallery_max_photos FROM clinic_settings WHERE id = 1')->fetchColumn() ?: 0);
            if ($cap > 0 && (int)$pdo->query('SELECT COUNT(*) FROM about_gallery')->fetchColumn() >= $cap) {
                jsonResponse(['success' => false, 'message' => "The gallery is full ($cap photos). Remove a photo or raise the limit first."]);
            }
        } catch (PDOException $e) {}
        // The browser resizes photos to 1600 px first, so 2 MB is plenty;
        // checked before decoding (base64 is 4 characters per 3 bytes).
        $tooBig = 'That photo is too large. The limit is ' . uploadSizeLabel(UPLOAD_MAX_GALLERY) . '.';
        if (strlen($m[2]) > UPLOAD_MAX_GALLERY / 3 * 4 + 4) jsonResponse(['success' => false, 'message' => $tooBig]);

        $binary = base64_decode($m[2], true);
        if ($binary === false) {
            jsonResponse(['success' => false, 'message' => 'Could not decode file.']);
        }
        if (strlen($binary) > UPLOAD_MAX_GALLERY) jsonResponse(['success' => false, 'message' => $tooBig]);

        // The type the content really has, not the one the request claims.
        $extMap   = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($binary) ?: '';
        if (!isset($extMap[$mimeType])) {
            jsonResponse(['success' => false, 'message' => 'Only PNG or JPG files are accepted.']);
        }

        if (!is_dir(GALLERY_DIR)) mkdir(GALLERY_DIR, 0755, true);

        $filename = 'photo_' . uniqid('', true) . '.' . $extMap[$mimeType];
        if (file_put_contents(GALLERY_DIR . $filename, $binary) === false) {
            jsonResponse(['success' => false, 'message' => 'Could not save file.']);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO about_gallery (caption, filename, sort_order) VALUES (?, ?, ?)'
        );
        $stmt->execute([$caption, $filename, $sortOrder]);

        jsonResponse(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'filename' => $filename]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $id = (int)(getBody()['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'message' => 'ID required.']);

        $row = $pdo->prepare('SELECT filename FROM about_gallery WHERE id = ?');
        $row->execute([$id]);
        $photo = $row->fetch();

        $pdo->prepare('DELETE FROM about_gallery WHERE id = ?')->execute([$id]);

        if ($photo && $photo['filename']) {
            $path = GALLERY_DIR . basename($photo['filename']);
            if (file_exists($path)) unlink($path);
        }

        jsonResponse(['success' => true]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $order = getBody()['order'] ?? [];
        if (!is_array($order)) jsonResponse(['success' => false, 'message' => 'order must be an array.']);
        $stmt = $pdo->prepare('UPDATE about_gallery SET sort_order = ? WHERE id = ?');
        foreach (array_values($order) as $i => $id) {
            $stmt->execute([$i, (int)$id]);
        }
        jsonResponse(['success' => true]);
    }

    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error.'], 500);
}
