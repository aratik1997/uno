<?php
require_once __DIR__ . '/../includes/util.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/xml_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') xml_ack(false, 'POST required.');

$sessionId = post_str('session_id', 64);
$code = strtoupper(post_str('code', 8));
$targetSessionId = post_str('target_session_id', 64);

if (!valid_session_id($sessionId) || !valid_room_code($code) || !valid_session_id($targetSessionId)) {
    xml_ack(false, 'Invalid request.');
}
if ($sessionId === $targetSessionId) xml_ack(false, 'You cannot kick yourself.');

$result = with_room_lock($code, function (PDO $pdo, array $room) use ($sessionId, $targetSessionId) {
    if ($room['host_session_id'] !== $sessionId) {
        return ['ok' => false, 'error' => 'Only the host can kick players.'];
    }
    $target = get_player($pdo, (int)$room['id'], $targetSessionId);
    if (!$target) return ['ok' => false, 'error' => 'Player not found.'];

    $hostPlayer = get_player($pdo, (int)$room['id'], $sessionId);
    $hostName = $hostPlayer ? $hostPlayer['name'] : 'Host';
    remove_player($pdo, $room, $targetSessionId, $hostName);
    return ['ok' => true];
});

if ($result === null) xml_ack(false, 'Room not found.');
if (!$result['ok']) xml_ack(false, $result['error']);
xml_ack(true);
