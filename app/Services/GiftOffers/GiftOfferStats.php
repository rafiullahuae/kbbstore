<?php

declare(strict_types=1);

namespace App\Services\GiftOffers;

use App\Support\StoreTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Marketing -> Gift offers -> Report (Lane GO): one row per store day x place
 * x range x event in gift_offer_stats, written by an upsert that adds -- the
 * Free gifts clicks precedent (GiftClicks, gift_clicks). The report reads the
 * period's rows in ONE query and folds them here, so its cost follows the
 * number of days, never the number of emails, claims or orders.
 *
 * Test sends and test claims never reach this table (GiftOffers checks
 * is_test before every bump).
 */
final class GiftOfferStats
{
    /** Each event and its column title on the report, in the report's order. */
    public const EVENTS = [
        'sent' => 'Emails sent with a gift',
        'open' => 'Clock opens',
        'click' => 'Claim clicks',
        'shown' => 'Popup shown',
        'claimed' => 'Popup claimed',
        'dismissed' => 'Popup dismissed',
        'added' => 'Gifts added',
        'orders' => 'Orders with the gift',
        'revenue' => 'Revenue of those orders',
    ];

    public const PLACES = [
        'cart' => 'Abandoned-cart email',
        'reminder_1' => 'Pending reminder 1',
        'reminder_2' => 'Pending reminder 2',
        'popup' => 'Exit popup',
    ];

    public const BY = ['day', 'week', 'month'];

    public const MAX_DAYS = 400;

    /** Add $n to today's count. Never throws: a report must never fail a send or an order. */
    public static function bump(string $place, int $rangeId, string $event, int $n = 1): void
    {
        if (! isset(self::PLACES[$place]) || ! isset(self::EVENTS[$event]) || $n < 1) {
            return;
        }

        try {
            $driver = DB::connection()->getDriverName();
            DB::table('gift_offer_stats')->upsert(
                [['day' => StoreTime::today()->format('Y-m-d'), 'place' => $place, 'range_id' => max(0, $rangeId), 'event' => $event, 'n' => $n]],
                ['day', 'place', 'range_id', 'event'],
                ['n' => DB::raw($driver === 'mysql' ? '`n` + VALUES(`n`)' : '"n" + excluded."n"')],
            );
        } catch (\Throwable) {
        }
    }

    /**
     * The report: totals per event, one bucket per day / week / month (empty
     * ones included), the split per place and per range.
     *
     * @return array<string, mixed>
     */
    public static function report(string $from, string $to, string $by, array $rangeLabels = []): array
    {
        $tz = StoreTime::timezone();
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, $tz) ?: StoreTime::today()->subDays(6);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, $tz) ?: StoreTime::today();

        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        if ($start->diffInDays($end) > self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS);
        }

        $by = in_array($by, self::BY, true) ? $by : 'day';
        $zero = array_fill_keys(array_keys(self::EVENTS), 0);
        $bucketOf = static fn (CarbonImmutable $d): array => match ($by) {
            'week' => [$d->startOfWeek(CarbonImmutable::MONDAY)->format('Y-m-d'), 'Week of ' . $d->startOfWeek(CarbonImmutable::MONDAY)->format('j M')],
            'month' => [$d->format('Y-m'), $d->format('M Y')],
            default => [$d->format('Y-m-d'), $d->format('D j M')],
        };

        $buckets = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            [$k, $label] = $bucketOf($d);
            $buckets[$k] ??= ['key' => $k, 'label' => $label, 'n' => $zero];
        }

        try {
            $rows = DB::table('gift_offer_stats')->whereBetween('day', [$start->format('Y-m-d'), $end->format('Y-m-d')])
                ->get(['day', 'place', 'range_id', 'event', 'n']);
        } catch (\Throwable) {
            $rows = collect();
        }

        $totals = $zero;
        $places = array_map(static fn () => $zero, self::PLACES);
        $ranges = [];

        foreach ($rows as $r) {
            $e = (string) $r->event;

            if (! isset($zero[$e])) {
                continue;
            }

            $n = (int) $r->n;
            [$k] = $bucketOf(CarbonImmutable::createFromFormat('!Y-m-d', substr((string) $r->day, 0, 10), $tz));

            if (isset($buckets[$k])) {
                $buckets[$k]['n'][$e] += $n;
            }

            $totals[$e] += $n;

            if (isset($places[$r->place])) {
                $places[$r->place][$e] += $n;
            }

            $rid = (int) $r->range_id;
            $ranges[$rid] ??= ['id' => $rid, 'label' => $rangeLabels[$rid] ?? ($rid > 0 ? 'Range #' . $rid : '—'), 'n' => $zero];
            $ranges[$rid]['n'][$e] += $n;
        }

        ksort($ranges);

        return [
            'from' => $start->format('Y-m-d'),
            'to' => $end->format('Y-m-d'),
            'by' => $by,
            'events' => self::EVENTS,
            'place_names' => self::PLACES,
            'totals' => $totals,
            'buckets' => array_values($buckets),
            'places' => $places,
            'ranges' => array_values($ranges),
        ];
    }
}
