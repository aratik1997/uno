<?php
require_once __DIR__ . '/../includes/util.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/xml_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') xml_ack(false, 'POST required.');

$sessionId = post_str('session_id', 64);
$code = strtoupper(post_str('code', 8));
$cardId = post_str('card_id', 40);
$colorRaw = post_str('color', 10);
$color = $colorRaw !== '' ? $colorRaw : null;

if (!valid_session_id($sessionId) || !valid_room_code($code) || $cardId === '') {
    xml_ack(false, 'Invalid request.');
}

$result = with_room_lock($code, function (PDO $pdo, array $room) use ($sessionId, $cardId, $color) {
    if ($room['status'] !== 'playing') return ['ok' => false, 'error' => 'Game is not in progress.'];
    $state = decode_state($room);
    if ($state === null) return ['ok' => false, 'error' => 'No active round.'];

    resolve_pending_timeout($pdo, $room, $state);

    $res = engine_play_card($state, $sessionId, $cardId, $color);
    if (!$res['ok']) return $res;

    $players = get_players($pdo, (int)$room['id']);
    $playerName = '???';
    foreach ($players as $p) if ($p['session_id'] === $sessionId) { $playerName = $p['name']; break; }

    insert_event($pdo, (int)$room['id'], 'card_played', ['sessionId' => $sessionId, 'name' => $playerName]);
    if (!empty($res['unoPlayerId'])) {
        insert_event($pdo, (int)$room['id'], 'uno', ['sessionId' => $res['unoPlayerId'], 'name' => $playerName]);
    }

    if (!empty($res['roundEnded'])) {
        $breakdown = engine_end_round($state, $res['winnerId']);
        save_state($pdo, (int)$room['id'], $state);
        $summary = apply_round_scores($pdo, $room, $breakdown, $res['winnerId']);

        insert_event($pdo, (int)$room['id'], 'round_ended', [
            'winnerName' => $summary['winnerName'],
            'breakdown' => $summary['breakdown'],
            'finished' => $summary['finished'],
        ]);

        if ($summary['finished']) {
            insert_event($pdo, (int)$room['id'], 'game_over', [
                'overallWinnerName' => $summary['overallWinner']['name'],
                'scoreboard' => $summary['scoreboard'],
            ]);
        }
        // The next poll from any client will notice roundActive=false and,
        // once the pause has elapsed, will advance the room to the next round.
    } else {
        save_state($pdo, (int)$room['id'], $state);
    }

    return ['ok' => true];
});

if ($result === null) xml_ack(false, 'Room not found.');
if (!$result['ok']) xml_ack(false, $result['error']);
xml_ack(true);
