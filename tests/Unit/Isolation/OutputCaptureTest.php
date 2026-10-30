<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Isolation\OutputCapture;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Reads from local socket pairs standing in for the pipes of a process.
 *
 * @covers \Mfonte\SecureImageConverter\Isolation\OutputCapture
 */
#[CoversClass(OutputCapture::class)]
final class OutputCaptureTest extends TestCase
{
    /**
     * @var list<resource>
     */
    private $resources = [];

    protected function tear_down(): void
    {
        foreach ($this->resources as $resource) {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
        $this->resources = [];
        parent::tear_down();
    }

    /**
     * @return array{resource, resource} the writing and the reading end
     */
    private function pipe(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        $this->resources[] = $pair[0];
        $this->resources[] = $pair[1];

        return [$pair[0], $pair[1]];
    }

    private static function readUntilClosed(OutputCapture $capture): void
    {
        for ($attempt = 0; $attempt < 1000 && $capture->isOpen(); ++$attempt) {
            $capture->read(0.05);
        }
        self::assertFalse($capture->isOpen());
    }

    public function testKeepsBothStreamsUntilTheyEnd(): void
    {
        [$stdoutWriter, $stdoutReader] = $this->pipe();
        [$stderrWriter, $stderrReader] = $this->pipe();
        $capture = new OutputCapture([1 => $stdoutReader, 2 => $stderrReader], 100);
        fwrite($stdoutWriter, "out\0put");
        fwrite($stderrWriter, 'err');
        fclose($stdoutWriter);
        fclose($stderrWriter);

        self::assertTrue($capture->isOpen());
        self::readUntilClosed($capture);

        self::assertSame("out\0put", $capture->getStdout());
        self::assertSame('err', $capture->getStderr());
        self::assertFalse($capture->isStdoutTruncated());
        self::assertFalse($capture->isStderrTruncated());
    }

    public function testKeepsAtMostTheLimitAndReadsTheRest(): void
    {
        [$writer, $reader] = $this->pipe();
        $capture = new OutputCapture([1 => $reader], 10);
        fwrite($writer, str_repeat('a', 10));
        $capture->read(1.0);
        self::assertFalse($capture->isStdoutTruncated(), 'exactly the limit is not a truncation');
        fwrite($writer, 'b');
        fclose($writer);

        self::readUntilClosed($capture);

        self::assertSame(str_repeat('a', 10), $capture->getStdout());
        self::assertTrue($capture->isStdoutTruncated());
        self::assertSame('', $capture->getStderr());
    }

    public function testCloseKeepsWhatWasRead(): void
    {
        [$writer, $reader] = $this->pipe();
        $capture = new OutputCapture([1 => $reader], 100);
        fwrite($writer, 'partial');
        $capture->read(1.0);
        $capture->close();

        self::assertFalse($capture->isOpen());
        self::assertSame('partial', $capture->getStdout());
        $capture->read(0.0);
        $capture->close();
        self::assertFalse(is_resource($reader));
    }

    /**
     * A stream that stream_select() cannot poll makes it warn on every call, like an unpollable pipe would: after a
     * few attempts the capture gives up instead of reporting no output.
     */
    public function testRepeatedSelectFailuresFail(): void
    {
        [, $reader] = $this->pipe();
        $memory = fopen('php://memory', 'r');
        self::assertIsResource($memory);
        $this->resources[] = $memory;
        $capture = new OutputCapture([1 => $reader, 2 => $memory], 100);

        for ($attempt = 1; $attempt < 10; ++$attempt) {
            $capture->read(0.0);
        }

        $this->expectException(ConversionFailedException::class);
        $this->expectExceptionMessage('The output of the process cannot be read: stream_select() failed repeatedly.');

        $capture->read(0.0);
    }
}
