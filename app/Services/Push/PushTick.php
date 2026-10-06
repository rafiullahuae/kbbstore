<?php

declare(strict_types=1);

namespace App\Services\Push;

use Illuminate\Support\Facades\DB;

/**
 * One minute of Push Notifications (Lane PN): `php artisan kbb:push-step`,
 * every minute from routes/console.php — the same one cron line that runs
 * Marketing Emails (kbb:campaigns-step), so a server that sends scheduled
 * emails sends scheduled pushes too, with nothing new to install.
 *
 *   1. touch the heartbeat (the screen says when no tick has been seen);
 *   2. start every scheduled campaign that has come due — once: a
 *      conditional UPDATE, so overlapping ticks start it once;
 *   3. sweep the automations: back in stock, baskets, price drops;
 *   4. send what is queued and due (cap + quiet hours inside);
 *   5. step every sending campaign until BUDGET seconds have passed.
 *
 * Costs a handful of indexed statements a minute when there is nothing to do.
 * Never on a shopper's request.
 */
final class PushTick
{
    public const BUDGET = 45;

    public const STALE_AFTER = 600;

    public function __construct(private PushSender $sender, private PushAutomations $auto) {}

    public static function markerPath(): string
    {
        return storage_path('framework/kbb-push.tick');
    }

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

    /** @return array{started:int, queued:int, sent:int, steps:int} */
    public function run(): array
    {
        @touch(self::markerPath());
        $began = hrtime(true);

        $started = $this->sender->startDue();
        $queued = 0;
        foreach (['stock', 'cart', 'price'] as $sweep) {
            try {
                $queued += $this->auto->{$sweep}();
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $sent = $this->sender->runQueue();

        $steps = 0;
        foreach (DB::table('push_campaigns')->where('status', 'sending')->orderBy('id')->pluck('id') as $id) {
            $left = self::BUDGET - (int) ((hrtime(true) - $began) / 1e9);
            if ($left <= 0) {
                break;
            }
            $this->sender->step((int) $id, min(PushSender::STEP_SECONDS, $left));
            $steps++;
        }

        return ['started' => $started, 'queued' => $queued, 'sent' => $sent, 'steps' => $steps];
    }
}
