<?php

declare(strict_types=1);

namespace App\Services\CartTracking;

use App\Support\IpRange;

/**
 * Is this address a datacenter or VPN network?                     (Lane CT)
 *
 * A LOCAL LIST, NO SERVICE. resources/data/hosting-networks.php holds the
 * X4BNet "lists_vpn" datacenter ranges (MIT) — every network that is "not an
 * eyeball network": AWS, Google Cloud, Azure, DigitalOcean, OVH, Hetzner,
 * Linode, Vultr, the VPN providers. 30k IPv4 and 5k IPv6 ranges after merging,
 * packed as sorted fixed-width [start,end] pairs, so a lookup is a binary
 * search over a byte string: ~15 substr() compares, no array of 35k entries
 * built in memory, no regex, no DNS, no HTTP.
 *
 * ASKED ONCE PER CART, on its first tracked event, never on a page view. Worth
 * 25 points: on its own it does not make a bot (plenty of real shoppers use a
 * VPN), beside a second signal it does.
 *
 * The list goes stale slowly — it is built from ASNs — and is refreshed by
 * regenerating the data file, which is a package like any other.
 */
final class HostingNetworks
{
    /** @var array{v4:string, v6:string}|null */
    private static ?array $packed = null;

    public static function contains(?string $ip): bool
    {
        $bin = IpRange::pack($ip);

        if ($bin === null) {
            return false;
        }

        $data = self::data();
        $width = strlen($bin);
        $haystack = $width === 4 ? $data['v4'] : $data['v6'];
        $pair = $width * 2;
        $count = intdiv(strlen($haystack), $pair);

        $lo = 0;
        $hi = $count - 1;

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

    /** For the Settings tab: where the list came from and how big it is. */
    public static function about(): array
    {
        $raw = self::raw();

        return [
            'source' => (string) ($raw['source'] ?? ''),
            'fetched' => (string) ($raw['fetched'] ?? ''),
            'v4' => (int) ($raw['v4_count'] ?? 0),
            'v6' => (int) ($raw['v6_count'] ?? 0),
        ];
    }

    public static function forget(): void
    {
        self::$packed = null;
    }

    /** @return array{v4:string, v6:string} */
    private static function data(): array
    {
        if (self::$packed !== null) {
            return self::$packed;
        }

        $raw = self::raw();

        return self::$packed = [
            'v4' => (string) base64_decode((string) ($raw['v4'] ?? ''), true),
            'v6' => (string) base64_decode((string) ($raw['v6'] ?? ''), true),
        ];
    }

    private static function raw(): array
    {
        $file = resource_path('data/hosting-networks.php');
        $raw = is_file($file) ? include $file : [];

        return is_array($raw) ? $raw : [];
    }
}
