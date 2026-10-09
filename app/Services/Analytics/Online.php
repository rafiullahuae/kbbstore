<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Online now": the visitors on the shop at this moment.            (Lane AN2)
 *
 * The owner, on a board reading "6 · active visitors · last 10 minutes": "the
 * visitors number must be change if user left the site ... only a total number
 * of visitors present on the website at same time!"
 *
 * One row per visitor in an_online, keyed by the same daily-salted hash as
 * an_hits (two tabs are one visitor; a new page moves the row):
 *   - every opened page upserts it (Tracker::record, the page-view beacon);
 *   - a VISIBLE tab sends a heartbeat every 45 s (POST /api/online, x=hb),
 *     which upserts it again -- a reader on a long article stays online;
 *   - pagehide, or the tab going hidden, sends x=left, which marks the row
 *     gone at once -- but only if it still names the page being left, so the
 *     leave beacon of page A cannot cancel page B's view that already landed.
 * Online now = not gone AND seen in the last WINDOW seconds. A visitor whose
 * phone locked without a leave beacon drops off after WINDOW seconds.
 *
 * COST: one UPSERT (or one UPDATE for a leave) per request; a visible tab sends
 * ~1.3 heartbeats a minute, so ~1.3 single-row writes per online visitor per
 * minute. The minute rollup deletes rows older than KEEP seconds, so the table
 * holds at most the last ten minutes' visitors. No cookie, no session.
 */
final class Online
{
    public const WINDOW = 90;

    public const KEEP = 600;

    public const HEARTBEAT_S = 45;

    /** The page-view beacon's row: the visitor is here, on this page. */
    public static function seen(array $row): void
    {
        DB::table('an_online')->upsert(
            [['v' => $row['v'], 't' => now()->getTimestamp(), 'gone' => 0, 'path' => $row['path'], 'title' => $row['title'], 'dev' => $row['dev'], 'cc' => $row['cc']]],
            ['v'],
            ['t', 'gone', 'path', 'title', 'dev', 'cc'],
        );
    }

    /** POST /api/online: a heartbeat or a leave. Never throws. */
    public static function ping(Request $request): bool
    {
        try {
            $v = Tracker::visitorFor($request);
            $path = Tracker::path((string) $request->input('p', ''));

            if ($v === null || $path === null) {
                return false;
            }

            if ($request->input('x') === 'left') {
                DB::table('an_online')->where('v', $v)->where('path', $path)->update(['gone' => 1]);

                return true;
            }

            $ua = (string) $request->userAgent();
            DB::table('an_online')->upsert(
                [['v' => $v, 't' => now()->getTimestamp(), 'gone' => 0, 'path' => $path, 'title' => Tracker::text($request->input('t'), 120),
                    'dev' => Tracker::device($ua), 'cc' => Tracker::country((string) $request->ip(), $request)]],
                ['v'],
                ['t', 'gone', 'path', 'title'],
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{online: int, pages: int} */
    public static function now(): array
    {
        $r = DB::table('an_online')->where('gone', 0)->where('t', '>=', now()->getTimestamp() - self::WINDOW)
            ->selectRaw('COUNT(*) n, COUNT(DISTINCT path) p')->first();

        return ['online' => (int) ($r->n ?? 0), 'pages' => (int) ($r->p ?? 0)];
    }

    /** @return list<array{path: string, title: string, n: int}> */
    public static function pages(int $limit = 8): array
    {
        return DB::table('an_online')->where('gone', 0)->where('t', '>=', now()->getTimestamp() - self::WINDOW)
            ->groupBy('path')->selectRaw('path, MAX(title) title, COUNT(*) n')
            ->orderByDesc('n')->orderBy('path')->limit($limit)->get()
            ->map(static fn ($r): array => ['path' => (string) $r->path, 'title' => (string) $r->title, 'n' => (int) $r->n])->all();
    }

    public static function prune(): int
    {
        return DB::table('an_online')->where('t', '<', now()->getTimestamp() - self::KEEP)->delete();
    }
}
