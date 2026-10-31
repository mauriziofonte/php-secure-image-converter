<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Backend;

use Mfonte\SecureImageConverter\Backend\Worker\AbstractWorker;
use Mfonte\SecureImageConverter\Backend\Worker\GdWorker;
use Mfonte\SecureImageConverter\Backend\Worker\ImagickWorker;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\ProcessResult;
use Mfonte\SecureImageConverter\Isolation\ProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The PHP workers run under "php -n" with only their own extension and the extensions PHP 7.2 always builds in
 * (Core, date, pcre, Reflection, SPL, standard): every function of the other extensions this PHP has without php.ini
 * is disabled (disable_functions), as a PHP 7 build with shared json, ctype, mbstring... would lack them. Probe and
 * conversion must answer as usual, without a message on the standard error.
 *
 * @covers \Mfonte\SecureImageConverter\Backend\Worker\AbstractWorker
 * @covers \Mfonte\SecureImageConverter\Backend\Worker\GdWorker
 * @covers \Mfonte\SecureImageConverter\Backend\Worker\ImagickWorker
 *
 * @group backends
 */
#[CoversClass(AbstractWorker::class)]
#[CoversClass(GdWorker::class)]
#[CoversClass(ImagickWorker::class)]
#[Group('backends')]
final class WorkerExtensionTest extends BackendTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function workerBackends(): array
    {
        return ['gd' => ['gd'], 'imagick' => ['imagick']];
    }

    /**
     * @dataProvider workerBackends
     */
    #[DataProvider('workerBackends')]
    public function testAWorkerNeedsNoOtherExtension(string $name): void
    {
        self::backend($name);
        $runner = new ProcessRunner(IsolationLevel::NONE);
        $directory = $this->workingDirectory();
        $php = [PHP_BINARY, '-n'];
        $modules = $runner->execute(array_merge($php, ['-m']), $directory);
        self::assertTrue($modules->isSuccessful(), $modules->getStderr());
        if (preg_match('/^' . $name . '\s*$/mi', $modules->getStdout()) !== 1) {
            $php = array_merge($php, ['-d', 'extension=' . $name]);
        }
        $list = $runner->execute(array_merge($php, ['-r', sprintf(
            '$f = []; foreach (get_loaded_extensions() as $e) { if (!in_array($e, ["Core", "date", "pcre", "Reflection", "SPL", "standard", "%s"], true)) { $f = array_merge($f, (array) get_extension_funcs($e)); } } echo implode(",", $f);',
            $name
        )]), $directory);
        self::assertTrue($list->isSuccessful(), $list->getStderr());
        $worker = array_merge($php, [
            '-d',
            'disable_functions=' . trim($list->getStdout()),
            '-d',
            'display_errors=stderr',
            '-d',
            'log_errors=0',
            \dirname(__DIR__, 3) . '/resources/workers/' . $name . '.php',
        ]);

        $probe = $runner->execute(array_merge($worker, ['probe']), $directory);
        self::assertSuccessfulAnswer($probe);
        $answer = json_decode($probe->getStdout(), true);
        self::assertIsArray($answer);
        self::assertIsArray($answer['inputs'] ?? null);
        self::assertContains('png', $answer['inputs']);

        self::assertTrue(copy(\dirname(__DIR__, 3) . '/resources/probe/still.png', $directory->pathOf('input.png')));
        $size = getimagesize($directory->pathOf('input.png'));
        self::assertIsArray($size);
        file_put_contents($directory->pathOf(AbstractWorker::JOB_FILE), json_encode([
            'input' => 'input.png',
            'input_format' => 'png',
            'input_width' => $size[0],
            'input_height' => $size[1],
            'output' => 'output.png',
            'output_format' => 'png',
            'width' => 4,
            'height' => 4,
            'fit' => 'cover',
            'quality' => 80,
            'effort' => 4,
            'lossless' => false,
            'strip' => true,
            'keep_icc' => false,
            'memory_bytes' => 268435456,
        ]));

        $convert = $runner->execute(array_merge($worker, ['convert']), $directory);
        self::assertSuccessfulAnswer($convert);
        self::assertSame(['ok' => true], json_decode($convert->getStdout(), true));
        $info = self::inspect($directory->pathOf('output.png'));
        self::assertNotNull($info);
        self::assertSame(['png', 4, 4], [$info->getFormat(), $info->getWidth(), $info->getHeight()]);
    }

    private static function assertSuccessfulAnswer(ProcessResult $result): void
    {
        self::assertSame(
            [0, ''],
            [$result->getExitCode(), $result->getStderr()],
            sprintf('stdout: %s', substr($result->getStdout(), 0, 2000))
        );
    }
}
