<?php
require_once __DIR__ . '/../includes/util.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/xml_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') xml_ack(false, 'POST required.');

$sessionId = post_str('session_id', 64);
$name = post_str('name', 20) ?: 'Player';
$code = strtoupper(post_str('code', 8));

if (!valid_session_id($sessionId)) xml_ack(false, 'Invalid session.');
if (!valid_room_code($code)) xml_ack(false, 'Invalid room code.');

$r = rules();

$result = with_room_lock($code, function (PDO $pdo, array $room) use ($sessionId, $name, $r) {
    $existing = get_player($pdo, (int)$room['id'], $sessionId);
    if ($existing) {
        return ['ok' => true, 'spectator' => $existing['role'] === 'spectator'];
    }

    $players = get_players($pdo, (int)$room['id']);
    $activeCount = count(array_filter($players, fn($p) => $p['role'] === 'player'));

    if ($room['status'] === 'lobby' && $activeCount >= $r['maxPlayers']) {
        return ['ok' => false, 'error' => 'Room is full.'];
    }

    $role = ($room['status'] === 'lobby' && $activeCount < $r['maxPlayers']) ? 'player' : 'spectator';
    $color = next_avatar_color($players);
    $seat = count($players);

    $pdo->prepare('INSERT INTO players (room_id, session_id, name, color, role, seat_order) VALUES (?,?,?,?,?,?)')
        ->execute([$room['id'], $sessionId, $name, $color, $role, $seat]);

    insert_event($pdo, (int)$room['id'], 'system', ['message' => "{$name} joined."]);

    return ['ok' => true, 'spectator' => $role === 'spectator'];
});

if ($result === null) xml_ack(false, 'Room not found.');
if (!$result['ok']) xml_ack(false, $result['error']);
xml_ack(true, null, ['spectator' => $result['spectator'] ? '1' : '0']);
