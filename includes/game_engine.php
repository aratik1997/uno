<?php
/**
 * Authoritative UNO round/turn engine. All functions operate on a plain
 * associative array ($state) that is JSON-encoded into rooms.game_state
 * between requests — there is no long-lived process, so every request
 * decodes state, mutates it, and saves it back inside a DB transaction.
 */
require_once __DIR__ . '/deck.php';
require_once __DIR__ . '/rules.php';

function now_ms(): int {
    return (int) round(microtime(true) * 1000);
}

function engine_current_player(array $state): ?string {
    if (empty($state['turnOrder'])) return null;
    return $state['turnOrder'][$state['currentIndex']] ?? null;
}

function engine_top_card(array $state): ?array {
    $n = count($state['discardPile']);
    return $n > 0 ? $state['discardPile'][$n - 1] : null;
}

function engine_reshuffle_if_needed(array &$state, int $minCards = 1): void {
    while (count($state['drawPile']) < $minCards) {
        if (count($state['discardPile']) <= 1) break;
        $top = array_pop($state['discardPile']);
        $rest = shuffle_cards($state['discardPile']);
        $state['drawPile'] = array_merge($state['drawPile'], $rest);
        $state['discardPile'] = [$top];
    }
}

function engine_draw_from_pile(array &$state, int $count): array {
    $drawn = [];
    for ($i = 0; $i < $count; $i++) {
        engine_reshuffle_if_needed($state, 1);
        $card = array_pop($state['drawPile']);
        if ($card === null) break;
        $drawn[] = $card;
    }
    return $drawn;
}

function engine_has_color(array $hand, string $color): bool {
    foreach ($hand as $c) {
        if ($c['color'] === $color) return true;
    }
    return false;
}

/** Builds a brand-new round state for the given (already shuffled) active session ids. */
function engine_start_round(array $activeSessionIds, int $roundNumber): array {
    $r = rules();
    $state = [
        'roundActive' => true,
        'roundNumber' => $roundNumber,
        'turnOrder' => array_values($activeSessionIds),
        'currentIndex' => 0,
        'direction' => 1,
        'currentColor' => null,
        'pendingDraw' => ['amount' => 0, 'type' => null],
        'drawPile' => shuffle_cards(build_deck()),
        'discardPile' => [],
        'hands' => [],
        'drawnCardPendingId' => null,
        'turnStartedAt' => now_ms(),
    ];

    foreach ($state['turnOrder'] as $sid) {
        $state['hands'][$sid] = engine_draw_from_pile($state, $r['startingHandSize']);
    }

    $first = array_pop($state['drawPile']);
    while ($first !== null && $first['type'] === 'wild4') {
        array_unshift($state['drawPile'], $first);
        $state['drawPile'] = shuffle_cards($state['drawPile']);
        $first = array_pop($state['drawPile']);
    }
    $state['discardPile'][] = $first;

    if ($first['type'] === 'wild') {
        $state['currentColor'] = $r['colors'][array_rand($r['colors'])];
    } else {
        $state['currentColor'] = $first['color'];
    }

    if ($first['type'] === 'skip') {
        engine_advance_turn($state, 1);
    } elseif ($first['type'] === 'reverse') {
        $state['direction'] *= -1;
        if (count($state['turnOrder']) === 2) engine_advance_turn($state, 1);
    } elseif ($first['type'] === 'draw2') {
        $state['pendingDraw'] = ['amount' => 2, 'type' => 'draw2'];
    }

    return $state;
}

function engine_is_playable(array $state, string $sessionId, array $card): bool {
    $hand = $state['hands'][$sessionId] ?? [];
    $top = engine_top_card($state);
    $r = rules();

    if ($state['pendingDraw']['amount'] > 0) {
        if ($state['pendingDraw']['type'] === 'draw2') {
            return $card['type'] === 'draw2';
        }
        return false; // wild4 penalties can never be answered with another card
    }

    if ($card['type'] === 'wild') return true;
    if ($card['type'] === 'wild4') {
        if (!$r['wildDrawFourRequiresNoMatchingColor']) return true;
        return !engine_has_color($hand, $state['currentColor']);
    }
    if ($card['color'] === $state['currentColor']) return true;
    if ($top && $top['type'] === 'number' && $card['type'] === 'number') return $card['value'] === $top['value'];
    if ($top && $card['type'] === $top['type'] && $card['type'] !== 'number') return true;
    return false;
}

function engine_advance_turn(array &$state, int $steps = 1): void {
    $n = count($state['turnOrder']);
    if ($n === 0) return;
    $state['currentIndex'] = (($state['currentIndex'] + $state['direction'] * $steps) % $n + $n) % $n;
}

/** @return array{ok:bool,error?:string,roundEnded?:bool,winnerId?:string,unoPlayerId?:?string} */
function engine_play_card(array &$state, string $sessionId, string $cardId, ?string $chosenColor): array {
    if (empty($state['roundActive'])) return ['ok' => false, 'error' => 'Round is not active.'];
    if (engine_current_player($state) !== $sessionId) return ['ok' => false, 'error' => 'Not your turn.'];

    $hand = $state['hands'][$sessionId] ?? [];
    $cardIndex = null;
    foreach ($hand as $i => $c) {
        if ($c['id'] === $cardId) { $cardIndex = $i; break; }
    }
    if ($cardIndex === null) return ['ok' => false, 'error' => 'Card not in hand.'];
    $card = $hand[$cardIndex];

    if (!engine_is_playable($state, $sessionId, $card)) {
        return ['ok' => false, 'error' => 'Card is not playable right now.'];
    }

    $r = rules();
    if (($card['type'] === 'wild' || $card['type'] === 'wild4') && !in_array($chosenColor, $r['colors'], true)) {
        return ['ok' => false, 'error' => 'A color must be chosen for wild cards.'];
    }

    array_splice($hand, $cardIndex, 1);
    $state['hands'][$sessionId] = $hand;
    $state['discardPile'][] = $card;
    $state['drawnCardPendingId'] = null;

    $state['currentColor'] = ($card['type'] === 'wild' || $card['type'] === 'wild4') ? $chosenColor : $card['color'];

    $unoPlayerId = count($hand) === 1 ? $sessionId : null;

    if (count($hand) === 0) {
        $state['roundActive'] = false;
        return ['ok' => true, 'roundEnded' => true, 'winnerId' => $sessionId, 'unoPlayerId' => $unoPlayerId];
    }

    switch ($card['type']) {
        case 'skip':
            engine_advance_turn($state, 2);
            break;
        case 'reverse':
            $state['direction'] *= -1;
            engine_advance_turn($state, count($state['turnOrder']) === 2 ? 2 : 1);
            break;
        case 'draw2':
            $state['pendingDraw'] = [
                'amount' => $state['pendingDraw']['amount'] > 0 ? $state['pendingDraw']['amount'] + 2 : 2,
                'type' => 'draw2',
            ];
            engine_advance_turn($state, 1);
            break;
        case 'wild4':
            $state['pendingDraw'] = ['amount' => 4, 'type' => 'wild4'];
            engine_advance_turn($state, 1);
            break;
        default:
            engine_advance_turn($state, 1);
    }

    $state['turnStartedAt'] = now_ms();
    return ['ok' => true, 'roundEnded' => false, 'unoPlayerId' => $unoPlayerId];
}

/** @return array{ok:bool,error?:string,drawn?:int,passed?:bool,playableCardId?:string} */
function engine_draw_card(array &$state, string $sessionId): array {
    if (empty($state['roundActive'])) return ['ok' => false, 'error' => 'Round is not active.'];
    if (engine_current_player($state) !== $sessionId) return ['ok' => false, 'error' => 'Not your turn.'];

    if ($state['pendingDraw']['amount'] > 0) {
        $drawn = engine_draw_from_pile($state, $state['pendingDraw']['amount']);
        $state['hands'][$sessionId] = array_merge($state['hands'][$sessionId], $drawn);
        $state['pendingDraw'] = ['amount' => 0, 'type' => null];
        engine_advance_turn($state, 1);
        $state['turnStartedAt'] = now_ms();
        return ['ok' => true, 'drawn' => count($drawn), 'forced' => true, 'passed' => true];
    }

    $drawn = engine_draw_from_pile($state, 1);
    if (empty($drawn)) {
        engine_advance_turn($state, 1);
        $state['turnStartedAt'] = now_ms();
        return ['ok' => true, 'drawn' => 0, 'passed' => true];
    }
    $card = $drawn[0];
    $state['hands'][$sessionId][] = $card;

    if (engine_is_playable($state, $sessionId, $card)) {
        $state['drawnCardPendingId'] = $card['id'];
        return ['ok' => true, 'drawn' => 1, 'passed' => false, 'playableCardId' => $card['id']];
    }

    engine_advance_turn($state, 1);
    $state['turnStartedAt'] = now_ms();
    return ['ok' => true, 'drawn' => 1, 'passed' => true];
}

function engine_pass_turn(array &$state, string $sessionId): array {
    if (empty($state['roundActive'])) return ['ok' => false, 'error' => 'Round is not active.'];
    if (engine_current_player($state) !== $sessionId) return ['ok' => false, 'error' => 'Not your turn.'];
    $state['drawnCardPendingId'] = null;
    engine_advance_turn($state, 1);
    $state['turnStartedAt'] = now_ms();
    return ['ok' => true];
}

/** Called lazily (by any poll/action) when the current player's 30s clock has run out. */
function engine_check_timeout(array &$state): ?array {
    $r = rules();
    if (empty($state['roundActive'])) return null;
    $elapsed = now_ms() - ($state['turnStartedAt'] ?? 0);
    if ($elapsed < $r['turnSeconds'] * 1000) return null;

    $sessionId = engine_current_player($state);
    if ($sessionId === null) return null;

    if ($state['pendingDraw']['amount'] > 0 || !$state['drawnCardPendingId']) {
        $result = engine_draw_card($state, $sessionId);
        if (!empty($result['ok']) && array_key_exists('passed', $result) && $result['passed'] === false) {
            // The auto-draw happened to be playable, which normally leaves the
            // decision to the player — but on a timeout no one is there to
            // decide, so immediately pass instead of stalling their turn.
            engine_pass_turn($state, $sessionId);
            $result = ['ok' => true, 'drawn' => $result['drawn'], 'passed' => true, 'autoPassedAfterDraw' => true];
        }
    } else {
        $result = engine_pass_turn($state, $sessionId);
    }
    return ['type' => 'timeout', 'sessionId' => $sessionId, 'result' => $result];
}

/**
 * Ends the round: everyone but the winner adds their hand's point value to
 * their OWN running score; the winner adds nothing. Returns a breakdown for
 * the caller to persist into players.score and to report as an event.
 */
function engine_end_round(array &$state, string $winnerId): array {
    $breakdown = [];
    foreach ($state['turnOrder'] as $sid) {
        if ($sid === $winnerId) continue;
        $hand = $state['hands'][$sid] ?? [];
        $points = 0;
        foreach ($hand as $c) $points += card_points($c);
        $breakdown[] = ['sessionId' => $sid, 'cardsLeft' => count($hand), 'pointsAdded' => $points];
    }
    $breakdown[] = ['sessionId' => $winnerId, 'cardsLeft' => 0, 'pointsAdded' => 0];
    $state['roundActive'] = false;
    $state['roundEndedAt'] = now_ms();
    return $breakdown;
}

/** Removes a player mid-round (left/kicked): their cards go back into the draw pile. */
function engine_remove_from_round(array &$state, string $sessionId): void {
    if (empty($state['roundActive'])) return;
    $idx = array_search($sessionId, $state['turnOrder'], true);
    if ($idx === false) return;

    $hand = $state['hands'][$sessionId] ?? [];
    $state['drawPile'] = shuffle_cards(array_merge($state['drawPile'], $hand));
    unset($state['hands'][$sessionId]);

    $wasCurrent = engine_current_player($state) === $sessionId;
    array_splice($state['turnOrder'], $idx, 1);

    if (count($state['turnOrder']) === 0) {
        $state['roundActive'] = false;
        return;
    }

    $n = count($state['turnOrder']);
    if ($idx < $state['currentIndex'] || ($idx === $state['currentIndex'] && $wasCurrent)) {
        $state['currentIndex'] = $state['currentIndex'] % $n;
    }
    if ($wasCurrent) {
        $state['drawnCardPendingId'] = null;
        $state['turnStartedAt'] = now_ms();
        $state['currentIndex'] = (($state['currentIndex']) % $n + $n) % $n;
    }
}
