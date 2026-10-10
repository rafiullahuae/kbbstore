<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Support\StoreTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * an_hits -> an_days + an_dims, and the prune.                       (Lane AN)
 *
 * WHAT RUNS WHEN NOBODY IS LOOKING: this, once a minute from the scheduler
 * (`kbb:analytics-rollup`), and nothing else. It does nothing at all in a
 * minute with no new hits (one MAX(id) query, compared with a watermark).
 * The live panel is never precomputed: it is read from the minute index of
 * an_hits when, and only when, the Analytics screen asks.
 *
 * ONE DAY IS RECOMPUTED WHOLE, not incremented. Sessions, bounces and distinct
 * visitors are not additive -- a session that reads a second page two minutes
 * later stops being a bounce -- so today is rebuilt from its own raw rows
 * each run: one pass in PHP over the day's hits (chunked by id), then a
 * delete-and-insert of that day's summary rows in one transaction. Idempotent:
 * running it twice, or late, gives the same rows. Yesterday is rebuilt too
 * until 40 minutes past midnight, for a session that crossed it.
 *
 * Bounded: each dimension keeps its top CAP values per day and folds the rest
 * into "(other)", so a day's summary size does not grow with the catalogue.
 */
final class Rollup
{
    public const CAP = 300;

    public const KEEP_HOURS = 48;

    /** Session-level dimensions: column on the session's first hit => dim. */
    public const SESSION_DIMS = [
        'path' => 'entry', 'ch' => 'channel', 'src' => 'source', 'med' => 'medium', 'cmp' => 'campaign',
        'ref' => 'referrer', 'dev' => 'device', 'br' => 'browser', 'os' => 'os', 'cc' => 'country', 'lang' => 'lang',
    ];

    /** Run what is due. $force ignores the watermark. Returns days rebuilt. */
    public static function runDue(bool $force = false): int
    {
        $max = (int) DB::table('an_hits')->max('id');
        $wm = (int) Cache::get('kbb:an:wm', -1);
        $now = StoreTime::now();
        $done = 0;

        if ($force || $max !== $wm) {
            self::rollDay($now->format('Y-m-d'));
            $done++;

            if ($now->getTimestamp() - $now->startOfDay()->getTimestamp() < 40 * 60) {
                self::rollDay($now->subDay()->format('Y-m-d'));
                $done++;
            }

            Cache::put('kbb:an:wm', $max, 86400);
        }

        self::prune();

        return $done;
    }

    public const TICK_KEY = 'kbb:an:tick';

    /**
     * Today's summary no older than $seconds, for the board's live poll
     * (Lane AN2): when it is, rebuild it here -- under a lock, so two boards
     * open at once (the console and the owner app) never both rebuild; the
     * one that does not get the lock reads the summary as it is and is fresh
     * on its next poll. Returns whether this call rebuilt. Without cron this
     * is what keeps today moving; with cron it almost never has work to do.
     */
    public static function freshToday(int $seconds = 60): bool
    {
        if (! self::stale($seconds)) {
            return false;
        }

        $ran = Cache::lock('kbb:an:rollup', 30)->get(static function (): bool {
            // Checked again inside the lock: the holder before us may have just done it.
            if (! self::stale(5)) {
                return false;
            }
            self::runDue(true);

            return true;
        });

        return (bool) $ran;
    }

    /** The scheduler ran the rollup within the last five minutes. */
    public static function cronAlive(): bool
    {
        return (int) Cache::get(self::TICK_KEY, 0) >= now()->getTimestamp() - 300;
    }

    /** Is today's summary older than $seconds? (The dashboard's lazy check.) */
    public static function stale(int $seconds = 120): bool
    {
        $at = DB::table('an_days')->where('day', StoreTime::now()->format('Y-m-d'))->value('rolled_at');

        return $at === null || CarbonImmutable::parse((string) $at, 'UTC')->getTimestamp() < now()->getTimestamp() - $seconds;
    }

    /** [start, end) epoch minutes of a shop day. */
    public static function minutes(string $day): array
    {
        $start = CarbonImmutable::parse($day.' 00:00:00', StoreTime::timezone());

        return [intdiv($start->getTimestamp(), 60), intdiv($start->addDay()->getTimestamp(), 60)];
    }

    public static function rollDay(string $day): void
    {
        [$from, $to] = self::minutes($day);

        $views = 0;
        $visitors = [];
        $cartV = [];
        $checkV = [];
        $pages = [];      // path => [views, visitors set, title]
        $sessions = [];   // s => [n, first row]

        DB::table('an_hits')
            ->where('m', '>=', $from)->where('m', '<', $to)
            ->select(['id', 'v', 's', 'k', 'path', 'title', 'ref', 'ch', 'src', 'med', 'cmp', 'dev', 'br', 'os', 'cc', 'lang'])
            ->lazyById(5000)
            ->each(function ($h) use (&$views, &$visitors, &$cartV, &$checkV, &$pages, &$sessions): void {
                $k = (int) $h->k;

                if ($k === 1) {
                    $cartV[$h->v] = true;

                    return;
                }

                if ($k === 2) {
                    $checkV[$h->v] = true;
                }

                $views++;
                $visitors[$h->v] = true;

                $p = &$pages[$h->path];
                $p ??= [0, [], ''];
                $p[0]++;
                $p[1][$h->v] = true;
                if ($h->title !== '') {
                    $p[2] = $h->title;
                }
                unset($p);

                if (isset($sessions[$h->s])) {
                    $sessions[$h->s][0]++;
                } else {
                    $sessions[$h->s] = [1, $h];
                }
            });

        $rows = [];
        $pageRows = [];
        foreach ($pages as $path => [$n, $vs, $title]) {
            $pageRows[] = ['val' => (string) $path, 'label' => (string) $title, 'views' => $n, 'visitors' => count($vs), 'sessions' => 0, 'bounces' => 0];
        }
        $rows = array_merge($rows, self::cap('page', $pageRows, 'views'));

        $bounces = 0;
        $dims = [];
        foreach ($sessions as [$n, $h]) {
            if ($n === 1) {
                $bounces++;
            }
            foreach (self::SESSION_DIMS as $col => $dim) {
                $val = (string) $h->{$col};
                if ($val === '' && in_array($dim, ['campaign', 'referrer', 'medium', 'source'], true)) {
                    continue;
                }
                $d = &$dims[$dim][$val];
                $d ??= [0, 0, 0, []];
                $d[0] += $n;
                $d[1]++;
                $d[2] += $n === 1 ? 1 : 0;
                $d[3][$h->v] = true;
                unset($d);
            }
        }

        /*
         * Lane ER: a campaign's add-to-carts and checkouts started. A cart hit
         * carries no page fields (Tracker::row kind 1), so it is tied to the
         * campaign by its VISITOR: the visitors whose session arrived with this
         * utm_campaign today, and of those, how many added to cart / reached the
         * checkout today. Kept as two more dims, read by the campaign report.
         */
        $cmpV = [];
        foreach ($sessions as [$n, $h]) {
            if ((string) $h->cmp !== '') {
                $cmpV[(string) $h->cmp][$h->v] = true;
            }
        }
        foreach (['cmp_cart' => $cartV, 'cmp_chk' => $checkV] as $dim => $set) {
            $list = [];
            foreach ($cmpV as $val => $vs) {
                $hit = count(array_intersect_key($vs, $set));
                if ($hit > 0) {
                    $list[] = ['val' => (string) $val, 'label' => '', 'views' => 0, 'visitors' => $hit, 'sessions' => $hit, 'bounces' => 0];
                }
            }
            $rows = array_merge($rows, self::cap($dim, $list, 'sessions'));
        }

        foreach ($dims as $dim => $vals) {
            $list = [];
            foreach ($vals as $val => [$v, $s, $b, $vs]) {
                $list[] = ['val' => (string) $val, 'label' => '', 'views' => $v, 'visitors' => count($vs), 'sessions' => $s, 'bounces' => $b];
            }
            $rows = array_merge($rows, self::cap($dim, $list, 'sessions'));
        }

        $stamp = now('UTC')->format('Y-m-d H:i:s');

        DB::transaction(function () use ($day, $rows, $views, $visitors, $sessions, $bounces, $cartV, $checkV, $stamp): void {
            DB::table('an_dims')->where('day', $day)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('an_dims')->insert(array_map(static fn (array $r): array => ['day' => $day] + $r, $chunk));
            }

            DB::table('an_days')->upsert([[
                'day' => $day, 'views' => $views, 'visitors' => count($visitors), 'sessions' => count($sessions),
                'bounces' => $bounces, 'carts' => count($cartV), 'checkouts' => count($checkV), 'rolled_at' => $stamp,
            ]], ['day'], ['views', 'visitors', 'sessions', 'bounces', 'carts', 'checkouts', 'rolled_at']);
        });
    }

    /**
     * The top CAP rows by $by; the rest folded into one "(other)" row.
     *
     * @param  list<array<string, mixed>>  $list
     * @return list<array<string, mixed>>
     */
    public static function cap(string $dim, array $list, string $by): array
    {
        usort($list, static fn (array $a, array $b): int => $b[$by] <=> $a[$by] ?: strcmp($a['val'], $b['val']));

        $keep = array_slice($list, 0, self::CAP);
        $rest = array_slice($list, self::CAP);

        if ($rest !== []) {
            $o = ['val' => '(other)', 'label' => '', 'views' => 0, 'visitors' => 0, 'sessions' => 0, 'bounces' => 0];
            foreach ($rest as $r) {
                foreach (['views', 'visitors', 'sessions', 'bounces'] as $f) {
                    $o[$f] += $r[$f];
                }
            }
            $keep[] = $o;
        }

        foreach ($keep as &$r) {
            $r['dim'] = $dim;
            $r['val'] = mb_substr($r['val'], 0, 191);
            $r['label'] = mb_substr($r['label'], 0, 120);
        }

        return $keep;
    }

    /** Delete raw hits older than KEEP_HOURS, and stale salts. */
    public static function prune(): int
    {
        $cutoff = intdiv(time(), 60) - self::KEEP_HOURS * 60;
        $maxId = DB::table('an_hits')->where('m', '<', $cutoff)->max('id');
        $n = $maxId === null ? 0 : DB::table('an_hits')->where('id', '<=', (int) $maxId)->delete();
        Tracker::forgetOldSalts();

        try {
            Online::prune();   // "Online now" rows older than ten minutes (Lane AN2)
        } catch (\Throwable) {
        }

        return $n;
    }
}
