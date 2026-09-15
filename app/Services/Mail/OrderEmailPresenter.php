<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Order;
use App\Support\Money;
use App\Support\Url;

/**
 * One order, turned into the exact strings an email prints.
 *
 * WHY THIS EXISTS AT ALL. Four templates — each with a plain-text twin — have to
 * agree about what an order says. If each worked the figures out for itself, the
 * HTML receipt and its text part could disagree about the total, and the customer
 * would be reading the disagreement rather than the price. Everything is decided
 * once, here, and both parts render the same array.
 *
 * WHAT IS PRINTED IS THE SNAPSHOT, NEVER THE LIVE PRODUCT. `order_items` carries
 * name, brand, sku, quantity and unit_price as they were on the day. The product
 * row behind it may since have been renamed, repriced, or soft-deleted
 * (product_id is nullable and Product soft-deletes), and a receipt that reprints
 * today's catalogue is a receipt for an order nobody placed. Nothing here touches
 * $item->product.
 *
 * MONEY IS PRINTED AT FULL PRECISION, WHICH IS NOT WHAT THE STOREFRONT DOES.
 * Money::displayDecimals() is 0 on this store — the live WooCommerce site prints
 * whole dirhams — and Money::format() therefore ROUNDS. On a shop page that is a
 * presentation choice. On a receipt it is a misstatement: an order totalling
 * 21550 fils would be receipted as "AED 216", fifty fils more than was charged.
 * So every figure in an order email renders at Money::minorExponent() decimals,
 * the currency's real precision, and the fils integer is carried alongside it so
 * a test can pin the exact amount rather than the rendering of it.
 *
 * EVERY STRING HERE IS CUSTOMER INPUT UNTIL PROVEN OTHERWISE. Names, addresses,
 * gift notes and order notes are typed by whoever placed the order. Nothing is
 * escaped in this class: escaping belongs at the point of output, where Blade's
 * {{ }} does it, and a pre-escaped string would render as a literal &amp;amp; in
 * the text part. The one value that arrives as HTML is the money, built by
 * Money::format(), which escapes the operator-supplied currency symbol itself.
 */
class OrderEmailPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(Order $order): array
    {
        return [
            'number' => (string) ($order->order_number ?: $order->id),
            'placedAt' => optional($order->created_at)->format('j F Y') ?: '',
            'status' => (string) $order->status,
            'customerName' => $this->customerName($order),
            'items' => $this->items($order),
            'totals' => $this->totals($order),
            'totalFils' => (int) $order->total,
            'totalHtml' => self::html((int) $order->total),
            'totalPlain' => self::plain((int) $order->total),
            'address' => $this->address($order->shipping_address ?: $order->billing_address),
            'deliveryMethod' => trim((string) $order->shipping_method) ?: 'Standard delivery',
            'paymentLabel' => $order->paymentLabel(),
            'giftNote' => $order->is_gift ? trim((string) $order->gift_note) : '',
            'customerNote' => trim((string) $order->customer_note),
            'email' => (string) $order->email,
            'phone' => trim((string) $order->phone),
            'trackUrl' => self::trackUrl($order),
            'accountUrl' => Url::redirect('/my-account/orders'),
        ];
    }

    /**
     * Where "track your order" points.
     *
     * The order-received page, with the order number in the query string and
     * nothing else. NOT a signed link and NOT a token: CheckoutController::success()
     * already decides who may see an order, and it decides it from the session —
     * the browser that placed the order, or a signed-in customer looking at their
     * own. A link carrying its own authority would be a second, weaker answer to a
     * question that already has one, and it would be an authority sitting in an
     * inbox forever. CustomerPasswordReset is the reference for when a mailed link
     * may carry a credential: when it is the credential, used once, and expiring.
     *
     * The consequence, stated plainly rather than papered over: opened on a
     * different device this link shows the "we could not find that order" panel.
     * That is why every order email also prints the order number and a link to the
     * account order list, which works anywhere once the customer signs in.
     */
    public static function trackUrl(Order $order): string
    {
        return Url::redirect('/checkout/success') . '?order=' . rawurlencode((string) $order->order_number);
    }

    /** The name to greet, from the order's own address snapshot. */
    private function customerName(Order $order): string
    {
        $address = is_array($order->billing_address) ? $order->billing_address : [];

        return trim(((string) ($address['first_name'] ?? '')) . ' ' . ((string) ($address['last_name'] ?? '')));
    }

    /**
     * The lines, as bought.
     *
     * @return list<array<string, mixed>>
     */
    private function items(Order $order): array
    {
        $out = [];

        foreach ($order->items as $item) {
            $attributes = is_array($item->variant_attributes) ? $item->variant_attributes : [];

            $variant = array_filter(
                array_map(static fn ($v) => trim((string) $v), $attributes),
                static fn (string $v) => $v !== '',
            );

            $out[] = [
                'name' => trim((string) $item->name) !== '' ? trim((string) $item->name) : 'Item',
                'brand' => trim((string) $item->brand),
                'sku' => trim((string) $item->sku),
                'variant' => implode(', ', $variant),
                'quantity' => (int) $item->quantity,
                'unitFils' => (int) $item->unit_price,
                'unitHtml' => self::html((int) $item->unit_price),
                'unitPlain' => self::plain((int) $item->unit_price),
                'lineFils' => (int) $item->total,
                'lineHtml' => self::html((int) $item->total),
                'linePlain' => self::plain((int) $item->total),
            ];
        }

        return $out;
    }

    /**
     * The money breakdown, in the order a receipt reads.
     *
     * Zero rows are left out rather than printed as "AED 0.00" — with one
     * exception, delivery, because "we charged you nothing to deliver this" is
     * information and a missing line is not.
     *
     * fee_total is the sum of the gift-wrapping charge and whatever the gateway
     * added (cash on delivery is the only one that charges anything today). The
     * two are split back out here, because a single "Fees AED 30.00" tells the
     * customer nothing about what they agreed to.
     *
     * @return list<array{label:string,fils:int,html:string,plain:string,strong:bool}>
     */
    private function totals(Order $order): array
    {
        $giftFee = (int) $order->gift_fee;
        $otherFees = max(0, (int) $order->fee_total - $giftFee);

        $rows = [['Subtotal', (int) $order->subtotal, false]];

        if ((int) $order->discount_total !== 0) {
            // Rendered negative, because it came off the bill. The column stores
            // it positive, which is right for the column and wrong for a receipt.
            $rows[] = [
                trim((string) $order->coupon_code) !== ''
                    ? 'Discount (' . trim((string) $order->coupon_code) . ')'
                    : 'Discount',
                -abs((int) $order->discount_total),
                false,
            ];
        }

        $rows[] = ['Delivery', (int) $order->shipping_total, false];

        if ($giftFee > 0) {
            $rows[] = ['Gift wrapping', $giftFee, false];
        }

        if ($otherFees > 0) {
            $rows[] = [$order->paymentLabel() . ' fee', $otherFees, false];
        }

        if ((int) $order->tax_total !== 0) {
            $rows[] = ['VAT', (int) $order->tax_total, false];
        }

        $rows[] = ['Total', (int) $order->total, true];

        return array_map(static fn (array $row) => [
            'label' => $row[0],
            'fils' => $row[1],
            'html' => self::html($row[1]),
            'plain' => self::plain($row[1]),
            'strong' => $row[2],
        ], $rows);
    }

    /**
     * A postal address as lines, empty parts dropped.
     *
     * @param  mixed  $address
     * @return list<string>
     */
    private function address($address): array
    {
        if (! is_array($address)) {
            return [];
        }

        $lines = [
            trim(((string) ($address['first_name'] ?? '')) . ' ' . ((string) ($address['last_name'] ?? ''))),
            (string) ($address['line1'] ?? ''),
            (string) ($address['line2'] ?? ''),
            trim(((string) ($address['city'] ?? '')) . ' ' . ((string) ($address['state'] ?? ''))),
            (string) ($address['country'] ?? ''),
            (string) ($address['phone'] ?? ''),
        ];

        return array_values(array_filter(array_map('trim', $lines), static fn (string $l) => $l !== ''));
    }

    /** Receipt precision, never the storefront's rounded display. See the header. */
    public static function html(int $fils): string
    {
        return Money::format($fils, Money::minorExponent());
    }

    /** The same figure with no markup, for the plain-text part. */
    public static function plain(int $fils): string
    {
        return Money::plain($fils, Money::minorExponent());
    }
}
