<?php
function post_str(string $key, int $maxLen = 255): string {
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) return '';
    return trim(mb_substr($v, 0, $maxLen));
}

function get_str(string $key, int $maxLen = 255): string {
    $v = $_GET[$key] ?? '';
    if (!is_string($v)) return '';
    return trim(mb_substr($v, 0, $maxLen));
}

/** Session ids are client-generated UUIDs; just bound their shape/length. */
function valid_session_id(string $sid): bool {
    return (bool) preg_match('/^[A-Za-z0-9_-]{8,64}$/', $sid);
}

function valid_room_code(string $code): bool {
    return (bool) preg_match('/^[A-Za-z0-9]{4,8}$/', $code);
}
