<?php

declare(strict_types=1);

namespace App\Services\GiftOffers;

/**
 * The email's moving clock (Lane GO): an animated GIF drawn by GD, sixty
 * one-second frames counting down from the moment it is asked for. Email
 * clients run no script, so this is the only clock an inbox can show; Outlook
 * on a computer draws the first frame only, which is the right time when the
 * email is opened.
 *
 * SMALL BY CONSTRUCTION. Four palette colours and no font file: the digits are
 * seven-segment shapes built from rectangles, so nothing depends on what fonts
 * the server has. Frame 1 is the whole picture; every later frame is ONLY the
 * digits that changed since the frame before (a sub-image placed at its own
 * offset, "do not dispose"), so a minute of seconds costs a few hundred bytes
 * a frame. GiftOffersTest pins the size under 40 KB.
 *
 * GD's own imagegif() writes one frame; the frames are joined here: one
 * header, one global colour table (every frame allocates the same colours in
 * the same order), a NETSCAPE block that plays once, and a graphic control
 * block per frame with a one-second delay.
 */
final class GiftClock
{
    public const WIDTH = 216;

    public const HEIGHT = 56;

    private const DW = 20;      // digit width

    private const DH = 34;      // digit height

    private const T = 5;        // stroke

    private const GAP = 6;      // between digits

    private const COLON = 10;   // colon column

    private const SEGMENTS = [
        '0' => 'abcdef', '1' => 'bc', '2' => 'abged', '3' => 'abgcd', '4' => 'fgbc',
        '5' => 'afgcd', '6' => 'afgedc', '7' => 'abc', '8' => 'abcdefg', '9' => 'abcdfg',
    ];

    public static function available(): bool
    {
        return function_exists('imagegif') && function_exists('imagecreate');
    }

    /** "07:59:59" for a number of seconds (capped at 99 hours). */
    public static function hms(int $seconds): string
    {
        $s = max(0, min(99 * 3600 + 3599, $seconds));

        return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
    }

    /** The GIF for a clock that has $left seconds to run, $frames frames. */
    public static function render(int $left, int $frames = 60): string
    {
        $frames = $left <= 0 ? 1 : max(1, min($frames, $left + 1));
        $times = [];
        for ($i = 0; $i < $frames; $i++) {
            $times[] = self::hms($left - $i);
        }

        $xs = self::positions();
        $out = '';
        $head = null;
        $prev = null;

        foreach ($times as $i => $text) {
            // Which characters changed: the first frame draws them all.
            $from = 0;
            $to = 7;

            if ($prev !== null) {
                $from = 8;
                $to = -1;
                for ($c = 0; $c < 8; $c++) {
                    if ($text[$c] !== $prev[$c]) {
                        $from = min($from, $c);
                        $to = max($to, $c);
                    }
                }
            }

            $prev = $text;

            if ($to < $from) {
                continue;
            }

            [$x0, $y0, $w, $h] = $i === 0
                ? [0, 0, self::WIDTH, self::HEIGHT]
                : [$xs[$from][0], (int) ((self::HEIGHT - self::DH) / 2), $xs[$to][0] + $xs[$to][1] - $xs[$from][0], self::DH];

            $gif = self::frame($text, $x0, $y0, $w, $h, $i === 0);
            [$global, $body] = self::split($gif);
            $head ??= $global;
            // The image descriptor: patch its left/top to the region's offset.
            $body = substr($body, 0, 1) . pack('v', $x0) . pack('v', $y0) . substr($body, 5);
            // Graphic control: do not dispose, 1 s (100 cs) delay, no transparency.
            $out .= "\x21\xF9\x04\x04" . pack('v', 100) . "\x00\x00" . $body;
        }

        // GD writes GIF87a; extensions need 89a. NETSCAPE2.0 with a loop count
        // of 1: play once and stop on the last frame.
        return 'GIF89a' . substr((string) $head, 6) . "\x21\xFF\x0BNETSCAPE2.0\x03\x01" . pack('v', 1) . "\x00" . $out . "\x3B";
    }

    /** [x, width] of each of the eight characters, centred. */
    private static function positions(): array
    {
        $widths = [self::DW, self::DW, self::COLON, self::DW, self::DW, self::COLON, self::DW, self::DW];
        $total = array_sum($widths) + self::GAP * 7;
        $x = intdiv(self::WIDTH - $total, 2);
        $out = [];

        foreach ($widths as $w) {
            $out[] = [$x, $w];
            $x += $w + self::GAP;
        }

        return $out;
    }

    /** One frame (the region asked for) as a GD GIF. */
    private static function frame(string $text, int $x0, int $y0, int $w, int $h, bool $full): string
    {
        $im = imagecreate($w, $h);
        // The same four colours, in the same order, in every frame.
        $bg = imagecolorallocate($im, 0xFF, 0xF7, 0xEC);
        $card = imagecolorallocate($im, 0xFF, 0xFF, 0xFF);
        $ink = imagecolorallocate($im, 0xC1, 0x3E, 0x63);
        $edge = imagecolorallocate($im, 0xF1, 0xE2, 0xC8);

        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $card);

        if ($full) {
            // A white tile with a thin gold edge and cut corners on the cream.
            imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $bg);
            imagefilledrectangle($im, 6, 0, $w - 7, $h - 1, $card);
            imagefilledrectangle($im, 0, 6, $w - 1, $h - 7, $card);
            foreach ([[6, 6], [$w - 7, 6], [6, $h - 7], [$w - 7, $h - 7]] as [$cx, $cy]) {
                imagefilledellipse($im, $cx, $cy, 12, 12, $card);
            }
            imageline($im, 6, 0, $w - 7, 0, $edge);
            imageline($im, 6, $h - 1, $w - 7, $h - 1, $edge);
            imageline($im, 0, 6, 0, $h - 7, $edge);
            imageline($im, $w - 1, 6, $w - 1, $h - 7, $edge);
        }

        $top = (int) ((self::HEIGHT - self::DH) / 2) - $y0;

        foreach (self::positions() as $c => [$x, $cw]) {
            $lx = $x - $x0;

            if ($lx + $cw < 0 || $lx > $w) {
                continue;
            }

            $ch = $text[$c];

            if ($ch === ':') {
                $mid = $lx + intdiv($cw - self::T, 2);
                imagefilledrectangle($im, $mid, $top + 9, $mid + self::T - 1, $top + 9 + self::T - 1, $ink);
                imagefilledrectangle($im, $mid, $top + self::DH - 14, $mid + self::T - 1, $top + self::DH - 14 + self::T - 1, $ink);

                continue;
            }

            self::digit($im, $lx, $top, $ch, $ink);
        }

        ob_start();
        imagegif($im);
        $gif = (string) ob_get_clean();
        imagedestroy($im);

        return $gif;
    }

    private static function digit(\GdImage $im, int $x, int $y, string $d, int $ink): void
    {
        $W = self::DW;
        $H = self::DH;
        $t = self::T;
        $mid = $y + intdiv($H - $t, 2);
        $r = [
            'a' => [$x + 1, $y, $x + $W - 2, $y + $t - 1],
            'd' => [$x + 1, $y + $H - $t, $x + $W - 2, $y + $H - 1],
            'g' => [$x + 1, $mid, $x + $W - 2, $mid + $t - 1],
            'f' => [$x, $y + 1, $x + $t - 1, $mid + $t - 1],
            'b' => [$x + $W - $t, $y + 1, $x + $W - 1, $mid + $t - 1],
            'e' => [$x, $mid, $x + $t - 1, $y + $H - 2],
            'c' => [$x + $W - $t, $mid, $x + $W - 1, $y + $H - 2],
        ];

        foreach (str_split(self::SEGMENTS[$d] ?? '') as $s) {
            imagefilledrectangle($im, ...array_merge($r[$s], [$ink]));
        }
    }

    /**
     * Split a one-frame GIF into [header + screen descriptor + global colour
     * table, the frame from its image descriptor up to (not including) the
     * trailer], dropping any graphic control block GD wrote.
     */
    private static function split(string $gif): array
    {
        $packed = ord($gif[10]);
        $table = ($packed & 0x80) ? 3 * (2 ** (($packed & 0x07) + 1)) : 0;
        $headLen = 13 + $table;
        $head = substr($gif, 0, $headLen);
        $rest = substr($gif, $headLen, -1);

        while (strlen($rest) > 0 && $rest[0] === "\x21") {
            // Skip an extension: introducer, label, then sub-blocks until 0.
            $p = 2;
            while ($p < strlen($rest) && ($len = ord($rest[$p])) > 0) {
                $p += $len + 1;
            }
            $rest = substr($rest, $p + 1);
        }

        return [$head, $rest];
    }
}
