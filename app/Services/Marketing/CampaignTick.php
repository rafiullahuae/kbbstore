<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use Illuminate\Support\Facades\DB;

/**
 * Driver B — the scheduler (Lane MK, docs/EMAILS-PLAN.md §4.4).
 *
 * `php artisan kbb:campaigns-step`, every minute from routes/console.php
 * (everyMinute()->withoutOverlapping(5)), once the server's one cron line is
 * installed. Each run:
 *
 *   1. touches the heartbeat file, which is how the Campaigns screen knows a
 *      scheduler is alive (no tick for 10 minutes → it shows the exact cron
 *      line to paste into Cloudways' Cron Job Manager, and offers Driver A);
 *   2. starts every scheduled campaign that has come due — through
 *      CampaignSender::start(), whose conditional UPDATE is why a scheduled
 *      campaign fires once however many ticks or tabs race for it;
 *   3. steps every campaign that is `sending` until it has nothing more it
 *      may send this minute or BUDGET seconds have passed.
 *
 * Nothing calls this on a web request. There is no request-tail heartbeat for
 * marketing (unlike OrderReminderTick): tests/Pest.php has nothing to hold
 * off, and says so.
 */
final class CampaignTick
{
    /** Seconds one run may spend stepping. Under the minute the next run starts. */
    public const BUDGET = 45;

    /** No tick for this long and the screen says the cron line is missing. */
    public const STALE_AFTER = 600;

    public function __construct(private CampaignSender $sender) {}

    public static function markerPath(): string
    {
        return storage_path('framework/kbb-campaigns.tick');
    }

    /** When the scheduler last ran this, or null if it never has. */
    public static function lastTick(): ?int
    {
        $t = @filemtime(self::markerPath());

        return $t === false ? null : (int) $t;
    }

    public static function alive(): bool
    {
        $t = self::lastTick();

        return $t !== null && $t >= time() - self::STALE_AFTER;
    }

    /** @return array{started:int, steps:int} */
    public function run(): array
    {
        @touch(self::markerPath());

        $started = 0;
        $steps = 0;
        $began = hrtime(true);

        $due = DB::table('mkt_campaigns')->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->orderBy('id')->pluck('id');

        foreach ($due as $id) {
            [$ok] = $this->sender->start((int) $id);

            if ($ok) {
                $started++;
            } else {
                // A campaign that cannot start (its group was deleted, its
                // email no longer validates) must not be retried every minute
                // for ever: it goes to `failed` and the screen says why.
                DB::table('mkt_campaigns')->where('id', $id)->where('status', 'scheduled')
                    ->update(['status' => 'failed', 'finished_at' => now(), 'updated_at' => now()]);
            }
        }

        foreach (DB::table('mkt_campaigns')->where('status', 'sending')->orderBy('id')->pluck('id') as $id) {
            while ((hrtime(true) - $began) / 1e9 < self::BUDGET) {
                $before = $this->sender->progress((int) $id);
                $after = $this->sender->step((int) $id);
                $steps++;

                $moved = ($after['recipients'] ?? 0) !== ($before['recipients'] ?? 0)
                    || ($after['pending'] ?? 0) !== ($before['pending'] ?? 0)
                    || ($after['building'] ?? false);

                if (($after['done'] ?? true) || ! $moved || ($after['status'] ?? '') !== 'sending') {
                    break;
                }
            }
        }

        return ['started' => $started, 'steps' => $steps];
    }
}
