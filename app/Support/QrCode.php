<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A QR code, drawn by the shop itself.                              (Lane FB)
 *
 * The footer's app row offers a laptop visitor "scan with your phone camera"
 * when Appearance → Footer → App row → "Show on laptops" is on. The brief: the
 * code is generated in-house, with no new package. So this is the whole of ISO
 * 18004 the shop needs and nothing more: byte mode, error correction level M
 * (15% of the code can be lost — a scuffed screen, a glare), versions 1 to 10
 * (up to 213 bytes, far more than any address on the shop), and the mask
 * chosen by the standard's four penalty rules.
 *
 * Verified module for module against an independent encoder (segno 1.6.6, in a
 * scratch environment, not a dependency) for every mask and every version this
 * draws — QrCodeTest pins a set of those matrices as fixtures.
 *
 * Pure: no I/O, no state. A version-5 code (a long product address) is built
 * in about a millisecond, and only on a page whose row asks for it.
 */
final class QrCode
{
    /**
     * Level M, per version: [error-correction codewords per block, [[blocks, data codewords], ...]].
     * ISO/IEC 18004:2015 table 9.
     */
    private const M = [
        1 => [10, [[1, 16]]],
        2 => [16, [[1, 28]]],
        3 => [26, [[1, 44]]],
        4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]],
        7 => [18, [[4, 31]]],
        8 => [22, [[2, 38], [2, 39]]],
        9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];

    /** Alignment pattern centres, per version (annex E). */
    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** The longest input this draws: version 10-M in byte mode. */
    public const MAX_BYTES = 213;

    /** @var list<list<bool>> */
    private array $dark = [];

    /** @var list<list<bool>> */
    private array $fixed = [];

    private int $size;

    private function __construct(private int $version)
    {
        $this->size = 17 + 4 * $version;
        $row = array_fill(0, $this->size, false);
        $this->dark = array_fill(0, $this->size, $row);
        $this->fixed = $this->dark;
    }

    /**
     * The module matrix, [row][column], true = dark; null when the text is too
     * long. $mask forces one of the eight masks (for tests); null chooses.
     *
     * @return list<list<bool>>|null
     */
    public static function matrix(string $text, ?int $mask = null): ?array
    {
        $len = strlen($text);
        $version = null;

        foreach (self::M as $v => [, $blocks]) {
            $cap = 0;
            foreach ($blocks as [$n, $dc]) {
                $cap += $n * $dc * 8;
            }
            if (4 + ($v < 10 ? 8 : 16) + 8 * $len <= $cap) {
                $version = $v;
                break;
            }
        }

        if ($version === null) {
            return null;
        }

        $qr = new self($version);
        $qr->functionPatterns();
        $qr->place($qr->codewords($text));

        if ($mask === null) {
            $best = PHP_INT_MAX;
            for ($m = 0; $m < 8; $m++) {
                $qr->mask($m);
                $qr->format($m);
                $p = $qr->penalty();
                if ($p < $best) {
                    $best = $p;
                    $mask = $m;
                }
                $qr->mask($m);
            }
        }

        $mask = max(0, min(7, (int) $mask));
        $qr->mask($mask);
        $qr->format($mask);

        return $qr->dark;
    }

    /**
     * The code as one inline SVG: a white square (the four-module quiet zone
     * included) and one path of dark runs. Constants and integers only, so it
     * is safe to print unescaped. '' when the text is too long.
     */
    public static function svg(string $text, string $label = ''): string
    {
        $m = self::matrix($text);

        if ($m === null) {
            return '';
        }

        $n = count($m);
        $w = $n + 8;
        $d = '';

        foreach ($m as $y => $row) {
            for ($x = 0; $x < $n; $x++) {
                if (! $row[$x]) {
                    continue;
                }
                $start = $x;
                while ($x < $n && $row[$x]) {
                    $x++;
                }
                $d .= 'M'.($start + 4).' '.($y + 4).'h'.($x - $start).'v1h-'.($x - $start).'z';
            }
        }

        return '<svg viewBox="0 0 '.$w.' '.$w.'" shape-rendering="crispEdges" role="img" aria-label="'.e($label).'">'
            .'<path fill="#fff" d="M0 0h'.$w.'v'.$w.'H0z"/><path fill="#000" d="'.$d.'"/></svg>';
    }

    /* ── the grid ─────────────────────────────────────────────────────── */

    private function set(int $x, int $y, bool $dark): void
    {
        $this->dark[$y][$x] = $dark;
        $this->fixed[$y][$x] = true;
    }

    private function functionPatterns(): void
    {
        $s = $this->size;

        for ($i = 0; $i < $s; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }

        foreach ([[3, 3], [$s - 4, 3], [3, $s - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $s && $y >= 0 && $y < $s) {
                        $dist = max(abs($dx), abs($dy));
                        $this->set($x, $y, $dist !== 2 && $dist !== 4);
                    }
                }
            }
        }

        $pos = self::ALIGN[$this->version];
        $last = count($pos) - 1;
        foreach ($pos as $i => $px) {
            foreach ($pos as $j => $py) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($px + $dx, $py + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }

        $this->format(0);

        if ($this->version >= 7) {
            $rem = $this->version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($this->version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $s - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $bit);
                $this->set($b, $a, $bit);
            }
        }
    }

    /** The 15 format bits, both copies, and the dark module. Level M is 00. */
    private function format(int $mask): void
    {
        $data = (0 << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;
        $s = $this->size;

        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }

        for ($i = 0; $i < 8; $i++) {
            $this->set($s - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $s - 15 + $i, $bit($i));
        }
        $this->set(8, $s - 8, true);
    }

    /* ── the data ─────────────────────────────────────────────────────── */

    /** @return list<int> data and error-correction codewords, interleaved */
    private function codewords(string $text): array
    {
        [$ec, $groups] = self::M[$this->version];
        $cap = 0;
        foreach ($groups as [$n, $dc]) {
            $cap += $n * $dc;
        }

        $bits = '0100'.str_pad(decbin(strlen($text)), $this->version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (str_split($text) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $cap * 8 - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);

        $data = array_map('bindec', str_split($bits, 8));
        for ($pad = 0xEC; count($data) < $cap; $pad ^= 0xEC ^ 0x11) {
            $data[] = $pad;
        }

        $div = self::divisor($ec);
        $blocks = [];
        $at = 0;
        foreach ($groups as [$n, $dc]) {
            for ($b = 0; $b < $n; $b++) {
                $d = array_slice($data, $at, $dc);
                $at += $dc;
                $blocks[] = [$d, self::remainder($d, $div)];
            }
        }

        $out = [];
        $longest = max(array_map(static fn (array $b): int => count($b[0]), $blocks));
        for ($i = 0; $i < $longest; $i++) {
            foreach ($blocks as [$d]) {
                if ($i < count($d)) {
                    $out[] = $d[$i];
                }
            }
        }
        for ($i = 0; $i < $ec; $i++) {
            foreach ($blocks as [, $e]) {
                $out[] = $e[$i];
            }
        }

        return $out;
    }

    private static function mul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z;
    }

    /** @return list<int> the generator polynomial of degree $deg, leading 1 dropped */
    private static function divisor(int $deg): array
    {
        $r = array_fill(0, $deg, 0);
        $r[$deg - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $deg; $i++) {
            for ($j = 0; $j < $deg; $j++) {
                $r[$j] = self::mul($r[$j], $root);
                if ($j + 1 < $deg) {
                    $r[$j] ^= $r[$j + 1];
                }
            }
            $root = self::mul($root, 0x02);
        }

        return $r;
    }

    /**
     * @param  list<int>  $data
     * @param  list<int>  $div
     * @return list<int>
     */
    private static function remainder(array $data, array $div): array
    {
        $r = array_fill(0, count($div), 0);
        foreach ($data as $b) {
            $f = $b ^ array_shift($r);
            $r[] = 0;
            foreach ($div as $i => $c) {
                $r[$i] ^= self::mul($c, $f);
            }
        }

        return $r;
    }

    /** @param list<int> $words */
    private function place(array $words): void
    {
        $s = $this->size;
        $total = count($words) * 8;
        $i = 0;

        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $s; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $y = (($right + 1) & 2) === 0 ? $s - 1 - $vert : $vert;
                    if (! $this->fixed[$y][$x] && $i < $total) {
                        $this->dark[$y][$x] = (($words[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    /** XOR the data modules with mask $m; applying it twice undoes it. */
    private function mask(int $m): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->fixed[$y][$x]) {
                    continue;
                }
                $flip = match ($m) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    default => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
                };
                if ($flip) {
                    $this->dark[$y][$x] = ! $this->dark[$y][$x];
                }
            }
        }
    }

    /** The standard's four penalty rules (section 7.8.3). Lower is better. */
    private function penalty(): int
    {
        $s = $this->size;
        $p = 0;
        $darkCount = 0;
        $lines = [];

        for ($y = 0; $y < $s; $y++) {
            $row = '';
            $col = '';
            for ($x = 0; $x < $s; $x++) {
                $row .= $this->dark[$y][$x] ? '1' : '0';
                $col .= $this->dark[$x][$y] ? '1' : '0';
            }
            $lines[] = $row;
            $lines[] = $col;
            $darkCount += substr_count($row, '1');
        }

        foreach ($lines as $line) {
            // N1: a run of five or more of one colour.
            if (preg_match_all('/0{5,}|1{5,}/', $line, $m)) {
                foreach ($m[0] as $run) {
                    $p += 3 + strlen($run) - 5;
                }
            }
            // N3: a finder-like 1:1:3:1:1 with four light modules on either side.
            $p += 40 * preg_match_all('/(?=10111010000|00001011101)/', $line);
        }

        // N2: each 2×2 block of one colour.
        for ($y = 0; $y < $s - 1; $y++) {
            for ($x = 0; $x < $s - 1; $x++) {
                $c = $this->dark[$y][$x];
                if ($c === $this->dark[$y][$x + 1] && $c === $this->dark[$y + 1][$x] && $c === $this->dark[$y + 1][$x + 1]) {
                    $p += 3;
                }
            }
        }

        // N4: how far the dark share is from half, in steps of 5%.
        $p += 10 * intdiv(abs($darkCount * 100 - $s * $s * 50), $s * $s * 5);

        return $p;
    }
}
