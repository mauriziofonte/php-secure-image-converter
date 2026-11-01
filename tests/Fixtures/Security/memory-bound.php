<?php

declare(strict_types=1);

/*
 * Child process of CorpusConversionTest::testAMemoryCeilingBoundsEveryDecoder(): converts every corpus file that the
 * inspector accepts to WEBP at the rlimit isolation level, with a memory ceiling, in a fresh process, so that its
 * getrusage(RUSAGE_CHILDREN) peak covers these conversions only. (At the sandbox level the peak is lost: bubblewrap
 * exits before the process inside its PID namespace is reaped.)
 *
 * Arguments: <working directory parent> <memory ceiling in bytes> <backends, comma-separated, or "">
 *
 * Prints a JSON object: "outcomes" (corpus path => "converted" or the error code), "children_max_rss_bytes" (the
 * largest resident set of a conversion process) and "php_peak_bytes" (the peak memory of this PHP process).
 * tests/bootstrap.php is loaded first, so a notice or warning raised by the library is an exception.
 */

use Mfonte\SecureImageConverter\Backend\BackendFactory;
use Mfonte\SecureImageConverter\Backend\BackendOptions;
use Mfonte\SecureImageConverter\Backend\BackendResolver;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\ProcessRunner;
use Mfonte\SecureImageConverter\Pipeline\ConverterOptions;
use Mfonte\SecureImageConverter\Pipeline\SecureConverter;
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageCorpus;
use Mfonte\SecureImageConverter\Validation\ImageInspector;

require dirname(__DIR__, 2) . '/bootstrap.php';

$arguments = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? array_values(array_filter($_SERVER['argv'], 'is_string')) : [];
if (count($arguments) !== 4 || !ctype_digit($arguments[2])) {
    fwrite(STDERR, "usage: memory-bound.php <parent> <ceiling bytes> <backends>\n");
    exit(2);
}
$parent = $arguments[1];
$ceiling = intval($arguments[2]);
$names = $arguments[3] !== '' ? BackendFactory::parseNames($arguments[3]) : null;

$backendOptions = (new BackendOptions())
    ->withCapabilityCache(false)
    ->withWorkingDirectoryParent($parent)
    ->withMemoryCeilingBytes($ceiling);
$runner = new ProcessRunner(IsolationLevel::RLIMIT);
$converter = new SecureConverter(
    new ImageInspector(null, array_values(array_diff(ImageFormat::all(), [ImageFormat::SVG]))),
    new BackendResolver(BackendFactory::createAll($runner, $backendOptions, $names), $ceiling),
    (new ConverterOptions())->withIsolation(IsolationLevel::RLIMIT)->withWorkingDirectoryParent($parent)
);

$outcomes = [];
foreach (ImageCorpus::cases() as $path => $case) {
    if (isset($case['expect']['error'])) {
        continue;
    }
    $input = $parent . '/input';
    $output = $parent . '/output';
    file_put_contents($input, $case['bytes']);

    try {
        $converter->convert($input, $output, new ConversionRequest());
        $outcomes[$path] = 'converted';
    } catch (ExceptionInterface $exception) {
        $outcomes[$path] = $exception->getErrorCode();
    }
    @unlink($input);
    @unlink($output);
}

$usage = getrusage(1);
echo json_encode([
    'outcomes' => $outcomes,
    'children_max_rss_bytes' => is_array($usage) && is_int($usage['ru_maxrss'] ?? null) ? $usage['ru_maxrss'] * 1024 : -1,
    'php_peak_bytes' => memory_get_peak_usage(true),
]), "\n";
