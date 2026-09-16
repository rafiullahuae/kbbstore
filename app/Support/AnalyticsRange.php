<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;

/**
 * The date filter behind Store -> Analytics: one object that owns every
 * boundary, every chart bucket, and the one place a timezone is read.
 *
 * WHY THIS IS A CLASS AND NOT SIX `if`s IN THE CONTROLLER
 * ------------------------------------------------------
 * Seven things on that screen have to agree about what "this month" means —
 * net revenue, refunded, average order, units sold, the chart, where the orders
 * are, and best sellers. A box that quietly disagrees with the box beside it is
 * worse than no filter at all, because the owner cannot tell which of the two
 * numbers they are reading. So the range is resolved ONCE and then applied; no
 * query works out its own dates.
 *
 * THE TIMEZONE HOOK
 * -----------------
 * timezone() below is the only place in this file — and, via apply() and
 * buckets(), the only place on the whole screen — that decides what wall clock
 * the shop keeps. config/app.php is UTC and nothing sets APP_TIMEZONE, so
 * "today" is currently a UTC day, and an order placed between midnight and
 * 04:00 in Dubai falls into yesterday. Pointing timezone() at Asia/Dubai (by
 * setting `kbb.display_timezone`, or APP_TIMEZONE) moves the KPI boundaries and
 * the chart buckets together, in one edit. Nothing else needs to change.
 *
 * HOW THE BOUNDARIES ARE COMPARED
 * -------------------------------
 * Half-open, `created_at >= start AND created_at < end`, with Carbon instances
 * handed straight to the query builder so the GRAMMAR formats them for the
 * connection.
 *
 *   Half-open, because an inclusive upper bound has to be written as some
 *   particular last instant and there is no right one: 23:59:59 drops a row
 *   stamped 23:59:59.400, and 23:59:59.999999 is not a value every column can
 *   hold. The start of the next day has neither problem.
 *
 *   Carbon instances rather than strings, because a formatted ISO-8601 cutoff —
 *   '2026-02-10T08:00:00.000000Z' — is the bug this codebase has already paid
 *   for: SQLite compares it to a stored '2026-02-10 08:00:00' as TEXT and ' '
 *   (0x20) sorts below 'T' (0x54), so the whole boundary day silently falls out
 *   of the window, while MySQL parses it but raises warning 1292 'Incorrect
 *   datetime value' on every load.
 *
 * WHAT ONE BAR IS
 * ---------------
 * Picked from the span, because a year of daily bars is a smear and a day of
 * one bar says nothing:
 *
 *      up to  2 days  ->  hour    (24 bars for "Today")
 *      up to 70 days  ->  day     ("This week", "This month", short ranges)
 *     up to 400 days  ->  week    ("This year" is ~53 bars)
 *          beyond     ->  month   (multi-year "All time")
 *
 * "All time" never uses hours: it is a history view, so its floor is a day even
 * on a shop whose first order was this morning.
 */
final class AnalyticsRange
{
    /** In the order the control shows them. `custom` last, because it opens a picker. */
    public const PERIODS = ['all', 'today', 'week', 'month', 'year', 'custom'];

    /**
     * All time, so an owner who opens Analytics and touches nothing sees the
     * same figures the screen has always shown rather than a silently narrowed
     * page that looks like the shop stopped trading.
     */
    public const DEFAULT_PERIOD = 'all';

    /**
     * THE WEEK STARTS ON MONDAY.
     *
     * The UAE moved its weekend to Saturday-Sunday on 1 January 2022, so the
     * working week here runs Monday to Friday and the calendar week runs Monday
     * to Sunday — the same as ISO-8601, and the same as every other market this
     * shop reports against. The older Gulf convention of a Sunday start belongs
     * to the Thursday-Friday weekend that no longer exists.
     *
     * It is also PRINTED. The screen shows "Mon 9 - Sun 15 Feb", because the one
     * way this decision goes wrong is silently: a week that starts on the wrong
     * day looks like a perfectly ordinary number that happens to be incorrect.
     */
    public const WEEK_STARTS_ON = CarbonInterface::MONDAY;

    private const LABELS = [
        'all' => 'All time',
        'today' => 'Today',
        'week' => 'This week',
        'month' => 'This month',
        'year' => 'This year',
        'custom' => 'Custom range',
    ];

    /** @var array<string, string> */
    private const HINTS = [
        'all' => 'Every order the shop has ever taken',
        'today' => 'Midnight to midnight',
        'week' => 'Monday to Sunday',
        'month' => 'The calendar month, not the last 30 days',
        'year' => '1 January to 31 December',
        'custom' => 'Two dates you choose, both days whole',
    ];

    private function __construct(
        private readonly string $key,
        private readonly ?CarbonImmutable $startLocal,
        private readonly ?CarbonImmutable $endLocal,        // exclusive
        private readonly CarbonImmutable $chartStartLocal,
        private readonly CarbonImmutable $chartEndLocal,    // exclusive
        private readonly string $bucket,
        private readonly string $timezone,
    ) {
    }

    /**
     * The shop's wall clock. THE ONE HOOK — see the class docblock.
     *
     * `kbb.display_timezone` first so the shop's own timezone can be set without
     * moving APP_TIMEZONE, which would also move every stored timestamp's
     * interpretation and every mail footer with it.
     */
    public static function timezone(): string
    {
        return self::known((string) config('kbb.display_timezone') ?: self::storageTimezone());
    }

    /**
     * The wall clock the stored timestamps are already ON. The second half of
     * the hook, and the reason moving the first half is safe.
     *
     * Laravel formats a Carbon for the connection using THAT Carbon's own
     * timezone, and writes `now()` in config('app.timezone'). So the tz a
     * datetime column is keeping is app.timezone, whatever the screen displays.
     *
     * Both knobs are read, so all three configurations are correct without
     * another edit here:
     *
     *   app=UTC,   display unset   -> no conversion at all (today's behaviour)
     *   app=UTC,   display=Dubai   -> stored UTC, shown and bucketed in Dubai
     *                                (what Lane CI is doing, and the safe one:
     *                                 it leaves every stored value alone)
     *   app=Dubai, display unset   -> stored Dubai, shown Dubai, no conversion
     *
     * Setting APP_TIMEZONE is the change to be careful with, and not because of
     * this file: it reinterprets nothing already in the table, so orders written
     * as UTC would start being read as Dubai. That is a migration, not a config
     * change. This class simply does not add a second, contradictory answer.
     */
    public static function storageTimezone(): string
    {
        return self::known((string) config('app.timezone'));
    }

    /**
     * An unknown name would throw deep inside a query build, where the error
     * reads as a database fault. UTC is the status quo, so falling back to it is
     * the change that is invisible rather than the one that 500s.
     */
    private static function known(string $tz): string
    {
        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
    }

    /**
     * Resolve a filter.
     *
     * $earliest/$latest are the stored created_at of the oldest and newest
     * order, used only to give "All time" a chart span. They are ignored for
     * every other period, and null is fine: an empty shop charts today.
     */
    public static function resolve(
        ?string $period,
        ?string $from = null,
        ?string $to = null,
        ?string $earliest = null,
        ?string $latest = null,
    ): self {
        $tz = self::timezone();
        $key = in_array((string) $period, self::PERIODS, true) ? (string) $period : self::DEFAULT_PERIOD;

        $now = CarbonImmutable::now($tz);

        [$start, $end] = match ($key) {
            'today' => [$now->startOfDay(), $now->startOfDay()->addDay()],
            'week' => [
                $now->startOfWeek(self::WEEK_STARTS_ON),
                $now->startOfWeek(self::WEEK_STARTS_ON)->addWeek(),
            ],
            'month' => [$now->startOfMonth(), $now->startOfMonth()->addMonth()],
            'year' => [$now->startOfYear(), $now->startOfYear()->addYear()],
            'custom' => self::customWindow((string) $from, (string) $to, $tz),
            default => [null, null],
        };

        /*
         * The chart's span. For a bounded period it is the period. For all time
         * it is first order -> last order, widened to today so a live shop's
         * chart always runs up to now, and defaulting to today on an empty one.
         */
        if ($start !== null && $end !== null) {
            $chartStart = $start;
            $chartEnd = $end;
        } else {
            // Read on the clock the column keeps, then moved onto the shop's —
            // the same two-step as apply() and bucketKeyForStoredHour(), because
            // an edge read in the wrong tz puts the first bar on the wrong day.
            $storage = self::storageTimezone();

            $first = $earliest !== null
                ? CarbonImmutable::parse($earliest, $storage)->setTimezone($tz)->startOfDay()
                : $now->startOfDay();

            $last = $latest !== null
                ? CarbonImmutable::parse($latest, $storage)->setTimezone($tz)->startOfDay()
                : $now->startOfDay();

            $chartStart = $first->min($now->startOfDay());
            $chartEnd = $last->max($now->startOfDay())->addDay();
        }

        return new self(
            $key,
            $start,
            $end,
            $chartStart,
            $chartEnd,
            self::bucketFor($chartStart, $chartEnd, $key),
            $tz,
        );
    }

    /**
     * A custom range is two DAYS, both of them whole.
     *
     * Handed over backwards, they are swapped rather than refused: "3rd to the
     * 1st" has exactly one sensible reading, and an error message in its place
     * is a worse answer than the obvious one. The validator upstream has already
     * established that both are real Y-m-d dates.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function customWindow(string $from, string $to, string $tz): array
    {
        $a = CarbonImmutable::createFromFormat('Y-m-d', $from, $tz)->startOfDay();
        $b = CarbonImmutable::createFromFormat('Y-m-d', $to, $tz)->startOfDay();

        if ($a->greaterThan($b)) {
            [$a, $b] = [$b, $a];
        }

        // Exclusive end: the day AFTER the last named day, so the last named day
        // is included down to its final instant without naming one.
        return [$a, $b->addDay()];
    }

    /** day / week / month / hour, from the span. See the class docblock. */
    private static function bucketFor(CarbonImmutable $start, CarbonImmutable $end, string $key): string
    {
        $days = max(1, (int) ceil($start->diffInHours($end) / 24));

        if ($days <= 2) {
            // All time is a history view; it never draws hours.
            return $key === 'all' ? 'day' : 'hour';
        }

        if ($days <= 70) {
            return 'day';
        }

        return $days <= 400 ? 'week' : 'month';
    }

    public function key(): string
    {
        return $this->key;
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    /** "one bar is one day" — printed on the chart card. */
    public function bucketLabel(): string
    {
        return match ($this->bucket) {
            'hour' => 'Each bar is one hour',
            'week' => 'Each bar is one week, Monday to Sunday',
            'month' => 'Each bar is one calendar month',
            default => 'Each bar is one day',
        };
    }

    /**
     * Narrow a query to the range.
     *
     * $column is qualified by the caller ('created_at', 'orders.created_at'),
     * because half these queries are joins built from another model and an
     * unqualified name is ambiguous the moment `refunds` is in the FROM list.
     *
     * A range with no bounds — All time — adds nothing at all, rather than a
     * pair of clauses spanning the epoch, so the SQL for the default page is
     * exactly the SQL that shipped before this lane touched it.
     *
     * @template T of BuilderContract
     *
     * @param  T  $query
     * @return T
     */
    public function apply(BuilderContract $query, string $column = 'created_at'): BuilderContract
    {
        /*
         * Moved onto the STORAGE clock, and handed over as a Carbon.
         *
         * setTimezone(storage) and not utc(): the grammar formats a Carbon with
         * that Carbon's OWN timezone, so a boundary must be expressed in the tz
         * the column is keeping or the comparison is off by the offset. Today
         * both are UTC and this is a no-op; it stops being one the moment
         * either knob moves. See storageTimezone().
         *
         * A Carbon and not a formatted string, because a pre-formatted ISO-8601
         * cutoff is the bug in the class docblock: SQLite mis-sorts it and
         * MySQL warns 1292 on it.
         */
        $storage = self::storageTimezone();

        if ($this->startLocal !== null) {
            $query->where($column, '>=', $this->startLocal->setTimezone($storage));
        }

        if ($this->endLocal !== null) {
            $query->where($column, '<', $this->endLocal->setTimezone($storage));
        }

        return $query;
    }

    /**
     * The chart's empty buckets, in order, keyed by bucket key.
     *
     * @return array<string, array{key: string, start: string, label: string, full: string, future: bool}>
     */
    public function buckets(): array
    {
        $now = CarbonImmutable::now($this->timezone);
        $out = [];

        /*
         * Walked from the START OF A BUCKET, not from the start of the range.
         *
         * Walking from the range start and calling addMonth() SKIPS MONTHS: PHP
         * dates overflow rather than clamp, so 31 October + 1 month is 1
         * December and November never gets a bucket at all. Every order placed
         * in November then resolved to a key that had not been generated, and
         * the screen 500'd with "Undefined array key". Found on a preview with
         * four years of real orders in it, because the unit tests had seeded
         * tidy dates that never landed on a 31st.
         *
         * Starting on the bucket boundary makes the walk canonical — every step
         * is the 1st of a month, a Monday, a midnight — so it cannot drift away
         * from what bucketKey() computes for a row.
         */
        for ($at = $this->bucketStart($this->chartStartLocal); $at->lessThan($this->chartEndLocal); $at = $this->advance($at)) {
            $key = $this->bucketKey($at);

            $out[$key] = [
                'key' => $key,
                'start' => $at->format('Y-m-d H:i:s'),
                'label' => $this->axisLabel($at),
                'full' => $this->fullLabel($at),
                // A bucket that has not begun yet. Drawn as "nothing here yet"
                // rather than as a bar of zero, because the back half of an
                // in-progress month rendered as a flat run reads as a collapse
                // in trade — the same "chart implying a trend that is not
                // there" this screen already had to fix once.
                'future' => $at->greaterThan($now),
            ];
        }

        return $out;
    }

    /**
     * Which bucket a stored hour belongs to, or null if it falls outside the
     * charted span.
     *
     * TAKES AN HOUR, NOT A ROW. The controller groups orders by their stored
     * hour in SQL — `substr(created_at, 1, 13)`, portable on both engines, and
     * one statement whatever the order count — and then hands each hour here to
     * be placed on the shop's wall clock.
     *
     * THE ONE THING AN HOURLY GRAIN ASSUMES is that the display timezone is a
     * whole number of hours from the storage one, so that an hour of stored
     * rows can never straddle a bucket boundary. UTC and Asia/Dubai (+04:00, no
     * daylight saving, ever) both are, and so is every pairing this shop will
     * have. A half-hour-offset timezone — Asia/Kolkata, Asia/Tehran — would need
     * the grain in the controller dropped from `1, 13` to the half hour, and
     * nothing else here would change. It is written down because it is the sort
     * of assumption that is invisible until it is wrong by thirty minutes.
     */
    public function bucketKeyForStoredHour(string $hour): ?string
    {
        $at = CarbonImmutable::createFromFormat('Y-m-d H', $hour, self::storageTimezone())
            ->setTimezone($this->timezone);

        // Compared against the first BUCKET, not the first instant of the
        // range: buckets() walks from the bucket boundary, so the opening
        // bucket can begin a little before the range does, and a row in that
        // bucket must not be dropped for sitting inside it.
        if ($at->lessThan($this->bucketStart($this->chartStartLocal)) || $at->greaterThanOrEqualTo($this->chartEndLocal)) {
            return null;
        }

        return $this->bucketKey($at);
    }

    /** The first instant of the bucket an instant falls in. */
    private function bucketStart(CarbonImmutable $at): CarbonImmutable
    {
        return match ($this->bucket) {
            'hour' => $at->startOfHour(),
            'week' => $at->startOfWeek(self::WEEK_STARTS_ON),
            'month' => $at->startOfMonth(),
            default => $at->startOfDay(),
        };
    }

    /**
     * The next bucket along, from a bucket start.
     *
     * startOfMonth() after the addition as well as before it, because a day of
     * the month that does not exist in the next month overflows into the one
     * after — see the comment in buckets(). Cheap, and it makes this correct
     * from any instant rather than only from a canonical one.
     */
    private function advance(CarbonImmutable $at): CarbonImmutable
    {
        return match ($this->bucket) {
            'hour' => $at->addHour(),
            'week' => $at->addWeek(),
            'month' => $at->startOfMonth()->addMonthNoOverflow(),
            default => $at->addDay(),
        };
    }

    /** The bucket an instant falls in, as its stable key. */
    private function bucketKey(CarbonImmutable $at): string
    {
        return match ($this->bucket) {
            'hour' => $at->format('Y-m-d H:00'),
            'week' => $at->startOfWeek(self::WEEK_STARTS_ON)->format('Y-m-d'),
            'month' => $at->format('Y-m-01'),
            default => $at->format('Y-m-d'),
        };
    }

    /** Short enough for an axis on a 390px phone. */
    private function axisLabel(CarbonImmutable $at): string
    {
        return match ($this->bucket) {
            'hour' => $at->format('H:i'),
            'month' => $at->format('M y'),
            default => $at->format('j M'),
        };
    }

    /** The tooltip: unambiguous, even a year later. */
    private function fullLabel(CarbonImmutable $at): string
    {
        return match ($this->bucket) {
            'hour' => $at->format('D j M, H:i') . '-' . $this->advance($at)->format('H:i'),
            'week' => $at->format('j M') . ' - ' . $at->addDays(6)->format('j M Y'),
            'month' => $at->format('F Y'),
            default => $at->format('D j M Y'),
        };
    }

    /** The first and last DAY of the range, as the owner would name them. */
    public function fromDate(): ?string
    {
        return $this->startLocal?->format('Y-m-d');
    }

    public function toDate(): ?string
    {
        // The stored end is exclusive; the owner's "to" is the last real day.
        return $this->endLocal?->subSecond()->format('Y-m-d');
    }

    /**
     * The period in words, for the top of every card.
     *
     * Every figure on the page prints this, so a screenshot of the screen says
     * what it covers without anyone having to remember which button was pressed.
     */
    public function rangeLabel(): string
    {
        if ($this->startLocal === null || $this->endLocal === null) {
            return 'every order the shop has ever taken';
        }

        $from = $this->startLocal;
        $to = $this->endLocal->subDay();

        if ($from->isSameDay($to)) {
            return $from->format('D j M Y');
        }

        if ($from->year === $to->year) {
            return $from->format($from->month === $to->month ? 'j' : 'j M') . ' - ' . $to->format('j M Y');
        }

        return $from->format('j M Y') . ' - ' . $to->format('j M Y');
    }

    /** The control itself, so the screen cannot offer an option the API refuses. */
    public static function options(): array
    {
        return array_map(fn (string $k) => [
            'key' => $k,
            'label' => self::LABELS[$k],
            'hint' => self::HINTS[$k],
        ], self::PERIODS);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => self::LABELS[$this->key],
            'hint' => self::HINTS[$this->key],
            'from' => $this->fromDate(),
            'to' => $this->toDate(),
            'range_label' => $this->rangeLabel(),
            'timezone' => $this->timezone,
            'week_starts' => 'Monday',
            'options' => self::options(),
        ];
    }
}
