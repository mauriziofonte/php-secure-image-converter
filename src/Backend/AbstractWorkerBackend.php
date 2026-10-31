<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

use Mfonte\SecureImageConverter\Backend\Worker\AbstractWorker;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\ExecutableFinder;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;
use Mfonte\SecureImageConverter\Validation\ImageInfo;

/**
 * A backend whose program is a PHP CLI worker (see AbstractWorker) using one PHP extension.
 *
 * The worker runs as "php -n [-d extension=<name>] -d display_errors=stderr -d memory_limit=-1 <script> convert":
 * -n loads no php.ini, so that neither the host configuration (auto_prepend_file, disable_functions, FPM pool
 * secrets in /usr/local/etc) nor its extensions reach the worker; the extension is loaded explicitly from the
 * extension directory when the PHP build does not include it statically (established by probing "php -n -m").
 * PHP's own memory limit is off: the address space limit of the job bounds the process, including the memory the
 * image library allocates outside PHP. The job is passed in AbstractWorker::JOB_FILE, never on the command line.
 *
 * The sandbox allowlist adds the time zone database (Debian's PHP reads /usr/share/zoneinfo at startup and aborts
 * without it) and what the extension needs; each run mounts the worker scripts (getSandboxResourcePaths()).
 *
 * The PHP binary is BackendOptions::getPhpBinary(), else the running PHP when it is the CLI, else "php" from the
 * runner's search path.
 */
abstract class AbstractWorkerBackend extends AbstractBackend
{
    /**
     * Time zone database read by distribution builds of PHP.
     */
    private const ZONEINFO = '/usr/share/zoneinfo';

    /**
     * Name of the PHP extension, as in extension=<name>.
     */
    abstract protected function extension(): string;

    /**
     * Base name of the entry script in resources/workers.
     */
    abstract protected function workerName(): string;

    protected function programs(ExecutableFinder $finder): array
    {
        $php = $this->getOptions()->getPhpBinary();
        if ($php === null && PHP_SAPI === 'cli' && ExecutableFinder::isAbsolutePath(PHP_BINARY)) {
            $php = PHP_BINARY;
        }
        if ($php !== null && !is_executable($php)) {
            $php = null;
        }

        return ['php' => $php !== null ? $php : $finder->find('php')];
    }

    protected function discover(array $programs, WorkingDirectory $directory): array
    {
        $memory = $this->estimateMemoryBytes(self::probeJob());
        $modules = $this->execute([$programs['php'], '-n', '-m'], $directory, $memory);
        if (!$modules->isSuccessful()) {
            return [self::FACT_UNAVAILABLE => 'the PHP CLI does not start'];
        }
        $load = '0';
        if (!self::listsModule($modules->getStdout(), $this->extension())) {
            $modules = $this->execute(
                [$programs['php'], '-n', '-d', 'extension=' . $this->extension(), '-m'],
                $directory,
                $memory
            );
            if (!$modules->isSuccessful() || !self::listsModule($modules->getStdout(), $this->extension())) {
                return [self::FACT_UNAVAILABLE => 'the ' . $this->extension() . ' extension is not available to the PHP CLI'];
            }
            $load = '1';
        }
        $facts = ['load' => $load];
        $probe = $this->execute(
            array_merge($this->phpCommand($programs['php'], $facts), ['probe']),
            $directory,
            $memory,
            $this->environment($directory, $facts)
        );
        $answer = $probe->isSuccessful() ? json_decode(trim($probe->getStdout()), true) : null;
        if (!is_array($answer)) {
            return [self::FACT_UNAVAILABLE => 'the ' . $this->workerName() . ' worker does not run'];
        }
        foreach (['inputs', 'outputs', 'lossless'] as $key) {
            $list = is_array($answer[$key] ?? null) ? array_filter($answer[$key], 'is_string') : [];
            $facts[$key] = implode(',', array_values(array_intersect(AbstractWorker::FORMATS, $list)));
        }

        return $facts;
    }

    protected function candidates(array $facts): array
    {
        $candidates = self::noCandidates();
        $candidates['inputs'] = self::formats($facts['inputs'] ?? '');
        $candidates['outputs'] = self::formats($facts['outputs'] ?? '');
        $candidates['lossless'] = self::formats($facts['lossless'] ?? '');
        $candidates['first_frame'] = [ImageFormat::PNG, ImageFormat::GIF, ImageFormat::WEBP];
        $candidates['fits'] = ConversionRequest::fitModes();

        return $candidates;
    }

    protected function command(BackendJob $job, string $input, string $output, array $programs, array $facts): array
    {
        return array_merge($this->phpCommand($programs['php'], $facts), ['convert']);
    }

    protected function prepare(BackendJob $job, WorkingDirectory $directory, string $input, string $output): void
    {
        if ($job->isAnimated()) {
            throw new ConversionFailedException(sprintf('The %s backend does not write animations.', $this->getName()));
        }
        $json = json_encode([
            'input' => $input,
            'input_format' => $job->getInputFormat(),
            'input_width' => $job->getInput()->getWidth(),
            'input_height' => $job->getInput()->getHeight(),
            'output' => $output,
            'output_format' => $job->getOutputFormat(),
            'width' => $job->getWidth(),
            'height' => $job->getHeight(),
            'fit' => $job->getFit(),
            'quality' => $job->getQuality(),
            'effort' => $job->getEffort(),
            'lossless' => $job->needsLosslessMode(),
            'strip' => $job->shouldStripMetadata(),
            'keep_icc' => $job->shouldKeepIccProfile(),
            'memory_bytes' => $this->estimateMemoryBytes($job),
        ]);
        $path = $directory->pathOf(AbstractWorker::JOB_FILE);
        $file = is_string($json) ? @fopen($path, 'xb') : false;
        if ($file === false || fwrite($file, (string) $json) !== strlen((string) $json) || !fclose($file)) {
            throw new ConversionFailedException('The worker job file could not be written.');
        }
    }

    protected function sandboxData(): array
    {
        return [self::ZONEINFO];
    }

    protected function sandboxResources(): array
    {
        return [\dirname(__DIR__, 2) . '/resources/workers', __DIR__ . '/Worker'];
    }

    protected function isWorker(): bool
    {
        return true;
    }

    /**
     * Absolute path of the entry script.
     */
    final protected function workerScript(): string
    {
        $directory = realpath(\dirname(__DIR__, 2) . '/resources/workers');

        return (is_string($directory) ? $directory : \dirname(__DIR__, 2) . '/resources/workers') . '/' . $this->workerName() . '.php';
    }

    /**
     * @param array<string, string> $facts
     *
     * @return list<string>
     */
    private function phpCommand(string $php, array $facts): array
    {
        $command = [$php, '-n'];
        if (($facts['load'] ?? '0') === '1') {
            array_push($command, '-d', 'extension=' . $this->extension());
        }
        array_push(
            $command,
            '-d',
            'display_errors=stderr',
            '-d',
            'log_errors=0',
            '-d',
            'memory_limit=-1',
            $this->workerScript()
        );

        return $command;
    }

    private static function listsModule(string $output, string $module): bool
    {
        return preg_match('/^' . preg_quote($module, '/') . '\s*$/mi', $output) === 1;
    }

    /**
     * A one-pixel job, for the memory budget of the discovery runs.
     */
    private static function probeJob(): BackendJob
    {
        return new BackendJob(
            '/dev/null',
            new ImageInfo(ImageFormat::PNG, 1, 1, 1, 1),
            '/dev/zero',
            ImageFormat::PNG,
            1,
            1
        );
    }

    /**
     * @return list<string>
     */
    private static function formats(string $list): array
    {
        return array_values(array_intersect(array_diff(ImageFormat::all(), [ImageFormat::SVG]), explode(',', $list)));
    }
}
