<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Fixtures;

/**
 * Large images of the content classes whose memory the backends are measured on (tests/Fixtures/backend/
 * memory-peaks.csv), generated in PHP without an image library:
 *
 * | Content              | Pixels                                                                                |
 * | -------------------- | ------------------------------------------------------------------------------------- |
 * | noise                | random values in every channel                                                        |
 * | screen               | a screenshot: white page, flat panels with borders, lines of 7 x 13 glyphs, 8 colours |
 * | screen_alpha         | the same on a transparent page                                                        |
 * | palette_screen       | the screenshot with its panels in 248 more colours: 256 in all                        |
 * | palette_screen_alpha | the same on a transparent page                                                        |
 * | palette_noise        | random indices into 256 random colours                                                |
 * | palette_noise_alpha  | random indices into 128 random colours, half of the pixels transparent                |
 *
 * Truecolor PNGs (RGB, or RGBA where the content has transparency or is noise) and, for every content but noise,
 * indexed PNGs and GIFs; the GIF codes are never compressed (a clear code every 128 pixels keeps them 9 bits wide),
 * so that the file stays valid without an LZW encoder. Every image is the same for the same arguments.
 */
final class ContentImages
{
    public const NOISE = 'noise';

    public const SCREEN = 'screen';

    public const SCREEN_ALPHA = 'screen_alpha';

    public const PALETTE_SCREEN = 'palette_screen';

    public const PALETTE_SCREEN_ALPHA = 'palette_screen_alpha';

    public const PALETTE_NOISE = 'palette_noise';

    public const PALETTE_NOISE_ALPHA = 'palette_noise_alpha';

    private const CONTENTS = [
        self::NOISE,
        self::SCREEN,
        self::SCREEN_ALPHA,
        self::PALETTE_SCREEN,
        self::PALETTE_SCREEN_ALPHA,
        self::PALETTE_NOISE,
        self::PALETTE_NOISE_ALPHA,
    ];

    /**
     * Index 0 is the page, 1 and 2 the text and border colours, 3 to 7 the panels of the screenshot.
     */
    private const SCREEN_COLOURS = ["\xFF\xFF\xFF", "\x1E\x1E\x1E", "\x78\x78\x78", "\x00\x78\xD7", "\xF0\xF0\xF0", "\xC8\x1E\x1E", "\x3C\xA0\x3C", "\xFA\xC8\x00"];

    private const GLYPH_WIDTH = 7;

    private const GLYPH_HEIGHT = 13;

    private const LINE_HEIGHT = 14;

    /**
     * @return list<string>
     */
    public static function contents(): array
    {
        return self::CONTENTS;
    }

    public static function hasAlpha(string $content): bool
    {
        return \in_array($content, [self::NOISE, self::SCREEN_ALPHA, self::PALETTE_SCREEN_ALPHA, self::PALETTE_NOISE_ALPHA], true);
    }

    /**
     * A truecolor PNG of the content: RGBA when it has transparency, RGB otherwise; 8 or 16 bits per channel.
     *
     * @param positive-int $width
     * @param positive-int $height
     * @param 8|16         $bitDepth
     */
    public static function png(string $content, int $width, int $height, int $bitDepth = 8): string
    {
        $alpha = self::hasAlpha($content);
        $channels = $alpha ? 4 : 3;
        if ($content === self::NOISE) {
            $rows = '';
            for ($y = 0; $y < $height; ++$y) {
                $rows .= "\0" . random_bytes(max(1, $width * $channels * intdiv($bitDepth, 8)));
            }

            return self::pngFile($width, $height, $bitDepth, 6, '', ImageBuilder::zlibStored($rows));
        }
        list($palette, $transparent, $indices) = self::indexed($content, $width, $height);
        $map = [];
        foreach ($palette as $index => $rgb) {
            $pixel = $rgb . ($alpha ? ($index === $transparent ? "\0" : "\xFF") : '');
            $map[pack('C', $index)] = $bitDepth === 16 ? (string) preg_replace('/(.)/s', '$1$1', $pixel) : $pixel;
        }
        $rows = '';
        foreach ($indices as $row) {
            $rows .= "\0" . strtr($row, $map);
        }

        return self::pngFile($width, $height, $bitDepth, $alpha ? 6 : 2, '', (string) gzcompress($rows, 6));
    }

    /**
     * An indexed PNG of the content (not noise), with a tRNS chunk when it has transparency.
     *
     * @param positive-int $width
     * @param positive-int $height
     */
    public static function indexedPng(string $content, int $width, int $height): string
    {
        list($palette, $transparent, $indices) = self::indexed($content, $width, $height);
        $ancillary = ImageBuilder::pngChunk('PLTE', implode('', $palette));
        if ($transparent !== null) {
            $ancillary .= ImageBuilder::pngChunk('tRNS', str_repeat("\xFF", $transparent) . "\0");
        }
        $rows = '';
        foreach ($indices as $row) {
            $rows .= "\0" . $row;
        }

        return self::pngFile($width, $height, 8, 3, $ancillary, (string) gzcompress($rows, 6));
    }

    /**
     * A still GIF of the content (not noise), with a transparent index when it has transparency.
     *
     * @param positive-int $width
     * @param positive-int $height
     */
    public static function gif(string $content, int $width, int $height): string
    {
        list($palette, $transparent, $indices) = self::indexed($content, $width, $height);
        $table = str_pad(implode('', $palette), 768, "\0");
        $data = 'GIF89a' . pack('vvCCC', $width, $height, 0xF7, 0, 0) . $table;
        if ($transparent !== null) {
            $data .= "\x21\xF9\x04\x01\x00\x00" . pack('C', $transparent) . "\x00";
        }
        // Codes of 9 bits: the clear code (256) every 128 pixels, the end code (257) last.
        $bits = 0;
        $count = 0;
        $codes = '';
        $pixels = 0;
        foreach ($indices as $row) {
            $length = \strlen($row);
            for ($x = 0; $x < $length; ++$x) {
                if ($pixels++ % 128 === 0) {
                    $bits |= 256 << $count;
                    $count += 9;
                }
                $bits |= \ord($row[$x]) << $count;
                $count += 9;
                while ($count >= 8) {
                    $codes .= \chr($bits & 0xFF);
                    $bits >>= 8;
                    $count -= 8;
                }
            }
        }
        $bits |= 257 << $count;
        $count += 9;
        while ($count > 0) {
            $codes .= \chr($bits & 0xFF);
            $bits >>= 8;
            $count -= 8;
        }

        return $data . "\x2C" . pack('vvvvC', 0, 0, $width, $height, 0) . "\x08" . ImageBuilder::gifSubBlocks($codes) . "\x3B";
    }

    /**
     * The palette (3 bytes per colour), the transparent index or null, and the rows of indices (1 byte per pixel).
     *
     * @param positive-int $width
     * @param positive-int $height
     *
     * @return array{0: list<string>, 1: int|null, 2: list<string>}
     */
    private static function indexed(string $content, int $width, int $height): array
    {
        mt_srand(42);
        switch ($content) {
            case self::SCREEN:
            case self::SCREEN_ALPHA:
                return [self::SCREEN_COLOURS, $content === self::SCREEN_ALPHA ? 0 : null, self::screen($width, $height, 5)];
            case self::PALETTE_SCREEN:
            case self::PALETTE_SCREEN_ALPHA:
                $palette = self::SCREEN_COLOURS;
                for ($index = \count($palette); $index < 256; ++$index) {
                    $palette[] = pack('CCC', mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
                }

                return [$palette, $content === self::PALETTE_SCREEN_ALPHA ? 0 : null, self::screen($width, $height, 253)];
            case self::PALETTE_NOISE:
            case self::PALETTE_NOISE_ALPHA:
                $palette = [];
                for ($index = 0; $index < 256; ++$index) {
                    $palette[] = pack('CCC', mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
                }
                $low = '';
                for ($byte = 0; $byte < 128; ++$byte) {
                    $low .= \chr($byte);
                }
                $rows = [];
                for ($y = 0; $y < $height; ++$y) {
                    $row = random_bytes($width);
                    $rows[] = $content === self::PALETTE_NOISE_ALPHA ? strtr($row, $low, str_repeat("\0", 128)) : $row;
                }

                return [$palette, $content === self::PALETTE_NOISE_ALPHA ? 0 : null, $rows];
            default:
                throw new \InvalidArgumentException(sprintf('No indexed image of the content "%s".', $content));
        }
    }

    /**
     * Rows of indices of a screenshot: the page (0), panels of $panelColours colours from index 3 with borders (1),
     * one panel per 5184 pixels and at least 50, then lines of glyphs (1 or 2) every 14 pixels across the width.
     *
     * @param positive-int $width
     * @param positive-int $height
     *
     * @return list<string>
     */
    private static function screen(int $width, int $height, int $panelColours): array
    {
        $rows = array_fill(0, $height, str_repeat("\0", $width));
        $panels = max(50, intdiv($width * $height, 5184));
        for ($panel = 0; $panel < $panels; ++$panel) {
            $left = mt_rand(0, $width);
            $top = mt_rand(0, $height);
            self::fill($rows, $left, $top, $left + mt_rand(20, 400), $top + mt_rand(10, 200), pack('C', mt_rand(3, 2 + $panelColours)));
            $right = min($width - 1, $left + mt_rand(20, 400));
            $bottom = min($height - 1, $top + mt_rand(10, 200));
            self::fill($rows, $left, $top, $right, $top, "\x01");
            self::fill($rows, $left, $bottom, $right, $bottom, "\x01");
            self::fill($rows, $left, $top, $left, $bottom, "\x01");
            self::fill($rows, $right, $top, $right, $bottom, "\x01");
        }
        $glyphs = self::glyphs();
        for ($line = 0; $line < $height; $line += self::LINE_HEIGHT) {
            $colour = mt_rand(0, 1) === 1 ? "\x01" : "\x02";
            $text = [];
            for ($character = intdiv($width, self::GLYPH_WIDTH); $character > 0; --$character) {
                $text[] = $glyphs[mt_rand(0, \count($glyphs) - 1)];
            }
            for ($dy = 0; $dy < self::GLYPH_HEIGHT && $line + $dy < $height; ++$dy) {
                $row = &$rows[$line + $dy];
                foreach ($text as $position => $glyph) {
                    $left = 2 + $position * self::GLYPH_WIDTH;
                    foreach ($glyph[$dy] as $dx) {
                        if ($left + $dx < $width) {
                            $row[$left + $dx] = $colour;
                        }
                    }
                }
                unset($row);
            }
        }

        return array_values($rows);
    }

    /**
     * Fills the rectangle from ($left, $top) to ($right, $bottom), clipped to the image.
     *
     * @param array<int, string> $rows
     */
    private static function fill(array &$rows, int $left, int $top, int $right, int $bottom, string $index): void
    {
        $width = \strlen($rows[0]);
        $right = min($width - 1, $right);
        $bottom = min(\count($rows) - 1, $bottom);
        if ($left > $right || $top > $bottom) {
            return;
        }
        $span = str_repeat($index, $right - $left + 1);
        for ($y = $top; $y <= $bottom; ++$y) {
            $rows[$y] = substr_replace($rows[$y], $span, $left, \strlen($span));
        }
    }

    /**
     * 90 glyphs of 7 x 13 pixels: for each row, the columns that are set (strokes in the middle 5 x 9 pixels).
     *
     * @return list<list<list<int>>>
     */
    private static function glyphs(): array
    {
        $glyphs = [];
        for ($glyph = 0; $glyph < 90; ++$glyph) {
            $rows = [];
            for ($y = 0; $y < self::GLYPH_HEIGHT; ++$y) {
                $columns = [];
                if ($y >= 2 && $y <= 10) {
                    for ($x = 1; $x <= 5; ++$x) {
                        if (mt_rand(0, 99) < 40) {
                            $columns[] = $x;
                        }
                    }
                }
                $rows[] = $columns;
            }
            $glyphs[] = $rows;
        }

        return $glyphs;
    }

    private static function pngFile(int $width, int $height, int $bitDepth, int $colourType, string $ancillary, string $data): string
    {
        return "\x89PNG\r\n\x1a\n" . ImageBuilder::pngHeader($width, $height, $bitDepth, $colourType) . $ancillary
            . ImageBuilder::pngChunk('IDAT', $data) . ImageBuilder::pngChunk('IEND', '');
    }
}
