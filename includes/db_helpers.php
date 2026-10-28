<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/game_engine.php';

// Generous relative to the ~1.2s client poll interval: browser tab
// throttling, phone screen locks, and brief network drops can all stall
// polling for well over 15s without the player actually being gone.
const PRESENCE_TIMEOUT_SECONDS = 45;
const AVATAR_COLORS = ['#e63946', '#f4a261', '#2a9d5c', '#3a6fd8', '#a663cc', '#ff6392', '#06b6d4', '#84cc16'];

function gen_room_code(PDO $pdo): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 5; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
        $stmt = $pdo->prepare('SELECT id FROM rooms WHERE code = ?');
        $stmt->execute([$code]);
    } while ($stmt->fetch());
    return $code;
}

/**
 * Runs $fn($pdo, $room) inside a transaction with the room row locked
 * (SELECT ... FOR UPDATE), so concurrent requests for the same room never
 * race on game_state. $room is the raw DB row (game_state still JSON text).
 * Commits on normal return, rolls back and rethrows on exception.
 */
function with_room_lock(string $code, callable $fn) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code = ? FOR UPDATE');
        $stmt->execute([$code]);
        $room = $stmt->fetch();
        if (!$room) {
            $pdo->rollBack();
            return null;
        }
        $result = $fn($pdo, $room);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function decode_state(array $room): ?array {
    if (empty($room['game_state'])) return null;
    return json_decode($room['game_state'], true);
}

function save_state(PDO $pdo, int $roomId, ?array $state): void {
    $pdo->prepare('UPDATE rooms SET game_state = ? WHERE id = ?')
        ->execute([$state === null ? null : json_encode($state), $roomId]);
}

function get_players(PDO $pdo, int $roomId): array {
    $stmt = $pdo->prepare('SELECT * FROM players WHERE room_id = ? ORDER BY seat_order ASC, id ASC');
    $stmt->execute([$roomId]);
    return $stmt->fetchAll();
}

function get_player(PDO $pdo, int $roomId, string $sessionId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM players WHERE room_id = ? AND session_id = ?');
    $stmt->execute([$roomId, $sessionId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function next_avatar_color(array $players): string {
    $used = array_column($players, 'color');
    foreach (AVATAR_COLORS as $c) {
        if (!in_array($c, $used, true)) return $c;
    }
    return AVATAR_COLORS[count($players) % count(AVATAR_COLORS)];
}

function insert_event(PDO $pdo, int $roomId, string $type, array $payload, ?string $targetSessionId = null): void {
    $pdo->prepare('INSERT INTO events (room_id, type, target_session_id, payload) VALUES (?,?,?,?)')
        ->execute([$roomId, $type, $targetSessionId, json_encode($payload)]);
}

function touch_last_seen(PDO $pdo, int $roomId, string $sessionId): void {
    $pdo->prepare('UPDATE players SET last_seen = CURRENT_TIMESTAMP(3) WHERE room_id = ? AND session_id = ?')
        ->execute([$roomId, $sessionId]);
}

/**
 * Lazily removes players who haven't polled/acted in a while (their tab was
 * closed, connection dropped, etc.) using the same path as an explicit
 * Leave — including being skipped out of an in-progress round.
 */
function reap_stale_players(PDO $pdo, array $room): array {
    $stmt = $pdo->prepare(
        'SELECT session_id FROM players WHERE room_id = ? AND is_bot = 0 AND last_seen < (NOW(3) - INTERVAL ? SECOND)'
    );
    $stmt->execute([$room['id'], PRESENCE_TIMEOUT_SECONDS]);
    $stale = array_column($stmt->fetchAll(), 'session_id');
    foreach ($stale as $sid) {
        $room = remove_player($pdo, $room, $sid, null);
    }
    return $room;
}

/**
 * Removes a player from a room: deletes their row, drops them from an
 * in-progress round (their cards return to the draw pile so counts stay
 * correct), reassigns host if needed, and logs a chat/system event.
 * Pass $kickedByName to indicate this was a kick rather than a normal leave.
 */
function remove_player(PDO $pdo, array $room, string $sessionId, ?string $kickedByName): array {
    $player = get_player($pdo, (int)$room['id'], $sessionId);
    if (!$player) return $room;

    $state = decode_state($room);
    if ($state && !empty($state['roundActive']) && in_array($sessionId, $state['turnOrder'], true)) {
        engine_remove_from_round($state, $sessionId);
        save_state($pdo, (int)$room['id'], $state);
        if (empty($state['roundActive'])) {
            // Everyone else left too; nothing left to finish.
        }
    }

    $pdo->prepare('DELETE FROM players WHERE id = ?')->execute([$player['id']]);

    if ($kickedByName !== null) {
        insert_event($pdo, (int)$room['id'], 'kicked', ['byName' => $kickedByName, 'name' => $player['name']], $sessionId);
        insert_event($pdo, (int)$room['id'], 'system', ['message' => "{$kickedByName} kicked {$player['name']}."]);
    } else {
        insert_event($pdo, (int)$room['id'], 'system', ['message' => "{$player['name']} left."]);
    }

    // Reassign host if the host left/was kicked.
    if ($room['host_session_id'] === $sessionId) {
        // Bots can't host, so the new host is the first human left. With no humans left, the room is over.
        $humans = array_values(array_filter(get_players($pdo, (int)$room['id']), fn($p) => !(int)$p['is_bot']));
        $newHost = $humans[0]['session_id'] ?? null;
        $pdo->prepare('UPDATE rooms SET host_session_id = ? WHERE id = ?')->execute([$newHost, $room['id']]);
        $room['host_session_id'] = $newHost;
        if ($newHost !== null) {
            $newHostName = $humans[0]['name'];
            insert_event($pdo, (int)$room['id'], 'system', ['message' => "{$newHostName} is now the host."]);
        } else {
            $pdo->prepare('UPDATE rooms SET status = ? WHERE id = ?')->execute(['finished', $room['id']]);
            $room['status'] = 'finished';
        }
    }

    $activeCount = count(array_filter(get_players($pdo, (int)$room['id']), fn($p) => $p['role'] === 'player'));
    if ($room['status'] === 'playing' && $activeCount < rules()['minPlayers']) {
        $pdo->prepare('UPDATE rooms SET status = ? WHERE id = ?')->execute(['finished', $room['id']]);
        $room['status'] = 'finished';
        insert_event($pdo, (int)$room['id'], 'not_enough_players', []);
    }

    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE id = ?');
    $stmt->execute([$room['id']]);
    return $stmt->fetch();
}

/** Applies a round's scoring breakdown to players.score and checks for a game-ending target-score reach. */
function apply_round_scores(PDO $pdo, array $room, array $breakdown, string $winnerSessionId): array {
    foreach ($breakdown as $row) {
        if ($row['pointsAdded'] > 0) {
            $pdo->prepare('UPDATE players SET score = score + ? WHERE room_id = ? AND session_id = ?')
                ->execute([$row['pointsAdded'], $room['id'], $row['sessionId']]);
        }
    }

    $players = get_players($pdo, (int)$room['id']);
    $activePlayers = array_values(array_filter($players, fn($p) => $p['role'] === 'player'));

    $target = (int)$room['target_score'];
    $finished = false;
    foreach ($activePlayers as $p) {
        if ((int)$p['score'] >= $target) { $finished = true; break; }
    }

    $winnerName = null;
    foreach ($players as $p) {
        if ($p['session_id'] === $winnerSessionId) { $winnerName = $p['name']; break; }
    }

    $namedBreakdown = array_map(function ($row) use ($players) {
        $name = '???';
        foreach ($players as $p) {
            if ($p['session_id'] === $row['sessionId']) { $name = $p['name']; break; }
        }
        return $row + ['name' => $name];
    }, $breakdown);

    $overallWinner = null;
    if ($finished) {
        $lowest = null;
        foreach ($activePlayers as $p) {
            if ($lowest === null || (int)$p['score'] < (int)$lowest['score']) $lowest = $p;
        }
        $overallWinner = $lowest;
        $pdo->prepare('UPDATE rooms SET status = ?, overall_winner_session_id = ? WHERE id = ?')
            ->execute(['finished', $overallWinner['session_id'], $room['id']]);
    }

    return [
        'winnerName' => $winnerName,
        'breakdown' => $namedBreakdown,
        'finished' => $finished,
        'overallWinner' => $overallWinner,
        'scoreboard' => array_map(fn($p) => ['sessionId' => $p['session_id'], 'name' => $p['name'], 'score' => (int)$p['score']], $activePlayers),
    ];
}

/**
 * Resolves an expired 30s turn clock, if any, before a new action or poll is
 * processed. Any connected client's request can trigger this — it doesn't
 * have to be the timed-out player's — so a stalled player never blocks the
 * table for longer than one poll interval past their deadline.
 */
function resolve_pending_timeout(PDO $pdo, array $room, array &$state): void {
    $players = get_players($pdo, (int)$room['id']);
    $nameOf = function (string $sid) use ($players): string {
        foreach ($players as $p) if ($p['session_id'] === $sid) return $p['name'];
        return 'A player';
    };

    // A single stale turn could theoretically chain into another timeout
    // (e.g. forced draw immediately followed by another idle player); resolve
    // a small bounded number of them per request rather than looping forever.
    for ($i = 0; $i < 4; $i++) {
        $timeout = engine_check_timeout($state);
        if ($timeout === null) break;

        $name = $nameOf($timeout['sessionId']);
        $r = $timeout['result'];
        if (!empty($r['forced'])) {
            insert_event($pdo, (int)$room['id'], 'system', ['message' => "{$name} ran out of time and drew {$r['drawn']} cards."]);
        } elseif (!empty($r['passed']) && ($r['drawn'] ?? 0) > 0) {
            insert_event($pdo, (int)$room['id'], 'system', ['message' => "{$name} ran out of time, drew a card, and passed."]);
        } else {
            insert_event($pdo, (int)$room['id'], 'system', ['message' => "{$name} ran out of time and passed."]);
        }
    }

    save_state($pdo, (int)$room['id'], $state);
}

const ROUND_PAUSE_MS = 8000;

/**
 * If the round ended a while ago and the game itself hasn't finished, moves
 * the room on to the next round. Lazily triggered by polling — there is no
 * background process, so whichever client polls first past the pause window
 * advances everyone.
 */
function maybe_advance_round(PDO $pdo, array $room): array {
    if ($room['status'] !== 'playing') return $room;
    $state = decode_state($room);
    if ($state === null || !empty($state['roundActive'])) return $room;
    $endedAt = $state['roundEndedAt'] ?? 0;
    if (now_ms() - $endedAt < ROUND_PAUSE_MS) return $room;

    $players = get_players($pdo, (int)$room['id']);
    $activeCount = count(array_filter($players, fn($p) => $p['role'] === 'player'));
    if ($activeCount < rules()['minPlayers']) {
        $pdo->prepare('UPDATE rooms SET status = ? WHERE id = ?')->execute(['finished', $room['id']]);
        insert_event($pdo, (int)$room['id'], 'not_enough_players', []);
        $room['status'] = 'finished';
        return $room;
    }

    start_next_round($pdo, $room);
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE id = ?');
    $stmt->execute([$room['id']]);
    return $stmt->fetch();
}

/** Promotes waiting spectators (capacity permitting), reshuffles turn order, and starts the next round. */
function start_next_round(PDO $pdo, array $room): array {
    $r = rules();
    $players = get_players($pdo, (int)$room['id']);
    $activeIds = array_column(array_filter($players, fn($p) => $p['role'] === 'player'), 'session_id');
    $spectators = array_filter($players, fn($p) => $p['role'] === 'spectator');

    $slots = $r['maxPlayers'] - count($activeIds);
    foreach ($spectators as $s) {
        if ($slots <= 0) break;
        $pdo->prepare('UPDATE players SET role = ? WHERE id = ?')->execute(['player', $s['id']]);
        $activeIds[] = $s['session_id'];
        $slots--;
    }

    shuffle($activeIds);
    $roundNumber = ((int)$room['round_number']) + 1;
    $state = engine_start_round($activeIds, $roundNumber);
    save_state($pdo, (int)$room['id'], $state);
    $pdo->prepare('UPDATE rooms SET round_number = ? WHERE id = ?')->execute([$roundNumber, $room['id']]);

    return $state;
}
