<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Validation;

use Mfonte\SecureImageConverter\Validation\ImageInspector;
use PHPUnit\Framework\Attributes\CoversNothing;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Static proof that input validation decodes nothing: the source of the Validation namespace calls only the
 * functions listed here (string, array and integer helpers, and stream reads), references no class outside this
 * library, and has no dynamic call, eval, include or shell execution. GD, Imagick, Gmagick, vips, EXIF, finfo, zlib
 * and process functions are therefore out of reach. Adding a function to the list is a deliberate, reviewed change.
 *
 * ImageCorpusTest gives the runtime side of the proof: the bytes the inspector reads from each bomb.
 *
 * @coversNothing
 */
#[CoversNothing]
final class NoDecoderTest extends TestCase
{
    /**
     * Every function the Validation namespace may call.
     */
    private const ALLOWED_FUNCTIONS = [
        // Integers, strings and arrays.
        'abs', 'array_diff', 'array_intersect', 'array_keys', 'array_unique', 'array_values', 'count', 'crc32',
        'implode', 'in_array', 'intdiv', 'is_array', 'is_int', 'is_resource', 'is_string', 'max', 'min', 'ord',
        'sprintf', 'str_split', 'strlen', 'strncmp', 'strpos', 'strspn', 'substr', 'unpack',
        // The path check of ImageInspector::inspect().
        'preg_match',
        // Opening, checking and reading the input.
        'fclose', 'fopen', 'fseek', 'fstat', 'get_resource_type', 'is_file', 'is_readable', 'stream_get_contents',
        'stream_get_meta_data',
    ];

    /**
     * Function name prefixes that decode, inspect with a library, decompress or run something: none may ever be
     * allowed.
     */
    private const FORBIDDEN_PREFIXES = [
        'image', 'getimagesize', 'exif_', 'read_exif', 'iptc', 'finfo', 'mime_content_type', 'gz', 'zlib_', 'inflate_',
        'bzdecompress', 'exec', 'shell_exec', 'system', 'passthru', 'proc_', 'popen', 'pcntl_', 'curl_',
        'file_get_contents', 'readfile', 'file', 'call_user_func', 'create_function', 'assert',
    ];

    /**
     * Fully qualified classes from outside this library that the Validation namespace may name.
     */
    private const ALLOWED_GLOBAL_CLASSES = ['TypeError'];

    private const NAMESPACE_PREFIX = 'Mfonte\\SecureImageConverter\\';

    public function testTheAllowlistHoldsNoDecodingOrExecutingFunction(): void
    {
        foreach (self::ALLOWED_FUNCTIONS as $function) {
            foreach (self::FORBIDDEN_PREFIXES as $prefix) {
                self::assertStringStartsNotWith($prefix, $function);
            }
        }
    }

    public function testValidationCallsOnlyAllowlistedFunctions(): void
    {
        $called = [];
        foreach (self::sourceFiles() as $path) {
            $tokens = self::tokens($path);
            foreach ($tokens as $index => $token) {
                if ($token[0] !== 'name' || !isset($tokens[$index + 1]) || $tokens[$index + 1][1] !== '(') {
                    continue;
                }
                $previous = $index > 0 ? $tokens[$index - 1][0] : null;
                if (in_array($previous, self::notFunctionCallPrefixes(), true)) {
                    continue;
                }
                $called[strtolower(ltrim($token[1], '\\'))][] = basename($path);
            }
        }

        self::assertNotEmpty($called);
        self::assertSame([], array_diff(array_keys($called), self::ALLOWED_FUNCTIONS), 'functions called outside the allowlist');
    }

    public function testValidationNamesNoClassOutsideThisLibrary(): void
    {
        foreach (self::sourceFiles() as $path) {
            $tokens = self::tokens($path);
            $inClass = false;
            foreach ($tokens as $index => $token) {
                $inClass = $inClass || in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT], true);
                if ($token[0] !== 'name') {
                    continue;
                }
                $previous = $index > 0 ? $tokens[$index - 1][0] : null;
                $next = isset($tokens[$index + 1]) ? $tokens[$index + 1][1] : '';
                if ($previous === T_NAMESPACE) {
                    self::assertStringStartsWith(self::NAMESPACE_PREFIX, $token[1] . '\\', basename($path));
                } elseif ($previous === T_USE && !$inClass) {
                    self::assertStringStartsWith(self::NAMESPACE_PREFIX, $token[1], basename($path) . ' imports a foreign class');
                } elseif (strpos($token[1], '\\') !== false && $next !== '(') {
                    $name = ltrim($token[1], '\\');
                    self::assertTrue(
                        strpos($name, self::NAMESPACE_PREFIX) === 0 || in_array($name, self::ALLOWED_GLOBAL_CLASSES, true),
                        basename($path) . ' names ' . $name
                    );
                }
            }
        }
    }

    public function testValidationHasNoDynamicCodeIncludeOrShellExecution(): void
    {
        $forbidden = [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EXIT, '`'];
        foreach (self::sourceFiles() as $path) {
            $tokens = self::tokens($path);
            foreach ($tokens as $index => $token) {
                self::assertNotContains($token[0], $forbidden, basename($path));
                $next = isset($tokens[$index + 1]) ? $tokens[$index + 1][1] : '';
                if ($token[0] === T_VARIABLE && $next === '(') {
                    self::fail(basename($path) . ' calls a function through a variable');
                }
                if ($token[0] === T_NEW) {
                    self::assertSame('name', $tokens[$index + 1][0], basename($path) . ' instantiates a class named at runtime');
                }
            }
        }
    }

    /**
     * Tokens before a name followed by "(" that make it something other than a function call.
     *
     * @return list<int|string>
     */
    private static function notFunctionCallPrefixes(): array
    {
        $prefixes = [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST];
        if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
            $prefixes[] = constant('T_NULLSAFE_OBJECT_OPERATOR');
        }

        return $prefixes;
    }

    /**
     * @return list<string>
     */
    private static function sourceFiles(): array
    {
        $directory = dirname((string) (new \ReflectionClass(ImageInspector::class))->getFileName());
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        self::assertGreaterThanOrEqual(10, count($files), 'the scan must see the inspector and every parser');

        return $files;
    }

    /**
     * The significant tokens of a file as [id, text]: whitespace and comments are dropped, a single-character token
     * has itself as id, and a possibly namespaced name (T_STRING parts and separators on PHP 7, T_NAME_* on PHP 8)
     * is one token of id "name".
     *
     * @return list<array{0: int|string, 1: string}>
     */
    private static function tokens(string $path): array
    {
        $code = file_get_contents($path);
        self::assertIsString($code);
        $nameIds = [T_STRING, T_NS_SEPARATOR];
        foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $constant) {
            if (defined($constant)) {
                $nameIds[] = constant($constant);
            }
        }

        $tokens = [];
        $name = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], $nameIds, true)) {
                $name .= $token[1];
                continue;
            }
            if ($name !== '') {
                $tokens[] = ['name', $name];
                $name = '';
            }
            if (!is_array($token)) {
                $tokens[] = [$token, $token];
            } elseif (!in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                $tokens[] = [$token[0], $token[1]];
            }
        }
        if ($name !== '') {
            $tokens[] = ['name', $name];
        }

        return $tokens;
    }
}
