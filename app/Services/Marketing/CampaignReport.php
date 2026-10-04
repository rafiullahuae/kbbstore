<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Models\Order;
use App\Support\Money;
use App\Support\StoreTime;
use Illuminate\Support\Facades\DB;

/**
 * Marketing Emails → Reports (m5) and the Campaigns tiles (m1) — Lane MK.
 *
 * THE HONEST NUMBERS ONLY (the owner's D10). There is no open-tracking pixel
 * anywhere in a campaign, so there is no open rate: Apple Mail pre-loads
 * every image and would report everything opened. What is counted:
 *
 *   sent          accepted by the receiving mail server
 *   failed        refused by it (the transport's own words are kept)
 *   skipped       unsubscribed / bounced after the list was written, or
 *                 cancelled before sending
 *   clicks        clicks on a tracked link; the rate is people who clicked
 *                 at least once over messages sent
 *   unsubscribes  one-click or the page
 *   orders        PAID orders (Order::REAL_STATUSES) by the same address
 *                 within 7 days after that person's first click on THIS
 *                 campaign — and the revenue of those orders
 *
 * Attribution is computed in two bounded queries per campaign (the clicked
 * sends, then their orders in slices of 500 addresses) and the window is
 * compared in PHP, so the same code is right on SQLite and MySQL without
 * date arithmetic in SQL.
 */
final class CampaignReport
{
    public const WINDOW_DAYS = 7;

    /**
     * Paid orders within WINDOW_DAYS of a first click, for these sends.
     *
     * @param  iterable<object{email:string, first_click_at:string}>  $clicked
     * @return array{orders:int, revenue_fils:int}
     */
    public function attribute(iterable $clicked): array
    {
        $first = [];

        foreach ($clicked as $s) {
            $email = mb_strtolower(trim((string) $s->email));
            $at = strtotime((string) $s->first_click_at);

            if ($email !== '' && $at !== false && (! isset($first[$email]) || $at < $first[$email])) {
                $first[$email] = $at;
            }
        }

        if ($first === []) {
            return ['orders' => 0, 'revenue_fils' => 0];
        }

        $orders = 0;
        $revenue = 0;
        $seen = [];

        foreach (array_chunk(array_keys($first), 500) as $chunk) {
            $rows = DB::table('orders')
                ->whereIn('status', Order::REAL_STATUSES)
                ->whereNull('deleted_at')
                ->whereIn(DB::raw('LOWER(TRIM(email))'), $chunk)
                ->where('created_at', '>=', date('Y-m-d H:i:s', min(array_intersect_key($first, array_flip($chunk)))))
                ->get(['id', 'email', 'total', 'created_at']);

            foreach ($rows as $o) {
                $email = mb_strtolower(trim((string) $o->email));
                $at = strtotime((string) $o->created_at);
                $click = $first[$email] ?? null;

                if ($click === null || $at === false || isset($seen[$o->id])) {
                    continue;
                }

                if ($at >= $click && $at <= $click + self::WINDOW_DAYS * 86400) {
                    $seen[$o->id] = true;
                    $orders++;
                    $revenue += (int) $o->total;
                }
            }
        }

        return ['orders' => $orders, 'revenue_fils' => $revenue];
    }

    /** One campaign's report. @return array<string, mixed>|null */
    public function campaign(int $id): ?array
    {
        $c = DB::table('mkt_campaigns')->where('id', $id)->first();

        if ($c === null) {
            return null;
        }

        $counts = DB::table('mkt_sends')->where('campaign_id', $id)->groupBy('status')
            ->selectRaw('status, COUNT(*) as n')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $sent = $counts['sent'] ?? 0;

        $clickers = (int) DB::table('mkt_sends')->where('campaign_id', $id)->whereNotNull('first_click_at')->count();
        $clicks = (int) DB::table('mkt_clicks as k')->join('mkt_sends as s', 's.id', '=', 'k.send_id')->where('s.campaign_id', $id)->count();
        $unsubs = (int) DB::table('mkt_sends')->where('campaign_id', $id)->whereNotNull('unsubscribed_at')->count();
        $complaints = (int) DB::table('email_suppressions')->where('reason', 'complaint')->where('source', 'campaign:' . $id)->count();

        $attr = $this->attribute(DB::table('mkt_sends')->where('campaign_id', $id)->whereNotNull('first_click_at')
            ->get(['email', 'first_click_at']));

        // Keep the table's counters in step with what the report just read.
        DB::table('mkt_campaigns')->where('id', $id)->update([
            'clicks' => $clicks, 'unsubscribes' => $unsubs, 'orders' => $attr['orders'], 'revenue_fils' => $attr['revenue_fils'],
        ]);

        $links = DB::table('mkt_links as l')
            ->leftJoin('mkt_clicks as k', 'k.link_id', '=', 'l.id')
            ->where('l.campaign_id', $id)
            ->groupBy('l.id', 'l.n', 'l.label', 'l.url')
            ->selectRaw('l.n as n, l.label as label, l.url as url, COUNT(k.id) as clicks')
            ->orderByDesc('clicks')->orderBy('l.n')->orderBy('l.id')
            ->limit(20)->get()
            ->map(fn ($r) => ['n' => (int) $r->n, 'label' => (string) ($r->label ?: $r->url), 'url' => (string) $r->url, 'clicks' => (int) $r->clicks])
            ->all();

        $failures = DB::table('mkt_sends')->where('campaign_id', $id)->whereIn('status', ['failed', 'skipped'])
            ->orderBy('id')->limit(50)->get(['email', 'status', 'error'])
            ->map(fn ($r) => ['email' => (string) $r->email, 'status' => (string) $r->status, 'error' => (string) preg_replace('/^HARD /', '', (string) $r->error)])
            ->all();

        $snap = json_decode((string) $c->rules_snapshot, true) ?: [];

        return [
            'id' => (int) $c->id,
            'name' => (string) $c->name,
            'subject' => (string) $c->subject,
            'status' => (string) $c->status,
            'group' => (string) ($snap['segment'] ?? ''),
            'audience' => (string) ($snap['audience'] ?? $c->audience),
            'started_at' => StoreTime::iso($c->started_at),
            'finished_at' => StoreTime::iso($c->finished_at),
            'recipients' => array_sum($counts),
            'sent' => $sent,
            'failed' => $counts['failed'] ?? 0,
            'skipped' => $counts['skipped'] ?? 0,
            'pending' => ($counts['pending'] ?? 0) + ($counts['claimed'] ?? 0),
            'clickers' => $clickers,
            'clicks' => $clicks,
            'click_rate' => $sent > 0 ? round($clickers * 100 / $sent, 1) : 0.0,
            'unsubscribes' => $unsubs,
            'complaints' => $complaints,
            'orders' => $attr['orders'],
            'revenue_fils' => $attr['revenue_fils'],
            'revenue' => Money::plain($attr['revenue_fils']),
            'links' => $links,
            'failures' => $failures,
        ];
    }

    /**
     * The four Campaigns tiles.
     *
     * @return array<string, mixed>
     */
    public function tiles(Audience $audience): array
    {
        $totals = $audience->totals();
        $monthStart = StoreTime::startOfDayUtc(StoreTime::today()->startOfMonth());
        $since = now()->subDays(30);

        $sentMonth = (int) DB::table('mkt_sends')->where('status', 'sent')->where('sent_at', '>=', $monthStart)->count();
        $sent30 = (int) DB::table('mkt_sends')->where('status', 'sent')->where('sent_at', '>=', $since)->count();
        $clicked30 = (int) DB::table('mkt_sends')->where('status', 'sent')->where('sent_at', '>=', $since)->whereNotNull('first_click_at')->count();

        $attr = $this->attribute(DB::table('mkt_sends')->whereNotNull('first_click_at')
            ->where('first_click_at', '>=', now()->subDays(30 + self::WINDOW_DAYS))->get(['email', 'first_click_at']));

        return [
            'can_email' => $totals['customers_ok'] + $totals['subscribers_ok'],
            'customers' => $totals['customers'],
            'customers_ok' => $totals['customers_ok'],
            'subscribers' => $totals['subscribers'],
            'subscribers_ok' => $totals['subscribers_ok'],
            'sent_month' => $sentMonth,
            'click_rate_30' => $sent30 > 0 ? round($clicked30 * 100 / $sent30, 1) : null,
            'orders_30' => $attr['orders'],
            'revenue_30' => Money::plain($attr['revenue_fils']),
        ];
    }
}
