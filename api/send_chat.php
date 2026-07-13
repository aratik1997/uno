<?php
require_once __DIR__ . '/../includes/util.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/xml_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') xml_ack(false, 'POST required.');

$sessionId = post_str('session_id', 64);
$code = strtoupper(post_str('code', 8));
$message = post_str('message', 300);

if (!valid_session_id($sessionId) || !valid_room_code($code) || $message === '') {
    xml_ack(false, 'Invalid request.');
}

$result = with_room_lock($code, function (PDO $pdo, array $room) use ($sessionId, $message) {
    $player = get_player($pdo, (int)$room['id'], $sessionId);
    if (!$player) return ['ok' => false, 'error' => 'Not in this room.'];
    insert_event($pdo, (int)$room['id'], 'chat', [
        'from' => $player['name'],
        'color' => $player['color'],
        'message' => $message,
    ]);
    return ['ok' => true];
});

if ($result === null) xml_ack(false, 'Room not found.');
if (!$result['ok']) xml_ack(false, $result['error']);
xml_ack(true);
