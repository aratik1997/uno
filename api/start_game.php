<?php
require_once __DIR__ . '/../includes/util.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/xml_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') xml_ack(false, 'POST required.');

$sessionId = post_str('session_id', 64);
$code = strtoupper(post_str('code', 8));
if (!valid_session_id($sessionId) || !valid_room_code($code)) xml_ack(false, 'Invalid request.');

$r = rules();

$result = with_room_lock($code, function (PDO $pdo, array $room) use ($sessionId, $r) {
    if ($room['host_session_id'] !== $sessionId) {
        return ['ok' => false, 'error' => 'Only the host can start the game.'];
    }
    if ($room['status'] !== 'lobby') {
        return ['ok' => false, 'error' => 'Game already started.'];
    }
    $players = get_players($pdo, (int)$room['id']);
    $activeCount = count(array_filter($players, fn($p) => $p['role'] === 'player'));
    if ($activeCount < $r['minPlayers'] || $activeCount > $r['maxPlayers']) {
        return ['ok' => false, 'error' => "Need {$r['minPlayers']}-{$r['maxPlayers']} players to start."];
    }

    $pdo->prepare('UPDATE rooms SET status = ? WHERE id = ?')->execute(['playing', $room['id']]);
    $room['status'] = 'playing';
    start_next_round($pdo, $room);
    insert_event($pdo, (int)$room['id'], 'system', ['message' => 'The game has started!']);
    return ['ok' => true];
});

if ($result === null) xml_ack(false, 'Room not found.');
if (!$result['ok']) xml_ack(false, $result['error']);
xml_ack(true);
