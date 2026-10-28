<?php
/**
 * The three things a player can do on their turn: play a card, draw, or pass. Human requests (the
 * play_card / draw_card / pass_turn endpoints) and bot turns both go through these, so a bot's move
 * writes the same events and scores as a person's would.
 *
 * Callers must already hold the room lock (with_room_lock) and have run resolve_pending_timeout().
 */

/** @return array{ok:bool,error?:string} */
function perform_play(PDO $pdo, array $room, array &$state, string $sessionId, string $cardId, ?string $color): array {
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
}

/** @return array{ok:bool,error?:string,result?:array} */
function perform_draw(PDO $pdo, array $room, array &$state, string $sessionId): array {
    $res = engine_draw_card($state, $sessionId);
    if (!$res['ok']) return $res;
    save_state($pdo, (int)$room['id'], $state);

    $players = get_players($pdo, (int)$room['id']);
    $playerName = '???';
    foreach ($players as $p) if ($p['session_id'] === $sessionId) { $playerName = $p['name']; break; }
    insert_event($pdo, (int)$room['id'], 'card_drawn', ['sessionId' => $sessionId, 'name' => $playerName, 'count' => $res['drawn']]);

    return ['ok' => true, 'result' => $res];
}

/** @return array{ok:bool,error?:string} */
function perform_pass(PDO $pdo, array $room, array &$state, string $sessionId): array {
    $res = engine_pass_turn($state, $sessionId);
    if (!$res['ok']) return $res;
    save_state($pdo, (int)$room['id'], $state);
    return ['ok' => true];
}
