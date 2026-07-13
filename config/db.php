<?php
/**
 * MySQL connection (PDO). Adjust these constants for your environment,
 * or set matching environment variables before starting Apache/PHP.
 */
define('DB_HOST', getenv('UNO_DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('UNO_DB_NAME') ?: 'uno_game');
define('DB_USER', getenv('UNO_DB_USER') ?: 'root');
define('DB_PASS', getenv('UNO_DB_PASS') ?: '');

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
