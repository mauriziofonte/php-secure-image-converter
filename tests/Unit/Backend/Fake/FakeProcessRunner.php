<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend\Fake;

use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\ProcessResult;
use Mfonte\SecureImageConverter\Isolation\ProcessRunnerInterface;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;

/**
 * A runner that starts nothing: it records every run (shared by the runners derived with withOptions()) and answers
 * with a handler, by default a successful empty run.
 */
final class FakeProcessRunner implements ProcessRunnerInterface
{
    /**
     * @var ProcessOptions
     */
    private $options;

    /**
     * @var string
     */
    private $level;

    /**
     * @var \ArrayObject<int, array{command: list<string>, options: ProcessOptions, directory: string, read_only: list<string>}>
     */
    private $runs;

    /**
     * @var callable(list<string>, WorkingDirectory, ProcessOptions): ProcessResult
     */
    private $handler;

    /**
     * @param (callable(list<string>, WorkingDirectory, ProcessOptions): ProcessResult)|null                                            $handler
     * @param \ArrayObject<int, array{command: list<string>, options: ProcessOptions, directory: string, read_only: list<string>}>|null $runs
     */
    public function __construct(
        ?callable $handler = null,
        ?ProcessOptions $options = null,
        string $level = IsolationLevel::SANDBOX,
        ?\ArrayObject $runs = null
    ) {
        $this->handler = $handler !== null ? $handler : static function (): ProcessResult {
            return self::result(0);
        };
        $this->options = $options !== null ? $options : new ProcessOptions();
        $this->level = $level;
        $this->runs = $runs !== null ? $runs : new \ArrayObject();
    }

    public static function result(int $exitCode, string $stdout = '', string $stderr = '', ?int $signal = null, bool $timedOut = false): ProcessResult
    {
        return new ProcessResult(
            $signal === null ? $exitCode : null,
            $signal,
            $timedOut,
            0.01,
            $stdout,
            $stderr,
            false,
            false,
            IsolationLevel::SANDBOX
        );
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getOptions(): ProcessOptions
    {
        return $this->options;
    }

    public function withOptions(ProcessOptions $options): ProcessRunnerInterface
    {
        return new self($this->handler, $options, $this->level, $this->runs);
    }

    public function getUnavailableReason(): ?string
    {
        return null;
    }

    public function run(array $command, WorkingDirectory $workingDirectory, ?string $stdinPath = null, array $readOnlyPaths = []): ProcessResult
    {
        return $this->execute($command, $workingDirectory, $stdinPath, $readOnlyPaths);
    }

    public function execute(array $command, WorkingDirectory $workingDirectory, ?string $stdinPath = null, array $readOnlyPaths = []): ProcessResult
    {
        $this->runs[] = [
            'command' => $command,
            'options' => $this->options,
            'directory' => $workingDirectory->getPath(),
            'read_only' => $readOnlyPaths,
        ];

        return ($this->handler)($command, $workingDirectory, $this->options);
    }

    /**
     * @return list<array{command: list<string>, options: ProcessOptions, directory: string, read_only: list<string>}>
     */
    public function getRuns(): array
    {
        return array_values($this->runs->getArrayCopy());
    }
}
