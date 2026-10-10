<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;
use App\Services\Analytics\Attribution;
use App\Services\CartTracking\CartTracker;
use Illuminate\Support\Facades\DB;

/**
 * What the shopper did BEFORE paying, on the order screens. (Lane TM.)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The owner, 10 October 2026: "i need the emails and the customer journey on
 * order details page". Read only from what this shop already records, and
 * nothing is invented to fill a gap:
 *
 *   - WHERE THEY CAME FROM: the first and last touch Analytics stamped on the
 *     order row at checkout (orders.src_attr, Attribution::stamp) — channel,
 *     utm source/medium/campaign, click-id type, landing page, days to order.
 *     On the row already: no query.
 *   - THE BASKET: the Cart Tracking basket that became this order
 *     (carts.ct_order_id) and its add/remove events with product names. ONE
 *     query, a join, capped.
 *   - PLACED: the order row.
 *
 * NOT SHOWN, BECAUSE IT IS NOT RECORDED PER SHOPPER: products viewed and
 * "checkout opened". Analytics keeps page views under a daily-salted visitor
 * hash (an_hits.v / .s) that is deliberately not linked to an order or a
 * person, and this lane adds no per-page tracking. The screen says so rather
 * than leaving a hole that looks like a bug.
 */
final class CustomerJourney
{
    private const EVENT_LIMIT = 40;

    /**
     * @return array{source: array<string, mixed>|null, steps: list<array<string, mixed>>, cart_id: int|null, not_tracked: string}
     */
    public static function for(Order $order): array
    {
        $steps = [];
        $panel = Attribution::panel($order->src_channel, $order->src_campaign, $order->src_attr);
        $days = $panel['days'];

        foreach (['first' => 'Arrived', 'last' => 'Came back'] as $key => $verb) {
            $t = $panel[$key];

            if ($t === null || ($key === 'last' && $t === $panel['first'])) {
                continue;
            }

            $detail = implode(' · ', array_filter([
                $t['channel'],
                $t['source'] !== '' ? 'source ' . $t['source'] : '',
                $t['medium'] !== '' ? 'medium ' . $t['medium'] : '',
                $t['campaign'] !== '' ? 'campaign ' . $t['campaign'] : '',
                $t['click'] !== '' ? 'ad click (' . $t['click'] . ')' : '',
                $t['landing'] !== '' ? 'first page ' . $t['landing'] : '',
            ]));

            // The touch carries a day number, not a time: placed in order before
            // the basket, and labelled by how many days before the order it was.
            $steps[] = ['at' => null, 'at_label' => $key === 'first' && $days !== null
                ? ($days === 0 ? 'same day as the order' : $days . ' day' . ($days === 1 ? '' : 's') . ' before the order')
                : '', 'tone' => '', 'title' => $verb . ' from ' . $t['channel'], 'detail' => $detail];
        }

        $cartId = null;

        foreach (self::cartEvents((int) $order->getKey()) as $i => $e) {
            $cartId = (int) $e->cart_id;

            if ($i === 0 && $e->ct_first_at !== null) {
                $steps[] = self::step($e->ct_first_at, 'Basket started', 'Cart Tracking basket #' . $cartId);
            }

            if ($e->type === null) {
                continue;
            }

            $name = $e->name !== null && $e->name !== '' ? (string) $e->name : 'a product no longer in the catalogue';
            $qty = abs((int) $e->qty);

            $steps[] = match ((int) $e->type) {
                CartTracker::ADD => self::step($e->created_at, 'Added to bag', $name . ($qty > 1 ? ' × ' . $qty : '')),
                CartTracker::REMOVE => self::step($e->created_at, 'Removed from bag', $name),
                default => self::step($e->created_at, 'Changed quantity', $name . ' → ' . (int) $e->qty_after),
            };
        }

        $steps[] = self::step($order->created_at, 'Order placed', trim(($order->paymentLabel() ?: (string) $order->payment_method) . ' · ' . Money::plain((int) $order->total)));

        return [
            'source' => $panel['known'] ? ['chip' => $panel['chip']] : null,
            'steps' => $steps,
            'cart_id' => $cartId,
            'not_tracked' => 'Pages and products viewed are not recorded per shopper (Analytics counts visits anonymously), so they cannot be shown here.',
        ];
    }

    /** @return list<object> */
    private static function cartEvents(int $orderId): array
    {
        try {
            // ONE query whatever the basket: the cart, its events and the product
            // names, joined. A cart with no events still comes back (left join)
            // so "Basket started" is not lost.
            return DB::table('carts as c')
                ->leftJoin('cart_events as e', 'e.cart_id', '=', 'c.id')
                ->leftJoin('products as p', 'p.id', '=', 'e.product_id')
                ->where('c.ct_order_id', $orderId)
                ->orderByDesc('c.id')
                ->orderBy('e.id')
                ->limit(self::EVENT_LIMIT)
                ->get(['c.id as cart_id', 'c.ct_first_at', 'e.type', 'e.qty', 'e.qty_after', 'e.created_at', 'p.name'])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{at: string|null, at_label: string, tone: string, title: string, detail: string} */
    private static function step(mixed $at, string $title, string $detail): array
    {
        $local = StoreTime::display($at instanceof \DateTimeInterface || is_string($at) ? $at : null);

        return ['at' => $local?->toIso8601String(), 'at_label' => $local?->format('j M Y, g:i:s A') ?? '', 'tone' => '', 'title' => $title, 'detail' => $detail];
    }
}
