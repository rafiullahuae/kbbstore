<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The shop's own wall clock — a DISPLAY layer over UTC storage.
 *
 * THE PROBLEM THIS SOLVES. The shop is in Dubai. `config/app.php` is `UTC` and
 * no env file sets `APP_TIMEZONE`, so every date the owner reads was a UTC day:
 * an order placed between midnight and 04:00 Dubai time landed in the PREVIOUS
 * day's bar on the 14-day chart, showed yesterday's date on its invoice, and
 * counted towards yesterday on the dashboard. The owner's "today" and the
 * software's "today" were four hours apart, every day, in every figure.
 *
 * WHAT IS STORED, ESTABLISHED BY EXPERIMENT AND NOT BY ASSUMPTION.
 * `orders.created_at` holds a naive `Y-m-d H:i:s` string that is a UTC instant.
 * An order placed at 01:30 on 16 September Dubai time is stored as
 * `2026-09-15 21:30:00`. The importer guarantees it — DateParser::utc()
 * converts from the WordPress site timezone (Asia/Dubai) to UTC before writing,
 * see app/Services/Import/DateParser.php:28 — and the application writes `now()`
 * under an app timezone of UTC. Storage is therefore internally consistent and
 * CORRECT. Nothing here rewrites it.
 *
 * WHY THIS IS NOT `APP_TIMEZONE=Asia/Dubai`, WHICH IS THE EXPENSIVE MISTAKE.
 * Flipping `config('app.timezone')` does not convert anything; it REINTERPRETS
 * it. Eloquent's datetime cast would read the stored `2026-09-15 21:30:00` as
 * 21:30 *Dubai* — a different instant, four hours later than the one that was
 * written — so every historical order would silently move, every total in a
 * bounded window would change, and a re-save would then write Dubai wall-clock
 * into a UTC column and corrupt the row for real. Worse, Laravel's
 * `fromDateTime()` formats a DateTime with `->format()` WITHOUT converting its
 * zone, so a Carbon carrying a non-UTC zone is stored as its wall clock
 * verbatim. There is a test that pins the raw column value precisely so this
 * class can never quietly become that change.
 *
 * So: storage stays UTC, always. Everything here converts an instant for
 * reading, or computes the UTC instant at which a shop-local day boundary
 * falls. The zone is a SETTING (`store_timezone`), not a constant, defaulting
 * to Asia/Dubai.
 *
 * ON BOUNDARIES. A day bucket computed in one zone and compared against a
 * timestamp in another is exactly the bug being fixed here, so there are only
 * two directions and they are named: dayKey() takes a stored UTC instant to the
 * shop-local day it belongs to, and startOfDayUtc()/windowStartUtc() take a
 * shop-local day boundary to the UTC instant a query may compare against. Query
 * bounds are handed to the builder as CarbonImmutable in UTC, never as an
 * ISO-8601 STRING: SQLite compares strings as TEXT, and `2026-08-17T12:00:00Z`
 * sorts after `2026-08-17 13:00:00` because 'T' > ' ', so an ISO bound silently
 * drops the whole boundary day on SQLite while MySQL coerces it and does not.
 */
final class StoreTime
{
    /** The setting the owner edits, in Store -> Business Details. */
    public const SETTING_KEY = 'store_timezone';

    /** The shop is in Dubai. Used when the setting is unset or unreadable. */
    public const DEFAULT_ZONE = 'Asia/Dubai';

    /**
     * Storage is UTC and this class never changes that. Named so the intent is
     * legible at every call site rather than being a bare string.
     */
    public const STORAGE_ZONE = 'UTC';

    /**
     * The shop's timezone, from the setting, falling back to Dubai.
     *
     * A value that is not a real timezone identifier falls back rather than
     * throwing: a bad setting must not take the dashboard down, and the
     * fallback is the truth for this store anyway. The settings endpoint
     * validates on the way in, so an invalid value can only arrive from a
     * hand-edited row.
     */
    public static function zone(): string
    {
        $raw = trim((string) app(\App\Services\SettingsService::class)->get(self::SETTING_KEY, ''));

        if ($raw === '' || ! self::isValidZone($raw)) {
            return self::DEFAULT_ZONE;
        }

        return $raw;
    }

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::zone());
    }

    public static function isValidZone(string $zone): bool
    {
        try {
            new DateTimeZone($zone);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Now, as the shop's clock reads it. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::STORAGE_ZONE)->setTimezone(self::timezone());
    }

    /**
     * A stored instant, converted for reading on the shop's clock.
     *
     * CONVERTED, not reinterpreted: setTimezone() keeps the instant and changes
     * the wall clock, which is the whole distinction this class exists to hold.
     * A value with no zone of its own is taken as UTC, because that is what the
     * columns hold.
     */
    public static function display(DateTimeInterface|string|null $at): ?CarbonImmutable
    {
        if ($at === null || $at === '') {
            return null;
        }

        $instant = $at instanceof DateTimeInterface
            ? CarbonImmutable::instance(
                $at instanceof \DateTime ? \DateTimeImmutable::createFromMutable($at) : $at
            )
            : CarbonImmutable::parse($at, self::STORAGE_ZONE);

        return $instant->setTimezone(self::timezone());
    }

    /**
     * The shop-local calendar day a stored instant belongs to, as 'Y-m-d'.
     *
     * This is the bucket key for the daily chart. `substr((string) $created_at,
     * 0, 10)` was the old form and it is the bug: it reads the UTC day.
     */
    public static function dayKey(DateTimeInterface|string|null $at): ?string
    {
        return self::display($at)?->format('Y-m-d');
    }

    /** Midnight today, on the shop's clock. */
    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    /**
     * The UTC instant at which a shop-local day begins — the value a query
     * bound is built from.
     *
     * For Dubai, shop-local 2026-09-16 00:00 is 2026-09-15 20:00 UTC.
     */
    public static function startOfDayUtc(DateTimeInterface|string|null $day = null): CarbonImmutable
    {
        $local = $day === null
            ? self::now()
            : ($day instanceof DateTimeInterface
                ? self::display($day)
                : CarbonImmutable::parse((string) $day, self::timezone()));

        return ($local ?? self::now())->startOfDay()->setTimezone(self::STORAGE_ZONE);
    }

    /**
     * The UTC instant a rolling "last N days" window opens at.
     *
     * It opens at MIDNIGHT on the shop's clock N days ago, not at the rolling
     * instant N x 24h ago, because that is what "last 30 days" means to a
     * person reading a dashboard — the window's oldest day is a whole day, and
     * the figure does not creep by a few minutes' worth of orders every time
     * the page is refreshed.
     */
    public static function windowStartUtc(int $days): CarbonImmutable
    {
        return self::now()->subDays($days)->startOfDay()->setTimezone(self::STORAGE_ZONE);
    }

    /**
     * The last $count shop-local day keys, oldest first, ending with today.
     *
     * Built by stepping the shop-local clock so a DST transition (none in
     * Dubai, but the zone is a setting and Europe/London is a legal value)
     * cannot drop or duplicate a bucket the way subtracting 86400 seconds
     * from a UTC instant would.
     */
    /** @return list<string> */
    public static function recentDayKeys(int $count): array
    {
        $today = self::today();
        $keys = [];

        for ($i = $count - 1; $i >= 0; $i--) {
            $keys[] = $today->subDays($i)->format('Y-m-d');
        }

        return $keys;
    }

    /**
     * A date as the owner should read it: "15 September 2026", shop-local.
     *
     * The single place invoices, emails and admin screens go through, so a
     * change of wording cannot land on one of the three and not the others.
     */
    public static function formatDate(DateTimeInterface|string|null $at, string $format = 'j F Y'): string
    {
        return self::display($at)?->format($format) ?? '';
    }

    /**
     * The shop-local ISO-8601 rendering of a stored instant, offset included.
     *
     * For JSON the admin screens read. The default Carbon serialisation is
     * `2026-09-15T21:30:00.000000Z`, and the dashboard does `.slice(0, 10)` on
     * it — which is why an order placed at 01:30 Dubai showed the day before.
     * This carries the offset, so `new Date(...)` in the browser is right too
     * and slicing the first ten characters gives the shop's day.
     */
    public static function iso(DateTimeInterface|string|null $at): ?string
    {
        return self::display($at)?->toIso8601String();
    }
}
