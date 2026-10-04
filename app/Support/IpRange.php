<?php

declare(strict_types=1);

namespace App\Support;

/**
 * IP addresses and CIDR ranges, as pure functions.               (Lane CT)
 *
 * Everything the block list, the bot score and the Cart Tracking screen need to
 * know about an address, in one place and with no I/O: parse, canonicalise,
 * mask to a prefix, test containment, and say whether an address is somebody
 * on the public internet at all.
 *
 * BINARY, NOT INTEGERS. inet_pton() gives four bytes for IPv4 and sixteen for
 * IPv6, and masking a byte string works identically for both, so there is one
 * code path rather than an int path that silently cannot hold IPv6. A masked
 * network is keyed by bin2hex() — a binary array key round-trips through
 * var_export() into the compiled block-list file as raw bytes, which is legal
 * PHP and unreadable in a diff.
 *
 * IPv4-MAPPED IPv6 IS IPv4. "::ffff:203.0.113.7" is how a dual-stack socket
 * reports an IPv4 client, and a block on 203.0.113.0/24 must catch it.
 * normalise() folds it before anything else looks at it.
 */
final class IpRange
{
    /**
     * Never blockable, whatever the owner types: the address is not a visitor.
     *
     * Loopback and private ranges are where a reverse proxy lives. On a host
     * whose real-client-IP restoration is missing, EVERY shopper arrives as the
     * proxy's address, and blocking that address shuts the shop. Cloudflare's
     * published edge ranges are here for the same reason: a site put behind
     * Cloudflare without restoring the visitor address sees only these.
     * (https://www.cloudflare.com/ips/ — stable for years; a range they add
     * later is simply not protected by this list, it is not made blockable.)
     */
    public const UNBLOCKABLE = [
        // loopback, unspecified, private, CGNAT, link-local, documentation-free reserved
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.168.0.0/16', '224.0.0.0/4', '240.0.0.0/4',
        '::/127', 'fc00::/7', 'fe80::/10', 'ff00::/8',
        // Cloudflare
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** The narrowest a manual range may be widened to: wider hits real customers. */
    public const MIN_PREFIX = [4 => 16, 6 => 32];

    /** "The whole range" from a cart: the owner's /24, and its IPv6 counterpart. */
    public const RANGE_PREFIX = [4 => 24, 6 => 64];

    /**
     * Packed bytes of a valid address, IPv4-mapped IPv6 folded to IPv4, or null.
     */
    public static function pack(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        $ip = trim($ip);

        if ($ip === '' || strlen($ip) > 45) {
            return null;
        }

        $bin = @inet_pton($ip);

        if ($bin === false) {
            return null;
        }

        if (strlen($bin) === 16 && strncmp($bin, str_repeat("\0", 10)."\xff\xff", 12) === 0) {
            return substr($bin, 12);
        }

        return $bin;
    }

    /** The canonical printable form ("2001:db8::1", "203.0.113.7"), or null. */
    public static function normalise(?string $ip): ?string
    {
        $bin = self::pack($ip);

        return $bin === null ? null : (string) inet_ntop($bin);
    }

    /** 4, 6, or null for something that is not an address. */
    public static function family(?string $ip): ?int
    {
        $bin = self::pack($ip);

        return $bin === null ? null : (strlen($bin) === 4 ? 4 : 6);
    }

    /** The first $bits bits of $bin, the rest zeroed. */
    public static function mask(string $bin, int $bits): string
    {
        $len = strlen($bin);
        $bits = max(0, min($len * 8, $bits));
        $full = intdiv($bits, 8);
        $rest = $bits % 8;

        $out = substr($bin, 0, $full);

        if ($rest > 0) {
            $out .= chr(ord($bin[$full]) & (0xFF << (8 - $rest)) & 0xFF);
            $full++;
        }

        return $out.str_repeat("\0", $len - $full);
    }

    /**
     * Parse "203.0.113.7", "203.0.113.0/24" or "2001:db8::/64".
     *
     * A host part under a prefix is masked off rather than refused — the owner
     * typing 203.0.113.7/24 means the /24 that address sits in, and refusing
     * him for a host bit is pedantry. The CANONICAL string is what is stored and
     * compared, so the same range typed two ways is one row.
     *
     * @return array{cidr:string, family:int, prefix:int, network:string}|null
     */
    public static function parse(?string $input): ?array
    {
        if ($input === null) {
            return null;
        }

        $input = trim($input);

        if ($input === '' || strlen($input) > 49) {
            return null;
        }

        $prefix = null;

        if (str_contains($input, '/')) {
            [$addr, $bits] = explode('/', $input, 2);

            if (! preg_match('/^\d{1,3}$/', $bits)) {
                return null;
            }

            $prefix = (int) $bits;
        } else {
            $addr = $input;
        }

        $bin = self::pack($addr);

        if ($bin === null) {
            return null;
        }

        $family = strlen($bin) === 4 ? 4 : 6;
        $max = $family === 4 ? 32 : 128;

        // A mapped-IPv6 prefix written against the 128-bit form ("::ffff:1.2.3.0/120")
        // is re-expressed against the folded IPv4 address.
        if ($prefix !== null && $family === 4 && str_contains($addr, ':')) {
            $prefix -= 96;
        }

        $prefix ??= $max;

        if ($prefix < 0 || $prefix > $max) {
            return null;
        }

        $network = self::mask($bin, $prefix);

        return [
            'cidr' => inet_ntop($network).'/'.$prefix,
            'family' => $family,
            'prefix' => $prefix,
            'network' => bin2hex($network),
        ];
    }

    /** Does $cidr contain $ip? Both as strings; anything unparseable is "no". */
    public static function contains(string $cidr, ?string $ip): bool
    {
        $range = self::parse($cidr);
        $bin = self::pack($ip);

        if ($range === null || $bin === null) {
            return false;
        }

        if ((strlen($bin) === 4 ? 4 : 6) !== $range['family']) {
            return false;
        }

        return bin2hex(self::mask($bin, $range['prefix'])) === $range['network'];
    }

    /** Do two ranges share any address? (One contains the other's network.) */
    public static function overlaps(string $a, string $b): bool
    {
        $ra = self::parse($a);
        $rb = self::parse($b);

        if ($ra === null || $rb === null || $ra['family'] !== $rb['family']) {
            return false;
        }

        $bits = min($ra['prefix'], $rb['prefix']);

        return self::mask((string) hex2bin($ra['network']), $bits) === self::mask((string) hex2bin($rb['network']), $bits);
    }

    /**
     * The "whole range" a single address belongs to: /24 for IPv4, /64 for IPv6.
     * Also what the bot score counts carts against.
     */
    public static function rangeOf(?string $ip): ?string
    {
        $bin = self::pack($ip);

        if ($bin === null) {
            return null;
        }

        $prefix = self::RANGE_PREFIX[strlen($bin) === 4 ? 4 : 6];

        return inet_ntop(self::mask($bin, $prefix)).'/'.$prefix;
    }

    /**
     * The first UNBLOCKABLE range $cidr overlaps, or null when it is a public range.
     */
    public static function protectedRange(string $cidr): ?string
    {
        foreach (self::UNBLOCKABLE as $guard) {
            if (self::overlaps($guard, $cidr)) {
                return $guard;
            }
        }

        return null;
    }
}
