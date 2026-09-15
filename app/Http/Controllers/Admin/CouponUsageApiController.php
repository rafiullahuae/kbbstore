<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Support\AggregatesQueries;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store → Coupons: what each code has actually been spent on.
 *
 * Until this package usage_count was decorative — nothing wrote it — so there
 * was nothing to show and no coupon screen in the console at all. Now that a
 * redemption is recorded on every placement, "how many times has GLOW10 been
 * used, and by whom" is a real question with a real answer, and it is the
 * question that tells the owner a code has escaped onto a deal site.
 *
 * READ-ONLY, deliberately. This lane made the counter true; editing coupons is
 * a different job with a different blast radius (changing usage_limit while
 * orders are being placed against it, for one). Nothing here writes.
 *
 * THESE ENDPOINTS ARE NOT PUBLIC AND MUST NOT BECOME PUBLIC.
 * coupon_redemptions.email is a customer's address, and the list below is
 * effectively "every shopper who used this code". /api/* is unauthenticated —
 * CLAUDE.md is emphatic and ApiSecurityTest exists because each case leaked
 * once already — so these are mounted only inside the admin-api group, behind
 * web + auth:admin. routes/coupons-admin.php says so at length; that is where
 * the guard actually lives.
 */
class CouponUsageApiController extends Controller
{
    use AggregatesQueries;

    /** Rows per page on the coupon list. */
    private const PER_PAGE = 25;

    /** Rows per page on one coupon's redemption list. */
    private const REDEMPTIONS_PER_PAGE = 50;

    /**
     * Every coupon, with the counter beside the limit that governs it.
     *
     * Sorted so the codes worth looking at come first: the ones closest to
     * their limit, then the most-used. A code with no limit sorts on usage
     * alone — it cannot be "nearly exhausted", but it can still be the one
     * being given away.
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        $query = Coupon::query()
            ->when($search !== '', function ($q) use ($search) {
                $term = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($search)) . '%';

                $q->whereRaw('LOWER(code) LIKE ?', [$term]);
            });

        // The summary is computed through AggregatesQueries, which strips the
        // row columns, the ORDER BY and the page window from a clone before
        // adding the aggregate. Doing it by hand is MySQL 1140 in strict mode,
        // and a surviving OFFSET makes every total read zero from page two on.
        // That bug shipped here twice; there is one implementation now.
        $summary = $this->aggregate($query, implode(', ', [
            'COUNT(*) AS coupons',
            'COALESCE(SUM(usage_count), 0) AS redemptions',
            'COALESCE(SUM(CASE WHEN usage_limit IS NOT NULL AND usage_count >= usage_limit THEN 1 ELSE 0 END), 0) AS exhausted',
        ]));

        $page = max(1, (int) $request->query('page', 1));

        $coupons = (clone $query)
            ->orderByDesc('usage_count')
            ->orderBy('code')
            ->forPage($page, self::PER_PAGE)
            ->get();

        $total = (int) ($summary->coupons ?? 0);

        return response()->json([
            'ok' => true,
            'summary' => [
                'coupons' => $total,
                'redemptions' => (int) ($summary->redemptions ?? 0),
                'exhausted' => (int) ($summary->exhausted ?? 0),
            ],
            'page' => $page,
            'pages' => (int) max(1, (int) ceil($total / self::PER_PAGE)),
            'coupons' => $coupons->map(fn (Coupon $c) => $this->couponRow($c))->all(),
        ]);
    }

    /** One coupon, and who has redeemed it. */
    public function show(Request $request, int $coupon): JsonResponse
    {
        $model = Coupon::find($coupon);

        if (! $model) {
            return response()->json(['ok' => false, 'error' => 'No such coupon.'], 404);
        }

        $page = max(1, (int) $request->query('page', 1));

        $redemptions = CouponRedemption::query()
            ->where('coupon_id', $model->id)
            ->leftJoin('orders', 'orders.id', '=', 'coupon_redemptions.order_id')
            ->orderByDesc('coupon_redemptions.id')
            ->forPage($page, self::REDEMPTIONS_PER_PAGE)
            ->get([
                'coupon_redemptions.id',
                'coupon_redemptions.email',
                'coupon_redemptions.amount',
                'coupon_redemptions.order_id',
                'coupon_redemptions.customer_id',
                'coupon_redemptions.created_at',
                'orders.order_number',
                'orders.status as order_status',
            ]);

        $rowCount = CouponRedemption::where('coupon_id', $model->id)->count();

        return response()->json([
            'ok' => true,
            'coupon' => $this->couponRow($model),
            'page' => $page,
            'pages' => (int) max(1, (int) ceil($rowCount / self::REDEMPTIONS_PER_PAGE)),
            'redemptions_total' => $rowCount,
            'redemptions' => $redemptions->map(fn ($r) => [
                'id' => (int) $r->id,
                'email' => $r->email,
                // Integer fils, and a display string built from it. Never a
                // float: the whole schema stores money as fils and dividing by
                // 100 in the browser is how AED 1.15 prints as 1.14.
                'amount_fils' => (int) $r->amount,
                'amount_display' => Money::plain((int) $r->amount),
                'order_id' => $r->order_id === null ? null : (int) $r->order_id,
                'order_number' => $r->order_number,
                'order_status' => $r->order_status,
                'customer_id' => $r->customer_id === null ? null : (int) $r->customer_id,
                'redeemed_at' => $r->created_at?->toDateTimeString(),
            ])->all(),
        ]);
    }

    /**
     * One coupon as the screen reads it.
     *
     * `remaining` is the number the owner actually acts on, and it is null
     * rather than a large number when the code is uncapped — "unlimited" and
     * "lots left" are different facts and a screen that prints a figure for
     * both invites the wrong one to be believed.
     */
    private function couponRow(Coupon $coupon): array
    {
        $limit = $coupon->usage_limit === null ? null : (int) $coupon->usage_limit;
        $used = (int) $coupon->usage_count;

        return [
            'id' => (int) $coupon->id,
            'code' => (string) $coupon->code,
            'type' => (string) $coupon->type,
            'amount' => (int) $coupon->amount,
            // A percent coupon stores percent x 100; a fixed one stores fils.
            // Formatting them the same way would print "AED 10.00" for 10% off.
            'amount_display' => $coupon->type === 'percent'
                ? rtrim(rtrim(number_format($coupon->amount / 100, 2), '0'), '.') . '%'
                : Money::plain((int) $coupon->amount),
            'usage_count' => $used,
            'usage_limit' => $limit,
            'usage_limit_per_user' => $coupon->usage_limit_per_user === null
                ? null
                : (int) $coupon->usage_limit_per_user,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'exhausted' => $limit !== null && $used >= $limit,
            'expires_at' => $coupon->expires_at?->toDateTimeString(),
            'starts_at' => $coupon->starts_at?->toDateTimeString(),
        ];
    }
}
