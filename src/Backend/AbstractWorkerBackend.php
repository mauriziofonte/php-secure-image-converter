<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Backend;

use Mfonte\SecureImageConverter\Backend\Internal\ExtensionFile;
use Mfonte\SecureImageConverter\Backend\Worker\AbstractWorker;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Isolation\ExecutableFinder;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;

/**
 * A backend whose program is a PHP CLI worker (see AbstractWorker) using one PHP extension.
 *
 * The worker runs as "php -n [-d extension=<name or file>] -d display_errors=stderr -d memory_limit=-1 <script>
 * convert": -n loads no php.ini, so that neither the host configuration (auto_prepend_file, disable_functions, FPM
 * pool secrets in /usr/local/etc) nor its extensions reach the worker; the extension is loaded explicitly when the
 * PHP build does not include it statically (established by probing "php -n -m"): by name from the binary's built-in
 * extension directory, or, when BackendOptions::getPhpExtensionDirectory() is set, by the resolved path of its shared
 * object there, which is loaded only while no other user can have planted or changed it (Internal\ExtensionFile:
 * the directory, the file and the directories above them owned by root or the effective user and not writable by
 * others). Otherwise the backend is unavailable, and a job refused when the directory changed after probing.
 * PHP's own memory limit is off: the address space limit of the process (the memory ceiling) bounds it, including the
 * memory the image library allocates outside PHP. The job is passed in AbstractWorker::JOB_FILE, never on the command
 * line.
 *
 * The sandbox allowlist adds the time zone database (Debian's PHP reads /usr/share/zoneinfo at startup and aborts
 * without it) and what the extension needs; each run mounts the worker scripts and, from a configured extension
 * directory, the extension's shared object alone, read-only (getSandboxResourcePaths()).
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
        $modules = $this->execute(array_merge($this->php($programs['php']), ['-m']), $directory);
        if (!$modules->isSuccessful()) {
            return [self::FACT_UNAVAILABLE => 'the PHP CLI does not start'];
        }
        $load = '0';
        if (!self::listsModule($modules->getStdout(), $this->extension())) {
            $problem = $this->extensionProblem();
            if ($problem !== null) {
                return [self::FACT_UNAVAILABLE => $problem];
            }
            $modules = $this->execute(
                array_merge($this->php($programs['php']), ['-d', 'extension=' . $this->extensionToLoad(), '-m']),
                $directory
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

    /**
     * @throws ConversionFailedException when the worker would load its extension from a configured directory that is
     *                                   not safe (any more)
     */
    protected function command(BackendJob $job, string $input, string $output, array $programs, array $facts): array
    {
        if (($facts['load'] ?? '0') === '1') {
            $problem = $this->extensionProblem();
            if ($problem !== null) {
                throw new ConversionFailedException(sprintf('The %s backend does not load its extension: %s.', $this->getName(), $problem));
            }
        }

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
            'memory_bytes' => $this->getOptions()->getMemoryCeilingBytes(),
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
        $resources = [\dirname(__DIR__, 2) . '/resources/workers', __DIR__ . '/Worker'];
        $directory = $this->getOptions()->getPhpExtensionDirectory();
        $file = $directory !== null ? ExtensionFile::path($directory, $this->extension()) : null;

        return $file !== null ? array_merge($resources, [$file]) : $resources;
    }

    /**
     * The extension's shared object, when the worker loads it: its libraries are traced like the program's.
     */
    protected function tracedObjects(array $programs, WorkingDirectory $directory, array $facts): array
    {
        if (($facts['load'] ?? '0') !== '1') {
            return [];
        }
        $configured = $this->getOptions()->getPhpExtensionDirectory();
        if ($configured !== null) {
            $file = ExtensionFile::path($configured, $this->extension());

            return $file !== null ? [$file] : [];
        }
        $result = $this->execute(
            array_merge($this->php($programs['php']), ['-r', 'echo ini_get("extension_dir");']),
            $directory
        );
        $object = rtrim(trim($result->getStdout()), '/') . '/' . $this->extension() . '.so';

        return $result->isSuccessful() && ExecutableFinder::isAbsolutePath($object) && is_file($object) ? [$object] : [];
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
        $command = $this->php($php);
        if (($facts['load'] ?? '0') === '1') {
            array_push($command, '-d', 'extension=' . $this->extensionToLoad());
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

    /**
     * The PHP binary without php.ini.
     *
     * @return list<string>
     */
    private function php(string $php): array
    {
        return [$php, '-n'];
    }

    /**
     * The value of extension= that loads the extension: its name, found in the binary's built-in extension
     * directory, or the resolved path of its shared object in the configured directory.
     */
    private function extensionToLoad(): string
    {
        $directory = $this->getOptions()->getPhpExtensionDirectory();
        if ($directory === null) {
            return $this->extension();
        }
        $file = ExtensionFile::path($directory, $this->extension());

        return $file !== null ? $file : rtrim($directory, '/') . '/' . $this->extension() . '.so';
    }

    /**
     * Why the extension must not be loaded from the configured directory (ExtensionFile), or null.
     */
    private function extensionProblem(): ?string
    {
        $directory = $this->getOptions()->getPhpExtensionDirectory();

        return $directory !== null ? ExtensionFile::problem($directory, $this->extension()) : null;
    }

    private static function listsModule(string $output, string $module): bool
    {
        return preg_match('/^' . preg_quote($module, '/') . '\s*$/mi', $output) === 1;
    }

    /**
     * @return list<string>
     */
    private static function formats(string $list): array
    {
        return array_values(array_intersect(array_diff(ImageFormat::all(), [ImageFormat::SVG]), explode(',', $list)));
    }
}
