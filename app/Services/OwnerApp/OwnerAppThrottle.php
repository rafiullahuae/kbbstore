<?php

declare(strict_types=1);

namespace App\Services\OwnerApp;

use Illuminate\Support\Facades\DB;

/**
 * The owner app's per-connection limiter (Lane SEC), kept in the database
 * because the cache cannot be trusted to count.
 *
 * The shop runs the FILE cache store, and FileStore::increment() reads the
 * value, adds one and writes it back with no lock: fifty requests in flight
 * all read 0 and all write 1. A limiter checked with tooManyAttempts() BEFORE
 * the bcrypt and hit() AFTER it is the same race twice over — every one of
 * them passes the check, because none of them has hit yet.
 *
 * So the attempt is RESERVED before any work is done, by one statement:
 *
 *   UPDATE owner_app_throttle SET hits = hits + 1 WHERE bucket = ? AND hits < max
 *
 * which the database applies one row-lock at a time. A request that changes
 * no row was over the limit and is refused. A good PIN hands its reservation
 * back (release()), so only wrong ones are counted, as before.
 *
 * Buckets are the address, and for IPv6 the /64: one phone line or one cloud
 * VM holds a whole /64, and keying on the full address would hand it 2^64
 * fresh limits.
 */
final class OwnerAppThrottle
{
    public static function bucket(string $kind, ?string $ip): string
    {
        return $kind.'|'.self::network((string) $ip);
    }

    /** The IPv4 address, or the IPv6 /64 written as its 16 hex digits. */
    public static function network(string $ip): string
    {
        $bin = @inet_pton($ip);

        if ($bin === false) {
            return 'unknown';
        }

        if (strlen($bin) === 16) {
            // ::ffff:203.0.113.9 is an IPv4 client: count it as one.
            if (str_starts_with($bin, str_repeat("\0", 10)."\xff\xff")) {
                return (string) inet_ntop(substr($bin, 12));
            }

            return bin2hex(substr($bin, 0, 8)).'::/64';
        }

        return (string) inet_ntop($bin);
    }

    /** Take one attempt from the bucket. False when it was already spent. */
    public static function reserve(string $bucket, int $max, int $decaySeconds): bool
    {
        $now = now();

        try {
            DB::table('owner_app_throttle')->insertOrIgnore(['bucket' => $bucket, 'hits' => 0, 'reset_at' => $now->copy()->addSeconds($decaySeconds)]);
            DB::table('owner_app_throttle')->where('bucket', $bucket)->where('reset_at', '<=', $now)
                ->update(['hits' => 0, 'reset_at' => $now->copy()->addSeconds($decaySeconds)]);

            if (random_int(1, 200) === 1) {
                DB::table('owner_app_throttle')->where('reset_at', '<', $now->copy()->subDay())->delete();
            }

            return DB::table('owner_app_throttle')->where('bucket', $bucket)->where('hits', '<', $max)->increment('hits') === 1;
        } catch (\Throwable) {
            // The table is missing (code ahead of its migration): fail CLOSED.
            return false;
        }
    }

    /** Hand back one attempt (the PIN was right). */
    public static function release(string $bucket): void
    {
        try {
            DB::table('owner_app_throttle')->where('bucket', $bucket)->where('hits', '>', 0)->decrement('hits');
        } catch (\Throwable) {
        }
    }

    public static function hits(string $bucket): int
    {
        return (int) DB::table('owner_app_throttle')->where('bucket', $bucket)->value('hits');
    }
}
