<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\NotFoundLog;
use Illuminate\Support\Facades\DB;

/**
 * Records genuine 404s so the admin can see what's actually being hit,
 * rather than guessing what's worth a redirect. Deliberately narrow: only
 * called for storefront GET requests (see bootstrap/app.php), and skips a
 * short list of paths that 404 constantly and legitimately on any site
 * (favicon.ico, apple-touch-icon, .well-known/*) — logging those would
 * just bury the genuinely broken links this exists to surface.
 */
class NotFoundLogger
{
    private const NOISE_EXACT = ['favicon.ico', 'apple-touch-icon.png', 'apple-touch-icon-precomposed.png'];
    private const NOISE_PREFIX = ['.well-known/'];

    /** Cap the table at roughly this many rows — trimmed by dropping the least-hit entries once exceeded. */
    private const MAX_ROWS = 300;

    public static function record(string $path, ?string $referer = null): void
    {
        $path = '/' . ltrim($path, '/');
        $bare = ltrim($path, '/');

        if (in_array($bare, self::NOISE_EXACT, true)) {
            return;
        }

        foreach (self::NOISE_PREFIX as $prefix) {
            if (str_starts_with($bare, $prefix)) {
                return;
            }
        }

        try {
            $existing = NotFoundLog::query()->where('path', $path)->first();

            if ($existing) {
                DB::table('not_found_log')->where('id', $existing->id)->update([
                    'hits' => $existing->hits + 1,
                    'last_seen_at' => now(),
                    'referer' => $referer ?: $existing->referer,
                ]);

                return;
            }

            NotFoundLog::query()->create([
                'path' => $path,
                'hits' => 1,
                'referer' => $referer,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);

            // Only worth checking the table size right after a genuine insert
            // grew it — an update to an existing row never changes the count.
            self::trimIfOversized();
        } catch (\Throwable $e) {
            // A logging failure must never surface to the visitor as an
            // error on top of the 404 they already hit.
        }
    }

    private static function trimIfOversized(): void
    {
        $count = NotFoundLog::query()->count();

        if ($count <= self::MAX_ROWS) {
            return;
        }

        // `id` last, because the rows this LIMIT does not reach are DELETED
        // on the next line. `hits` is 1 for most of a 404 log and
        // `last_seen_at` ties at the second, so without a total order it is
        // the database that picks which of a tied block survives the trim.
        $idsToKeep = NotFoundLog::query()
            ->orderByDesc('hits')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ROWS)
            ->pluck('id');

        NotFoundLog::query()->whereNotIn('id', $idsToKeep)->delete();
    }
}
