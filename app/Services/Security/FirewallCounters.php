<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * The firewall's per-address and per-range counters and bans.      (Lane FW)
 *
 * WHY NOT THE CACHE. Measured on the lane's preview: three Cache::increment()s
 * on Laravel's file store cost ~130 µs a request even with the cache on RAM,
 * and — the deciding fact — a counter keyed by "address + 10-second window" is
 * a NEW FILE every ten seconds for every visitor, which the file store never
 * deletes unless the same key is read again. A busy day would leave hundreds of
 * thousands of dead files. Redis would fix both, but the live shop runs
 * CACHE_STORE=file and the owner should not need a server change for the
 * firewall to be light.
 *
 * SO: A FIXED TABLE. 256 shard files × 64 buckets × 4 slots × 64 bytes =
 * 4 MiB under storage/framework/firewall/, whatever the traffic — no file per
 * visitor, nothing to clean up, nothing that grows. A record is found by a
 * keyed hash of (kind, address): shard, then bucket, then up to 4 slots read
 * in one 256-byte fread. An update is fopen + flock(LOCK_EX) on that one shard
 * + read + write of 64 bytes + unlock: ATOMIC (two simultaneous requests cannot
 * both read "5" and write "6", which Laravel's file-store increment can), and
 * 1/256th of the table locked at a time, so requests from different addresses
 * almost never wait on each other.
 *
 * Eviction, when a bucket's four slots are taken: an empty slot, else the
 * least recently seen slot without an active ban, else the least recently seen.
 * 65,536 slots hold every address active in the last minutes of any realistic
 * day; past that, the oldest counts are forgotten first — the firewall degrades
 * to "counts less", never to "refuses more".
 *
 * Every failure (unwritable directory, full disk) answers "no record": the
 * firewall counts nothing and refuses nothing. It never throws.
 *
 * Record (59 bytes used of 64): kind (a = IPv4 address, A = IPv6 address,
 * n = IPv4 /24, N = IPv6 /64), 16 address bytes, then u32 seen, w10, n10, w60,
 * n60, p60 (the previous minute's count, for the sliding estimate), wp, np
 * (the prefetch window and count), ban (until), stk (strikes
 * remembered until), then u8 strikes and u8 flags (bit 0: banned in Monitor
 * mode; bits 1-3: why).
 */
final class FirewallCounters
{
    private const SLOT = 64;

    private const WAYS = 4;

    private const BUCKETS = 64;

    private const SHARDS = 256;

    private const PACK = 'a1a16N10C2';

    private const UNPACK = 'a1kind/a16addr/Nseen/Nw10/Nn10/Nw60/Nn60/Np60/Nwp/Nnp/Nban/Nstk/Cstrikes/Cflags';

    public const WHY = [1 => '10s', 2 => '60s', 3 => 'range', 4 => 'prefetch'];

    private static ?string $dir = null;

    private static ?string $salt = null;

    public static function dir(): string
    {
        return self::$dir ??= storage_path('framework/firewall'.(app()->runningUnitTests() ? '-test-'.getmypid() : ''));
    }

    public static function useDir(?string $dir): void
    {
        self::$dir = $dir;
    }

    /** The record for (kind, address), read without a lock, or null. */
    public static function read(string $kind, string $addr): ?array
    {
        [$file, $offset] = self::locate($kind, $addr);
        $h = @fopen($file, 'rb');

        if ($h === false) {
            return null;
        }

        fseek($h, $offset);
        $bucket = (string) fread($h, self::SLOT * self::WAYS);
        fclose($h);
        $key = $kind.str_pad($addr, 16, "\0");

        for ($i = 0; $i < self::WAYS; $i++) {
            if (substr($bucket, $i * self::SLOT, 17) === $key) {
                return self::unpack(substr($bucket, $i * self::SLOT, self::SLOT));
            }
        }

        return null;
    }

    /**
     * Read-modify-write one record under the shard's lock. $change receives the
     * record (zeros when new) and returns it changed. Returns what was written,
     * or null when the table cannot be written (the firewall then counts
     * nothing).
     *
     * @param  callable(array): array  $change
     */
    public static function update(string $kind, string $addr, int $now, callable $change): ?array
    {
        [$file, $offset] = self::locate($kind, $addr);
        $h = @fopen($file, 'c+b');

        if ($h === false) {
            if (! is_dir(self::dir()) && @mkdir(self::dir(), 0775, true)) {
                $h = @fopen($file, 'c+b');
            }

            if ($h === false) {
                return null;
            }
        }

        try {
            if (! flock($h, LOCK_EX)) {
                return null;
            }

            fseek($h, $offset);
            $bucket = str_pad((string) fread($h, self::SLOT * self::WAYS), self::SLOT * self::WAYS, "\0");
            $key = $kind.str_pad($addr, 16, "\0");
            $slot = null;
            $victim = null;
            $victimScore = PHP_INT_MAX;

            for ($i = 0; $i < self::WAYS; $i++) {
                $raw = substr($bucket, $i * self::SLOT, self::SLOT);

                if (substr($raw, 0, 17) === $key) {
                    $slot = $i;
                    break;
                }

                if ($raw[0] === "\0") {
                    $score = -1; // empty: take it
                } else {
                    $r = self::unpack($raw);
                    // Least recently seen first; a running ban is kept unless
                    // every slot holds one.
                    $score = $r['seen'] + ($r['ban'] > $now ? 1 << 40 : 0);
                }

                if ($score < $victimScore) {
                    $victimScore = $score;
                    $victim = $i;
                }
            }

            $fresh = $slot === null;
            $slot ??= $victim;
            $record = $fresh
                ? ['kind' => $kind, 'addr' => str_pad($addr, 16, "\0"), 'seen' => 0, 'w10' => 0, 'n10' => 0, 'w60' => 0, 'n60' => 0, 'p60' => 0,
                    'wp' => 0, 'np' => 0, 'ban' => 0, 'stk' => 0, 'strikes' => 0, 'flags' => 0]
                : self::unpack(substr($bucket, $slot * self::SLOT, self::SLOT));

            $record = $change($record);
            $record['seen'] = $now;

            fseek($h, $offset + $slot * self::SLOT);
            fwrite($h, self::pack($record));
            fflush($h);

            return $record;
        } catch (\Throwable) {
            return null;
        } finally {
            @flock($h, LOCK_UN);
            fclose($h);
        }
    }

    /**
     * Every record with a ban still running. Reads the whole table (4 MiB) —
     * for the admin screen only, never a shop request.
     *
     * @return list<array<string, mixed>>
     */
    public static function bans(int $now): array
    {
        $out = [];

        for ($s = 0; $s < self::SHARDS; $s++) {
            $data = @file_get_contents(self::dir().'/'.sprintf('%02x', $s).'.bin');

            if (! is_string($data) || $data === '') {
                continue;
            }

            foreach (str_split($data, self::SLOT) as $raw) {
                if (strlen($raw) === self::SLOT && $raw[0] !== "\0") {
                    $r = self::unpack($raw);

                    if ($r['ban'] > $now) {
                        $out[] = $r;
                    }
                }
            }
        }

        return $out;
    }

    public static function reset(): void
    {
        self::$dir = null;
        self::$salt = null;
    }

    /**
     * Tests only: empty this process's table, so a ban made by one test is not
     * still running in the next. Refuses to touch anything but a test table.
     */
    public static function wipeTestTable(): void
    {
        self::reset();
        $dir = storage_path('framework/firewall-test-'.getmypid());

        if (app()->runningUnitTests()) {
            @unlink(Firewall::killSwitch());

            foreach (glob($dir.'/*.bin') ?: [] as $f) {
                @unlink($f);
            }
        }
    }

    /** @return array{0:string, 1:int} [shard file, byte offset of the bucket] */
    private static function locate(string $kind, string $addr): array
    {
        // Keyed, so nobody outside can pick addresses that crowd one bucket.
        self::$salt ??= substr(hash_hmac('sha256', 'kbb-firewall-table-v1', (string) config('app.key'), true), 0, 8);
        $h = crc32(self::$salt.$kind.$addr);

        return [self::dir().'/'.sprintf('%02x', $h & 0xFF).'.bin', (($h >> 8) % self::BUCKETS) * self::SLOT * self::WAYS];
    }

    private static function pack(array $r): string
    {
        return str_pad(pack(self::PACK, $r['kind'], $r['addr'], $r['seen'], $r['w10'], min($r['n10'], 0xFFFFFFFF), $r['w60'],
            min($r['n60'], 0xFFFFFFFF), min($r['p60'], 0xFFFFFFFF), $r['wp'], min($r['np'], 0xFFFFFFFF), $r['ban'], $r['stk'], min($r['strikes'], 255), $r['flags'] & 0xFF), self::SLOT, "\0");
    }

    private static function unpack(string $raw): array
    {
        return unpack(self::UNPACK, $raw) ?: [];
    }
}
