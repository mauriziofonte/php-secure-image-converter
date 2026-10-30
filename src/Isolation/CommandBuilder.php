<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Isolation;

use Mfonte\SecureImageConverter\Contract\IsolationLevel;

/**
 * Builds the argument list and the environment that ProcessRunner hands to proc_open() for an isolation level. The
 * command is wrapped, outermost first, in:
 *
 * - setsid (process level and above): a new session and process group, so that the whole group can be killed;
 * - prlimit with ResourceLimits (rlimit level and above);
 * - bwrap (sandbox level): new user, mount, PID, network, IPC, UTS and cgroup namespaces (--unshare-all, so no
 *   network at all), --die-with-parent, --new-session (no terminal injection), every capability dropped, the system
 *   paths and the per-run inputs mounted read-only, private /proc, /dev and /tmp, and the working directory as the
 *   only writable host path.
 *
 * Every element is passed as a separate argument: no shell ever parses the command (see toShellCommand() for PHP
 * 7.2 and 7.3).
 *
 * @internal used by ProcessRunner; not covered by the backward compatibility promise
 */
final class CommandBuilder
{
    /**
     * @var ProcessOptions
     */
    private $options;

    public function __construct(ProcessOptions $options)
    {
        $this->options = $options;
    }

    /**
     * The full argument list for proc_open().
     *
     * @param string               $level            IsolationLevel value
     * @param list<string>         $command          the command; its first element is an absolute path
     * @param array<string,string> $tools            absolute paths of the tools the level needs, keyed by
     *                                               ProcessOptions::TOOL_* name
     * @param string               $workingDirectory absolute path, mounted read-write in the sandbox
     * @param list<string>         $readOnlyPaths    absolute paths mounted read-only in the sandbox for this run
     *
     * @return list<string>
     */
    public function build(
        string $level,
        array $command,
        array $tools,
        string $workingDirectory,
        array $readOnlyPaths
    ): array {
        $rank = IsolationLevel::rank($level);
        if ($rank >= IsolationLevel::rank(IsolationLevel::SANDBOX)) {
            $command = array_merge(
                [$tools[ProcessOptions::TOOL_BWRAP]],
                $this->bubblewrapArguments($workingDirectory, $readOnlyPaths),
                ['--'],
                $command
            );
        }
        if ($rank >= IsolationLevel::rank(IsolationLevel::RLIMIT)) {
            $command = array_merge(
                [$tools[ProcessOptions::TOOL_PRLIMIT]],
                $this->options->getResourceLimits()->toPrlimitArguments(),
                ['--'],
                $command
            );
        }
        if ($rank >= IsolationLevel::rank(IsolationLevel::PROCESS)) {
            $command = array_merge([$tools[ProcessOptions::TOOL_SETSID], '--'], $command);
        }

        return $command;
    }

    /**
     * The bubblewrap options, without the program name and the final "--".
     *
     * @param list<string> $readOnlyPaths absolute paths mounted read-only for this run, in addition to the configured
     *                                    system paths
     *
     * @return list<string>
     */
    public function bubblewrapArguments(string $workingDirectory, array $readOnlyPaths): array
    {
        $arguments = ['--unshare-all', '--die-with-parent', '--new-session', '--cap-drop', 'ALL'];
        foreach ($this->options->getSandboxReadOnlyPaths() as $path) {
            if (is_link($path)) {
                $target = readlink($path);
                if (is_string($target)) {
                    array_push($arguments, '--symlink', $target, $path);
                }
            } elseif (file_exists($path)) {
                array_push($arguments, '--ro-bind', $path, $path);
            }
        }
        // /tmp is mounted before the per-run paths and the working directory, which usually live below it.
        array_push($arguments, '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp');
        foreach ($readOnlyPaths as $path) {
            array_push($arguments, '--ro-bind', $path, $path);
        }
        array_push($arguments, '--bind', $workingDirectory, $workingDirectory, '--chdir', $workingDirectory);

        return $arguments;
    }

    /**
     * The process environment, built from scratch: the inherited variables that are set in the PHP process, the
     * explicit variables, then PATH, HOME, TMPDIR and PWD.
     *
     * @return array<string, string>
     */
    public function environment(string $workingDirectory): array
    {
        $environment = [];
        foreach ($this->options->getInheritedEnvironment() as $name) {
            $value = getenv($name);
            if (is_string($value) && strpos($value, "\0") === false) {
                $environment[$name] = $value;
            }
        }
        foreach ($this->options->getEnvironment() as $name => $value) {
            $environment[$name] = $value;
        }
        $environment['PATH'] = implode(':', $this->options->getSearchPath());
        $environment['HOME'] = $workingDirectory;
        $environment['TMPDIR'] = $workingDirectory;
        $environment['PWD'] = $workingDirectory;

        return $environment;
    }

    /**
     * The argument list as a /bin/sh command line for PHP 7.2 and 7.3, whose proc_open() accepts only a string: each
     * argument is quoted with escapeshellarg(), and "exec" replaces the shell with the command, so that no shell
     * process remains and the process ID that PHP reports is the command's own.
     *
     * @param list<string> $arguments
     */
    public static function toShellCommand(array $arguments): string
    {
        return 'exec ' . implode(' ', array_map('escapeshellarg', $arguments));
    }
}
