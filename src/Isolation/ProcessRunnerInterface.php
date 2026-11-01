<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Isolation;

use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionTimeoutException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\IsolationUnavailableException;

/**
 * Runs external programs at a fixed isolation level. ProcessRunner is the implementation; the backends depend on
 * this interface only, so that their command lines can be tested with a fake runner that starts nothing.
 *
 * See ProcessRunner for what each level guarantees, how the command, the standard input and the read-only paths are
 * checked, and how the output is captured.
 */
interface ProcessRunnerInterface
{
    /**
     * The IsolationLevel value this runner enforces on every run.
     */
    public function getLevel(): string;

    /**
     * The options every run starts with.
     */
    public function getOptions(): ProcessOptions;

    /**
     * A runner at the same level with other options, for example the resource limits and sandbox paths of one job.
     * This runner is left unchanged.
     */
    public function withOptions(ProcessOptions $options): ProcessRunnerInterface;

    /**
     * Why nothing can run at this level on this host (a fixed English sentence fragment without paths), or null when
     * runs can start.
     */
    public function getUnavailableReason(): ?string;

    /**
     * Runs the command and fails when its wall-clock timeout expires.
     *
     * @param list<string> $command       program and arguments; the program is a name looked up in the search path or
     *                                    an absolute path
     * @param string|null  $stdinPath     regular file directly inside the working directory to use as standard input;
     *                                    /dev/null when null
     * @param list<string> $readOnlyPaths existing absolute paths mounted read-only in the sandbox for this run
     *
     * @throws ConversionTimeoutException    when the wall-clock timeout expired
     * @throws IsolationUnavailableException when the level is not available on the host
     * @throws InvalidArgumentException      when the command, the standard input, the read-only paths or the working
     *                                       directory are invalid
     * @throws ConversionFailedException     when the process cannot be started or its output or status is lost
     */
    public function run(
        array $command,
        WorkingDirectory $workingDirectory,
        ?string $stdinPath = null,
        array $readOnlyPaths = []
    ): ProcessResult;

    /**
     * Runs the command and reports a timeout in the result instead of throwing. Same parameters as run().
     *
     * @param list<string> $command
     * @param list<string> $readOnlyPaths
     *
     * @throws IsolationUnavailableException when the level is not available on the host
     * @throws InvalidArgumentException      when the command, the standard input, the read-only paths or the working
     *                                       directory are invalid
     * @throws ConversionFailedException     when the process cannot be started or its output or status is lost
     */
    public function execute(
        array $command,
        WorkingDirectory $workingDirectory,
        ?string $stdinPath = null,
        array $readOnlyPaths = []
    ): ProcessResult;
}
