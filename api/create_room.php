<?php
require_once __DIR__ . '/../includes/util.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/xml_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') xml_ack(false, 'POST required.');

$sessionId = post_str('session_id', 64);
$name = post_str('name', 20) ?: 'Host';
$targetScore = (int) post_str('target_score', 10);
if ($targetScore <= 0) $targetScore = rules()['defaultTargetScore'];

if (!valid_session_id($sessionId)) xml_ack(false, 'Invalid session.');

$pdo = db();
$code = gen_room_code($pdo);
$color = AVATAR_COLORS[0];

$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO rooms (code, host_session_id, status, target_score) VALUES (?,?,?,?)')
        ->execute([$code, $sessionId, 'lobby', $targetScore]);
    $roomId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO players (room_id, session_id, name, color, role, seat_order) VALUES (?,?,?,?,?,0)')
        ->execute([$roomId, $sessionId, $name, $color, 'player']);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

xml_ack(true, null, ['code' => $code]);
