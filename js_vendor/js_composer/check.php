<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$projectRoot = realpath(dirname(__DIR__, 2));
$configPath  = dirname(__DIR__) . '/js_composer.json';

if (!is_file($configPath)) {
    http_response_code(500);
    exit("❌ js_composer.json not found\n");
}

$config = json_decode(file_get_contents($configPath), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    exit("❌ js_composer.json invalid: " . json_last_error_msg() . "\n");
}

$jsPaths    = $config['js-path'] ?? [];
$libSrc     = $config['lib-src'] ?? [];
$ignoreDirs = $config['ignore']  ?? [];
$hasError   = false;
$totalFns   = 0;
$totalLibs  = 0;

if (!is_array($jsPaths)) $jsPaths = [];
if (!is_array($libSrc))  $libSrc  = [];

if (count($jsPaths) === 0 && count($libSrc) === 0) {
    http_response_code(500);
    exit("❌ \"js-path\" and \"lib-src\" are both empty\n");
}

// --- js-path (functions) ---
foreach ($jsPaths as $dir) {
    if (!preg_match('#^/[a-zA-Z0-9_\-/]*$#', $dir) || str_contains($dir, '..')) {
        echo "❌ js-path: invalid directory: $dir\n";
        $hasError = true;
        continue;
    }

    $absolute = realpath($projectRoot . $dir);
    if ($absolute === false || !is_dir($absolute)) {
        echo "❌ js-path: directory not found: $dir\n";
        $hasError = true;
        continue;
    }

    $files = scanDirectory($projectRoot, $dir, $ignoreDirs);
    if (count($files) === 0) {
        echo "⚠️  js-path: no .js files in $dir\n";
        continue;
    }

    foreach ($files as $file) {
        $relative = substr($file, strlen($projectRoot));
        $source   = file_get_contents($file);
        $lines    = explode("\n", $source);
        $isOpen   = false;
        $current  = null;
        $fns      = [];
        $errors   = [];

        foreach ($lines as $i => $line) {
            $n    = $i + 1;
            $line = trim($line);

            if (preg_match('/^\/\/=>\(\)\s*(\S+)\s*$/', $line, $m)) {
                $marker = trim($m[1]);

                if ($marker === 'end') {
                    if (!$isOpen) {
                        $errors[] = "Line $n: '//=>() end' without opening";
                    } else {
                        $isOpen  = false;
                        $current = null;
                    }
                } else {
                    if ($isOpen) $errors[] = "Line $n: '$marker' opened without closing '$current'";
                    $isOpen  = true;
                    $current = $marker;
                    if (isset($fns[$marker])) $errors[] = "Line $n: '$marker' duplicated";
                    else $fns[$marker] = $n;
                }
            }
        }

        if ($isOpen) $errors[] = "Function '$current' never closed";

        if ($errors) {
            echo "❌ $relative:\n";
            foreach ($errors as $e) echo "   - $e\n";
            $hasError = true;
        } else {
            echo "✅ $relative — " . count($fns) . " functions: " . implode(', ', array_keys($fns)) . "\n";
            $totalFns += count($fns);
        }
    }
}

// --- lib-src (libs) ---
foreach ($libSrc as $dir) {
    if (!preg_match('#^/[a-zA-Z0-9_\-/]*$#', $dir) || str_contains($dir, '..')) {
        echo "❌ lib-src: invalid directory: $dir\n";
        $hasError = true;
        continue;
    }

    $absolute = realpath($projectRoot . $dir);
    if ($absolute === false || !is_dir($absolute)) {
        echo "❌ lib-src: directory not found: $dir\n";
        $hasError = true;
        continue;
    }

    foreach (scanDirectory($projectRoot, $dir, $ignoreDirs) as $file) {
        $relative = substr($file, strlen($projectRoot));
        $libName  = basename($file, '.js');
        echo "📚 lib: $relative → \"$libName\"\n";
        $totalLibs++;
    }
}

if ($hasError) {
    http_response_code(500);
    exit("\n❌ errors found\n");
}

echo "\n✅ js_composer.json OK ($totalFns functions, $totalLibs libs)\n";

// =====================================================================
function scanDirectory(string $root, string $relativePath, array $ignoreDirs): array
{
    $result = [];
    if (!preg_match('#^/[a-zA-Z0-9_\-/]*$#', $relativePath)) return $result;
    $absolute = realpath($root . $relativePath);
    if ($absolute === false || !is_dir($absolute)) return $result;
    if (!str_starts_with($absolute, $root)) return $result;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        if (strtolower($file->getExtension()) !== 'js') continue;

        $rel = substr($file->getPathname(), strlen($root));
        $segments = explode('/', trim($rel, '/'));
        $skip = false;
        foreach ($segments as $seg) {
            if (in_array($seg, $ignoreDirs, true)) { $skip = true; break; }
        }
        if ($skip) continue;

        $result[] = $file->getPathname();
    }
    return $result;
}