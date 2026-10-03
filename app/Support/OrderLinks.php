<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;

/**
 * The two links an order email carries that must open on ANY device (Lane RL).
 *
 *   track — "Track your order": the shop's own /track-my-order/ page, showing
 *           this order's status card. 30 days (to the next midnight UTC after). The owner: "tracking number is
 *           the same order number ... the order can be tracked on our website,
 *           and whatever we put the status of the order, it will show."
 *   pay   — "Complete your order": /checkout/order-pay, where an unpaid order
 *           can be paid with the methods the checkout already offers. 7 days (ditto).
 *
 * Before this the email's button went to /checkout/success?order=, which opens
 * only in the browser session that placed the order — on the phone where most
 * email is read it said "not found" (audit §6).
 *
 * SIGNED WITH CustomerLinkSigner, NOT URL::signedRoute(). That class's header
 * gives both reasons — the open signed-URL path-confusion advisory on Laravel
 * 11, and a signature over the URL breaking whenever KBB_BASE_PATH or APP_URL
 * differ between the moment of sending and the moment of clicking. The claim
 * here is the order's id AND number, the purpose, and the expiry; nothing about
 * the host. A token for one order cannot open another (the id is in the MAC),
 * a track token cannot be used to pay (the purpose is), and an expired one is
 * indistinguishable from a forged one (CustomerLinkSigner folds both into one
 * boolean, compared with hash_equals).
 *
 * THE TOKEN TRAVELS AS ONE QUERY PARAMETER, `t=<expires>.<hex mac>`, beside the
 * order number the page already takes as `order`.
 */
final class OrderLinks
{
    public const TRACK = 'order.track';

    public const PAY = 'order.pay';

    public const TRACK_TTL = 30 * 86400;

    public const PAY_TTL = 7 * 86400;

    public static function trackUrl(Order $order): string
    {
        return Url::external('/track-my-order/') . '?' . http_build_query([
            'order' => (string) $order->order_number,
            't' => self::token(self::TRACK, $order, self::TRACK_TTL),
        ]);
    }

    public static function payUrl(Order $order): string
    {
        return Url::external('/checkout/order-pay') . '?' . http_build_query([
            'order' => (string) $order->order_number,
            't' => self::token(self::PAY, $order, self::PAY_TTL),
        ]);
    }

    public static function token(string $purpose, Order $order, int $ttl): string
    {
        // The application clock, not time(), so a test can move it. Rounded UP
        // to the next UTC midnight, so the link lives its full term or up to a
        // day longer, and two renders of one email in the same day carry the
        // same link (the English-unchanged guards compare renders byte for byte).
        $now = now()->getTimestamp();
        $expires = (intdiv($now, 86400) + 1) * 86400 + $ttl;

        return $expires . '.' . CustomerLinkSigner::sign($purpose, self::claims($order), $expires);
    }

    /**
     * The order this (number, token) pair opens, or null.
     *
     * THE SAME WORK EITHER WAY. An order number that does not exist is
     * verified against a stand-in order, so "no such order", "wrong order for
     * this token", "forged" and "expired" all cost one lookup and one HMAC and
     * all answer null. Nothing a caller does with null can tell them apart,
     * which is what keeps a sequential order number from becoming an oracle.
     */
    public static function resolve(string $purpose, string $number, string $token): ?Order
    {
        $number = trim($number);
        $order = $number === '' ? null : Order::query()->where('order_number', $number)->first();

        [$expires, $signature] = array_pad(explode('.', trim($token), 2), 2, '');

        $subject = $order ?? (new Order)->forceFill(['id' => 0, 'order_number' => $number]);

        try {
            $ok = CustomerLinkSigner::verify($purpose, self::claims($subject), (int) $expires, (string) $signature);
        } catch (\Throwable) {
            return null;
        }

        // Expiry on the application clock as well (CustomerLinkSigner reads
        // time()); folded into the same boolean, after the MAC, so a stale link
        // and a forged one still cannot be told apart.
        $fresh = (int) $expires >= now()->getTimestamp();

        return $ok && $fresh && $order !== null ? $order : null;
    }

    /** @return array<string, int|string> */
    private static function claims(Order $order): array
    {
        return ['order' => (int) $order->getKey(), 'number' => (string) $order->order_number];
    }
}
