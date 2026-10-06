<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Models\Order;
use App\Models\Product;
use App\Services\SiteAppPush;
use App\Support\Money;
use App\Support\ProductVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The four messages nobody has to press "send" for (Lane PN), each with its
 * own switch on Growth & Marketing → Push Notifications → Automations, all ON
 * because the owner picked all four:
 *
 *   order updates   automatic, the same statuses the order emails use and the
 *                   same gate (the operator's "notify the customer" choice,
 *                   the import's silence): OrderMailer::statusChanged() calls
 *                   orderStatus() after its own checks. Only to the phones of
 *                   that order's shopper or the phone that placed it. Exempt
 *                   from the cap and quiet hours.
 *
 *   back in stock   the owner: "they will receive notification only once if
 *                   in last sessions customer visit that out of stock
 *                   product". ONLY phones with a site_app_push_interests row
 *                   for the product (it was viewed while sold out), ONCE EVER
 *                   per (phone, product): the unique dedupe s:{product}:{sub}
 *                   AND notified_at, set when it is queued.
 *
 *   basket          ONE reminder per cart, after the delay (default 3 hours),
 *                   only for a phone whose cart (or signed-in shopper's cart)
 *                   is still active with something in it and no order since.
 *
 *   price drop      only products the phone viewed sold out or hearted, a
 *                   drop of at least the threshold (default 10 %) from the
 *                   highest price seen since its last price message, at most
 *                   once per product per phone in 30 days, and only while the
 *                   product can be bought.
 *
 * The three marketing ones are queued, then sent by PushSender::runQueue()
 * under the cap and outside quiet hours. Every sweep is one or two set-based
 * statements when there is nothing to do.
 *
 * WORDING is the shop's own translation mechanism: InterfaceStrings keys
 * store.push.* (English in code, editable as an 'en' row) with Arabic drafts,
 * rendered in each phone's language at queue time.
 */
final class PushAutomations
{
    /** How recently a sold-out visit or a heart still counts as interest. */
    public const INTEREST_DAYS = 90;

    /** A basket older than the delay plus this is history, not a reminder. */
    public const CART_WINDOW_HOURS = 48;

    public const PRICE_COOLDOWN_DAYS = 30;

    /** Price sweep at most this often (it loads products; the others are one statement). */
    public const PRICE_EVERY_MINUTES = 30;

    public const PRICE_MARKER = 'framework/kbb-push-price.tick';

    public function __construct(private PushRules $rules, private PushSender $sender) {}

    /** InterfaceStrings key for one template. */
    public static function key(string $name): string
    {
        return 'store.push.'.$name;
    }

    private static function say(string $name, array $vars, string $locale): string
    {
        return trim((string) __(self::key($name), $vars, $locale));
    }

    /* -------------------------------------------------------- order updates */

    /**
     * An order's status changed and the shopper is owed a message about it
     * (OrderMailer::statusChanged(), after its gates). Queue one push per
     * phone and deliver them right after this response.
     */
    public function orderStatus(Order $order, string $status): int
    {
        if (! $this->rules->get('order_on') || ! in_array($status, (array) $this->rules->get('order_statuses'), true)) {
            return 0;
        }
        $orderId = (int) $order->getKey();
        $subs = DB::table('site_app_push_subscriptions as s')->where('s.status', 'active')
            ->where(function ($q) use ($order, $orderId) {
                $q->whereIn('s.id', DB::table('site_app_push_orders')->where('order_id', $orderId)->select('subscription_id'));
                if ($order->customer_id) {
                    $q->orWhere('s.customer_id', (int) $order->customer_id);
                }
            })
            ->get(['s.id', 's.locale', 's.customer_id', 's.region', 's.city', 's.country']);
        if ($subs->isEmpty()) {
            return 0;
        }

        $number = (string) $order->order_number;
        $rows = [];
        foreach ($subs as $s) {
            $locale = (string) $s->locale;
            $rows[] = [
                'kind' => 'order', 'ref' => $orderId, 'subscription_id' => (int) $s->id,
                'emirate' => PushAudience::emirateOf($s->region, $s->city, $s->country),
                'title' => mb_substr(self::say('order_'.$status.'_title', ['order' => $number], $locale), 0, 80),
                'body' => mb_substr(self::say('order_'.$status.'_body', ['order' => $number], $locale), 0, 200),
                // Their own order page when signed in on that phone; else the tracking page.
                'url' => $s->customer_id && (int) $s->customer_id === (int) $order->customer_id ? '/my-account/orders/'.$orderId : '/track-my-order',
                'dedupe' => 'o:'.$orderId.':'.$status.':'.$s->id,
            ];
        }
        $ids = $this->sender->queue($rows);

        if ($ids !== []) {
            $sender = $this->sender;
            $run = static function () use ($sender, $ids): void {
                try {
                    $sender->deliver($ids);
                } catch (\Throwable) {
                    // Still queued: the next kbb:push-step sends it.
                }
            };
            // After the response the operator is waiting for, never inside it.
            app()->runningInConsole() ? $run() : app()->terminating($run);
        }

        return count($ids);
    }

    /* ------------------------------------------------- the phone's own links */

    /** An order placed from a subscribed phone: remember which phone (guests have no customer id). */
    public static function linkOrder(int $orderId, string $cookieHash): void
    {
        $sid = DB::table(SiteAppPush::TABLE)->where('cookie_hash', $cookieHash)->value('id');
        if ($sid !== null) {
            DB::table('site_app_push_orders')->insertOrIgnore(['order_id' => $orderId, 'subscription_id' => (int) $sid, 'created_at' => now()]);
        }
    }

    /** A cart created on a request from a subscribed phone: the basket reminder's device. */
    public static function linkCart(int $cartId): void
    {
        $token = app()->bound('request') ? SiteAppPush::token(request()->cookie(SiteAppPush::COOKIE)) : null;
        if ($token === null) {
            return;
        }
        try {
            DB::table(SiteAppPush::TABLE)->where('cookie_hash', hash('sha256', $token))->update(['cart_id' => $cartId]);
        } catch (\Throwable) {
        }
    }

    /** A heart pressed (or released) on a subscribed phone: price-drop's other watch list. */
    public static function wished(int $productId, bool $on): void
    {
        $token = app()->bound('request') ? SiteAppPush::token(request()->cookie(SiteAppPush::COOKIE)) : null;
        if ($token === null || $productId < 1) {
            return;
        }
        try {
            $sid = DB::table(SiteAppPush::TABLE)->where('cookie_hash', hash('sha256', $token))->where('status', 'active')->value('id');
            if ($sid === null) {
                return;
            }
            $on
                ? DB::table('site_app_push_wishes')->upsert([['subscription_id' => (int) $sid, 'product_id' => $productId, 'wished_at' => now()]], ['subscription_id', 'product_id'], ['wished_at'])
                : DB::table('site_app_push_wishes')->where('subscription_id', $sid)->where('product_id', $productId)->delete();
        } catch (\Throwable) {
        }
    }

    /* ------------------------------------------------------- back in stock */

    /** Products that can be bought now: in stock (a variable one: some variant is) and visible. */
    private function buyable($q)
    {
        return $q->where('products.stock_status', 'instock')
            ->where(fn ($v) => $v->whereNotExists(fn ($e) => $e->select(DB::raw(1))->from('product_variants')->whereColumn('product_variants.product_id', 'products.id'))
                ->orWhereExists(fn ($e) => $e->select(DB::raw(1))->from('product_variants')->whereColumn('product_variants.product_id', 'products.id')->where('product_variants.stock_status', 'instock')))
            ->where(fn ($v) => ProductVisibility::raw($v, 'products'));
    }

    public function stock(int $limit = 300): int
    {
        if (! $this->rules->get('stock_on')) {
            return 0;
        }
        $due = DB::table('site_app_push_interests as i')
            ->join('site_app_push_subscriptions as s', 's.id', '=', 'i.subscription_id')
            ->join('products', 'products.id', '=', 'i.product_id')
            ->whereNull('i.notified_at')->where('s.status', 'active')
            ->where('i.viewed_at', '>=', now()->subDays(self::INTEREST_DAYS))
            ->where(fn ($q) => $this->buyable($q))
            ->orderBy('i.id')->limit($limit)
            ->get(['i.id', 'i.product_id', 'i.subscription_id', 's.locale', 's.region', 's.city', 's.country', 'products.name', 'products.slug']);
        if ($due->isEmpty()) {
            return 0;
        }

        $rows = [];
        foreach ($due as $d) {
            $rows[] = [
                'kind' => 'stock', 'ref' => (int) $d->product_id, 'subscription_id' => (int) $d->subscription_id,
                'emirate' => PushAudience::emirateOf($d->region, $d->city, $d->country),
                'title' => mb_substr(self::say('stock_title', ['product' => $d->name], (string) $d->locale), 0, 80),
                'body' => mb_substr(self::say('stock_body', ['product' => $d->name], (string) $d->locale), 0, 200),
                'url' => '/product/'.$d->slug.'/',
                'dedupe' => 's:'.$d->product_id.':'.$d->subscription_id,
            ];
        }
        // Once EVER: the dedupe refuses a second row, and notified_at takes the
        // interest out of every later sweep.
        DB::table('site_app_push_interests')->whereIn('id', $due->pluck('id')->all())->whereNull('notified_at')->update(['notified_at' => now()]);

        return count($this->sender->queue($rows));
    }

    /* -------------------------------------------------------------- basket */

    public function cart(int $limit = 300): int
    {
        if (! $this->rules->get('cart_on')) {
            return 0;
        }
        $hours = (float) $this->rules->get('cart_hours');
        $now = CarbonImmutable::now('UTC');
        $latest = $now->subMinutes((int) round($hours * 60));
        $earliest = $latest->subHours(self::CART_WINDOW_HOURS);

        $due = DB::table('carts as c')
            ->join('site_app_push_subscriptions as s', function ($j) {
                $j->on('s.cart_id', '=', 'c.id')->orOn(fn ($o) => $o->on('s.customer_id', '=', 'c.customer_id')->whereNotNull('c.customer_id'));
            })
            ->where('s.status', 'active')->where('c.status', 'active')
            ->whereBetween('c.last_activity_at', [$earliest, $latest])
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('cart_items')->whereColumn('cart_items.cart_id', 'c.id'))
            // No order since the basket was last touched: not by its shopper, not from that phone.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('orders')->whereColumn('orders.customer_id', 'c.customer_id')->whereColumn('orders.created_at', '>=', 'c.last_activity_at'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('site_app_push_orders as po')->whereColumn('po.subscription_id', 's.id')->whereColumn('po.created_at', '>=', 'c.last_activity_at'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('push_sends as ps')->where('ps.kind', 'cart')->whereColumn('ps.ref', 'c.id')->whereColumn('ps.subscription_id', 's.id'))
            ->orderBy('c.id')->limit($limit)
            ->get(['c.id as cart_id', 's.id as sid', 's.locale', 's.region', 's.city', 's.country']);
        if ($due->isEmpty()) {
            return 0;
        }

        $rows = [];
        foreach ($due as $d) {
            $rows[] = [
                'kind' => 'cart', 'ref' => (int) $d->cart_id, 'subscription_id' => (int) $d->sid,
                'emirate' => PushAudience::emirateOf($d->region, $d->city, $d->country),
                'title' => mb_substr(self::say('cart_title', [], (string) $d->locale), 0, 80),
                'body' => mb_substr(self::say('cart_body', [], (string) $d->locale), 0, 200),
                'url' => '/cart',
                'dedupe' => 'a:'.$d->cart_id.':'.$d->sid,
            ];
        }

        return count($this->sender->queue($rows));
    }

    /* ---------------------------------------------------------- price drop */

    public function price(bool $force = false): int
    {
        if (! $this->rules->get('price_on')) {
            return 0;
        }
        $marker = storage_path(self::PRICE_MARKER);
        $last = @filemtime($marker);
        if (! $force && $last !== false && $last > time() - self::PRICE_EVERY_MINUTES * 60) {
            return 0;
        }
        @touch($marker);

        $since = now()->subDays(self::INTEREST_DAYS);
        $watched = DB::table('site_app_push_interests')->where('viewed_at', '>=', $since)->select('product_id')
            ->union(DB::table('site_app_push_wishes')->where('wished_at', '>=', $since)->select('product_id'));
        $ids = DB::query()->fromSub($watched, 'w')->distinct()->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return 0;
        }

        $pct = (int) $this->rules->get('price_pct');
        $queued = 0;
        foreach (array_chunk($ids, 200) as $chunk) {
            $buyable = DB::table('products')->whereIn('products.id', $chunk)->where(fn ($q) => $this->buyable($q))->pluck('products.id')->all();
            $products = Product::query()->whereIn('id', $chunk)->with('variants')->get()->keyBy('id');
            $seen = DB::table('push_prices')->whereIn('product_id', $chunk)->pluck('price', 'product_id')->all();
            $dropped = [];
            foreach ($products as $id => $p) {
                try {
                    $now = (int) $p->effectivePrice();
                } catch (\Throwable) {
                    continue;
                }
                if ($now <= 0) {
                    continue;
                }
                $was = isset($seen[$id]) ? (int) $seen[$id] : null;
                if ($was === null || $now > $was) {
                    DB::table('push_prices')->upsert([['product_id' => $id, 'price' => $now, 'seen_at' => now()]], ['product_id'], ['price', 'seen_at']);
                } elseif ($now * 100 <= $was * (100 - $pct) && in_array($id, $buyable, true)) {
                    $dropped[$id] = [$p, $now];
                }
            }
            foreach ($dropped as $id => [$p, $now]) {
                $queued += $this->queuePrice($p, $now);
                DB::table('push_prices')->where('product_id', $id)->update(['price' => $now, 'seen_at' => now()]);
            }
        }

        return $queued;
    }

    private function queuePrice(Product $p, int $price): int
    {
        $id = (int) $p->getKey();
        $since = now()->subDays(self::INTEREST_DAYS);
        $subs = DB::table('site_app_push_subscriptions as s')->where('s.status', 'active')
            ->where(fn ($q) => $q
                ->whereIn('s.id', DB::table('site_app_push_interests')->where('product_id', $id)->where('viewed_at', '>=', $since)->select('subscription_id'))
                ->orWhereIn('s.id', DB::table('site_app_push_wishes')->where('product_id', $id)->where('wished_at', '>=', $since)->select('subscription_id')))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('push_sends as ps')->where('ps.kind', 'price')->where('ps.ref', $id)
                ->whereColumn('ps.subscription_id', 's.id')->where('ps.created_at', '>=', now()->subDays(self::PRICE_COOLDOWN_DAYS)))
            ->get(['s.id', 's.locale', 's.region', 's.city', 's.country']);
        $rows = [];
        foreach ($subs as $s) {
            $vars = ['product' => (string) $p->name, 'price' => Money::plain($price)];
            $rows[] = [
                'kind' => 'price', 'ref' => $id, 'subscription_id' => (int) $s->id,
                'emirate' => PushAudience::emirateOf($s->region, $s->city, $s->country),
                'title' => mb_substr(self::say('price_title', $vars, (string) $s->locale), 0, 80),
                'body' => mb_substr(self::say('price_body', $vars, (string) $s->locale), 0, 200),
                'url' => '/product/'.$p->slug.'/',
                'dedupe' => 'p:'.$id.':'.$s->id.':'.$price,
            ];
        }

        return count($this->sender->queue($rows));
    }

    /**
     * The template names an automation uses, for the Automations tab.
     *
     * @return array<string, list<string>>
     */
    public static function templates(): array
    {
        $order = [];
        foreach (PushRules::ORDER_STATUSES as $s) {
            $order[] = 'order_'.$s.'_title';
            $order[] = 'order_'.$s.'_body';
        }

        return [
            'order' => $order,
            'stock' => ['stock_title', 'stock_body'],
            'cart' => ['cart_title', 'cart_body'],
            'price' => ['price_title', 'price_body'],
        ];
    }
}
