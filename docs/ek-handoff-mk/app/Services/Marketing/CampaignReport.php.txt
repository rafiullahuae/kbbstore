<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Models\Campaign;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The numbers on Email Marketing → Reports (m5) and on the Campaigns list (m1)
 * — Lane EK.
 *
 * "Accepted by mail servers" is what the shop can honestly know: the mail
 * server took the message (a recipient row in `sent`). Whether it reached the
 * inbox or spam is decided later by the receiver, and the screen says so.
 * Opens are shown only as "at least N" — Apple Mail privacy protection loads
 * every picture itself, so the pixel over-counts and under-counts at once.
 * Clicks and orders are real: a click went through the shop's redirect, and an
 * order is a real order (Order::REAL_STATUSES) placed by a recipient's address
 * within seven days of their message.
 *
 * A fixed number of grouped queries per campaign — never one per recipient.
 */
final class CampaignReport
{
    public const ORDER_WINDOW_DAYS = 7;

    /** @return array<string, mixed> */
    public function for(Campaign $campaign): array
    {
        $counts = DB::table('campaign_recipients')->where('campaign_id', $campaign->id)
            ->selectRaw("SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,"
                . " SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,"
                . " SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) AS skipped,"
                . " SUM(CASE WHEN status IN ('queued', 'sending') THEN 1 ELSE 0 END) AS waiting,"
                . ' SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,'
                . ' SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,'
                . ' SUM(CASE WHEN unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) AS unsubscribed,'
                . ' MIN(sent_at) AS first_at, MAX(sent_at) AS last_at')
            ->first();

        $sent = (int) ($counts->sent ?? 0);
        $clicked = (int) ($counts->clicked ?? 0);
        [$orders, $revenue] = $this->orders($campaign);

        $links = is_array($campaign->links) ? $campaign->links : [];
        $byLink = DB::table('campaign_clicks')->where('campaign_id', $campaign->id)
            ->groupBy('link')->selectRaw('link, COUNT(*) AS n')->orderByDesc('n')->limit(10)->pluck('n', 'link')->all();
        $top = [];

        foreach ($byLink as $i => $n) {
            if (isset($links[$i])) {
                $top[] = ['label' => (string) ($links[$i]['label'] ?? ''), 'url' => (string) ($links[$i]['url'] ?? ''), 'clicks' => (int) $n];
            }
        }

        return [
            'sent' => $sent,
            'failed' => (int) ($counts->failed ?? 0),
            'skipped' => (int) ($counts->skipped ?? 0),
            'waiting' => (int) ($counts->waiting ?? 0),
            'opened_at_least' => (int) ($counts->opened ?? 0),
            'clicked' => $clicked,
            'click_rate' => $sent > 0 ? round($clicked * 100 / $sent, 1) : 0.0,
            'orders' => $orders,
            'revenue' => Money::plain($revenue),
            'revenue_fils' => $revenue,
            'unsubscribed' => (int) ($counts->unsubscribed ?? 0),
            'first_at' => $counts->first_at ? Carbon::parse((string) $counts->first_at)->toIso8601String() : null,
            'last_at' => $counts->last_at ? Carbon::parse((string) $counts->last_at)->toIso8601String() : null,
            'top_links' => $top,
        ];
    }

    /**
     * The Campaigns tab's four figures: who can be emailed, sent this month,
     * click rate and orders over the last 30 days.
     */
    public function overview(CampaignAudience $audience): array
    {
        $totals = $audience->totals();
        $monthStart = now()->startOfMonth()->toDateTimeString();
        $since = now()->subDays(30)->toDateTimeString();

        $recent = DB::table('campaign_recipients')->where('sent_at', '>=', $since)
            ->selectRaw('COUNT(*) AS sent, SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked')->first();
        $sent30 = (int) ($recent->sent ?? 0);
        $revenue = $this->revenueSince(now()->subDays(30));

        return $totals + [
            'sent_month' => DB::table('campaign_recipients')->where('sent_at', '>=', $monthStart)->count(),
            'click_rate_30' => $sent30 > 0 ? round(((int) $recent->clicked) * 100 / $sent30, 1) : 0.0,
            'revenue_30' => Money::plain($revenue),
        ];
    }

    /**
     * Fils of real orders placed since $since by an address a campaign reached
     * in the seven days before the order. Two queries in all, however many
     * campaigns there were.
     */
    private function revenueSince(\Illuminate\Support\Carbon $since): int
    {
        $sends = [];

        foreach (DB::table('campaign_recipients')->where('status', 'sent')
            ->where('sent_at', '>=', $since->copy()->subDays(self::ORDER_WINDOW_DAYS)->toDateTimeString())
            ->get(['email', 'sent_at']) as $r) {
            $sends[(string) $r->email][] = Carbon::parse((string) $r->sent_at);
        }

        if ($sends === []) {
            return 0;
        }

        $fils = 0;

        foreach (DB::table('orders')->whereIn('status', Order::REAL_STATUSES)->where('created_at', '>=', $since->toDateTimeString())
            ->get(['email', 'total', 'created_at']) as $o) {
            $placed = Carbon::parse((string) $o->created_at);

            foreach ($sends[mb_strtolower(trim((string) $o->email))] ?? [] as $sent) {
                if ($placed->gte($sent) && $placed->lt($sent->copy()->addDays(self::ORDER_WINDOW_DAYS))) {
                    $fils += (int) $o->total;
                    break;
                }
            }
        }

        return $fils;
    }

    /**
     * The Campaigns list's columns for many campaigns at once: click rate and
     * orders, in three queries whatever the number of campaigns.
     *
     * @param  list<Campaign>  $campaigns
     * @return array<int, array{click_rate:float, orders:int, revenue:string}>
     */
    public function summaries(array $campaigns): array
    {
        $ids = array_map(static fn (Campaign $c) => (int) $c->id, array_filter($campaigns, static fn (Campaign $c) => $c->started_at !== null));

        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('campaign_recipients')->whereIn('campaign_id', $ids)->groupBy('campaign_id')
            ->selectRaw("campaign_id, SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent, SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked")
            ->get() as $r) {
            $sent = (int) $r->sent;
            $out[(int) $r->campaign_id] = ['click_rate' => $sent > 0 ? round(((int) $r->clicked) * 100 / $sent, 1) : 0.0, 'orders' => 0, 'revenue_fils' => 0];
        }

        $sends = [];
        $earliest = null;

        foreach (DB::table('campaign_recipients')->whereIn('campaign_id', $ids)->where('status', 'sent')->get(['campaign_id', 'email', 'sent_at']) as $r) {
            $at = Carbon::parse((string) $r->sent_at);
            $sends[(string) $r->email][] = [(int) $r->campaign_id, $at];
            $earliest = $earliest === null || $at->lt($earliest) ? $at : $earliest;
        }

        if ($earliest !== null) {
            foreach (DB::table('orders')->whereIn('status', Order::REAL_STATUSES)->where('created_at', '>=', $earliest->toDateTimeString())
                ->get(['email', 'total', 'created_at']) as $o) {
                $placed = Carbon::parse((string) $o->created_at);

                foreach ($sends[mb_strtolower(trim((string) $o->email))] ?? [] as [$cid, $sent]) {
                    if ($placed->gte($sent) && $placed->lt($sent->copy()->addDays(self::ORDER_WINDOW_DAYS))) {
                        $out[$cid]['orders']++;
                        $out[$cid]['revenue_fils'] += (int) $o->total;
                    }
                }
            }
        }

        foreach ($out as $cid => $row) {
            $out[$cid]['revenue'] = Money::plain($row['revenue_fils']);
        }

        return $out;
    }

    /** [orders, fils] placed by recipients within the window after their own message. */
    public function orders(Campaign $campaign): array
    {
        if ($campaign->started_at === null) {
            return [0, 0];
        }

        $sentAt = DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->where('status', 'sent')
            ->pluck('sent_at', 'email')->all();

        if ($sentAt === []) {
            return [0, 0];
        }

        $until = ($campaign->finished_at ?? now())->copy()->addDays(self::ORDER_WINDOW_DAYS);
        $n = 0;
        $fils = 0;

        // The orders in the campaign's window, ONE query, matched to recipients here.
        foreach (DB::table('orders')->whereIn('status', Order::REAL_STATUSES)
            ->where('created_at', '>=', $campaign->started_at->toDateTimeString())
            ->where('created_at', '<', $until->toDateTimeString())
            ->get(['email', 'total', 'created_at']) as $o) {
            $email = mb_strtolower(trim((string) $o->email));
            $at = $sentAt[$email] ?? null;

            if ($at === null) {
                continue;
            }

            $placed = Carbon::parse((string) $o->created_at);
            $sent = Carbon::parse((string) $at);

            if ($placed->gte($sent) && $placed->lt($sent->copy()->addDays(self::ORDER_WINDOW_DAYS))) {
                $n++;
                $fils += (int) $o->total;
            }
        }

        return [$n, $fils];
    }
}
