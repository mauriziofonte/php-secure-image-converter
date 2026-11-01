<?php

declare(strict_types=1);

/*
 * Child process of FullFilesystemTest: converts with a fake backend while the file size limit (RLIMIT_FSIZE, with
 * SIGXFSZ ignored by the parent shell) makes a write fail part way, as on a full filesystem.
 *
 * Arguments: <working directory> <input|output> <respectful|strict>
 *   input   the limit is set before the conversion: the private copy of the input fails
 *   output  the limit is set once the backend wrote its output: the copy next to the destination fails
 *   strict  the error handler throws for every error, the @ operator included; respectful honours error_reporting()
 *
 * Prints the class and message of what convert() threw (or "converted"), then the names in the output directory.
 */

use Mfonte\SecureImageConverter\Backend\BackendCapabilities;
use Mfonte\SecureImageConverter\Backend\BackendJob;
use Mfonte\SecureImageConverter\Backend\BackendResolver;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Pipeline\ConverterOptions;
use Mfonte\SecureImageConverter\Pipeline\SecureConverter;
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageBuilder;
use Mfonte\SecureImageConverter\Tests\Unit\Pipeline\Fake\WritingBackend;
use Mfonte\SecureImageConverter\Validation\ImageInspector;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

list(, $root, $mode, $handler) = $argv;
$strict = $handler === 'strict';
set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0) use ($strict): bool {
    if (!$strict && (error_reporting() & $severity) === 0) {
        return false;
    }

    throw new \ErrorException($message, 0, $severity, $file, $line);
});
$limit = 8192;
$writer = static function (BackendJob $job) use ($mode, $limit): void {
    $chunks = ImageBuilder::webpChunk('VP8L', ImageBuilder::vp8l($job->getWidth(), $job->getHeight()) . str_repeat("\0", 4 * $limit));
    file_put_contents($job->getOutputPath(), ImageBuilder::riff($chunks));
    if ($mode === 'output') {
        posix_setrlimit(POSIX_RLIMIT_FSIZE, $limit, $limit);
    }
};
// A large input, so that the padded output is smaller and is published instead of the input.
file_put_contents($root . '/in.png', ImageBuilder::png(40, 30, 8, 2, ImageBuilder::pngChunk('zzZz', str_repeat("\1", 16 * $limit))));
mkdir($root . '/work', 0700);
mkdir($root . '/out', 0700);
$backend = new WritingBackend((new BackendCapabilities('cwebp', [ImageFormat::PNG], [ImageFormat::WEBP]))->withFits(ConversionRequest::fitModes()), $writer);
$converter = new SecureConverter(new ImageInspector(), new BackendResolver([$backend]), (new ConverterOptions())->withWorkingDirectoryParent($root . '/work'));
if ($mode === 'input') {
    posix_setrlimit(POSIX_RLIMIT_FSIZE, $limit, $limit);
}

try {
    $converter->convert($root . '/in.png', $root . '/out/out.webp', new ConversionRequest());
    echo "converted\n";
} catch (\Throwable $exception) {
    echo get_class($exception), ': ', $exception->getMessage(), "\n";
}
echo implode(' ', array_diff((array) scandir($root . '/out'), ['.', '..'])), "\n";
echo implode(' ', array_diff((array) scandir($root . '/work'), ['.', '..'])), "\n";
