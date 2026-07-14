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
        ensure_schema($pdo);
    }
    return $pdo;
}

/**
 * Idempotently creates the rooms/players/events tables if they don't exist
 * yet, so a fresh deploy works the first time an API endpoint runs without a
 * separate manual `sql/schema.sql` import step. Safe to call on every
 * request: CREATE TABLE IF NOT EXISTS is a no-op once the tables are there.
 */
function ensure_schema(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(6) NOT NULL UNIQUE,
        host_session_id VARCHAR(64) NOT NULL,
        status ENUM('lobby','playing','finished') NOT NULL DEFAULT 'lobby',
        target_score INT NOT NULL DEFAULT 500,
        round_number INT NOT NULL DEFAULT 0,
        game_state LONGTEXT NULL,
        overall_winner_session_id VARCHAR(64) NULL,
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS players (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_id INT NOT NULL,
        session_id VARCHAR(64) NOT NULL,
        name VARCHAR(20) NOT NULL,
        color VARCHAR(7) NOT NULL DEFAULT '#e63946',
        role ENUM('player','spectator') NOT NULL DEFAULT 'player',
        score INT NOT NULL DEFAULT 0,
        seat_order INT NOT NULL DEFAULT 0,
        joined_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        last_seen DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        UNIQUE KEY uniq_room_session (room_id, session_id),
        CONSTRAINT fk_players_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        room_id INT NOT NULL,
        type VARCHAR(32) NOT NULL,
        target_session_id VARCHAR(64) NULL,
        payload TEXT NOT NULL,
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        CONSTRAINT fk_events_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
        INDEX idx_room_event (room_id, id)
    ) ENGINE=InnoDB");

    $checked = true;
}
