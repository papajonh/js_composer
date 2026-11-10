<?php
declare(strict_types=1);

/**
 * Resolves a list of functions/libs into a single JS string.
 * Pure function: no headers, no echo, no exit.
 *
 * @param string[]    $names      Function names ("add()") or lib names ("jquery.min")
 * @param string|null $configPath Path to js_composer.json (default: ../js_composer.json)
 * @return string                 The concatenated JS
 * @throws RuntimeException       On any config/resolve error
 */
function js_composer_resolve(array $names, ?string $configPath = null): string
{
    $configPath = $configPath ?? dirname(__DIR__) . '/js_composer.json';

    if (!is_file($configPath)) {
        throw new RuntimeException('js_composer.json not found: ' . $configPath);
    }

    $config = json_decode(file_get_contents($configPath), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('js_composer.json invalid: ' . json_last_error_msg());
    }

    $jsPaths = $config['js-path'] ?? [];
    $libSrc  = $config['lib-src'] ?? [];
    $ignore  = $config['ignore']  ?? [];

    if ((!is_array($jsPaths) || count($jsPaths) === 0) && (!is_array($libSrc) || count($libSrc) === 0)) {
        throw new RuntimeException('"js-path" and "lib-src" are both empty');
    }

    // Project root = 2 levels above js_composer.json (js_vendor/js_composer.json → project root)
    $projectRoot = realpath(dirname($configPath, 2));
    if ($projectRoot === false) {
        throw new RuntimeException('cannot resolve project root from config path');
    }

    $ignoreDirs = array_filter(array_map('trim', $ignore));

    // --- Build indexes ---
    $functionIndex = [];
    $libIndex      = [];

    if (is_array($jsPaths)) {
        foreach ($jsPaths as $dir) {
            foreach (js_composer_scan_dir($projectRoot, $dir, $ignoreDirs) as $file) {
                $source = file_get_contents($file);
                if (preg_match_all('/^\/\/=>\(\)\s*(\S+)\s*$/m', $source, $m)) {
                    foreach ($m[1] as $markerName) {
                        if (!isset($functionIndex[$markerName])) {
                            $functionIndex[$markerName] = $file;
                        }
                    }
                }
            }
        }
    }

    if (is_array($libSrc)) {
        foreach ($libSrc as $dir) {
            foreach (js_composer_scan_dir($projectRoot, $dir, $ignoreDirs) as $file) {
                $libName = basename($file, '.js');
                if (!isset($libIndex[$libName])) {
                    $libIndex[$libName] = $file;
                }
            }
        }
    }

    // --- Resolve names (libs first, then functions) ---
    $resolved = [];

    foreach ($names as $name) {
        if (!is_string($name) || $name === '') continue;

        $noParens = preg_replace('/\s*\(\s*\)\s*$/', '', $name);

        if (isset($libIndex[$name])) {
            $resolved[$name] = ['type' => 'lib', 'file' => $libIndex[$name]];
            continue;
        }
        if ($noParens !== $name && isset($libIndex[$noParens])) {
            $resolved[$name] = ['type' => 'lib', 'file' => $libIndex[$noParens]];
            continue;
        }
        if (isset($functionIndex[$name])) {
            $resolved[$name] = ['type' => 'function', 'file' => $functionIndex[$name], 'marker' => $name];
            continue;
        }
        if ($noParens !== $name && isset($functionIndex[$noParens])) {
            $resolved[$name] = ['type' => 'function', 'file' => $functionIndex[$noParens], 'marker' => $noParens];
            continue;
        }
    }

    $missing = array_diff($names, array_keys($resolved));
    if (count($missing) > 0) {
        throw new RuntimeException('not found: ' . implode(', ', $missing));
    }

    // --- Extract & build JS ---
    $libParts      = [];
    $functionParts = [];

    foreach ($resolved as $name => $info) {
        if ($info['type'] === 'lib') {
            $libParts[] = trim(file_get_contents($info['file']));
            continue;
        }

        $marker = $info['marker'] ?? $name;
        $source = file_get_contents($info['file']);

        $openPattern  = '/^\/\/=>\(\)\s*' . preg_quote($marker, '/') . '\s*$/m';
        $closePattern = '/^\/\/=>\(\)\s*end\s*$/m';

        if (!preg_match($openPattern, $source, $openMatch, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException('inconsistent index for: ' . $name);
        }

        $startOffset = $openMatch[0][1] + strlen($openMatch[0][0]);

        if (!preg_match($closePattern, $source, $closeMatch, PREG_OFFSET_CAPTURE, $startOffset)) {
            throw new RuntimeException('"//=>() end" not found for: ' . $name);
        }

        $endOffset = $closeMatch[0][1];
        $chunk     = trim(substr($source, $startOffset, $endOffset - $startOffset));

        if ($chunk === '') {
            throw new RuntimeException('empty function: ' . $name);
        }

        // Strip import/export
        $chunk = preg_replace('/^\s*import\s+.*?;?\s*$/m', '', $chunk);
        $chunk = preg_replace('/^\s*export\s+(async\s+function|function|const|let|var|class)\s+/m', '$1 ', $chunk);
        $chunk = preg_replace('/^\s*export\s+default\s+/m', '', $chunk);

        // Attach to window
        $fnName = preg_replace('/\s*\(\s*\)\s*$/', '', $marker);
        $fnName = trim($fnName);

        if (!preg_match('/^[a-zA-Z_$][a-zA-Z0-9_$]*$/', $fnName)) {
            throw new RuntimeException('invalid function name for window export: ' . $fnName);
        }

        $chunk .= "\nwindow['" . addslashes($fnName) . "'] = " . $fnName . ";";
        $functionParts[] = trim($chunk);
    }

    // Libs FIRST, then functions
    return implode("\n\n", array_merge($libParts, $functionParts)) . "\n";
}

/**
 * Recursively scans a directory for .js files, skipping ignored folders.
 */
function js_composer_scan_dir(string $root, string $relativePath, array $ignoreDirs): array
{
    $result = [];

    if (!preg_match('#^/[a-zA-Z0-9_\-/]*$#', $relativePath)) {
        return $result;
    }

    $absolutePath = realpath($root . $relativePath);
    if ($absolutePath === false || !is_dir($absolutePath)) {
        return $result;
    }
    if (!str_starts_with($absolutePath, $root)) {
        return $result;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        if (strtolower($file->getExtension()) !== 'js') continue;

        $relativeToRoot = substr($file->getPathname(), strlen($root));
        $segments       = explode('/', trim($relativeToRoot, '/'));
        $skip           = false;

        foreach ($segments as $segment) {
            if (in_array($segment, $ignoreDirs, true)) {
                $skip = true;
                break;
            }
        }

        if ($skip) continue;

        $result[] = $file->getPathname();
    }

    return $result;
}