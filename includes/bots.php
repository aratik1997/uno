<?php
/**
 * Computer players for UNO. A bot is a players row with is_bot = 1 whose session_id starts with
 * "bot_". Bots play through the same actions a person uses (includes/actions.php), so every rule and
 * score is applied the same way.
 *
 * Difficulty only changes the choices:
 *  - easy:   plays a random legal card, and sometimes keeps a card it just drew instead of playing it.
 *  - medium: sheds its highest-value number cards first, keeps action and wild cards for later.
 *  - hard:   also weighs the colour it holds most of, and uses skip/reverse/draw-two aggressively
 *            when the next player is nearly out of cards.
 *
 * Bots run inside game_poll, under the room lock, so they never race a human action.
 */

const BOT_DIFFICULTIES = ['easy', 'medium', 'hard'];

/** How long a bot "thinks" before each move, in milliseconds (plus a fixed 0-999ms jitter). */
const BOT_DELAY_MS = ['easy' => 3000, 'medium' => 2000, 'hard' => 1000];

function bot_player_row(PDO $pdo, int $roomId, string $sessionId): ?array {
    $player = get_player($pdo, $roomId, $sessionId);
    return ($player && (int)$player['is_bot'] === 1) ? $player : null;
}

/** True once the bot's thinking time for the current turn has passed. */
function bot_due(array $state, string $difficulty): bool {
    $start = (int)($state['turnStartedAt'] ?? 0);
    $jitter = abs(crc32($start . ':' . engine_current_player($state))) % 1000;
    return now_ms() - $start >= BOT_DELAY_MS[$difficulty] + $jitter;
}

/** Colour a wild card should name: the colour the bot holds most of (ties broken at random). */
function bot_choose_color(array $hand, string $difficulty): string {
    $colors = rules()['colors'];
    if ($difficulty === 'easy') {
        return $colors[array_rand($colors)];
    }
    $counts = array_fill_keys($colors, 0);
    foreach ($hand as $c) {
        if (isset($counts[$c['color']])) $counts[$c['color']]++;
    }
    $most = max($counts);
    $best = array_keys(array_filter($counts, fn($n) => $n === $most));
    return $best[array_rand($best)];
}

/**
 * Decides a bot's move. Returns ['action' => 'play', 'cardId' => ..., 'color' => ?string],
 * ['action' => 'draw'] or ['action' => 'pass'].
 */
function bot_choose(array $state, string $sid, string $difficulty): array {
    $hand = $state['hands'][$sid] ?? [];

    // It just drew a card that can be played: play it (easy sometimes keeps it instead).
    if (!empty($state['drawnCardPendingId'])) {
        if ($difficulty === 'easy' && mt_rand(0, 1) === 0) {
            return ['action' => 'pass'];
        }
        foreach ($hand as $c) {
            if ($c['id'] === $state['drawnCardPendingId']) {
                return ['action' => 'play', 'cardId' => $c['id'], 'color' => bot_color_for($c, $hand, $difficulty)];
            }
        }
        return ['action' => 'pass'];
    }

    $playable = array_values(array_filter($hand, fn($c) => engine_is_playable($state, $sid, $c)));
    if (!$playable) {
        return ['action' => 'draw'];
    }

    $card = $difficulty === 'easy'
        ? $playable[array_rand($playable)]
        : bot_best_card($playable, $hand, $state, $sid, $difficulty);

    return ['action' => 'play', 'cardId' => $card['id'], 'color' => bot_color_for($card, $hand, $difficulty)];
}

function bot_color_for(array $card, array $hand, string $difficulty): ?string {
    if ($card['type'] !== 'wild' && $card['type'] !== 'wild4') return null;
    return bot_choose_color($hand, $difficulty);
}

/** Medium/hard pick by score: shed points first, save wild cards for when nothing else works. */
function bot_best_card(array $playable, array $hand, array $state, string $sid, string $difficulty): array {
    $n = count($state['turnOrder']);
    $nextSid = $state['turnOrder'][(($state['currentIndex'] + $state['direction']) % $n + $n) % $n] ?? null;
    $nextIsNearlyOut = $nextSid !== null && count($state['hands'][$nextSid] ?? []) <= 2;
    $colorCounts = array_count_values(array_column(array_filter($hand, fn($c) => $c['type'] === 'number'), 'color'));

    $score = function (array $c) use ($difficulty, $nextIsNearlyOut, $colorCounts): float {
        if ($c['type'] === 'number') {
            $s = 10 + (int)$c['value'];
            if ($difficulty === 'hard') $s += ($colorCounts[$c['color']] ?? 0) * 0.5;
            return $s;
        }
        if ($c['type'] === 'wild4') return 2;
        if ($c['type'] === 'wild') return 1;
        $s = $c['type'] === 'draw2' ? 9 : 8; // skip, reverse, draw2
        if ($difficulty === 'hard' && $nextIsNearlyOut) $s += 6;
        return $s;
    };

    $best = $playable[0];
    foreach ($playable as $c) {
        if ($score($c) > $score($best)) $best = $c;
    }
    return $best;
}

/**
 * Plays any bot turns that are due, one move at a time, until a human is up or a bot is still
 * thinking. Called from game_poll under the room lock. Saves the state through the action helpers.
 */
function run_bot_turns(PDO $pdo, array $room, array &$state): void {
    for ($i = 0; $i < 4; $i++) {
        if (empty($state['roundActive'])) return;
        $sid = engine_current_player($state);
        if ($sid === null) return;

        $bot = bot_player_row($pdo, (int)$room['id'], $sid);
        if ($bot === null) return; // a human's turn
        $difficulty = in_array($bot['bot_difficulty'], BOT_DIFFICULTIES, true) ? $bot['bot_difficulty'] : 'medium';
        if (!bot_due($state, $difficulty)) return;

        $choice = bot_choose($state, $sid, $difficulty);
        if ($choice['action'] === 'play') {
            $res = perform_play($pdo, $room, $state, $sid, $choice['cardId'], $choice['color']);
        } elseif ($choice['action'] === 'draw') {
            $res = perform_draw($pdo, $room, $state, $sid);
            // A drawn card that can be played keeps the turn; give the bot a beat before it decides.
            if (!empty($state['drawnCardPendingId'])) {
                $state['turnStartedAt'] = now_ms();
                save_state($pdo, (int)$room['id'], $state);
            }
        } else {
            $res = perform_pass($pdo, $room, $state, $sid);
        }
        if (empty($res['ok'])) return; // should not happen; bail rather than loop
    }
}
