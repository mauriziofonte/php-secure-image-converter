<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend\Internal;

use Mfonte\SecureImageConverter\Backend\Internal\LibraryTrace;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\SecureImageConverter\Backend\Internal\LibraryTrace
 */
#[CoversClass(LibraryTrace::class)]
final class LibraryTraceTest extends TestCase
{
    public function testAGlibcTraceListsTheLibrariesAndTheLoader(): void
    {
        $trace = "\tlinux-vdso.so.1 (0x00007ffd5b9f3000)\n"
            . "\tlibvips.so.42 => /lib/x86_64-linux-gnu/libvips.so.42 (0x00007f2c2a000000)\n"
            . "\tlibc.so.6 => /lib/x86_64-linux-gnu/libc.so.6 (0x00007f2c29c00000)\n"
            . "\tlibheif.so.1 => /opt/heif/lib/libheif.so.1 (0x00007f2c29a00000)\n"
            . "\t/lib64/ld-linux-x86-64.so.2 (0x00007f2c2a5b0000)\n";

        self::assertSame(
            [
                'libraries' => [
                    '/lib/x86_64-linux-gnu/libvips.so.42',
                    '/lib/x86_64-linux-gnu/libc.so.6',
                    '/opt/heif/lib/libheif.so.1',
                    '/lib64/ld-linux-x86-64.so.2',
                ],
                'loader' => '/lib64/ld-linux-x86-64.so.2',
            ],
            LibraryTrace::parse($trace)
        );
        self::assertSame(['libraries' => [], 'loader' => null], LibraryTrace::parse("\tstatically linked\n"));
    }

    public function testAnIncompleteOrMissingTraceIsUnreadable(): void
    {
        self::assertNull(LibraryTrace::parse("\tlibvips.so.42 => not found\n\t/lib64/ld-linux-x86-64.so.2 (0x00007f2c2a5b0000)\n"), 'a missing library');
        self::assertNull(LibraryTrace::parse(''), 'no output');
        self::assertNull(LibraryTrace::parse("usage: vips [options] action\n"), 'the program ran: the loader ignores the variable');
        self::assertNull(LibraryTrace::parse("\tlibx.so => relative/libx.so (0x1)\n"), 'a relative path');
    }
}
