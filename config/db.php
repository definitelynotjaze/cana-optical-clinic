<?php

require_once __DIR__ . '/env.php';

// Database configuration from Railway environment variables (or, on hosts
// without env-var injection like Hostinger, from the root .env file above)
define('DB_NAME', getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE') ?: 'canaopticalclinic_db');
define('DB_HOST', getenv('MYSQLHOST') ?: 'localhost');
define('DB_USER', getenv('MYSQLUSER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: '');
define('DB_PORT', getenv('MYSQLPORT') ?: '3306');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        // Let PDOException propagate — every caller already wraps getDB()
        // in try/catch (PDOException) to return a clean JSON error response.
        // Dying here would print plain text and break res.json() on the frontend.
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Match MySQL's session clock to the clinic's local time (Philippines)
        // so NOW()/CURRENT_TIMESTAMP line up with PHP's date_default_timezone_set()
        // in helpers.php — otherwise timestamps stored by the DB (e.g. on
        // CURRENT_TIMESTAMP defaults) drift from what the app displays.
        $pdo->exec("SET time_zone = '+08:00'");

        // Talk to MySQL in the same collation every table uses. Hosts differ
        // in their server default (Hostinger: utf8mb4_general_ci, XAMPP:
        // utf8mb4_unicode_ci); when the connection's default doesn't match
        // the tables', any statement mixing a bound value with a column
        // (e.g. COALESCE/NULLIF) fails with "Illegal mix of collations".
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    return $pdo;
}
