<?php
// ================================================================
//  CANAOPTICALCLINIC — database/add_admin.php
//  One-time-use tool: creates a new admin account directly against the
//  database, without needing to already be logged in as one. Useful for
//  bootstrapping the very first admin on a fresh install, or recovering
//  access if the only admin account is ever locked out. Unlike a bootstrap
//  script, this does NOT refuse when an admin already exists — it just
//  adds another one.
//
//  Usage (CLI, recommended):
//    php add_admin.php you@yourdomain.com "A-Strong-Passw0rd!" "Your Name"
//
//  Usage (browser, if you don't have SSH access):
//    https://yourdomain.com/database/add_admin.php?email=...&password=...&name=...
//    (this folder is normally blocked from the web by database/.htaccess —
//    temporarily rename or remove that file to use this method, then
//    restore it immediately after)
//
//  DELETE THIS FILE once you've confirmed you can log in with the new
//  account — it's a standing way for anyone who can reach it to create
//  an admin account.
// ================================================================

require_once '../config/db.php';

$isCLI = (php_sapi_name() === 'cli');

if ($isCLI) {
    $email    = trim($argv[1] ?? '');
    $password = $argv[2] ?? '';
    $name     = trim($argv[3] ?? 'Admin');
} else {
    $email    = trim($_GET['email'] ?? '');
    $password = $_GET['password'] ?? '';
    $name     = trim($_GET['name'] ?? 'Admin');
}

function out(string $msg): void {
    echo (php_sapi_name() === 'cli') ? ($msg . "\n") : ('<p>' . htmlspecialchars($msg) . '</p>');
}

// Same policy the app itself enforces (validatePasswordPolicy(), api/helpers.php)
// — kept as an inline copy rather than requiring helpers.php, so this stays a
// single self-contained file with nothing else to load.
function passwordPolicyError(string $password): ?string {
    if (strlen($password) < 8)             return 'Password must be at least 8 characters.';
    if (!preg_match('/[a-z]/', $password)) return 'Password must include at least one lowercase letter.';
    if (!preg_match('/[A-Z]/', $password)) return 'Password must include at least one uppercase letter.';
    if (!preg_match('/[0-9]/', $password)) return 'Password must include at least one number.';
    if (!preg_match('/[^A-Za-z0-9]/', $password)) return 'Password must include at least one special character.';
    return null;
}

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    out('Usage: provide a valid email address.');
    exit(1);
}
if ($pwError = passwordPolicyError($password)) {
    out($pwError);
    exit(1);
}

try {
    $pdo = getDB();

    $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $dup->execute([$email]);
    if ($dup->fetchColumn()) {
        out('That email is already registered to another account.');
        exit(1);
    }

    $parts     = preg_split('/\s+/', $name, 2);
    $firstName = $parts[0] ?: 'Admin';
    $lastName  = $parts[1] ?? '';

    // Next sequential ADM### id — same convention api/users/create.php
    // itself uses for a normal "Add User" (Admin role), so an account made
    // here is indistinguishable from one made through the app.
    $lastId = $pdo->query("SELECT id FROM admins ORDER BY id DESC LIMIT 1")->fetchColumn();
    $next   = 1;
    if ($lastId && preg_match('/^ADM(\d+)$/i', $lastId, $m)) {
        $next = (int)$m[1] + 1;
    }
    $newId = 'ADM' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
    $dupId = $pdo->prepare('SELECT id FROM admins WHERE id = ?');
    while (true) {
        $dupId->execute([$newId]);
        if (!$dupId->fetch()) break;
        $next++;
        $newId = 'ADM' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
    }

    $pdo->beginTransaction();

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare(
        'INSERT INTO users (email, password_hash, role, is_active, email_verified) VALUES (?, ?, \'admin\', 1, 1)'
    )->execute([$email, $hash]);
    $userId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO admins (id, user_id, first_name, last_name, status) VALUES (?, ?, ?, ?, \'active\')'
    )->execute([$newId, $userId, $firstName, $lastName]);

    $pdo->commit();

    out("Admin account created: $email (id $newId)");
    out('You can now log in. Delete this file now — do not leave it on the server.');

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    out('Database error: ' . $e->getMessage());
    exit(1);
}
