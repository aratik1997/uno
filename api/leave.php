<?php
require_once __DIR__ . '/../includes/util.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/xml_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') xml_ack(false, 'POST required.');

$sessionId = post_str('session_id', 64);
$code = strtoupper(post_str('code', 8));
if (!valid_session_id($sessionId) || !valid_room_code($code)) xml_ack(false, 'Invalid request.');

with_room_lock($code, function (PDO $pdo, array $room) use ($sessionId) {
    remove_player($pdo, $room, $sessionId, null);
    return true;
});

xml_ack(true);
