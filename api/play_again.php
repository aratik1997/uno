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
        return ['ok' => false, 'error' => 'Only the host can restart.'];
    }
    $pdo->prepare('UPDATE rooms SET status = ?, game_state = NULL, round_number = 0, overall_winner_session_id = NULL WHERE id = ?')
        ->execute(['lobby', $room['id']]);
    $pdo->prepare('UPDATE players SET role = ?, score = 0 WHERE room_id = ?')->execute(['player', $room['id']]);

    // Cap back down to max players if the room was oversubscribed with spectators.
    $players = get_players($pdo, (int)$room['id']);
    $max = $r['maxPlayers'];
    foreach ($players as $i => $p) {
        if ($i >= $max) {
            $pdo->prepare('UPDATE players SET role = ? WHERE id = ?')->execute(['spectator', $p['id']]);
        }
    }
    return ['ok' => true];
});

if ($result === null) xml_ack(false, 'Room not found.');
if (!$result['ok']) xml_ack(false, $result['error']);
xml_ack(true);
