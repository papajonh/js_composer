<?php
// Usage: php js_vendor/js_composer/cli.php {check|list}
$command    = $argv[1] ?? 'help';
$configPath = dirname(__DIR__) . '/js_composer.json';

if (!is_file($configPath)) {
    exit("❌ js_composer.json not found\n");
}

$config = json_decode(file_get_contents($configPath), true);

if ($command === 'check') {
    passthru('php ' . escapeshellarg(__DIR__ . '/check.php'));
    exit;
}

if ($command === 'list') {
    echo "Project: " . ($config['name']    ?? '?') . "\n";
    echo "Version: " . ($config['version'] ?? '?') . "\n\n";

    echo "js-path (functions):\n";
    foreach ($config['js-path'] ?? [] as $p) echo "  $p\n";

    echo "\nlib-src (libs):\n";
    foreach ($config['lib-src'] ?? [] as $p) echo "  $p\n";

    echo "\nignore:\n";
    foreach ($config['ignore'] ?? [] as $p) echo "  $p\n";

    echo "\n";
    exit;
}

echo "Usage: php js_vendor/js_composer/cli.php {check|list}\n";