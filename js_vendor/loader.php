<?php
declare(strict_types=1);

require __DIR__ . '/js_composer/core.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');

$fnString = $_GET['fn'] ?? '';
if ($fnString === '') {
    http_response_code(400);
    exit('// loader: no functions requested');
}

$names = array_values(array_filter(array_map('trim', explode(',', $fnString))));

try {
    echo js_composer_resolve($names);
} catch (RuntimeException $e) {
    http_response_code(500);
    exit('// loader: ' . $e->getMessage());
}