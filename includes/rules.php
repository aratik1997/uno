<?php
/**
 * Loads config/rules.xml once and exposes the game's rule constants.
 */

function load_rules(): array {
    static $rules = null;
    if ($rules !== null) return $rules;

    $xml = simplexml_load_file(__DIR__ . '/../config/rules.xml');
    if ($xml === false) {
        throw new RuntimeException('Could not parse config/rules.xml');
    }

    $colors = [];
    foreach ($xml->deck->colors->color as $c) {
        $colors[] = (string)$c;
    }

    $rules = [
        'minPlayers' => (int)$xml->players['min'],
        'maxPlayers' => (int)$xml->players['max'],
        'startingHandSize' => (int)$xml->players['startingHandSize'],
        'turnSeconds' => (int)$xml->turn['seconds'],
        'defaultTargetScore' => (int)$xml->targetScore['default'],
        'colors' => $colors,
        'wildCount' => (int)$xml->deck->wildCount,
        'wildDrawFourCount' => (int)$xml->deck->wildDrawFourCount,
        'drawTwoStackable' => filter_var((string)$xml->stacking->drawTwo['stackable'], FILTER_VALIDATE_BOOLEAN),
        'wildDrawFourStackable' => filter_var((string)$xml->stacking->wildDrawFour['stackable'], FILTER_VALIDATE_BOOLEAN),
        'wildDrawFourRequiresNoMatchingColor' => filter_var((string)$xml->wildDrawFourRule->requireNoMatchingColor, FILTER_VALIDATE_BOOLEAN),
        'scoreActionCard' => (int)$xml->scoring->skip, // skip/reverse/draw2 share one value
        'scoreWildCard' => (int)$xml->scoring->wild,    // wild/wild4 share one value
        'roundWinnerPoints' => (int)$xml->scoring->roundWinnerPoints,
    ];

    return $rules;
}

function rules(): array {
    return load_rules();
}
