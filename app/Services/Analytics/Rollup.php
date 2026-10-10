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

    /**
     * TIME ON SITE (Lane AT). A gap longer than this between two hits of the
     * same visitor (or session) is a break, not reading time: it adds nothing.
     * Thirty minutes is the session timeout the browser already uses, and
     * GA's. It is also what keeps a tab left open over lunch from reading as
     * a two-hour visit.
     */
    public const IDLE_MIN = 30;

    /** Time histograms are kept per whole minute up to here; longer is one "over" bucket. */
    public const HIST_MAX = 120;

    /** Visitor segments for time on site: reached checkout, added to cart, neither. */
    public const SEGMENTS = ['chk', 'cart', 'browse'];

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
        $sessions = [];   // s => [n, first row, last minute, active seconds]
        $vis = [];        // v => [last minute, active seconds, hits, active seconds before the first add to cart|null]

        DB::table('an_hits')
            ->where('m', '>=', $from)->where('m', '<', $to)
            ->select(['id', 'm', 'v', 's', 'k', 'path', 'title', 'ref', 'ch', 'src', 'med', 'cmp', 'dev', 'br', 'os', 'cc', 'lang'])
            ->lazyById(5000)
            ->each(function ($h) use (&$views, &$visitors, &$cartV, &$checkV, &$pages, &$sessions, &$vis): void {
                $k = (int) $h->k;
                $m = (int) $h->m;

                // Time on site, per visitor (Lane AT): every hit, pages and
                // carts, in arrival order. The gap since their previous hit is
                // reading time when it is a minute or more and at most IDLE_MIN.
                // A hit stamped a minute behind the one before (two requests
                // racing across a minute boundary) adds nothing and does not
                // move the clock back.
                if (isset($vis[$h->v])) {
                    $x = &$vis[$h->v];
                    $gap = $m - $x[0];
                    if ($gap > 0 && $gap <= self::IDLE_MIN) {
                        $x[1] += $gap * 60;
                    }
                    $x[0] = max($x[0], $m);
                    $x[2]++;
                    if ($k === 1 && $x[3] === null) {
                        $x[3] = $x[1];
                    }
                    unset($x);
                } else {
                    // A cart as the day's first hit has no page before it to
                    // time from: -1 marks it, so no later cart is timed either.
                    $vis[$h->v] = [$m, 0, 1, $k === 1 ? -1 : null];
                }

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
                    $x = &$sessions[$h->s];
                    $x[0]++;
                    $gap = $m - $x[2];
                    if ($gap > 0 && $gap <= self::IDLE_MIN) {
                        $x[3] += $gap * 60;
                    }
                    $x[2] = max($x[2], $m);
                    unset($x);
                } else {
                    $sessions[$h->s] = [1, $h, $m, 0];
                }
            });

        $rows = [];
        $pageRows = [];
        foreach ($pages as $path => [$n, $vs, $title]) {
            $pageRows[] = ['val' => (string) $path, 'label' => (string) $title, 'views' => $n, 'visitors' => count($vs), 'sessions' => 0, 'bounces' => 0];
        }
        $rows = array_merge($rows, self::cap('page', $pageRows, 'views'));

        $bounces = 0;
        $timed = 0;
        $secs = 0;
        $dims = [];
        foreach ($sessions as [$n, $h, , $sec]) {
            if ($n === 1) {
                $bounces++;
            } else {
                // Two or more page views: the only sessions hits can time.
                $timed++;
                $secs += $sec;
            }
            foreach (self::SESSION_DIMS as $col => $dim) {
                $val = (string) $h->{$col};
                if ($val === '' && in_array($dim, ['campaign', 'referrer', 'medium', 'source'], true)) {
                    continue;
                }
                $d = &$dims[$dim][$val];
                $d ??= [0, 0, 0, [], 0, 0];
                $d[0] += $n;
                $d[1]++;
                $d[2] += $n === 1 ? 1 : 0;
                $d[3][$h->v] = true;
                if ($n > 1) {
                    $d[4]++;
                    $d[5] += $sec;
                }
                unset($d);
            }
        }

        $rows = array_merge($rows, self::timeRows($vis, $cartV, $checkV));
        $tvis = 0;
        $vsecs = 0;
        foreach ($vis as [, $sec, $hits]) {
            if ($hits > 1) {
                $tvis++;
                $vsecs += $sec;
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
            foreach ($vals as $val => [$v, $s, $b, $vs, $tn, $ts]) {
                $list[] = ['val' => (string) $val, 'label' => '', 'views' => $v, 'visitors' => count($vs), 'sessions' => $s, 'bounces' => $b, 'timed' => $tn, 'secs' => $ts];
            }
            $rows = array_merge($rows, self::cap($dim, $list, 'sessions'));
        }

        $stamp = now('UTC')->format('Y-m-d H:i:s');

        $time = ['timed' => $timed, 'secs' => $secs, 'tvis' => $tvis, 'vsecs' => $vsecs];

        DB::transaction(function () use ($day, $rows, $views, $visitors, $sessions, $bounces, $cartV, $checkV, $stamp, $time): void {
            DB::table('an_dims')->where('day', $day)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('an_dims')->insert(array_map(static fn (array $r): array => ['day' => $day] + $r, $chunk));
            }

            DB::table('an_days')->upsert([[
                'day' => $day, 'views' => $views, 'visitors' => count($visitors), 'sessions' => count($sessions),
                'bounces' => $bounces, 'carts' => count($cartV), 'checkouts' => count($checkV), 'rolled_at' => $stamp,
            ] + $time], ['day'], ['views', 'visitors', 'sessions', 'bounces', 'carts', 'checkouts', 'rolled_at', 'timed', 'secs', 'tvis', 'vsecs']);
        });
    }

    /**
     * Time on site's own rows for the day (Lane AT), from the per-visitor
     * clocks the pass kept. Aggregates only, like every other dim row:
     *
     *   tseg   one row per segment (SEGMENTS): visitors = everybody in it,
     *          timed = those with two or more hits, secs = their seconds.
     *          A visitor who reached checkout is "chk" whether or not they
     *          also carted; "cart" is carted without checkout; "browse" neither.
     *   t_vis  how many timed visitors spent each whole minute (val '000' to
     *          HIST_MAX, then one over bucket), so a period's MEDIAN can be read
     *          from summed counts -- a median cannot be added up from days.
     *   t_cart the same histogram for the active time before a visitor's first
     *          add to cart, for visitors who opened a page before it.
     *
     * @param  array<string, array{0: int, 1: int, 2: int, 3: int|null}>  $vis
     * @param  array<string, true>  $cartV
     * @param  array<string, true>  $checkV
     * @return list<array<string, mixed>>
     */
    public static function timeRows(array $vis, array $cartV, array $checkV): array
    {
        $seg = array_fill_keys(self::SEGMENTS, [0, 0, 0]);
        $hist = ['t_vis' => [], 't_cart' => []];
        $bucket = static fn (int $sec): string => sprintf('%03d', min(intdiv($sec, 60), self::HIST_MAX + 1));

        foreach ($vis as $v => [, $sec, $hits, $toCart]) {
            $key = isset($checkV[$v]) ? 'chk' : (isset($cartV[$v]) ? 'cart' : 'browse');
            $seg[$key][0]++;
            if ($hits > 1) {
                $seg[$key][1]++;
                $seg[$key][2] += $sec;
                $b = $bucket($sec);
                $hist['t_vis'][$b] = ($hist['t_vis'][$b] ?? 0) + 1;
            }
            if ($toCart !== null && $toCart >= 0) {
                $b = $bucket($toCart);
                $hist['t_cart'][$b] = ($hist['t_cart'][$b] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach ($seg as $val => [$all, $n, $sec]) {
            if ($all > 0) {
                $rows[] = ['dim' => 'tseg', 'val' => $val, 'label' => '', 'views' => 0, 'visitors' => $all, 'sessions' => 0, 'bounces' => 0, 'timed' => $n, 'secs' => $sec];
            }
        }
        foreach ($hist as $dim => $counts) {
            ksort($counts);
            foreach ($counts as $val => $n) {
                $rows[] = ['dim' => $dim, 'val' => (string) $val, 'label' => '', 'views' => 0, 'visitors' => $n, 'sessions' => 0, 'bounces' => 0, 'timed' => $n, 'secs' => 0];
            }
        }

        return $rows;
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
            $o = ['val' => '(other)', 'label' => '', 'views' => 0, 'visitors' => 0, 'sessions' => 0, 'bounces' => 0, 'timed' => 0, 'secs' => 0];
            foreach ($rest as $r) {
                foreach (['views', 'visitors', 'sessions', 'bounces', 'timed', 'secs'] as $f) {
                    $o[$f] += $r[$f] ?? 0;
                }
            }
            $keep[] = $o;
        }

        foreach ($keep as &$r) {
            // Every row the same columns, so one multi-row INSERT holds them all.
            $r += ['timed' => 0, 'secs' => 0];
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
