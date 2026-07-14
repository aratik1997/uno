<?php
/**
 * MySQL connection (PDO). Adjust these constants for your environment,
 * or set matching environment variables before starting Apache/PHP.
 *
 * On hosts where per-app environment variables aren't available, drop a
 * db.local.php next to this file (gitignored — never commit real
 * credentials) defining the same UNO_DB_* constants; see db.local.php.example.
 */
if (is_file(__DIR__ . '/db.local.php')) {
    require __DIR__ . '/db.local.php';
}

define('DB_HOST', getenv('UNO_DB_HOST') ?: (defined('UNO_DB_HOST') ? UNO_DB_HOST : '127.0.0.1'));
define('DB_NAME', getenv('UNO_DB_NAME') ?: (defined('UNO_DB_NAME') ? UNO_DB_NAME : 'uno_game'));
define('DB_USER', getenv('UNO_DB_USER') ?: (defined('UNO_DB_USER') ? UNO_DB_USER : 'root'));
define('DB_PASS', getenv('UNO_DB_PASS') ?: (defined('UNO_DB_PASS') ? UNO_DB_PASS : ''));

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
