<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Services\Mail\MailSettings;
use App\Services\SettingsService;
use App\Support\StoreTime;
use Illuminate\Support\Facades\DB;

/**
 * How fast campaigns may go (Lane MK, docs/EMAILS-PLAN.md §4).
 *
 *   per minute   default 60. Counted over the last 60 seconds of sends
 *                across EVERY campaign, so two campaigns running at once
 *                share it rather than doubling it.
 *   per day      default 2,000 when Google Workspace is the transport
 *                (Google's own limit is about 2,000 a day per user, the
 *                owner's D1) and 500 otherwise — a shared host's mail server
 *                is the conservative case: it shares an IP's reputation and
 *                the order emails go out through it too. Counted from
 *                midnight on the shop's clock.
 *   per step     at most 25, and a step stops after 15 seconds.
 *
 * Both are owner-editable (Marketing Emails → Campaigns → Sending limits),
 * stored as settings rows, and clamped here whatever is stored.
 */
final class SendLimits
{
    public const STEP_MAX = 25;

    public const STEP_SECONDS = 15;

    public const RATE_DEFAULT = 60;

    public const RATE_MAX = 120;

    public const CAP_GOOGLE = 2000;

    public const CAP_OTHER = 500;

    public const CAP_MAX = 10000;

    public function __construct(private SettingsService $settings, private MailSettings $mail) {}

    public function perMinute(): int
    {
        $v = (int) $this->settings->get('mkt_rate_per_minute', 0);

        return $v > 0 ? max(1, min(self::RATE_MAX, $v)) : self::RATE_DEFAULT;
    }

    public function defaultCap(): int
    {
        try {
            return $this->mail->transport() === MailSettings::TRANSPORT_GMAIL ? self::CAP_GOOGLE : self::CAP_OTHER;
        } catch (\Throwable) {
            return self::CAP_OTHER;
        }
    }

    public function perDay(): int
    {
        $v = (int) $this->settings->get('mkt_daily_cap', 0);
        $ceiling = $this->defaultCap() === self::CAP_GOOGLE ? self::CAP_GOOGLE : self::CAP_MAX;

        return $v > 0 ? max(1, min($ceiling, $v)) : $this->defaultCap();
    }

    /** Messages handed to the transport (sent or failed) in the last minute. */
    public function usedThisMinute(): int
    {
        return DB::table('mkt_sends')->whereIn('status', ['sent', 'failed'])
            ->where('sent_at', '>=', now()->subSeconds(60))->count();
    }

    /** … and since midnight, shop time. */
    public function usedToday(): int
    {
        return DB::table('mkt_sends')->whereIn('status', ['sent', 'failed'])
            ->where('sent_at', '>=', StoreTime::startOfDayUtc())->count();
    }

    /**
     * How many rows the next step may claim: min(rate left this minute,
     * daily cap left, 25).
     *
     * @return array{allowed:int, minute_left:int, day_left:int}
     */
    public function room(): array
    {
        $minute = max(0, $this->perMinute() - $this->usedThisMinute());
        $day = max(0, $this->perDay() - $this->usedToday());

        return ['allowed' => min($minute, $day, self::STEP_MAX), 'minute_left' => $minute, 'day_left' => $day];
    }
}
