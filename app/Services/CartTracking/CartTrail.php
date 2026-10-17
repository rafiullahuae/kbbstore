<?php

declare(strict_types=1);

namespace App\Services\CartTracking;

use App\Models\Cart;
use App\Services\Analytics\Tracker;
use App\Services\CartService;
use App\Support\InstantNav;
use App\Support\StoreTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Where a shopper with a basket went, and where they left.        (Lane CJ)
 *
 * The owner, 13 October 2026: "I want to track customer left page tracking.
 * that from where customer left? ... like he proceeded to checkout or not,
 * where he clicked (for cart and checkout page) and more if any. also this
 * functionality i want in my owner app too."
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHO IS RECORDED, AND WHAT IT COSTS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Only a browser that already carries a basket (the kbb_cart cookie, or a
 * signed-in customer's active cart). Everyone else costs nothing: no query,
 * no write, no new request. For a basket-holder:
 *
 *   - a PAGE rides on the page-view beacon every shop page already sends
 *     (/api/viewed): one indexed SELECT for the cart, one INSERT, one UPDATE
 *     of four columns on the cart. A reload of the same page inside 15 s is
 *     not a second row.
 *   - a TAP on the bag panel, the cart page or the checkout comes from the
 *     shop's own bundle (resources/js/kbb/trail.js) in batches through
 *     sendBeacon (/api/cart-trail): the same three queries per batch.
 *
 * WHAT IS NEVER RECORDED: anything typed. A code from CODES and a path; for
 * "Place order stopped", only WHICH fields were missing, from an allowlist.
 * Not recorded for a signed-in administrator (the kbb_ah hint), a prefetch,
 * or a crawler, and not when Cart Tracking or this switch is off.
 *
 * Old rows go with Cart Tracking's own "Keep cart events for" (CartTracking-
 * Prune). Nothing here may throw into the shopper's request.
 */
final class CartTrail
{
    public const PAGE = 1;
    public const LEFT = 2;
    public const BACK = 3;

    /**
     * "This cart became a REAL order" as SQL over a `carts as c` row (Lane CT).
     *
     * ct_order_id is stamped the moment checkout CREATES the order — before
     * Tamara, Tabby or a card has taken a dirham. So "ct_order_id IS NOT NULL"
     * counted an unpaid Tamara order (pending, AED 563, #56251) as "Became
     * orders" and its value in "Bought value" (owner, 17 October: "today paid
     * i have received 6, but u can see it's showing 7"). Became-an-order is
     * now the reporting rule, Order::REAL_STATUSES — the one /admin-api/stats
     * revenue reads — on an order that is not in the bin. pending, failed,
     * cancelled, refunded, draft and trashed orders are not bought.
     *
     * One correlated primary-key probe per cart inside the same statement:
     * no extra query, no per-row PHP. The statuses are constants, never input.
     */
    public static function boughtSql(): string
    {
        $in = implode(', ', array_map(fn (string $s) => "'".$s."'", \App\Models\Order::REAL_STATUSES));

        return '(c.ct_order_id IS NOT NULL AND EXISTS (SELECT 1 FROM orders ct_o WHERE ct_o.id = c.ct_order_id'
            .' AND ct_o.status IN ('.$in.') AND ct_o.deleted_at IS NULL))';
    }

    /** Furthest step reached, as stored in carts.ct_stage. 8 (Ordered) is read from ct_order_id (a REAL order, see boughtSql()). */
    public const STAGES = [
        0 => 'Added to bag',
        2 => 'Viewed the bag',
        3 => 'Opened checkout',
        4 => 'Entered email',
        5 => 'Completed address',
        6 => 'Chose payment',
        7 => 'Pressed Place order',
        8 => 'Ordered',
    ];

    /** code => [what the owner reads, the step it proves, where it happens]. */
    public const CODES = [
        self::PAGE => ['Opened a page', 0, 'page'],
        self::LEFT => ['Closed or left the page', 0, 'left'],
        self::BACK => ['Came back to the page', 0, 'page'],
        10 => ['Opened the bag', 2, 'bag'],
        11 => ['Closed the bag', 0, 'bag'],
        12 => ['Tapped Checkout in the bag', 2, 'bag'],
        13 => ['Tapped View cart in the bag', 2, 'bag'],
        14 => ['Removed an item in the bag', 2, 'bag'],
        15 => ['Changed a quantity in the bag', 2, 'bag'],
        16 => ['Opened a product from the bag', 2, 'bag'],
        17 => ['Looked at Browsed in the bag', 2, 'bag'],
        18 => ['Applied a coupon in the bag', 2, 'bag'],
        20 => ['Tapped Proceed to checkout', 2, 'cart'],
        21 => ['Removed an item on the cart page', 2, 'cart'],
        22 => ['Changed a quantity on the cart page', 2, 'cart'],
        23 => ['Applied a coupon on the cart page', 2, 'cart'],
        24 => ['Tapped Continue shopping', 2, 'cart'],
        30 => ['Entered their email', 4, 'checkout'],
        31 => ['Completed the address', 5, 'checkout'],
        32 => ['Chose Cash on delivery', 6, 'checkout'],
        33 => ['Chose card', 6, 'checkout'],
        34 => ['Chose Tabby', 6, 'checkout'],
        35 => ['Chose Tamara', 6, 'checkout'],
        36 => ['Chose another payment method', 6, 'checkout'],
        37 => ['Opened Sign in', 3, 'checkout'],
        38 => ['Applied a coupon at checkout', 3, 'checkout'],
        39 => ['Pressed Place order', 7, 'checkout'],
        40 => ['Place order stopped: fields to fix', 7, 'checkout'],
        41 => ['Went back to the cart', 3, 'checkout'],
        42 => ['Changed the delivery option', 3, 'checkout'],
        43 => ['Ticked Create an account', 3, 'checkout'],
        44 => ['Opened a policy page', 3, 'checkout'],
    ];

    /** The checkout fields "Place order stopped" may name, and how they read. */
    public const FIELDS = [
        'billing_email' => 'Email', 'billing_phone' => 'Phone', 'billing_first_name' => 'First name',
        'billing_last_name' => 'Last name', 'billing_address_1' => 'Address', 'billing_address_2' => 'Area',
        'billing_city' => 'City', 'billing_state' => 'Emirate', 'billing_country' => 'Country',
        'account_password' => 'Password', 'shipping_method' => 'Delivery', 'payment_method' => 'Payment',
    ];

    /** A reload of the same page inside this many seconds is the same visit. */
    private const SAME_PAGE_SECONDS = 15;

    /** At most this many taps per beacon; a beacon is one page's worth. */
    public const MAX_BATCH = 25;

    /** The journey a cart screen shows. */
    public const JOURNEY_LIMIT = 300;

    public function __construct(private CartService $carts, private CartTrackingSettings $settings) {}

    /** A page a basket-holder opened (from the /api/viewed beacon). */
    public function page(Request $request, string $path): void
    {
        $path = self::path($path);

        if ($path === null || ! $this->allowed($request)) {
            return;
        }

        try {
            $cart = $this->carts->current($request, create: false);

            if ($cart === null) {
                return;
            }

            $stage = str_starts_with($path, '/checkout') ? 3 : (str_starts_with($path, '/cart') ? 2 : 0);
            $same = $cart->ct_seen_path === $path && $cart->ct_seen_at !== null
                && $cart->ct_seen_at->gt(now()->subSeconds(self::SAME_PAGE_SECONDS));

            if (! $same) {
                DB::table('cart_trail')->insert(['cart_id' => (int) $cart->getKey(), 'code' => self::PAGE, 'path' => $path, 'created_at' => now()]);
            }

            $this->summarise($cart, $path, $stage, false);
        } catch (\Throwable) {
            // A page view is never worth an error.
        }
    }

    /**
     * Taps from the bag, the cart page and the checkout (the /api/cart-trail
     * beacon). Unknown codes are dropped, not stored.
     *
     * @param  mixed  $events  [[code, path?, ageMs?], ...] as sent
     */
    public function client(Request $request, mixed $events): int
    {
        if (! is_array($events) || $events === [] || ! $this->allowed($request)) {
            return 0;
        }

        try {
            $cart = $this->carts->current($request, create: false);

            if ($cart === null) {
                return 0;
            }

            $rows = [];
            $stage = 0;
            $left = false;
            $lastPath = null;
            $now = now();

            foreach (array_slice($events, 0, self::MAX_BATCH) as $e) {
                if (! is_array($e)) {
                    continue;
                }

                $code = (int) ($e[0] ?? 0);

                if ($code === self::PAGE || ! isset(self::CODES[$code])) {
                    continue;
                }

                $path = $code === 40 ? self::fields((string) ($e[1] ?? '')) : self::path((string) ($e[1] ?? ''));
                $age = max(0, min(600000, (int) ($e[2] ?? 0)));

                $rows[] = ['cart_id' => (int) $cart->getKey(), 'code' => $code, 'path' => $path, 'created_at' => $now->copy()->subMilliseconds($age)];
                $stage = max($stage, self::CODES[$code][1]);

                if ($code === self::LEFT || $code === self::BACK) {
                    $left = $code === self::LEFT;
                }

                if ($code !== 40 && $path !== null) {
                    $lastPath = $path;
                }
            }

            if ($rows === []) {
                return 0;
            }

            DB::table('cart_trail')->insert($rows);
            $this->summarise($cart, $lastPath, $stage, $left);

            return count($rows);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * One cart's journey, oldest first, for the console and the owner app.
     *
     * @return list<array{at: string|null, code: int, kind: string, title: string, detail: string|null}>
     */
    public static function journey(int $cartId): array
    {
        try {
            $rows = DB::table('cart_trail')->where('cart_id', $cartId)->orderBy('id')->limit(self::JOURNEY_LIMIT)
                ->get(['code', 'path', 'created_at']);
        } catch (\Throwable) {
            return [];
        }

        $names = self::productNames($rows->where('code', self::PAGE)->pluck('path')->all());

        return $rows->map(function ($r) use ($names) {
            $code = (int) $r->code;
            [$title, , $kind] = self::CODES[$code] ?? ['Something happened', 0, 'page'];
            $detail = $r->path;

            if ($code === self::PAGE) {
                $title = self::pageLabel((string) $r->path, $names);
                $detail = (string) $r->path;
            } elseif ($code === self::LEFT) {
                $title = 'Left ' . self::pageLabel((string) $r->path, $names);
            } elseif ($code === self::BACK) {
                $title = 'Came back to ' . self::pageLabel((string) $r->path, $names);
            }

            return ['at' => StoreTime::iso($r->created_at), 'code' => $code, 'kind' => $kind, 'title' => $title, 'detail' => $detail];
        })->values()->all();
    }

    /**
     * The "Left at" line and the furthest step for one Carts row, from the
     * columns already selected for it: no query.
     *
     * @return array{stage: int, stage_label: string, seen: string|null, seen_page: string|null, left: string|null, online: bool}
     */
    public static function summary(object $r, bool $ordered): array
    {
        $stage = $ordered ? 8 : (int) ($r->ct_stage ?? 0);
        $path = $r->ct_seen_path ?? null;
        $seen = $r->ct_seen_at ?? null;
        $left = $r->ct_left_at ?? null;
        $seenTs = $seen !== null ? strtotime((string) $seen) : null;
        $leftTs = $left !== null ? strtotime((string) $left) : null;

        return [
            'stage' => $stage,
            'stage_label' => self::STAGES[$stage] ?? self::STAGES[0],
            'seen' => StoreTime::iso($seen),
            'seen_page' => $path === null ? null : self::pageLabel((string) $path, []),
            'left' => $leftTs !== null && ($seenTs === null || $leftTs >= $seenTs) ? StoreTime::iso($left) : null,
            // Seen in the last 2 minutes and not left since: still on the shop.
            'online' => $seenTs !== null && $seenTs > time() - 120 && ($leftTs === null || $leftTs < $seenTs),
        ];
    }

    /** What the owner reads for a path: "Checkout", "Product: …", "Home". */
    public static function pageLabel(string $path, array $productNames): string
    {
        $p = preg_replace('#^/ar(?=/|$)#', '', $path) ?: '/';
        $ar = $p !== $path ? ' (Arabic)' : '';

        $label = match (true) {
            $p === '/' => 'Home',
            str_starts_with($p, '/checkout/order-received'), str_starts_with($p, '/checkout/thank') => 'Order confirmation',
            str_starts_with($p, '/checkout') => 'Checkout',
            str_starts_with($p, '/cart') => 'Cart page',
            str_starts_with($p, '/product/') => 'Product: ' . ($productNames[trim(substr($p, 9), '/')] ?? self::words(substr($p, 9))),
            str_starts_with($p, '/product-category/') => 'Category: ' . self::words(basename(rtrim($p, '/'))),
            str_starts_with($p, '/brand') => 'Brand: ' . self::words(basename(rtrim($p, '/'))),
            str_starts_with($p, '/shop') => 'Shop',
            str_starts_with($p, '/my-account') => 'My account',
            str_starts_with($p, '/track-my-order') => 'Track my order',
            default => 'Page: ' . $p,
        };

        return $label . $ar;
    }

    /**
     * Checkout funnel for a set of carts: for each step, how many of them
     * really DID it (recorded in their journey), not "got at least this far"
     * -- a shopper who pressed Place order with no address did not complete
     * the address. Two reads: the carts' totals, and one grouped read of their
     * journeys. Carts from before the journey existed count only in the first
     * and last steps.
     *
     * @return list<array{stage: int, label: string, carts: int}>
     */
    public static function funnel(\Illuminate\Database\Query\Builder $carts): array
    {
        try {
            $t = (clone $carts)->selectRaw('COUNT(*) AS n, SUM(CASE WHEN '.self::boughtSql().' THEN 1 ELSE 0 END) AS o')->first();
            $ids = (clone $carts)->select('c.id');

            $step = 'CASE'
                .' WHEN t.code IN (39, 40) THEN 7'
                .' WHEN t.code BETWEEN 32 AND 36 THEN 6'
                .' WHEN t.code = 31 THEN 5'
                .' WHEN t.code = 30 THEN 4'
                ." WHEN (t.code IN (1, 2, 3) AND t.path LIKE '/checkout%') OR t.code IN (37, 38, 41, 42, 43, 44) THEN 3"
                ." WHEN (t.code IN (1, 2, 3) AND t.path LIKE '/cart%') OR t.code BETWEEN 10 AND 24 THEN 2"
                .' ELSE 0 END';

            $rows = DB::table('cart_trail as t')->whereIn('t.cart_id', $ids)
                ->selectRaw($step.' AS s, COUNT(DISTINCT t.cart_id) AS n')
                ->groupBy(DB::raw($step))->get();
        } catch (\Throwable) {
            return [];
        }

        $did = [0 => (int) ($t->n ?? 0), 8 => (int) ($t->o ?? 0)];

        foreach ($rows as $r) {
            if ((int) $r->s > 0) {
                $did[(int) $r->s] = (int) $r->n;
            }
        }

        return array_map(fn (int $s) => ['stage' => $s, 'label' => self::STAGES[$s], 'carts' => $did[$s] ?? 0], array_keys(self::STAGES));
    }

    /* ------------------------------------------------------------ internals */

    private function allowed(Request $request): bool
    {
        if (InstantNav::isSpeculative($request) || $request->cookies->has('kbb_ah')) {
            return false;
        }

        $ua = (string) $request->userAgent();

        if ($ua === '' || preg_match(Tracker::BOT_RE, $ua) === 1) {
            return false;
        }

        return (bool) $this->settings->get('track') && (bool) $this->settings->get('journey');
    }

    /** One UPDATE: last page and when, the furthest step, and the leave. */
    private function summarise(Cart $cart, ?string $path, int $stage, bool $left): void
    {
        $set = ['ct_seen_at' => now()];

        if ($path !== null) {
            $set['ct_seen_path'] = $path;
        }

        if ($stage > (int) $cart->ct_stage) {
            $set['ct_stage'] = $stage;
        }

        if ($left) {
            $set['ct_left_at'] = now();
        }

        DB::table('carts')->where('id', $cart->getKey())->update($set);
        $cart->forceFill($set);
        $cart->syncOriginalAttributes(array_keys($set));
    }

    /** A same-site path, query and fragment removed, capped. Null for anything else. */
    public static function path(string $path): ?string
    {
        $path = trim($path);

        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
            return null;
        }

        $path = (string) strtok($path, '?#');
        $base = rtrim((string) config('kbb.base_path', ''), '/');

        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }

        $path = preg_replace('/[^\x21-\x7E]/', '', $path) ?? '';

        return $path === '' ? null : mb_substr($path, 0, 160);
    }

    /** "billing_email,billing_phone" -> "Email, Phone": allowlisted names only. */
    private static function fields(string $list): ?string
    {
        $out = [];

        foreach (explode(',', $list) as $name) {
            $name = trim($name);

            if (isset(self::FIELDS[$name]) && ! in_array(self::FIELDS[$name], $out, true)) {
                $out[] = self::FIELDS[$name];
            }
        }

        return $out === [] ? null : implode(', ', $out);
    }

    /** "glow-serum-propolis" -> "Glow serum propolis". */
    private static function words(string $slug): string
    {
        $w = trim(str_replace(['-', '_'], ' ', urldecode(trim($slug, '/'))));

        return $w === '' ? '' : mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1);
    }

    /** Product names for the product pages in a journey, in one query. */
    private static function productNames(array $paths): array
    {
        $slugs = [];

        foreach ($paths as $p) {
            $p = preg_replace('#^/ar(?=/)#', '', (string) $p) ?? '';

            if (str_starts_with($p, '/product/')) {
                $slugs[] = trim(substr($p, 9), '/');
            }
        }

        $slugs = array_values(array_unique(array_filter($slugs)));

        if ($slugs === []) {
            return [];
        }

        try {
            return DB::table('products')->whereIn('slug', array_slice($slugs, 0, 200))->pluck('name', 'slug')->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
