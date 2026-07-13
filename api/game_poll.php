<?php
require_once __DIR__ . '/../includes/util.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/xml_helper.php';
require_once __DIR__ . '/../includes/events_xml.php';

$sessionId = get_str('session_id', 64);
$code = strtoupper(get_str('code', 8));
$sinceEvent = (int) get_str('since_event', 20);

if (!valid_session_id($sessionId) || !valid_room_code($code)) {
    [$doc, $root] = xml_new_doc('state', ['found' => '0']);
    xml_send($doc);
}

$r = rules();

$bundle = with_room_lock($code, function (PDO $pdo, array $room) use ($sessionId, $sinceEvent) {
    touch_last_seen($pdo, (int)$room['id'], $sessionId);
    $room = reap_stale_players($pdo, $room);

    if ($room['status'] === 'playing') {
        $state = decode_state($room);
        if ($state !== null && !empty($state['roundActive'])) {
            resolve_pending_timeout($pdo, $room, $state); // mutates $state and saves it
        }
    }
    $room = maybe_advance_round($pdo, $room);

    // Re-read the authoritative row: resolve_pending_timeout() and/or
    // maybe_advance_round() may have just persisted new game_state, and the
    // response must reflect exactly what was saved, not a stale in-memory copy.
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE id = ?');
    $stmt->execute([$room['id']]);
    $room = $stmt->fetch();

    $state = decode_state($room);
    $players = get_players($pdo, (int)$room['id']);
    $events = fetch_events($pdo, (int)$room['id'], $sinceEvent, $sessionId);
    return ['room' => $room, 'state' => $state, 'players' => $players, 'events' => $events];
});

if ($bundle === null) {
    [$doc, $root] = xml_new_doc('state', ['found' => '0']);
    xml_send($doc);
}

$room = $bundle['room'];
$state = $bundle['state'];
$players = $bundle['players'];
$events = $bundle['events'];

[$doc, $root] = xml_new_doc('state', [
    'found' => '1',
    'code' => $room['code'],
    'status' => $room['status'],
    'hostId' => $room['host_session_id'],
    'targetScore' => $room['target_score'],
]);

$playersEl = xml_el($doc, $root, 'players');
foreach ($players as $p) {
    xml_el($doc, $playersEl, 'p', [
        'id' => $p['session_id'],
        'name' => $p['name'],
        'color' => $p['color'],
        'role' => $p['role'],
        'isHost' => $p['session_id'] === $room['host_session_id'] ? '1' : '0',
        'score' => $p['score'],
    ]);
}

if ($state !== null) {
    $roundEl = xml_el($doc, $root, 'round', [
        'active' => !empty($state['roundActive']) ? '1' : '0',
        'number' => $state['roundNumber'] ?? 0,
        'color' => $state['currentColor'] ?? '',
        'direction' => $state['direction'] ?? 1,
        'drawPileCount' => count($state['drawPile'] ?? []),
        'drawnCardPendingId' => $state['drawnCardPendingId'] ?? '',
    ]);

    xml_el($doc, $roundEl, 'pendingDraw', [
        'amount' => $state['pendingDraw']['amount'] ?? 0,
        'type' => $state['pendingDraw']['type'] ?? '',
    ]);

    $top = !empty($state['discardPile']) ? end($state['discardPile']) : null;
    if ($top) {
        xml_el($doc, $roundEl, 'topCard', ['id' => $top['id'], 'color' => $top['color'], 'type' => $top['type'], 'value' => $top['value'] ?? '']);
    }

    $turnOrderEl = xml_el($doc, $roundEl, 'turnOrder');
    foreach (($state['turnOrder'] ?? []) as $sid) {
        $handCount = isset($state['hands'][$sid]) ? count($state['hands'][$sid]) : 0;
        $turnOrderEl->appendChild($doc->createElement('p'));
        $pEl = $turnOrderEl->lastChild;
        $pEl->setAttribute('id', $sid);
        $pEl->setAttribute('cards', (string)$handCount);
    }

    $currentId = null;
    if (!empty($state['turnOrder'])) {
        $currentId = $state['turnOrder'][$state['currentIndex']] ?? null;
    }
    if ($currentId !== null) {
        $endsAt = ($state['turnStartedAt'] ?? now_ms()) + $r['turnSeconds'] * 1000;
        xml_el($doc, $roundEl, 'currentPlayer', ['id' => $currentId, 'endsAt' => $endsAt, 'seconds' => $r['turnSeconds']]);
    }

    $myHandEl = xml_el($doc, $roundEl, 'myHand');
    $myHand = $state['hands'][$sessionId] ?? null;
    if ($myHand !== null) {
        foreach ($myHand as $c) {
            xml_el($doc, $myHandEl, 'card', ['id' => $c['id'], 'color' => $c['color'], 'type' => $c['type'], 'value' => $c['value'] ?? '']);
        }
    }
}

render_events_xml($doc, $root, $events);

xml_send($doc);
