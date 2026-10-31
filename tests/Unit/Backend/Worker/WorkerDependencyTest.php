<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend\Worker;

use Mfonte\SecureImageConverter\Backend\Worker\AbstractWorker;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The workers run as "php -n", which loads no shared extension but the one the backend names: their code may call
 * only what PHP 7.2 always builds in (Core, date, pcre, Reflection, SPL, standard) and their own extension. The
 * check reads the tokens of the worker files and looks each called function, named class and constant up in the
 * extensions of the running PHP, so that a 7.2 job sees the extensions as 7.2 splits them (json is one of its own
 * there, and shared in setup-php's build). Names looked up by string behind a check (function_exists(), defined(),
 * constant()) are left to the worker's own guards.
 *
 * @coversNothing
 */
#[CoversNothing]
final class WorkerDependencyTest extends TestCase
{
    /**
     * The extensions every PHP 7.2 build has, which "php -n" cannot lose.
     */
    private const CORE_EXTENSIONS = ['Core', 'date', 'pcre', 'Reflection', 'SPL', 'standard'];

    /**
     * Names in the worker files that are neither calls, classes nor constants: types and literals.
     */
    private const KEYWORDS = ['array', 'bool', 'callable', 'false', 'float', 'int', 'iterable', 'mixed', 'null', 'object', 'parent', 'self', 'static', 'string', 'strict_types', 'true', 'void'];

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function workerFiles(): array
    {
        $root = \dirname(__DIR__, 4);

        return [
            'AbstractWorker' => [$root . '/src/Backend/Worker/AbstractWorker.php', ''],
            'GdWorker' => [$root . '/src/Backend/Worker/GdWorker.php', 'gd'],
            'ImagickWorker' => [$root . '/src/Backend/Worker/ImagickWorker.php', 'imagick'],
            'gd.php' => [$root . '/resources/workers/gd.php', 'gd'],
            'imagick.php' => [$root . '/resources/workers/imagick.php', 'imagick'],
        ];
    }

    /**
     * @dataProvider workerFiles
     */
    #[DataProvider('workerFiles')]
    public function testAWorkerUsesOnlyTheBuiltInExtensionsAndItsOwn(string $file, string $extension): void
    {
        $outside = [];
        foreach (self::references($file) as $reference) {
            [$kind, $name] = $reference;
            if (!self::isAllowed($kind, $name, $extension)) {
                $outside[] = $kind . ' ' . $name;
            }
        }

        self::assertSame([], array_values(array_unique($outside)), 'Outside the built-in extensions and ' . ($extension !== '' ? $extension : 'none') . '.');
    }

    public function testTheCheckFindsAJsonCall(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sic-worker-');
        self::assertIsString($file);

        try {
            file_put_contents($file, "<?php\nnamespace A;\necho json_encode([\\JSON_THROW_ON_ERROR, new \\ArrayObject(), \\Imagick::class, image_type_to_extension(1)]);\n");
            $outside = [];
            foreach (self::references($file) as $reference) {
                if (!self::isAllowed($reference[0], $reference[1], 'gd')) {
                    $outside[] = $reference[0] . ' ' . $reference[1];
                }
            }
        } finally {
            unlink($file);
        }

        self::assertSame(['function json_encode', 'constant JSON_THROW_ON_ERROR', 'class Imagick'], $outside);
    }

    /**
     * The functions called, the classes named and the constants read by a PHP file, by name without the leading
     * namespace separator, in order.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function references(string $file): array
    {
        $tokens = [];
        $pending = null;
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            $id = is_array($token) ? $token[0] : null;
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (self::isName($token)) {
                // PHP 7 splits \Name and A\Name into separators and strings; PHP 8 has one token per name.
                $pending = ($pending ?? '') . ltrim((string) $token[1], '\\');
                continue;
            }
            if ($id === T_NS_SEPARATOR) {
                $pending = $pending !== null ? $pending . '\\' : '';
                continue;
            }
            if ($pending !== null) {
                $tokens[] = ['name', $pending];
                $pending = null;
            }
            $tokens[] = [$id !== null ? $id : (string) $token, is_array($token) ? $token[1] : (string) $token];
        }

        $references = [];
        foreach ($tokens as $index => $token) {
            if ($token[0] !== 'name') {
                continue;
            }
            $name = $token[1];
            $previous = $tokens[$index - 1][0] ?? null;
            $next = $tokens[$index + 1][0] ?? null;
            if (in_array($previous, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_CLASS, T_NAMESPACE, T_USE, T_GOTO], true)
                || in_array(strtolower($name), self::KEYWORDS, true)
            ) {
                continue;
            }
            if (in_array($previous, [T_NEW, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS], true) || $next === T_DOUBLE_COLON
                || ($previous === '(' && $next === T_VARIABLE && ($tokens[$index - 2][0] ?? null) === T_CATCH)
            ) {
                $references[] = ['class', $name];
            } elseif ($next === '(') {
                $references[] = ['function', $name];
            } elseif (preg_match('/\A[A-Z][A-Z0-9_]*\z/', $name) === 1) {
                $references[] = ['constant', $name];
            }
        }

        return $references;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isName($token): bool
    {
        if (!is_array($token)) {
            return false;
        }
        $names = [T_STRING];
        foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED'] as $constant) {
            if (defined($constant)) {
                $names[] = constant($constant);
            }
        }

        return in_array($token[0], $names, true);
    }

    private static function isAllowed(string $kind, string $name, string $extension): bool
    {
        if ($kind === 'function') {
            if ($extension === 'gd' && preg_match('/\A(?:image|gd_info\z)/i', $name) === 1) {
                return true;
            }
            foreach (self::CORE_EXTENSIONS as $core) {
                $functions = get_extension_funcs($core);
                foreach (is_array($functions) ? $functions : [] as $function) {
                    if (strcasecmp($function, $name) === 0) {
                        return true;
                    }
                }
            }

            return false;
        }
        if ($kind === 'class') {
            $namespace = substr(AbstractWorker::class, 0, (int) strrpos(AbstractWorker::class, '\\') + 1);
            if (strpos($name, $namespace) === 0 || strpos($name, '\\') === false && class_exists($namespace . $name)) {
                return true;
            }
            if ($extension === 'imagick' && preg_match('/\AImagick[A-Za-z]*\z/', $name) === 1) {
                return true;
            }
            if (!class_exists($name) && !interface_exists($name)) {
                return false;
            }
            $owner = (new \ReflectionClass($name))->getExtensionName();

            return is_string($owner) && in_array($owner, self::CORE_EXTENSIONS, true);
        }
        foreach (get_defined_constants(true) as $owner => $constants) {
            if (in_array($owner, self::CORE_EXTENSIONS, true) && array_key_exists($name, $constants)) {
                return true;
            }
        }

        return false;
    }
}
