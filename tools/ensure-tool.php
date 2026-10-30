<?php

declare(strict_types=1);

/*
 * Guard used by the Composer scripts: development tools that cannot be installed on PHP 7.2 live in
 * tools/<tool>/ with their own composer.json. This script exits with a clear hint when the requested
 * tool has not been installed yet, instead of letting the script fail with "file not found".
 *
 * Usage: php tools/ensure-tool.php <tool>
 */

$tool = isset($argv[1]) ? (string) $argv[1] : '';

if (preg_match('/\A[a-z0-9][a-z0-9-]*\z/', $tool) !== 1 || !is_file(__DIR__ . '/' . $tool . '/composer.json')) {
    fwrite(STDERR, 'Usage: php tools/ensure-tool.php <tool>, where <tool> is a directory under tools/.' . PHP_EOL);
    exit(2);
}

if (!is_file(__DIR__ . '/' . $tool . '/vendor/autoload.php')) {
    fwrite(
        STDERR,
        sprintf('%s is not installed. Run: composer install --working-dir=tools/%s', $tool, $tool) . PHP_EOL
    );
    exit(1);
}
