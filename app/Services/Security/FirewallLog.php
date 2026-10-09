<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Support\IpRange;
use Illuminate\Support\Facades\DB;

/**
 * The firewall's live view, aggregated in the cache and written in batches.
 *                                                                   (Lane FW)
 *
 * NEVER AN INSERT PER REQUEST. A flagged request costs one bump() of its
 * (five-minute bucket, reason, address) counter. The FIRST time an address
 * shows up in a bucket for a reason it also takes a numbered slot in that
 * bucket's index — another bump() and one put() — which is how a cache that
 * cannot list its keys can still say who was in it. A bucket holds at most
 * SLOTS addresses; past that, hits are counted under "other addresses" so the
 * totals stay right whatever the size of the botnet.
 *
 * flush() moves finished buckets into `firewall_log` as one row per address
 * and reason with its count, in chunked multi-row INSERTs: at most once a
 * minute, after a response (app()->terminating), or when the screen opens.
 * Rows older than KEEP_DAYS are deleted in the same pass.
 */
final class FirewallLog
{
    public const BUCKET = 300;

    public const SLOTS = 2000;

    public const KEEP_DAYS = 8;

    public const REASONS = [
        'flood' => 'Flood: banned for too many requests',
        'banned' => 'Request from a banned address or range',
        'fake_bot' => 'Fake search-engine bot',
        'country' => 'Country set to Block',
        'no_proof' => 'Protect: posted without loading a page',
        'watch' => 'Country set to Watch (logged only)',
    ];

    private static bool $flushQueued = false;

    /** Record one flagged request. */
    public static function hit(string $reason, string $bin, string $cc, bool $enforced): void
    {
        $bucket = intdiv(FirewallStore::now(), self::BUCKET);
        $ttl = 2 * 3600;
        $ip = bin2hex($bin);
        $e = $enforced ? 1 : 0;

        if (FirewallStore::bump("fw:l:{$bucket}:{$reason}:{$e}:{$ip}", $ttl) === 1) {
            $slot = FirewallStore::bump("fw:lx:{$bucket}", $ttl);

            if ($slot > 0 && $slot <= self::SLOTS) {
                FirewallStore::put("fw:lx:{$bucket}:{$slot}", "{$reason}|{$e}|{$ip}|{$cc}", $ttl);
            } else {
                FirewallStore::bump("fw:lo:{$bucket}:{$reason}:{$e}", $ttl);
            }
        }

        self::flushLater();
    }

    private static function flushLater(): void
    {
        if (self::$flushQueued) {
            return;
        }

        self::$flushQueued = true;

        try {
            app()->terminating(static function (): void {
                self::$flushQueued = false;

                if (FirewallStore::add('fw:lf:lock', 1, 60)) {
                    self::flush();
                }
            });
        } catch (\Throwable) {
        }
    }

    /**
     * Write every finished bucket not yet written. Returns rows inserted.
     */
    public static function flush(): int
    {
        $now = intdiv(FirewallStore::now(), self::BUCKET);
        $last = (int) (FirewallStore::get('fw:lf:last') ?? ($now - 24));
        $until = $now - 1;
        $written = 0;

        try {
            for ($b = max($last + 1, $now - 24); $b <= $until; $b++) {
                $rows = self::bucketRows($b);

                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('firewall_log')->insert($chunk);
                    $written += count($chunk);
                }

                FirewallStore::put('fw:lf:last', $b, 86400 * 2);
            }

            DB::table('firewall_log')->where('bucket_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
        } catch (\Throwable) {
            // No table yet, or the database is busy: the counters keep for two
            // hours and the next pass picks them up.
        }

        return $written;
    }

    /**
     * The rows a bucket holds, read from the cache.
     *
     * @return list<array<string, mixed>>
     */
    public static function bucketRows(int $bucket): array
    {
        $count = min(self::SLOTS, (int) (FirewallStore::get("fw:lx:{$bucket}") ?? 0));
        $at = date('Y-m-d H:i:s', $bucket * self::BUCKET);
        $rows = [];

        if ($count > 0) {
            $slots = FirewallStore::many(array_map(static fn (int $i): string => "fw:lx:{$bucket}:{$i}", range(1, $count)));
            $entries = [];

            foreach ($slots as $v) {
                if (is_string($v) && substr_count($v, '|') === 3) {
                    $entries[] = explode('|', $v);
                }
            }

            $counts = $entries === [] ? [] : FirewallStore::many(array_map(
                static fn (array $e): string => "fw:l:{$bucket}:{$e[0]}:{$e[1]}:{$e[2]}", $entries));

            foreach ($entries as $e) {
                [$reason, $enforced, $hex, $cc] = $e;
                $bin = @hex2bin($hex);
                $ip = is_string($bin) ? (string) @inet_ntop($bin) : '';

                if (! isset(self::REASONS[$reason]) || $ip === '') {
                    continue;
                }

                $rows[] = [
                    'bucket_at' => $at,
                    'reason' => $reason,
                    'ip' => $ip,
                    'net' => IpRange::rangeOf($ip),
                    'country' => preg_match('/^[A-Z]{2}$/', $cc) === 1 ? $cc : null,
                    'enforced' => $enforced === '1',
                    'hits' => max(1, (int) ($counts["fw:l:{$bucket}:{$reason}:{$enforced}:{$hex}"] ?? 1)),
                ];
            }
        }

        foreach (self::REASONS as $reason => $_) {
            foreach ([0, 1] as $e) {
                $n = (int) (FirewallStore::get("fw:lo:{$bucket}:{$reason}:{$e}") ?? 0);

                if ($n > 0) {
                    $rows[] = ['bucket_at' => $at, 'reason' => $reason, 'ip' => null, 'net' => null, 'country' => null, 'enforced' => $e === 1, 'hits' => $n];
                }
            }
        }

        return $rows;
    }

    /**
     * The last 24 hours for the screen: written rows plus the bucket in
     * progress, straight from the cache.
     *
     * @return array<string, mixed>
     */
    public static function report(): array
    {
        self::flush();

        $since = now()->subDay();
        $rows = [];

        try {
            $rows = DB::table('firewall_log')->where('bucket_at', '>=', $since)
                ->get(['reason', 'ip', 'net', 'country', 'enforced', 'hits'])
                ->map(static fn ($r): array => (array) $r)->all();
        } catch (\Throwable) {
        }

        $now = intdiv(FirewallStore::now(), self::BUCKET);
        $last = (int) (FirewallStore::get('fw:lf:last') ?? ($now - 1));

        for ($b = max($last + 1, $now - 1); $b <= $now; $b++) {
            array_push($rows, ...self::bucketRows($b));
        }

        $byReason = [];
        $countries = [];
        $ips = [];
        $nets = [];
        $refused = 0;
        $logged = 0;

        foreach ($rows as $r) {
            $hits = (int) $r['hits'];
            $enforced = (bool) $r['enforced'];
            $key = $r['reason'];
            $byReason[$key] ??= ['reason' => $key, 'label' => self::REASONS[$key] ?? $key, 'refused' => 0, 'logged' => 0];
            $byReason[$key][$enforced ? 'refused' : 'logged'] += $hits;
            if ($enforced) {
                $refused += $hits;
            } else {
                $logged += $hits;
            }

            if ($r['country'] !== null && $r['country'] !== '') {
                $countries[$r['country']] = ($countries[$r['country']] ?? 0) + $hits;
            }

            if ($r['ip'] !== null) {
                $ips[$r['ip']] ??= ['ip' => $r['ip'], 'country' => $r['country'], 'hits' => 0, 'reasons' => []];
                $ips[$r['ip']]['hits'] += $hits;
                $ips[$r['ip']]['reasons'][$key] = true;
                $nets[$r['net']] = ($nets[$r['net']] ?? 0) + $hits;
            }
        }

        arsort($countries);
        arsort($nets);
        usort($ips, static fn (array $a, array $b): int => $b['hits'] <=> $a['hits']);

        return [
            'refused' => $refused,
            'logged' => $logged,
            'reasons' => array_values($byReason),
            'countries' => array_map(static fn ($cc, $n) => ['country' => $cc, 'hits' => $n], array_keys(array_slice($countries, 0, 10, true)), array_slice($countries, 0, 10, true)),
            'ips' => array_map(static function (array $i): array {
                $i['reasons'] = array_keys($i['reasons']);

                return $i;
            }, array_slice($ips, 0, 15)),
            'nets' => array_map(static fn ($net, $n) => ['net' => $net, 'hits' => $n], array_keys(array_slice($nets, 0, 10, true)), array_slice($nets, 0, 10, true)),
        ];
    }

    /**
     * Just the two totals for the last 24 hours — the Overview tab's tiles.
     * One grouped SUM over the written rows plus the bucket in progress from
     * the cache; the per-address lists are report()'s, for the Live tab.
     *
     * @return array{refused:int, logged:int}
     */
    public static function summary(): array
    {
        self::flush();
        $out = ['refused' => 0, 'logged' => 0];

        try {
            foreach (DB::table('firewall_log')->where('bucket_at', '>=', now()->subDay())
                ->selectRaw('enforced, SUM(hits) AS n')->groupBy('enforced')->get() as $row) {
                $out[(int) $row->enforced === 1 ? 'refused' : 'logged'] += (int) $row->n;
            }
        } catch (\Throwable) {
        }

        $now = intdiv(FirewallStore::now(), self::BUCKET);
        $last = (int) (FirewallStore::get('fw:lf:last') ?? ($now - 1));

        for ($b = max($last + 1, $now - 1); $b <= $now; $b++) {
            foreach (self::bucketRows($b) as $r) {
                $out[$r['enforced'] ? 'refused' : 'logged'] += (int) $r['hits'];
            }
        }

        return $out;
    }

    public static function reset(): void
    {
        self::$flushQueued = false;
    }
}
