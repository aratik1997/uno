<?php
require_once __DIR__ . '/xml_helper.php';

/** Fetches events newer than $sinceId that this session is allowed to see. */
function fetch_events(PDO $pdo, int $roomId, int $sinceId, string $sessionId, int $limit = 100): array {
    $stmt = $pdo->prepare(
        'SELECT id, type, target_session_id, payload FROM events
         WHERE room_id = ? AND id > ? AND (target_session_id IS NULL OR target_session_id = ?)
         ORDER BY id ASC LIMIT ' . (int)$limit
    );
    $stmt->execute([$roomId, $sinceId, $sessionId]);
    return $stmt->fetchAll();
}

function render_events_xml(DOMDocument $doc, DOMElement $parent, array $events): void {
    $wrap = xml_el($doc, $parent, 'events');
    foreach ($events as $ev) {
        $payload = json_decode($ev['payload'], true) ?: [];
        $type = $ev['type'];

        switch ($type) {
            case 'system':
                xml_el($doc, $wrap, 'event', ['id' => $ev['id'], 'type' => 'system', 'message' => $payload['message'] ?? '']);
                break;
            case 'chat':
                xml_el($doc, $wrap, 'event', [
                    'id' => $ev['id'], 'type' => 'chat',
                    'from' => $payload['from'] ?? '', 'color' => $payload['color'] ?? '#999999',
                    'message' => $payload['message'] ?? '',
                ]);
                break;
            case 'card_played':
                xml_el($doc, $wrap, 'event', ['id' => $ev['id'], 'type' => 'card_played', 'sessionId' => $payload['sessionId'] ?? '', 'name' => $payload['name'] ?? '']);
                break;
            case 'card_drawn':
                xml_el($doc, $wrap, 'event', ['id' => $ev['id'], 'type' => 'card_drawn', 'sessionId' => $payload['sessionId'] ?? '', 'name' => $payload['name'] ?? '', 'count' => $payload['count'] ?? 0]);
                break;
            case 'uno':
                xml_el($doc, $wrap, 'event', ['id' => $ev['id'], 'type' => 'uno', 'sessionId' => $payload['sessionId'] ?? '', 'name' => $payload['name'] ?? '']);
                break;
            case 'kicked':
                xml_el($doc, $wrap, 'event', ['id' => $ev['id'], 'type' => 'kicked', 'byName' => $payload['byName'] ?? '']);
                break;
            case 'not_enough_players':
                xml_el($doc, $wrap, 'event', ['id' => $ev['id'], 'type' => 'not_enough_players']);
                break;
            case 'round_ended':
                $el = xml_el($doc, $wrap, 'event', [
                    'id' => $ev['id'], 'type' => 'round_ended',
                    'winnerName' => $payload['winnerName'] ?? '', 'finished' => !empty($payload['finished']) ? '1' : '0',
                ]);
                $bd = xml_el($doc, $el, 'breakdown');
                foreach (($payload['breakdown'] ?? []) as $row) {
                    xml_el($doc, $bd, 'p', [
                        'sessionId' => $row['sessionId'] ?? '', 'name' => $row['name'] ?? '',
                        'cardsLeft' => $row['cardsLeft'] ?? 0, 'pointsAdded' => $row['pointsAdded'] ?? 0,
                    ]);
                }
                break;
            case 'game_over':
                $el = xml_el($doc, $wrap, 'event', ['id' => $ev['id'], 'type' => 'game_over', 'overallWinnerName' => $payload['overallWinnerName'] ?? '']);
                $sb = xml_el($doc, $el, 'scoreboard');
                foreach (($payload['scoreboard'] ?? []) as $row) {
                    xml_el($doc, $sb, 'p', ['sessionId' => $row['sessionId'] ?? '', 'name' => $row['name'] ?? '', 'score' => $row['score'] ?? 0]);
                }
                break;
            default:
                xml_el($doc, $wrap, 'event', ['id' => $ev['id'], 'type' => $type]);
        }
    }
}
