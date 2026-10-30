<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Contract\Exception;

use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionTimeoutException;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidRequestException;
use Mfonte\SecureImageConverter\Contract\Exception\IsolationUnavailableException;
use Mfonte\SecureImageConverter\Contract\Exception\LimitExceededException;
use Mfonte\SecureImageConverter\Contract\Exception\OutputVerificationException;
use Mfonte\SecureImageConverter\Contract\Exception\UnsupportedFormatException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Contract\Exception\InvalidRequestException
 */
#[CoversClass(InvalidRequestException::class)]
final class ExceptionHierarchyTest extends TestCase
{
    /**
     * Every exception of the contract, with the SPL exception it must extend and the HTTP status its docblock
     * documents for the API.
     *
     * @return array<string, array{class-string<\Throwable>, class-string<\Throwable>, int}>
     */
    public static function exceptions(): array
    {
        return [
            'InvalidRequestException' => [InvalidRequestException::class, \InvalidArgumentException::class, 400],
            'UnsupportedFormatException' => [UnsupportedFormatException::class, \UnexpectedValueException::class, 415],
            'InvalidImageException' => [InvalidImageException::class, \UnexpectedValueException::class, 422],
            'LimitExceededException' => [LimitExceededException::class, \RangeException::class, 413],
            'IsolationUnavailableException' => [IsolationUnavailableException::class, \RuntimeException::class, 503],
            'ConcurrencyLimitException' => [ConcurrencyLimitException::class, \OverflowException::class, 503],
            'ConversionFailedException' => [ConversionFailedException::class, \RuntimeException::class, 500],
            'ConversionTimeoutException' => [ConversionTimeoutException::class, ConversionFailedException::class, 504],
            'OutputVerificationException' => [OutputVerificationException::class, \UnexpectedValueException::class, 500],
            'InvalidArgumentException' => [InvalidArgumentException::class, \InvalidArgumentException::class, 500],
        ];
    }

    /**
     * @param class-string<\Throwable> $class
     * @param class-string<\Throwable> $parent
     *
     * @dataProvider exceptions
     */
    #[DataProvider('exceptions')]
    public function testExceptionImplementsTheMarkerAndExtendsItsParent(string $class, string $parent, int $status): void
    {
        $exception = new $class('message');

        self::assertInstanceOf(ExceptionInterface::class, $exception);
        self::assertInstanceOf($parent, $exception);
        self::assertSame('message', $exception->getMessage());

        $reflection = new \ReflectionClass($class);
        $docComment = $reflection->getDocComment();
        self::assertIsString($docComment);
        self::assertStringContainsString(sprintf('Expected HTTP status in the API: %d', $status), $docComment);
    }

    public function testTimeoutIsAConversionFailure(): void
    {
        self::assertInstanceOf(ConversionFailedException::class, new ConversionTimeoutException());
    }

    public function testInvalidRequestForParameterCarriesTheParameterName(): void
    {
        $exception = InvalidRequestException::forParameter('quality', 'expected an integer from 1 to 100, got 0');

        self::assertSame('quality', $exception->getParameter());
        self::assertSame('Invalid parameter "quality": expected an integer from 1 to 100, got 0.', $exception->getMessage());
    }

    public function testInvalidRequestWithoutParameter(): void
    {
        self::assertNull(InvalidRequestException::forParameter(null, 'unknown parameter')->getParameter());
        self::assertSame('Invalid request: unknown parameter.', InvalidRequestException::forParameter(null, 'unknown parameter')->getMessage());
        self::assertNull((new InvalidRequestException('plain'))->getParameter());
    }
}
