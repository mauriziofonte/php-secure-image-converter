<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests;

/**
 * Error handler for the test suite: turns notices, warnings and deprecations into exceptions so that they fail
 * the test that raised them, on every PHP version of the matrix (PHP 8.4 and 8.5 deprecations included).
 *
 * The only tolerated case is a deprecation raised from a file outside the project's own directories (src/ and
 * tests/), typically inside vendor/: dependencies must remain installable at their lowest versions on the newest
 * PHP, and their deprecations are not ours to fix. Those are counted and reported once on STDERR at shutdown.
 *
 * PHPUnit 8.5 to 11.5 do not replace an error handler that is already installed when a test starts, so the handler
 * installed by tests/bootstrap.php stays active for the whole run (StrictErrorHandlerTest proves it).
 */
final class StrictErrorHandler
{
    /**
     * @var list<string> absolute directory paths, each with a trailing directory separator
     */
    private $ownDirectories = [];

    /**
     * @var array<string, int> deprecation description => number of occurrences
     */
    private $foreignDeprecations = [];

    /**
     * @param list<string> $ownDirectories Directories whose files must never raise a notice, warning or
     *                                     deprecation. Directories that do not exist are ignored.
     */
    public function __construct(array $ownDirectories)
    {
        foreach ($ownDirectories as $directory) {
            $realPath = realpath($directory);
            if ($realPath !== false && is_dir($realPath)) {
                $this->ownDirectories[] = rtrim($realPath, '/\\') . DIRECTORY_SEPARATOR;
            }
        }
    }

    /**
     * Installs a new handler as the current PHP error handler and registers its shutdown report.
     *
     * @param list<string> $ownDirectories see the constructor
     */
    public static function install(array $ownDirectories): self
    {
        $handler = new self($ownDirectories);
        set_error_handler([$handler, 'handle']);
        register_shutdown_function([$handler, 'reportForeignDeprecations']);

        return $handler;
    }

    /**
     * Error handler callback, see set_error_handler().
     *
     * @throws \ErrorException for every error that is not silenced and is not a foreign deprecation
     *
     * @return bool true when the error has been handled (foreign deprecation), false to let PHP apply its default
     *              behaviour to an error silenced with the @ operator
     */
    public function handle(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        $isDeprecation = $severity === E_DEPRECATED || $severity === E_USER_DEPRECATED;
        if ($isDeprecation && !$this->isOwnFile($file)) {
            $key = sprintf('%s in %s:%d', $message, $file, $line);
            $this->foreignDeprecations[$key] = (isset($this->foreignDeprecations[$key]) ? $this->foreignDeprecations[$key] : 0) + 1;

            return true;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Whether the file is inside one of the project's own directories.
     */
    public function isOwnFile(string $file): bool
    {
        foreach ($this->ownDirectories as $directory) {
            if (strpos($file, $directory) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int> deprecations raised outside the project's own directories, with their count
     */
    public function getForeignDeprecations(): array
    {
        return $this->foreignDeprecations;
    }

    /**
     * Prints the tolerated foreign deprecations on STDERR, so that they stay visible without failing the build.
     */
    public function reportForeignDeprecations(): void
    {
        if ($this->foreignDeprecations === [] || !defined('STDERR')) {
            return;
        }

        $lines = [sprintf('%d distinct deprecation(s) raised outside src/ and tests/ (not fatal):', count($this->foreignDeprecations))];
        foreach ($this->foreignDeprecations as $description => $count) {
            $lines[] = sprintf('  [%dx] %s', $count, $description);
        }

        fwrite(STDERR, PHP_EOL . implode(PHP_EOL, $lines) . PHP_EOL);
    }
}
