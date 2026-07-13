<?php
/**
 * Small DOMDocument helpers so every API endpoint can build and return XML
 * without repeating boilerplate. All AJAX endpoints in this project speak
 * XML exclusively (requests are simple POST fields; responses are XML).
 */

function xml_new_doc(string $rootName, array $rootAttrs = []): array {
    $doc = new DOMDocument('1.0', 'UTF-8');
    $root = $doc->createElement($rootName);
    foreach ($rootAttrs as $k => $v) {
        $root->setAttribute($k, (string)$v);
    }
    $doc->appendChild($root);
    return [$doc, $root];
}

function xml_el(DOMDocument $doc, DOMElement $parent, string $name, array $attrs = [], ?string $text = null): DOMElement {
    $el = $doc->createElement($name);
    foreach ($attrs as $k => $v) {
        if ($v === null) continue;
        $el->setAttribute($k, (string)$v);
    }
    if ($text !== null) {
        $el->appendChild($doc->createTextNode($text));
    }
    $parent->appendChild($el);
    return $el;
}

function xml_send(DOMDocument $doc): void {
    header('Content-Type: application/xml; charset=utf-8');
    echo $doc->saveXML();
    exit;
}

/** Shortcut for simple ok/error acknowledgements returned by action endpoints. */
function xml_ack(bool $ok, ?string $error = null, array $extra = []): void {
    [$doc, $root] = xml_new_doc('response', array_merge(['ok' => $ok ? '1' : '0'], $extra));
    if ($error !== null) {
        xml_el($doc, $root, 'error', [], $error);
    }
    xml_send($doc);
}
