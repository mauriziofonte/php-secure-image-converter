<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Integration\Backend;

use Mfonte\SecureImageConverter\Backend\AbstractBackend;
use Mfonte\SecureImageConverter\Backend\AbstractWorkerBackend;
use Mfonte\SecureImageConverter\Backend\GdBackend;
use Mfonte\SecureImageConverter\Backend\ImagickBackend;
use Mfonte\SecureImageConverter\Backend\Internal\SandboxPaths;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * What a backend sees in the sandbox: its allowlist (its programs, the libraries, its own data) and nothing else of
 * the host. The CI job "backends" and tests/docker/Dockerfile put a page at /usr/share/nginx/html/index.html, the
 * document root of a web server running on the same host, which must stay invisible.
 *
 * @covers \Mfonte\SecureImageConverter\Backend\AbstractBackend
 * @covers \Mfonte\SecureImageConverter\Backend\AbstractWorkerBackend
 * @covers \Mfonte\SecureImageConverter\Backend\Internal\SandboxPaths
 *
 * @group backends
 * @group sandbox
 */
#[CoversClass(AbstractBackend::class)]
#[CoversClass(AbstractWorkerBackend::class)]
#[CoversClass(SandboxPaths::class)]
#[Group('backends')]
#[Group('sandbox')]
final class SandboxVisibilityTest extends BackendTestCase
{
    /**
     * Host data and configuration that no backend needs.
     */
    private const PRIVATE_PATHS = [
        '/usr/share/nginx/html',
        '/usr/share/doc',
        '/usr/include',
        '/usr/local/etc',
        '/usr/local/src',
        '/etc/passwd',
        '/etc/hostname',
        '/etc/ssl',
        '/home',
        '/root',
        '/var',
        '/srv',
        '/opt',
    ];

    /**
     * Every path of the allowlist is the backend's own: none is or contains a private path, the system directories as
     * a whole or the checkout of this library.
     *
     * @dataProvider enabledBackends
     */
    #[DataProvider('enabledBackends')]
    public function testTheAllowlistHoldsNoHostData(string $name): void
    {
        $paths = self::backend($name)->getSandboxPaths();
        $private = array_merge(self::PRIVATE_PATHS, ['/usr/share', '/etc/ImageMagick-6', '/etc/ImageMagick-7', '/etc/php', self::checkout() . '/tests', self::checkout() . '/vendor']);

        self::assertNotSame([], $paths);
        foreach ($paths as $path) {
            self::assertNotContains($path, ['/', '/usr', '/etc', '/usr/local', '/usr/bin', '/usr/share', self::checkout()], 'The allowlist exposes a whole system directory.');
            foreach ($private as $hidden) {
                self::assertStringStartsNotWith(rtrim($path, '/') . '/', $hidden . '/', sprintf('%s exposes %s.', $path, $hidden));
            }
        }
    }

    /**
     * A PHP worker's sandbox, seen from inside: no private path, only the PHP binary in /usr/bin, an empty read-only
     * /tmp, TMPDIR and HOME in the writable working directory (so that tmpfile() works), and its time zone data,
     * worker scripts and (for Imagick) policy visible.
     */
    public function testAPhpWorkerSeesOnlyItsAllowlist(): void
    {
        self::requireLevel(IsolationLevel::SANDBOX);
        $backend = null;
        foreach ([ImagickBackend::NAME, GdBackend::NAME] as $name) {
            if (in_array($name, self::enabledNames(), true)) {
                $candidate = self::backend($name);
                if ($candidate->getCapabilities()->isAvailable()) {
                    $backend = $candidate;
                    break;
                }
            }
        }
        if ($backend === null) {
            self::markTestSkipped('Neither PHP worker backend is enabled.');
        }
        $command = $backend->commandLine($this->job(self::still(ImageFormat::PNG), ImageFormat::PNG));
        $script = (string) $command[count($command) - 2];
        // A private path above an allowed one (/home above a checkout in /home) is visible as an empty skeleton.
        $visible = [self::checkout() . '/composer.json', self::checkout() . '/vendor', \dirname(__DIR__, 2) . '/Fixtures/backend/still.png'];
        foreach (self::PRIVATE_PATHS as $path) {
            $above = false;
            foreach ($backend->getSandboxPaths() as $allowed) {
                $above = $above || strpos($allowed, $path . '/') === 0;
            }
            if (!$above) {
                $visible[] = $path;
            }
        }
        $code = sprintf(
            '$seen = []; foreach (%s as $path) { if (file_exists($path) && !(is_dir($path) && count((array) scandir($path)) <= 2)) { $seen[] = $path; } }'
            . ' $tmp = tmpfile(); echo json_encode(["seen" => $seen, "usr_bin" => array_values(array_diff((array) @scandir("/usr/bin"), [".", ".."])),'
            . ' "tmp" => array_values(array_diff((array) @scandir("/tmp"), [".", ".."])), "tmp_writable" => @file_put_contents("/tmp/probe", "x") !== false,'
            . ' "tmpdir" => getenv("TMPDIR"), "home" => getenv("HOME"), "cwd" => getcwd(), "tmpfile" => is_resource($tmp),'
            . ' "zoneinfo" => is_dir("/usr/share/zoneinfo"), "script" => is_file(%s), "policy" => is_file(%s)]);',
            var_export($visible, true),
            var_export($script, true),
            var_export(ImagickBackend::policyDirectory() . '/policy.xml', true)
        );
        $directory = $this->workingDirectory();

        $result = $backend->createJobRunner(268435456)->execute([(string) $command[0], '-n', '-r', $code], $directory);

        self::assertTrue($result->isSuccessful(), $result->getStderr());
        self::assertSame(IsolationLevel::SANDBOX, $result->getIsolationLevel());
        $seen = json_decode($result->getStdout(), true);
        self::assertIsArray($seen, $result->getStdout());
        self::assertSame([], $seen['seen'], 'Host paths are visible in the sandbox.');
        self::assertIsArray($seen['usr_bin']);
        foreach ($seen['usr_bin'] as $entry) {
            self::assertIsString($entry);
            self::assertStringStartsWith('php', $entry, '/usr/bin holds more than the PHP binary.');
        }
        $parts = explode('/', $directory->getPath());
        self::assertSame(\count($parts) > 2 && $parts[1] === 'tmp' ? [$parts[2]] : [], $seen['tmp'], '/tmp holds more than the working directory.');
        self::assertFalse($seen['tmp_writable'], '/tmp is writable.');
        self::assertSame($directory->getPath(), $seen['tmpdir']);
        self::assertSame($directory->getPath(), $seen['home']);
        self::assertSame($directory->getPath(), $seen['cwd']);
        self::assertTrue($seen['tmpfile'], 'tmpfile() fails.');
        self::assertTrue($seen['zoneinfo'], 'The time zone data is missing.');
        self::assertTrue($seen['script'], 'The worker script is missing.');
        self::assertSame($backend->getName() === ImagickBackend::NAME, $seen['policy'], 'The ImageMagick policy is visible to Imagick only.');
    }

    private static function checkout(): string
    {
        return (string) realpath(\dirname(__DIR__, 3));
    }
}
