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
 *   per minute   default 6 on Google Workspace, 60 otherwise. Counted over
 *                the last 60 seconds of sends across EVERY campaign, so two
 *                campaigns running at once share it rather than doubling it.
 *   per day      default 1,500 on Google Workspace, at most 2,000; 500
 *                otherwise — a shared host's mail server is the conservative
 *                case: it shares an IP's reputation and the order emails go
 *                out through it too. Counted from midnight on the shop's clock.
 *   gap          (Lane EB) on Google Workspace, one email every 8–12 seconds:
 *                10 s, ±2 s of jitter that differs per message. 0 elsewhere.
 *   per step     at most 25, and a step stops after 15 seconds.
 *
 * ── WHY THESE GOOGLE NUMBERS (Lane EB, the owner, 10 October) ──────────────
 *
 * "i'm using google workspace to send emails, adjust the bulk emails sending
 * duration inbetween according and it must not be spamming."
 *
 * Google's published per-user limits for Gmail in Workspace ("Gmail sending
 * limits in Google Workspace", support.google.com/a/answer/166852, mirrored at
 * knowledge.workspace.google.com/admin/gmail/gmail-sending-limits-in-google-
 * workspace; read 10 Oct 2026): 2,000 messages a day per user (500 on a
 * trial), 2,000 UNIQUE EXTERNAL recipients a day, and 1,500 a day for mail
 * merge. A campaign is all unique external recipients, and the ORDER emails
 * leave through the same account, so:
 *
 *   1,500 a day leaves 500 of Google's 2,000 for order confirmations, and is
 *         Google's own figure for mail merge. Editable, never above 2,000.
 *   10 s ± 2 between messages ≈ 6 a minute, so 1,500 take about four hours —
 *         a steady trickle rather than a burst, which is what a receiving
 *         server's rate-based spam scoring and Google's own "unusual sending
 *         activity" lock both look for. Editable (Bounces & unsubscribes →
 *         Settings → Sending pace), 3–120 s.
 *   6 a minute so the per-minute cap agrees with the pace.
 *
 * Exceeding the daily limit locks the ACCOUNT's sending for up to 24 hours —
 * order confirmations included — which is why SendBackoff stops everything on
 * the first "5.4.5" rather than retrying.
 *
 * All are owner-editable (Marketing Emails → Campaigns → Sending limits, and
 * the pace under Bounces & unsubscribes → Settings), stored as settings rows,
 * and clamped here whatever is stored. A value the owner already SAVED is his
 * and is kept; only the defaults moved.
 */
final class SendLimits
{
    public const STEP_MAX = 25;

    public const STEP_SECONDS = 15;

    public const RATE_DEFAULT = 60;

    /** Lane EB: 6 a minute on Google Workspace, in step with the 10 s gap. */
    public const RATE_GOOGLE = 6;

    public const RATE_MAX = 120;

    /** Lane EB: the DEFAULT on Google Workspace (was 2,000). */
    public const CAP_GOOGLE = 1500;

    /** Google's own per-user ceiling; nothing stored goes above it. */
    public const CAP_GOOGLE_MAX = 2000;

    public const GAP_KEY = 'mkt_send_gap_seconds';

    public const GAP_GOOGLE = 10;

    public const GAP_MIN = 3;

    public const GAP_MAX = 120;

    /** ± this many seconds around the gap, different for every message. */
    public const JITTER = 2;

    public const CAP_OTHER = 500;

    public const CAP_MAX = 10000;

    public function __construct(private SettingsService $settings, private MailSettings $mail) {}

    public function perMinute(): int
    {
        $v = (int) $this->settings->get('mkt_rate_per_minute', 0);

        return $v > 0 ? max(1, min(self::RATE_MAX, $v)) : ($this->google() ? self::RATE_GOOGLE : self::RATE_DEFAULT);
    }

    private function google(): bool
    {
        try {
            return $this->mail->transport() === MailSettings::TRANSPORT_GMAIL;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Seconds between two campaign messages: the stored value (3–120), else
     * 10 on Google Workspace and 0 (no pacing) on any other transport. A
     * stored 0 switches pacing off.
     */
    public function gap(): int
    {
        $v = $this->settings->get(self::GAP_KEY, null);

        if ($v === null || $v === '') {
            return $this->google() ? self::GAP_GOOGLE : 0;
        }

        $v = (int) $v;

        return $v <= 0 ? 0 : max(self::GAP_MIN, min(self::GAP_MAX, $v));
    }

    /**
     * The gap after the message with this send id: gap ± JITTER, the same
     * answer every time it is asked about the same message (so two drivers
     * agree), and a different one for the next.
     */
    public function gapAfter(int $sendId): int
    {
        $gap = $this->gap();

        if ($gap <= self::JITTER * 2) {
            return $gap;
        }

        return $gap - self::JITTER + (crc32('gap|' . $sendId) % (self::JITTER * 2 + 1));
    }

    /** The most messages the pace allows in a minute. */
    public function effectivePerMinute(): int
    {
        $gap = $this->gap();

        return $gap > 0 ? max(1, min($this->perMinute(), intdiv(60, $gap))) : $this->perMinute();
    }

    /**
     * Seconds until the next campaign message may go: Google's back-off
     * (SendBackoff), or the gap after the last message. One indexed query.
     */
    public function wait(): int
    {
        $backoff = SendBackoff::wait();
        $gap = $this->gap();

        if ($gap === 0) {
            return $backoff;
        }

        $last = DB::table('mkt_sends')->whereIn('status', ['sent', 'failed'])->whereNotNull('sent_at')
            ->orderByDesc('sent_at')->orderByDesc('id')->first(['id', 'sent_at']);

        if ($last === null) {
            return $backoff;
        }

        $next = \Illuminate\Support\Carbon::parse((string) $last->sent_at)->getTimestamp() + $this->gapAfter((int) $last->id);

        return max($backoff, $next - now()->getTimestamp());
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
        $ceiling = $this->google() ? self::CAP_GOOGLE_MAX : self::CAP_MAX;

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
     * daily cap left, 25) — and, when paced (Lane EB), ONE, and none until
     * the gap after the last message (or Google's back-off) has passed.
     *
     * @return array{allowed:int, minute_left:int, day_left:int, wait:int}
     */
    public function room(): array
    {
        $minute = max(0, $this->perMinute() - $this->usedThisMinute());
        $day = max(0, $this->perDay() - $this->usedToday());
        $allowed = min($minute, $day, self::STEP_MAX);
        $wait = $allowed > 0 ? $this->wait() : 0;

        if ($wait > 0) {
            $allowed = 0;
        } elseif ($this->gap() > 0) {
            $allowed = min($allowed, 1);
        }

        return ['allowed' => $allowed, 'minute_left' => $minute, 'day_left' => $day, 'wait' => $wait];
    }
}
