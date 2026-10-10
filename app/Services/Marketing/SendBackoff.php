<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Support\StoreTime;

/**
 * "Google says slow down" — the automatic pause (Lane EB).
 *
 * When Google's SMTP server answers a campaign message with a SENDER refusal
 * (BounceCodes::atSend: 421, 4.7.x, "rate limit", "temporarily deferred",
 * 5.4.5 daily limit, a dropped connection), that row goes back to pending and
 * ALL campaign sending stops until:
 *
 *   strike 1   5 minutes      strike 4   40 minutes
 *   strike 2  10 minutes      strike 5+  80 minutes, then doubling, at most 6 h
 *   strike 3  20 minutes
 *
 *   "Daily user sending limit exceeded" (5.4.5) → until after midnight, Dubai
 *   time, plus five minutes: Google lifts the limit within 24 hours and
 *   knocking sooner only extends it.
 *
 * Kept up to STRIKES_PAUSE consecutive strikes; the next one pauses every
 * sending campaign (status `paused`), which the owner resumes from Review &
 * send. One message accepted clears the count.
 *
 * State is a small JSON file in storage/framework (like the campaign tick's
 * heartbeat), NOT a settings row: a settings write empties the shop's whole
 * settings cache, and this must never be a reason the shop slows down.
 */
final class SendBackoff
{
    public const BASE_MINUTES = 5;

    public const MAX_MINUTES = 360;

    public const STRIKES_PAUSE = 6;

    public static function path(): string
    {
        return storage_path('framework/kbb-mail-backoff.json');
    }

    /** @return array{strikes:int, until:int, why:string} */
    public static function state(): array
    {
        $raw = @file_get_contents(self::path());
        $d = is_string($raw) ? json_decode($raw, true) : null;

        return [
            'strikes' => (int) ($d['strikes'] ?? 0),
            'until' => (int) ($d['until'] ?? 0),
            'why' => (string) ($d['why'] ?? ''),
        ];
    }

    /** Seconds until sending may resume; 0 when it may now. */
    public static function wait(): int
    {
        return max(0, self::state()['until'] - now()->getTimestamp());
    }

    /**
     * Record a sender refusal; returns the new state.
     *
     * @return array{strikes:int, until:int, why:string}
     */
    public static function strike(string $why, ?string $code): array
    {
        $s = self::state();
        $s['strikes']++;

        if ($code === '5.4.5' || preg_match('/daily (user )?sending (limit|quota)/i', $why) === 1) {
            $until = StoreTime::startOfDayUtc()->addDay()->addMinutes(5)->getTimestamp();
        } else {
            $minutes = min(self::MAX_MINUTES, self::BASE_MINUTES * (2 ** min(10, $s['strikes'] - 1)));
            $until = now()->getTimestamp() + $minutes * 60;
        }

        $s['until'] = max($until, $s['until']);
        $s['why'] = mb_substr($why, 0, 200);
        @file_put_contents(self::path(), json_encode($s), LOCK_EX);

        return $s;
    }

    /** A message was accepted: the strikes are forgiven. Writes only when needed. */
    public static function clear(): void
    {
        if (is_file(self::path())) {
            @unlink(self::path());
        }
    }
}
