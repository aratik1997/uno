<?php
require_once __DIR__ . '/../includes/util.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/xml_helper.php';
require_once __DIR__ . '/../includes/events_xml.php';

$sessionId = get_str('session_id', 64);
$code = strtoupper(get_str('code', 8));
$sinceEvent = (int) get_str('since_event', 20);

if (!valid_session_id($sessionId) || !valid_room_code($code)) {
    [$doc, $root] = xml_new_doc('lobby', ['found' => '0']);
    xml_send($doc);
}

$bundle = with_room_lock($code, function (PDO $pdo, array $room) use ($sessionId, $sinceEvent) {
    touch_last_seen($pdo, (int)$room['id'], $sessionId);
    $room = reap_stale_players($pdo, $room);
    $room = maybe_advance_round($pdo, $room);
    $players = get_players($pdo, (int)$room['id']);
    $events = fetch_events($pdo, (int)$room['id'], $sinceEvent, $sessionId);
    return ['room' => $room, 'players' => $players, 'events' => $events];
});

if ($bundle === null) {
    [$doc, $root] = xml_new_doc('lobby', ['found' => '0']);
    xml_send($doc);
}

$room = $bundle['room'];
$players = $bundle['players'];
$events = $bundle['events'];

[$doc, $root] = xml_new_doc('lobby', [
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
        'isBot' => (int)$p['is_bot'] === 1 ? '1' : '0',
        'difficulty' => $p['bot_difficulty'] ?? '',
    ]);
}

render_events_xml($doc, $root, $events ?? []);

xml_send($doc);
