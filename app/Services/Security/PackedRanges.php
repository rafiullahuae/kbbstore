<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Support\IpRange;

/**
 * A list of CIDR ranges as sorted, merged [start,end] pairs in one byte
 * string per family — the format HostingNetworks already uses. A lookup is a
 * binary search with substr()/strcmp(): no array of thousands of entries is
 * built per request, no regex, no DNS.                              (Lane FW)
 */
final class PackedRanges
{
    /**
     * @param  list<string>  $cidrs
     * @return array{v4:string, v6:string}
     */
    public static function pack(array $cidrs): array
    {
        $by = [4 => [], 6 => []];

        foreach ($cidrs as $cidr) {
            $r = IpRange::parse($cidr);

            if ($r === null) {
                continue;
            }

            $start = (string) hex2bin($r['network']);
            $by[$r['family']][] = [$start, self::last($start, $r['prefix'])];
        }

        $out = [];

        foreach ([4 => 'v4', 6 => 'v6'] as $family => $key) {
            $pairs = $by[$family];
            usort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
            $merged = [];

            foreach ($pairs as [$s, $e]) {
                $n = count($merged);

                if ($n > 0 && strcmp($s, self::next($merged[$n - 1][1])) <= 0) {
                    if (strcmp($e, $merged[$n - 1][1]) > 0) {
                        $merged[$n - 1][1] = $e;
                    }
                    continue;
                }

                $merged[] = [$s, $e];
            }

            $out[$key] = implode('', array_map(static fn (array $p): string => $p[0].$p[1], $merged));
        }

        return $out;
    }

    public static function contains(string $haystack, string $bin): bool
    {
        $width = strlen($bin);
        $pair = $width * 2;
        $lo = 0;
        $hi = intdiv(strlen($haystack), $pair) - 1;

        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            $at = $mid * $pair;

            if (strcmp($bin, substr($haystack, $at, $width)) < 0) {
                $hi = $mid - 1;
            } elseif (strcmp($bin, substr($haystack, $at + $width, $width)) > 0) {
                $lo = $mid + 1;
            } else {
                return true;
            }
        }

        return false;
    }

    /** @param array{v4?:string, v6?:string} $packed */
    public static function count(array $packed): int
    {
        return intdiv(strlen($packed['v4'] ?? ''), 8) + intdiv(strlen($packed['v6'] ?? ''), 32);
    }

    /** The last address of a network. */
    private static function last(string $network, int $prefix): string
    {
        $len = strlen($network);
        $out = '';

        for ($i = 0; $i < $len; $i++) {
            $bits = max(0, min(8, $prefix - $i * 8));
            $out .= chr(ord($network[$i]) | (0xFF >> $bits));
        }

        return $out;
    }

    /** $bin + 1 (wrapping at the top, which only an all-ones address hits). */
    private static function next(string $bin): string
    {
        for ($i = strlen($bin) - 1; $i >= 0; $i--) {
            $b = ord($bin[$i]);

            if ($b < 255) {
                return substr($bin, 0, $i).chr($b + 1).str_repeat("\0", strlen($bin) - $i - 1);
            }
        }

        return $bin;
    }
}
