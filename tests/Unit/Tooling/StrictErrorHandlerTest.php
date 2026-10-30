<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Tooling;

use Mfonte\SecureImageConverter\Tests\StrictErrorHandler;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Proves that the test suite fails on notices, warnings and deprecations raised by the project's own code, on every
 * PHPUnit version of the matrix, while deprecations raised inside vendor/ stay non-fatal.
 *
 * @coversNothing
 */
#[CoversNothing]
final class StrictErrorHandlerTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function userErrorLevels(): array
    {
        return [
            'notice' => [E_USER_NOTICE],
            'warning' => [E_USER_WARNING],
            'deprecation' => [E_USER_DEPRECATED],
        ];
    }

    /**
     * The handler installed by tests/bootstrap.php must still be active while a test runs: PHPUnit must not have
     * replaced it with its own (which only reports deprecations).
     *
     * @dataProvider userErrorLevels
     */
    #[DataProvider('userErrorLevels')]
    public function testErrorRaisedByTestCodeFailsTheTest(int $level): void
    {
        $this->expectException(\ErrorException::class);
        $this->expectExceptionMessage('strict error handler probe');

        trigger_error('strict error handler probe', $level);
    }

    public function testSilencedErrorIsLeftToPhp(): void
    {
        $handler = new StrictErrorHandler([dirname(__DIR__, 3) . '/tests']);
        $previous = error_reporting(0);

        try {
            $handled = $handler->handle(E_USER_WARNING, 'silenced', __FILE__, __LINE__);
        } finally {
            error_reporting($previous);
        }

        self::assertFalse($handled);
    }

    public function testDeprecationFromOwnDirectoryThrows(): void
    {
        $handler = new StrictErrorHandler([dirname(__DIR__, 3) . '/src', dirname(__DIR__, 3) . '/tests']);

        $this->expectException(\ErrorException::class);
        $this->expectExceptionMessage('own deprecation');

        $handler->handle(E_DEPRECATED, 'own deprecation', __FILE__, __LINE__);
    }

    public function testDeprecationFromVendorIsRecordedButNotFatal(): void
    {
        $root = dirname(__DIR__, 3);
        $handler = new StrictErrorHandler([$root . '/src', $root . '/tests']);
        $vendorFile = $root . '/vendor/autoload.php';

        self::assertTrue($handler->handle(E_DEPRECATED, 'vendor deprecation', $vendorFile, 7));
        self::assertTrue($handler->handle(E_USER_DEPRECATED, 'vendor deprecation', $vendorFile, 7));

        self::assertSame(
            [
                sprintf('vendor deprecation in %s:7', $vendorFile) => 2,
            ],
            $handler->getForeignDeprecations()
        );
    }

    public function testWarningFromVendorStillThrows(): void
    {
        $root = dirname(__DIR__, 3);
        $handler = new StrictErrorHandler([$root . '/src', $root . '/tests']);

        $this->expectException(\ErrorException::class);

        $handler->handle(E_WARNING, 'vendor warning', $root . '/vendor/autoload.php', 7);
    }

    public function testMissingOwnDirectoryIsIgnored(): void
    {
        $handler = new StrictErrorHandler([dirname(__DIR__, 3) . '/does-not-exist']);

        self::assertFalse($handler->isOwnFile(__FILE__));
    }
}
