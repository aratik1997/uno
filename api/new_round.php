<?php
/**
 * Host control: abandons the round in progress (no scoring for it — hands
 * are simply discarded) and immediately deals a fresh round, reshuffling
 * turn order exactly like a normal round transition would.
 */
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
        return ['ok' => false, 'error' => 'Only the host can start a new round.'];
    }
    if ($room['status'] !== 'playing') {
        return ['ok' => false, 'error' => 'Game is not in progress.'];
    }

    $players = get_players($pdo, (int)$room['id']);
    $activeCount = count(array_filter($players, fn($p) => $p['role'] === 'player'));
    if ($activeCount < $r['minPlayers']) {
        return ['ok' => false, 'error' => "Need at least {$r['minPlayers']} players to start a new round."];
    }

    $hostName = '';
    foreach ($players as $p) if ($p['session_id'] === $sessionId) { $hostName = $p['name']; break; }

    start_next_round($pdo, $room);
    insert_event($pdo, (int)$room['id'], 'system', ['message' => "{$hostName} cancelled the round — dealing a new one."]);

    return ['ok' => true];
});

if ($result === null) xml_ack(false, 'Room not found.');
if (!$result['ok']) xml_ack(false, $result['error']);
xml_ack(true);
