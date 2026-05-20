<?php
/**
 * One-time migration: copy root entry scripts to src/Http and replace root with stubs.
 * Run on the server: php tools/migrate_http.php
 * Or open in browser (remove or protect after use).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/bootstrap.php';

$httpDir = APP_ROOT . '/src/Http';
if (!is_dir($httpDir)) {
    mkdir($httpDir, 0755, true);
}

$files = [
    'results.php',
    'addNew.php',
    'pricing.php',
    'report.php',
    'error_report.php',
];

$stub = <<<'PHP'
<?php
/** Public entry point — implementation: src/Http/%s */
require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/src/Http/%s';

PHP;

$bootstrapPatterns = [
    "require_once __DIR__ . '/bootstrap.php';",
    'require_once __DIR__ . "/bootstrap.php";',
];

header('Content-Type: text/plain; charset=utf-8');

foreach ($files as $name) {
    $src = APP_ROOT . '/' . $name;
    $dest = $httpDir . '/' . $name;

    if (!is_file($src)) {
        echo "skip (missing): $name\n";
        continue;
    }

    $body = file_get_contents($src);
    if ($body === false) {
        echo "error reading: $name\n";
        continue;
    }

    // Already migrated?
    if (str_contains($body, "src/Http/$name")) {
        echo "already stub: $name\n";
        continue;
    }

    foreach ($bootstrapPatterns as $line) {
        $body = str_replace($line . "\r\n", '', $body);
        $body = str_replace($line . "\n", '', $body);
        $body = str_replace($line, '', $body);
    }

    if (file_put_contents($dest, $body) === false) {
        echo "error writing: $dest\n";
        continue;
    }

    if (file_put_contents($src, sprintf($stub, $name, $name)) === false) {
        echo "error stubbing: $name\n";
        continue;
    }

    echo "ok: $name\n";
}

echo "done.\n";
