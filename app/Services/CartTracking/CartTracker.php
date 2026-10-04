<?php

declare(strict_types=1);

namespace App\Services\CartTracking;

use App\Http\Middleware\BlockGate;
use App\Models\Cart;
use App\Models\Order;
use App\Services\Security\IpBlockList;
use App\Support\IpRange;
use App\Support\ShopperCountry;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;

/**
 * Records what happens to a cart.                                  (Lane CT)
 *
 * The owner, 4 October: "I want a super functional Cart Tracking
 * Functionality … to track every cart, along with the visitors countries,
 * products list".
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE COST, WHICH IS THE DESIGN
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Called ONLY from CartService's add / quantity / remove (and the checkout's
 * conversion), so a page view, a product page, a search — anything that does
 * not change a cart — runs none of this.
 *
 * Per event, exactly ONE new query: the INSERT into cart_events. Everything the
 * Carts list shows (address, country, bot score, value, counts, last update)
 * is set on the Cart model IN MEMORY here, and written by the
 * `$cart->forceFill(['last_activity_at' => now()])->save()` CartService was
 * already doing — so the summary rides on an UPDATE that existed before this
 * feature did.
 *
 * Once per CART, on its first tracked event, one more read: how many carts
 * the same address and range started inside the burst window (one indexed
 * COUNT). The datacenter check is a binary search over a local file.
 *
 * Settings come from IpBlockList's compiled file, not SettingsService.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * IT CANNOT BREAK ADD-TO-CART
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Nothing is staged unless the compiled file says the schema is READY (it is
 * only ready once `ip_blocks` — the last Cart Tracking table — exists), so the
 * seconds between a package landing and its migration running cannot make the
 * cart's own save fail on a column that is not there yet. The event insert is
 * inside a try/catch: an add-to-cart that cannot be recorded is still an
 * add-to-cart.
 *
 * NOT TRACKED: a cart changed from the admin (Store → New Order builds its
 * order through CartService) — that is the operator's address and browser,
 * not a shopper's.
 */
final class CartTracker
{
    public const ADD = 1;
    public const REMOVE = 2;
    public const QTY = 3;

    private const CONTEXT = 'kbb.ct.context';

    /**
     * One add, remove or quantity change.
     *
     * @param  int  $qtyDelta    signed change in units
     * @param  int  $qtyAfter    the line's quantity afterwards
     * @param  int  $unitPrice   the line's unit price now, fils
     * @param  int  $valueDelta  the change in the cart's lines total, fils
     */
    public function record(
        Cart $cart,
        int $type,
        ?int $productId,
        ?int $variantId,
        int $qtyDelta,
        int $qtyAfter,
        int $unitPrice,
        int $valueDelta,
    ): void {
        try {
            $ctx = $this->context();

            if ($ctx === null || $cart->getKey() === null) {
                return;
            }

            $this->stage($cart, $ctx);

            $cart->setAttribute('ct_value', max(0, (int) $cart->getAttribute('ct_value') + $valueDelta));

            if ($type === self::ADD) {
                $cart->setAttribute('ct_added', (int) $cart->getAttribute('ct_added') + 1);
            } elseif ($type === self::REMOVE) {
                $cart->setAttribute('ct_removed', (int) $cart->getAttribute('ct_removed') + 1);
            }
        } catch (\Throwable) {
            return;
        }

        try {
            DB::table('cart_events')->insert([
                'cart_id' => (int) $cart->getKey(),
                'type' => $type,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'qty' => max(-32768, min(32767, $qtyDelta)),
                'qty_after' => max(0, min(65535, $qtyAfter)),
                'unit_price' => $unitPrice,
                // Only when they differ from the cart's own, so a million
                // events do not carry a million copies of one address.
                'ip' => $ctx['ip'] !== $cart->getAttribute('ct_ip') ? $ctx['ip'] : null,
                'country' => $ctx['country'] !== null && $ctx['country'] !== $cart->getAttribute('ct_country') ? $ctx['country'] : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // See the class header: never at the cost of the add itself.
        }
    }

    /**
     * The cart became an order. Staged for the caller's own save (no query):
     * the order id, the exact lines total, and — for a cart that was opened
     * before tracking existed — the address it was bought from.
     */
    public function converted(Cart $cart, Order $order): void
    {
        try {
            $ctx = $this->context();

            if ($ctx === null) {
                return;
            }

            $this->stage($cart, $ctx, false);

            if ($cart->getAttribute('ct_country') === null) {
                $billing = $order->billing_address;
                $country = is_array($billing) ? strtoupper((string) ($billing['country'] ?? '')) : '';

                if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
                    $cart->setAttribute('ct_country', $country);
                }
            }

            $value = 0;

            foreach ($cart->items as $item) {
                $value += (int) $item->unit_price * (int) $item->quantity;
            }

            $cart->setAttribute('ct_value', $value);
            $cart->setAttribute('ct_order_id', (int) $order->getKey());
        } catch (\Throwable) {
        }
    }

    /**
     * After a guest basket is merged into an account's at sign-in, the
     * account cart's value is whatever its lines now total. Rare path (one per
     * sign-in with two baskets), so it may afford its one SUM.
     */
    public function revalue(Cart $cart, ?int $known = null): void
    {
        try {
            if ($this->context() === null || $cart->getAttribute('ct_first_at') === null) {
                return;
            }

            $sum = $known ?? (int) DB::table('cart_items')->where('cart_id', $cart->getKey())->sum(DB::raw('quantity * unit_price'));
            $cart->setAttribute('ct_value', $sum);
        } catch (\Throwable) {
        }
    }

    /**
     * Put this request's facts on the cart: first-event facts once, the bot
     * flags every time (they only ever accumulate), the clock always.
     */
    private function stage(Cart $cart, array $ctx, bool $score = true): void
    {
        $now = now();

        if ($cart->getAttribute('ct_first_at') === null) {
            $cart->setAttribute('ct_first_at', $now);
            $cart->setAttribute('ct_ip', $ctx['ip']);
            $cart->setAttribute('ct_net', $ctx['net']);
            $cart->setAttribute('ct_ua', $ctx['ua']);
            $cart->setAttribute('ct_country', $ctx['country']);

            if ($score) {
                $ctx['flags'] |= $this->placeFlags($ctx);
            }
        } elseif ($cart->getAttribute('ct_country') === null && $ctx['country'] !== null) {
            $cart->setAttribute('ct_country', $ctx['country']);
        }

        if ($score) {
            $flags = (int) $cart->getAttribute('ct_bot_flags') | $ctx['flags'];

            // "No script" is a fact about a request, and a single request with
            // the script header proves the shopper's browser ran it — so once
            // any event carried it, the cart is not "no script" any more.
            if ($ctx['ms'] !== null) {
                $flags &= ~BotSignals::NO_JS;
            } elseif ($cart->getAttribute('ct_speed_ms') !== null) {
                $flags &= ~BotSignals::NO_JS;
            }

            $cart->setAttribute('ct_bot_flags', $flags);
            $cart->setAttribute('ct_bot_score', BotSignals::score($flags));

            if ($ctx['ms'] !== null) {
                $old = $cart->getAttribute('ct_speed_ms');
                $cart->setAttribute('ct_speed_ms', $old === null ? $ctx['ms'] : min((int) $old, $ctx['ms']));
            }
        }

        $cart->setAttribute('ct_last_at', $now);
    }

    /** Hosting network, and how many carts this address / range just started. */
    private function placeFlags(array $ctx): int
    {
        $flags = 0;

        if ($ctx['ip'] === null) {
            return 0;
        }

        if (HostingNetworks::contains($ctx['ip'])) {
            $flags |= BotSignals::HOSTING;
        }

        $s = $ctx['settings'];

        try {
            $row = DB::table('carts')
                ->where('ct_net', $ctx['net'])
                ->where('ct_first_at', '>=', now()->subMinutes((int) $s['burst_minutes']))
                ->selectRaw('COUNT(*) AS net_carts, SUM(CASE WHEN ct_ip = ? THEN 1 ELSE 0 END) AS ip_carts', [$ctx['ip']])
                ->first();

            // +1: this cart, whose first event is being written now.
            if ((int) ($row->ip_carts ?? 0) + 1 >= (int) $s['burst_ip']) {
                $flags |= BotSignals::BURST_IP;
            }

            if ((int) ($row->net_carts ?? 0) + 1 >= (int) $s['burst_net']) {
                $flags |= BotSignals::BURST_NET;
            }
        } catch (\Throwable) {
        }

        return $flags;
    }

    /**
     * This request's facts, worked out once per request — or null when
     * nothing should be tracked (off, not ready, no request, the admin).
     *
     * @return array{ip:?string, net:?string, ua:?string, country:?string, flags:int, ms:?int, settings:array}|null
     */
    private function context(): ?array
    {
        $compiled = IpBlockList::compiled();
        $settings = $compiled['settings'];

        if (! $compiled['ready'] || empty($settings['track'])) {
            return null;
        }

        $request = app()->bound('request') ? app('request') : null;

        if (! $request instanceof Request) {
            return null;
        }

        $memo = $request->attributes->get(self::CONTEXT);

        if (is_array($memo) || $memo === false) {
            return $memo ?: null;
        }

        $route = $request->route();

        if ($route instanceof Route && BlockGate::isAdminArea($route)) {
            $request->attributes->set(self::CONTEXT, false);

            return null;
        }

        $ip = IpRange::normalise($request->ip());
        $ua = $request->userAgent();
        $ua = $ua === null ? null : mb_substr(trim($ua), 0, 255);
        [$uaFlags] = BotSignals::agent($ua);
        [$jsFlags, $ms] = BotSignals::script($request->header(BotSignals::HEADER), (int) $settings['speed_ms']);

        $country = null;

        try {
            $where = ShopperCountry::for($request);
            $country = $where->source === ShopperCountry::DEFAULT ? null : $where->code;
        } catch (\Throwable) {
        }

        $ctx = [
            'ip' => $ip,
            'net' => IpRange::rangeOf($ip),
            'ua' => $ua === '' ? null : $ua,
            'country' => $country,
            'flags' => $uaFlags | $jsFlags,
            'ms' => $ms,
            'settings' => $settings,
        ];

        $request->attributes->set(self::CONTEXT, $ctx);

        return $ctx;
    }
}
