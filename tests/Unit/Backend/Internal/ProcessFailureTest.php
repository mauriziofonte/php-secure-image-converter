<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend\Internal;

use Mfonte\SecureImageConverter\Backend\Internal\ProcessFailure;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionTimeoutException;
use Mfonte\SecureImageConverter\Contract\Exception\ResourceLimitException;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\ProcessResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Backend\Internal\ProcessFailure
 */
#[CoversClass(ProcessFailure::class)]
final class ProcessFailureTest extends TestCase
{
    /**
     * @return array<string, array{0: ProcessResult, 1: bool, 2: class-string, 3: string}>
     */
    public static function results(): array
    {
        $sandbox = IsolationLevel::SANDBOX;
        $rlimit = IsolationLevel::RLIMIT;

        return [
            'timeout' => [new ProcessResult(null, 9, true, 30.0, '', '', false, false, $rlimit), false, ConversionTimeoutException::class, 'The vips conversion exceeded its time limit.'],
            'SIGXCPU' => [new ProcessResult(null, 24, false, 1.0, '', '', false, false, $rlimit), false, ResourceLimitException::class, 'The vips conversion was killed (signal 24): it exceeded its CPU time or memory budget.'],
            'SIGKILL without timeout' => [new ProcessResult(null, 9, false, 1.0, '', '', false, false, $rlimit), false, ResourceLimitException::class, 'The vips conversion was killed (signal 9): it exceeded its CPU time or memory budget.'],
            'SIGKILL in the sandbox' => [new ProcessResult(137, null, false, 1.0, '', '', false, false, $sandbox), false, ResourceLimitException::class, 'The vips conversion was killed (signal 9): it exceeded its CPU time or memory budget.'],
            'SIGXFSZ in the sandbox' => [new ProcessResult(153, null, false, 1.0, '', '', false, false, $sandbox), false, ResourceLimitException::class, 'The vips conversion exceeded its file size budget.'],
            'GLib abort' => [new ProcessResult(null, 5, false, 1.0, '', "GLib-ERROR **: failed to allocate 4096 bytes\n", false, false, $rlimit), false, ResourceLimitException::class, 'The vips conversion ran out of memory.'],
            'ENOMEM exit' => [new ProcessResult(1, null, false, 1.0, '', 'Cannot allocate memory', false, false, $rlimit), false, ResourceLimitException::class, 'The vips conversion ran out of memory.'],
            'worker resource limit' => [new ProcessResult(3, null, false, 1.0, '{"ok":false}', '', false, false, $rlimit), true, ResourceLimitException::class, 'The vips conversion exceeded its memory or pixel budget.'],
            'exit 3 of a program' => [new ProcessResult(3, null, false, 1.0, '', '', false, false, $rlimit), false, ConversionFailedException::class, 'The vips conversion failed (exit code 3).'],
            'silent crash' => [new ProcessResult(null, 11, false, 1.0, '', '', false, false, $rlimit), false, ConversionFailedException::class, 'The vips conversion crashed (signal 11).'],
            'sandboxed crash' => [new ProcessResult(139, null, false, 1.0, '', '', false, false, $sandbox), false, ConversionFailedException::class, 'The vips conversion crashed (signal 11).'],
            'exit 139 outside the sandbox' => [new ProcessResult(139, null, false, 1.0, '', '', false, false, $rlimit), false, ConversionFailedException::class, 'The vips conversion failed (exit code 139).'],
            'error exit' => [new ProcessResult(1, null, false, 1.0, '', 'VipsJpeg: Premature end of input file', false, false, $rlimit), false, ConversionFailedException::class, 'The vips conversion failed (exit code 1).'],
        ];
    }

    /**
     * @dataProvider results
     *
     * @param class-string $class
     */
    #[DataProvider('results')]
    public function testFailuresMapToTheirException(ProcessResult $result, bool $worker, string $class, string $message): void
    {
        $exception = ProcessFailure::exception('vips', $result, $worker);

        self::assertInstanceOf($class, $exception);
        self::assertSame($message, $exception->getMessage());
    }

    public function testMemoryMessagesAreRecognised(): void
    {
        self::assertTrue(ProcessFailure::reportsMemoryExhaustion('PHP Fatal error:  Out of memory (allocated 2097152)'));
        self::assertTrue(ProcessFailure::reportsMemoryExhaustion('terminate called after throwing an instance of std::bad_alloc'));
        self::assertTrue(ProcessFailure::reportsMemoryExhaustion('convert: cache resources exhausted'));
        self::assertTrue(ProcessFailure::reportsMemoryExhaustion('vips: error while loading shared libraries: libvips.so.42: failed to map segment from shared object'));
        self::assertTrue(ProcessFailure::reportsMemoryExhaustion('(vips:1): GLib-ERROR **: Error creating thread: Resource temporarily unavailable'));
        self::assertFalse(ProcessFailure::reportsMemoryExhaustion('Not a JPEG file: starts with 0x89 0x50'));
    }

    public function testHostErrorsAreRecognised(): void
    {
        self::assertTrue(ProcessFailure::reportsHostError('Error! Cannot save file: No space left on device'));
        self::assertTrue(ProcessFailure::reportsHostError('TIFFWriteDirectory: Read-only file system'));
        self::assertTrue(ProcessFailure::reportsHostError('unable to open image: Too many open files'));
        self::assertTrue(ProcessFailure::reportsHostError('write failed: Input/output error'));
        self::assertFalse(ProcessFailure::reportsHostError('libpng error: IDAT: invalid distance too far back'));
    }
}
