<?php
require_once __DIR__ . '/../includes/util.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/actions.php';
require_once __DIR__ . '/../includes/xml_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') xml_ack(false, 'POST required.');

$sessionId = post_str('session_id', 64);
$code = strtoupper(post_str('code', 8));
if (!valid_session_id($sessionId) || !valid_room_code($code)) xml_ack(false, 'Invalid request.');

$result = with_room_lock($code, function (PDO $pdo, array $room) use ($sessionId) {
    if ($room['status'] !== 'playing') return ['ok' => false, 'error' => 'Game is not in progress.'];
    $state = decode_state($room);
    if ($state === null) return ['ok' => false, 'error' => 'No active round.'];

    resolve_pending_timeout($pdo, $room, $state);

    return perform_pass($pdo, $room, $state, $sessionId);
});

if ($result === null) xml_ack(false, 'Room not found.');
if (!$result['ok']) xml_ack(false, $result['error']);
xml_ack(true);
