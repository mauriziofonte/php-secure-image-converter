<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract;

use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * How strongly a conversion is isolated from the calling PHP process and from the host, as lowercase string
 * constants ordered from weakest to strongest. Each level includes every guarantee of the levels below it.
 *
 * The level actually enforced on a host is detected at run time and reported by Capabilities::getIsolationLevel()
 * and by ConversionResult::getIsolationLevel(). Consumers compare levels with isAtLeast(), never with string
 * comparison.
 */
final class IsolationLevel
{
    /**
     * Decoding and encoding run inside the calling PHP process (GD or Imagick extension). A decoder crash or memory
     * exhaustion takes the PHP worker down, and only max_execution_time bounds the duration.
     */
    public const NONE = 'none';

    /**
     * Decoding and encoding run in a separate process (proc_open) that is killed when its wall-clock timeout
     * expires. Memory and CPU are not otherwise bounded.
     */
    public const PROCESS = 'process';

    /**
     * PROCESS, plus kernel resource limits applied with prlimit: address space, CPU time, file size and number of
     * open files.
     */
    public const RLIMIT = 'rlimit';

    /**
     * RLIMIT, plus a bubblewrap sandbox: new namespaces without network access, a read-only view of the system and a
     * private temporary filesystem, so that a compromised decoder can neither reach the network nor alter the host.
     */
    public const SANDBOX = 'sandbox';

    /**
     * @var array<string, int>
     */
    private const RANKS = [
        self::NONE => 0,
        self::PROCESS => 1,
        self::RLIMIT => 2,
        self::SANDBOX => 3,
    ];

    private function __construct()
    {
    }

    /**
     * Every level, from weakest to strongest: none, process, rlimit, sandbox.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::RANKS);
    }

    /**
     * Whether the string is a known level. Levels are case-sensitive and always lowercase.
     */
    public static function isValid(string $level): bool
    {
        return isset(self::RANKS[$level]);
    }

    /**
     * Position of a level in the ordering: 0 for none up to 3 for sandbox.
     *
     * @throws InvalidArgumentException when the level is not known
     */
    public static function rank(string $level): int
    {
        if (!self::isValid($level)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown isolation level; expected one of: %s.',
                implode(', ', self::all())
            ));
        }

        return self::RANKS[$level];
    }

    /**
     * Whether $level provides at least the guarantees of $minimum, e.g. isAtLeast('sandbox', 'rlimit') is true and
     * isAtLeast('process', 'rlimit') is false.
     *
     * @throws InvalidArgumentException when either level is not known
     */
    public static function isAtLeast(string $level, string $minimum): bool
    {
        return self::rank($level) >= self::rank($minimum);
    }
}
