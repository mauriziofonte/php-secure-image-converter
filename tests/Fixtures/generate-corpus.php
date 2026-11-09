<?php

declare(strict_types=1);

/*
 * Writes the input validation corpus (tests/Fixtures/corpus/) from ImageCorpus, and removes the files of that
 * directory that no case produces. The output is deterministic: running it again changes nothing.
 *
 * Usage: php tests/Fixtures/generate-corpus.php [--check]
 *   --check  write nothing; list the files that differ and exit with status 1 if any does.
 *
 * Requires the development autoloader (composer install).
 */

use Mfonte\SecureImageConverter\Tests\Fixtures\ImageCorpus;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$arguments = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : [];
$check = in_array('--check', $arguments, true);
$directory = ImageCorpus::directory();

$expected = [];
foreach (ImageCorpus::cases() as $path => $case) {
    if ($case['committed']) {
        $expected[$path] = $case['bytes'];
    }
}

$stale = [];
if (is_dir($directory)) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($directory) + 1));
            if (!isset($expected[$path])) {
                $stale[] = $path;
            }
        }
    }
}

$differences = 0;
foreach ($expected as $path => $bytes) {
    $file = $directory . '/' . $path;
    if (is_file($file) && file_get_contents($file) === $bytes) {
        continue;
    }
    ++$differences;
    if ($check) {
        fwrite(STDOUT, 'differs: ' . $path . PHP_EOL);
        continue;
    }
    if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0777, true)) {
        fwrite(STDERR, 'cannot create the directory of ' . $path . PHP_EOL);
        exit(1);
    }
    if (file_put_contents($file, $bytes) !== strlen($bytes)) {
        fwrite(STDERR, 'cannot write ' . $path . PHP_EOL);
        exit(1);
    }
    fwrite(STDOUT, 'written: ' . $path . PHP_EOL);
}
foreach ($stale as $path) {
    ++$differences;
    if ($check) {
        fwrite(STDOUT, 'stale: ' . $path . PHP_EOL);
        continue;
    }
    if (!unlink($directory . '/' . $path)) {
        fwrite(STDERR, 'cannot remove ' . $path . PHP_EOL);
        exit(1);
    }
    fwrite(STDOUT, 'removed: ' . $path . PHP_EOL);
}

fwrite(STDOUT, sprintf('%d committed cases, %d %s.' . PHP_EOL, count($expected), $differences, $check ? 'differences' : 'changes'));
exit($check && $differences > 0 ? 1 : 0);
