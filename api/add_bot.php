<?php
require_once __DIR__ . '/../includes/util.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/bots.php';
require_once __DIR__ . '/../includes/xml_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') xml_ack(false, 'POST required.');

$sessionId = post_str('session_id', 64);
$code = strtoupper(post_str('code', 8));
$difficulty = strtolower(post_str('difficulty', 6));

if (!valid_session_id($sessionId) || !valid_room_code($code)) xml_ack(false, 'Invalid request.');
if (!in_array($difficulty, BOT_DIFFICULTIES, true)) xml_ack(false, 'Bot difficulty must be easy, medium or hard.');

$r = rules();

$result = with_room_lock($code, function (PDO $pdo, array $room) use ($sessionId, $difficulty, $r) {
    if ($room['host_session_id'] !== $sessionId) {
        return ['ok' => false, 'error' => 'Only the host can add bots.'];
    }
    if ($room['status'] !== 'lobby') {
        return ['ok' => false, 'error' => 'Bots can only be added before the game starts.'];
    }

    $players = get_players($pdo, (int)$room['id']);
    $activeCount = count(array_filter($players, fn($p) => $p['role'] === 'player'));
    if ($activeCount >= $r['maxPlayers']) {
        return ['ok' => false, 'error' => 'Room is full.'];
    }

    // The first "Bot N" name nobody in the room is using.
    $taken = array_map(fn($p) => mb_strtolower($p['name']), $players);
    $number = 1;
    while (in_array(mb_strtolower("Bot {$number}"), $taken, true)) {
        $number++;
    }
    $name = "Bot {$number}";

    $botSessionId = 'bot_' . bin2hex(random_bytes(8));
    $color = next_avatar_color($players);
    $pdo->prepare('INSERT INTO players (room_id, session_id, name, color, role, seat_order, is_bot, bot_difficulty) VALUES (?,?,?,?,?,?,1,?)')
        ->execute([$room['id'], $botSessionId, $name, $color, 'player', count($players), $difficulty]);

    insert_event($pdo, (int)$room['id'], 'system', ['message' => "{$name} joined."]);

    return ['ok' => true];
});

if ($result === null) xml_ack(false, 'Room not found.');
if (!$result['ok']) xml_ack(false, $result['error']);
xml_ack(true);
