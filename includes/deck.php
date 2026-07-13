<?php
require_once __DIR__ . '/rules.php';

function make_card(string $color, string $type, $value = null): array {
    static $seq = 0;
    $seq++;
    return [
        'id' => 'c' . $seq . '_' . bin2hex(random_bytes(4)),
        'color' => $color,
        'type' => $type,
        'value' => $value,
    ];
}

function build_deck(): array {
    $r = rules();
    $deck = [];

    foreach ($r['colors'] as $color) {
        $deck[] = make_card($color, 'number', 0);
        for ($v = 1; $v <= 9; $v++) {
            $deck[] = make_card($color, 'number', $v);
            $deck[] = make_card($color, 'number', $v);
        }
        for ($i = 0; $i < 2; $i++) {
            $deck[] = make_card($color, 'skip');
            $deck[] = make_card($color, 'reverse');
            $deck[] = make_card($color, 'draw2');
        }
    }

    for ($i = 0; $i < $r['wildCount']; $i++) {
        $deck[] = make_card('wild', 'wild');
    }
    for ($i = 0; $i < $r['wildDrawFourCount']; $i++) {
        $deck[] = make_card('wild', 'wild4');
    }

    return $deck;
}

function shuffle_cards(array $cards): array {
    for ($i = count($cards) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$cards[$i], $cards[$j]] = [$cards[$j], $cards[$i]];
    }
    return $cards;
}

function card_points(array $card): int {
    $r = rules();
    if ($card['type'] === 'number') return (int)$card['value'];
    if ($card['type'] === 'wild' || $card['type'] === 'wild4') return $r['scoreWildCard'];
    return $r['scoreActionCard']; // skip, reverse, draw2
}
