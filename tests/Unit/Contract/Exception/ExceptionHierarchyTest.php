<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Contract\Exception;

use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionTimeoutException;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidRequestException;
use Mfonte\SecureImageConverter\Contract\Exception\IsolationUnavailableException;
use Mfonte\SecureImageConverter\Contract\Exception\LimitExceededException;
use Mfonte\SecureImageConverter\Contract\Exception\OutputVerificationException;
use Mfonte\SecureImageConverter\Contract\Exception\ResourceLimitException;
use Mfonte\SecureImageConverter\Contract\Exception\UnsupportedFormatException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Contract\Exception\ErrorCode
 * @covers \Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException
 * @covers \Mfonte\SecureImageConverter\Contract\Exception\InvalidRequestException
 * @covers \Mfonte\SecureImageConverter\Contract\Exception\LimitExceededException
 */
#[CoversClass(ErrorCode::class)]
#[CoversClass(InvalidImageException::class)]
#[CoversClass(InvalidRequestException::class)]
#[CoversClass(LimitExceededException::class)]
final class ExceptionHierarchyTest extends TestCase
{
    /**
     * HTTP status of each error code, as fixed by the "Contract decisions" of the project conventions.
     */
    private const EXPECTED_STATUSES = [
        'invalid_request' => 400,
        'unsupported_format' => 415,
        'invalid_image' => 422,
        'animation_rejected' => 422,
        'file_too_large' => 413,
        'image_too_large' => 422,
        'conversion_timeout' => 422,
        'resource_limit' => 422,
        'isolation_unavailable' => 503,
        'concurrency_limit' => 503,
        'conversion_failed' => 500,
        'output_verification_failed' => 500,
        'internal_error' => 500,
    ];

    /**
     * Every exception of the contract that has a public constructor, with the SPL exception it directly extends and
     * the error code of an instance built with that constructor.
     *
     * @return array<string, array{class-string<ExceptionInterface>, class-string<\Throwable>, string}>
     */
    public static function exceptions(): array
    {
        return [
            'InvalidRequestException' => [InvalidRequestException::class, \InvalidArgumentException::class, 'invalid_request'],
            'UnsupportedFormatException' => [UnsupportedFormatException::class, \UnexpectedValueException::class, 'unsupported_format'],
            'InvalidImageException' => [InvalidImageException::class, \UnexpectedValueException::class, 'invalid_image'],
            'IsolationUnavailableException' => [IsolationUnavailableException::class, \RuntimeException::class, 'isolation_unavailable'],
            'ConcurrencyLimitException' => [ConcurrencyLimitException::class, \OverflowException::class, 'concurrency_limit'],
            'ConversionFailedException' => [ConversionFailedException::class, \RuntimeException::class, 'conversion_failed'],
            'ConversionTimeoutException' => [ConversionTimeoutException::class, \RuntimeException::class, 'conversion_timeout'],
            'ResourceLimitException' => [ResourceLimitException::class, \RuntimeException::class, 'resource_limit'],
            'OutputVerificationException' => [OutputVerificationException::class, \UnexpectedValueException::class, 'output_verification_failed'],
            'InvalidArgumentException' => [InvalidArgumentException::class, \InvalidArgumentException::class, 'internal_error'],
        ];
    }

    /**
     * $class is typed as any Throwable, so that the interface is asserted rather than taken from the data provider.
     *
     * @param class-string<\Throwable> $class
     * @param class-string<\Throwable> $parent
     *
     * @dataProvider exceptions
     */
    #[DataProvider('exceptions')]
    public function testExceptionImplementsTheInterfaceAndHasItsErrorCode(string $class, string $parent, string $code): void
    {
        $exception = new $class('message');

        self::assertInstanceOf(ExceptionInterface::class, $exception);
        // The direct parent, so that no exception can be caught as another one with a different code (for example
        // a timeout, 422, is not a ConversionFailedException, 500).
        self::assertSame($parent, get_parent_class($exception));
        self::assertSame('message', $exception->getMessage());
        self::assertSame($code, $exception->getErrorCode());
    }

    public function testEveryExceptionClassIsCovered(): void
    {
        $covered = array_column(self::exceptions(), 0);
        $covered[] = LimitExceededException::class;

        $files = glob(dirname(__DIR__, 4) . '/src/Contract/Exception/*.php');
        self::assertIsArray($files);
        $found = [];
        foreach ($files as $file) {
            $class = 'Mfonte\\SecureImageConverter\\Contract\\Exception\\' . basename($file, '.php');
            if (is_subclass_of($class, ExceptionInterface::class)) {
                $found[] = $class;
                self::assertTrue((new \ReflectionClass($class))->isFinal(), $class . ' must be final');
            }
        }

        sort($covered);
        sort($found);
        self::assertSame($covered, $found);
    }

    public function testErrorCodesAreUniqueStableTokens(): void
    {
        $codes = ErrorCode::all();

        self::assertSame(array_values(array_unique($codes)), $codes);
        self::assertSame(array_values((new \ReflectionClass(ErrorCode::class))->getConstants()), $codes);
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/\A[a-z]+(?:_[a-z]+)*\z/', $code);
        }
        self::assertSame(array_keys(self::EXPECTED_STATUSES), $codes);
    }

    public function testErrorCodeTableDocumentsTheConventionStatuses(): void
    {
        $docComment = (new \ReflectionClass(ErrorCode::class))->getDocComment();
        self::assertIsString($docComment);
        self::assertSame(13, preg_match_all('/^ \* \| `([a-z_]+)` +\| (\d{3}) +\| (\w+) +\|/m', $docComment, $rows, PREG_SET_ORDER));

        $documented = [];
        foreach ($rows as $row) {
            $documented[$row[1]] = (int) $row[2];
        }
        self::assertSame(self::EXPECTED_STATUSES, $documented);
    }

    public function testInvalidRequestForParameterCarriesTheParameterName(): void
    {
        $exception = InvalidRequestException::forParameter('quality', 'expected an integer from 1 to 100, got 0');

        self::assertSame('quality', $exception->getParameter());
        self::assertSame('Invalid parameter "quality": expected an integer from 1 to 100, got 0.', $exception->getMessage());
        self::assertSame(ErrorCode::INVALID_REQUEST, $exception->getErrorCode());
    }

    public function testInvalidRequestWithoutParameter(): void
    {
        self::assertNull(InvalidRequestException::forParameter(null, 'unknown parameter')->getParameter());
        self::assertSame('Invalid request: unknown parameter.', InvalidRequestException::forParameter(null, 'unknown parameter')->getMessage());
        self::assertNull((new InvalidRequestException('plain'))->getParameter());
    }

    public function testAnimationRejectedHasItsOwnCode(): void
    {
        $exception = InvalidImageException::animationRejected(12);

        self::assertSame(ErrorCode::ANIMATION_REJECTED, $exception->getErrorCode());
        self::assertSame('The input is animated (12 frames) and the request\'s animation policy is reject.', $exception->getMessage());
        self::assertSame(ErrorCode::INVALID_IMAGE, (new InvalidImageException('truncated'))->getErrorCode());
    }

    public function testInputByteSizeLimitIsFileTooLarge(): void
    {
        $exception = LimitExceededException::forInput(Capabilities::LIMIT_MAX_BYTES, 20971520, 20971521);

        // Read through reflection, so that the assertion checks the class hierarchy rather than its declaration.
        $parent = (new \ReflectionClass($exception))->getParentClass();
        self::assertNotFalse($parent);
        self::assertSame(\RangeException::class, $parent->getName());
        self::assertSame(ErrorCode::FILE_TOO_LARGE, $exception->getErrorCode());
        self::assertSame('max_bytes', $exception->getLimit());
        self::assertSame(20971520, $exception->getMaximum());
        self::assertSame(20971521, $exception->getActual());
        self::assertFalse($exception->isOutputLimit());
        self::assertSame('Input file size is 20971521 bytes, above the limit of 20971520 bytes (max_bytes).', $exception->getMessage());
    }

    public function testInputDimensionLimitIsImageTooLarge(): void
    {
        $exception = LimitExceededException::forInput(Capabilities::LIMIT_MAX_WIDTH, 8192, 20000);

        self::assertSame(ErrorCode::IMAGE_TOO_LARGE, $exception->getErrorCode());
        self::assertFalse($exception->isOutputLimit());
        self::assertSame('Input width is 20000 px, above the limit of 8192 px (max_width).', $exception->getMessage());
    }

    public function testOutputLimitIsImageTooLarge(): void
    {
        $exception = LimitExceededException::forOutput(Capabilities::LIMIT_MAX_MEGAPIXELS, 40000000, 268402689);

        self::assertSame(ErrorCode::IMAGE_TOO_LARGE, $exception->getErrorCode());
        self::assertTrue($exception->isOutputLimit());
        self::assertSame('max_megapixels', $exception->getLimit());
        self::assertSame(
            'Resolved output area is 268402689 pixels, above the limit of 40000000 pixels (max_megapixels).',
            $exception->getMessage()
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function limitNames(): array
    {
        $names = [];
        foreach (Capabilities::limitNames() as $name) {
            $names[$name] = [$name];
        }

        return $names;
    }

    /**
     * @dataProvider limitNames
     */
    #[DataProvider('limitNames')]
    public function testEveryLimitCanBeReportedForTheInput(string $limit): void
    {
        $exception = LimitExceededException::forInput($limit, 1, 2);

        self::assertSame($limit, $exception->getLimit());
        self::assertSame($limit === 'max_bytes' ? 'file_too_large' : 'image_too_large', $exception->getErrorCode());
        self::assertStringEndsWith(' (' . $limit . ').', $exception->getMessage());
    }

    public function testByteSizeLimitDoesNotApplyToTheOutput(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LimitExceededException::forOutput(Capabilities::LIMIT_MAX_BYTES, 10, 11);
    }

    public function testUnknownLimitIsAProgrammingError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LimitExceededException::forInput('max_depth', 10, 11);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function valuesWithinTheLimit(): array
    {
        return [
            'equal' => [8192, 8192],
            'below' => [8192, 1],
            'zero maximum' => [0, 1],
            'negative maximum' => [-1, 1],
        ];
    }

    /**
     * @dataProvider valuesWithinTheLimit
     */
    #[DataProvider('valuesWithinTheLimit')]
    public function testValuesThatDoNotExceedTheLimitAreAProgrammingError(int $maximum, int $actual): void
    {
        $this->expectException(InvalidArgumentException::class);

        LimitExceededException::forInput(Capabilities::LIMIT_MAX_WIDTH, $maximum, $actual);
    }
}
