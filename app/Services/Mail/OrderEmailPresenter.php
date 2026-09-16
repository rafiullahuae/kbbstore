<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Order;
use App\Support\Money;
use App\Support\Url;
use App\Support\VatDisplay;

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
            // The shop's clock, not UTC — an order placed at 01:30 in Dubai
            // is stored as 21:30 the previous day, so the confirmation email
            // told the customer they had ordered yesterday. See
            // App\Support\StoreTime.
            'placedAt' => \App\Support\StoreTime::formatDate($order->created_at),
            'status' => (string) $order->status,
            'customerName' => $this->customerName($order),
            'items' => $this->items($order),
            'totals' => $this->totals($order),
            'vatNote' => $this->vatNote($order),
            'totalFils' => (int) $order->total,
            'totalHtml' => self::html((int) $order->total),
            'totalPlain' => self::plain((int) $order->total),
            'address' => $this->address($order->shipping_address ?: $order->billing_address),
            'deliveryMethod' => trim((string) $order->shipping_method) ?: 'Standard delivery',
            'destinationCountry' => $this->destinationCountry($order),
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

        /*
         * VAT AS A ROW — only when it was ADDED to the figures above it. The
         * rows in this block sum to Total, so an `inclusive` order's tax, which
         * is a portion of the prices already listed, goes under the Total as a
         * note instead (vatNote() below). An order with no tax record behaves
         * exactly as it did before this lane: an imported WooCommerce order
         * prints its real `tax_total` as a row.
         */
        $taxRecord = \App\Support\OrderTax::recorded($order);

        if ($taxRecord === null) {
            if ((int) $order->tax_total !== 0) {
                $rows[] = ['VAT', (int) $order->tax_total, false];
            }
        } elseif ($taxRecord['added'] && $taxRecord['fils'] !== 0) {
            $rows[] = ['VAT at ' . (new \App\Support\TaxRule($taxRecord['rate'], $taxRecord['basis']))->printableRate() . '%', $taxRecord['fils'], false];
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
     * "You're paying VAT (5%)" — the line the checkout page already showed,
     * printed on the receipt as well. Or null, when there is nothing true to say.
     *
     * WHY THE RECEIPT WAS SILENT ABOUT TAX UNTIL NOW, AND WHY THAT WAS A BUG.
     * The VAT row in totals() above is gated on `tax_total`, and this store
     * always writes that column 0 — VAT is a display line, never charged, never
     * added to a total, never stored (decision D-64, App\Support\VatDisplay).
     * So the gate was not a condition, it was a closed door: the shopper was
     * shown an inclusive-VAT line at checkout, the formal tax invoice carried
     * VAT and the TRN, and the one document in between — the receipt the
     * customer actually keeps — mentioned tax nowhere at all.
     *
     * THIS IS A NOTE, NOT A ROW, and the distinction is the whole of D-64. It
     * is rendered under the Total rather than among the rows that add up to it,
     * because it is a portion OF that total and adding it as a row would make
     * the column of figures stop summing to what was charged. Nothing here
     * writes `tax_total`; the totals above are untouched.
     *
     * NULL IN TWO CASES, each a reason to say nothing rather than something
     * untrue — the same two InvoiceDocument::vatNote() answers null to:
     *
     *   - the order carries its own `tax_total`. That is an imported order with
     *     real tax already printed as a real row, and a second computed figure
     *     beside it would be two different tax numbers on one receipt.
     *   - VatDisplay has nothing to return: the owner switched the line off, the
     *     rate is zero, or the portion rounds to nothing.
     *
     * THE FIGURE IS VatDisplay'S AND THE WORDING IS VatDisplay'S. One source of
     * truth with the checkout page, so the two cannot drift; the label is
     * label(), the shopper's own second-person sentence, because this is read by
     * the shopper. (The invoice restates the same figure as "Includes VAT at
     * 5%", which suits a document an accountant reads — see InvoiceDocument.)
     *
     * Only the RENDERING differs, and it has to: VatDisplay::line() formats at
     * the storefront's display precision, which rounds, and a receipt may not
     * round. The fils integer is taken and re-rendered at the currency's real
     * precision like every other figure in this class. See the header.
     *
     * @return array{label:string,fils:int,html:string,plain:string}|null
     */
    private function vatNote(Order $order): ?array
    {
        /*
         * THE ORDER'S OWN RECORD FIRST — see InvoiceDocument::vatNote() for the
         * full reasoning. Asking VatDisplay live at send time means a resent
         * receipt states whatever rate the settings hold today rather than the
         * one the customer was charged, and per-country rates the owner can
         * edit make that a real misstatement rather than a theoretical one.
         *
         * An `exclusive` order's tax is already a row in totals() above and is
         * not restated here; only a portion OF the total belongs under it.
         */
        $taxRecord = \App\Support\OrderTax::recorded($order);

        if ($taxRecord !== null) {
            if ($taxRecord['added'] || $taxRecord['fils'] <= 0) {
                return null;
            }

            $rate = (new \App\Support\TaxRule($taxRecord['rate'], $taxRecord['basis']))->printableRate();

            return [
                'label' => str_replace(
                    '{rate}',
                    $rate,
                    (string) app(\App\Services\SettingsService::class)->get('vat_label', "You're paying VAT ({rate}%)")
                ),
                'fils' => $taxRecord['fils'],
                'html' => self::html($taxRecord['fils']),
                'plain' => self::plain($taxRecord['fils']),
            ];
        }

        if ((int) $order->tax_total !== 0) {
            return null;
        }

        $line = app(VatDisplay::class)->line((int) $order->total);

        if ($line === null) {
            return null;
        }

        return [
            'label' => (string) $line['label'],
            'fils' => (int) $line['amount'],
            'html' => self::html((int) $line['amount']),
            'plain' => self::plain((int) $line['amount']),
        ];
    }

    /**
     * Where the parcel is actually going, as an upper-case ISO code, or ''.
     *
     * The shipping address first and the billing address as the fallback — the
     * same choice address() makes, so the code and the printed lines can never
     * describe two different places.
     *
     * It exists because this store does not only ship to the UAE.
     * ShippingSeeder has carried a Gulf zone (SA, KW, QA, BH, OM) from the
     * start, and OrderStatusChanged used to tell every one of those customers
     * how long delivery takes "in the UAE". Empty is an honest answer and the
     * templates treat it as one: an order with no country recorded gets no
     * delivery estimate rather than a guessed one.
     */
    private function destinationCountry(Order $order): string
    {
        $address = $order->shipping_address ?: $order->billing_address;

        if (! is_array($address)) {
            return '';
        }

        return strtoupper(trim((string) ($address['country'] ?? '')));
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
