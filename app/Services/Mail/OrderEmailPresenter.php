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
        /*
         * ONE WIDTH FOR THE WHOLE RECEIPT, decided once and handed to every
         * figure below. See ledgerWidth(). Computed here rather than inside
         * each helper so that a row added later cannot quietly print at a
         * different precision from the column it joins.
         */
        $w = self::ledgerWidth($order);

        return [
            'number' => (string) ($order->order_number ?: $order->id),
            // The shop's clock, not UTC — an order placed at 01:30 in Dubai
            // is stored as 21:30 the previous day, so the confirmation email
            // told the customer they had ordered yesterday. See
            // App\Support\StoreTime.
            'placedAt' => \App\Support\StoreTime::formatDate($order->created_at),
            'status' => (string) $order->status,
            'customerName' => $this->customerName($order),
            'items' => $this->items($order, $w),
            'totals' => $this->totals($order, $w),
            'vatNote' => $this->vatNote($order, $w),
            'totalFils' => (int) $order->total,
            'totalHtml' => self::html((int) $order->total, $w),
            'totalPlain' => self::plain((int) $order->total, $w),
            'address' => $this->address($order->shipping_address ?: $order->billing_address),
            'deliveryMethod' => trim((string) $order->shipping_method) ?: 'Standard delivery',
            'destinationCountry' => $this->destinationCountry($order),
            'paymentLabel' => $order->paymentLabel(),
            'giftNote' => $order->is_gift ? trim((string) $order->gift_note) : '',
            'customerNote' => trim((string) $order->customer_note),
            'email' => (string) $order->email,
            'phone' => trim((string) $order->phone),
            'trackUrl' => self::trackUrl($order),
            'accountUrl' => Url::external('/my-account/orders'),
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
        return Url::external('/checkout/success') . '?order=' . rawurlencode((string) $order->order_number);
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
    private function items(Order $order, ?int $w = null): array
    {
        $out = [];

        foreach ($order->items as $item) {
            $attributes = is_array($item->variant_attributes) ? $item->variant_attributes : [];

            $variant = array_filter(
                array_map(static fn ($v) => trim((string) $v), $attributes),
                static fn (string $v) => $v !== '',
            );

            /*
             * THE LANGUAGE THE ORDER WAS PLACED IN, NOT THE SHOP'S DEFAULT.
             *
             * Every mail this presenter feeds goes to the customer, and
             * Services\Mail\OrderMailer sends each one inside
             * OrderLocale::render() so its wording is their language. The line
             * name was the last thing on those mails still arriving in English:
             * a shopper who browsed /ar/shop saw the Arabic name on the card, in
             * the basket drawer and in the checkout summary, and then got a
             * confirmation calling it something else.
             *
             * `name_localised` is the snapshot OrderLocale::listen() took at
             * checkout, so this is still a record of the day and still costs no
             * query. NULL -- which is every row while this shop serves one
             * language -- falls back to the English snapshot.
             */
            $name = trim((string) $item->name) !== '' ? trim((string) $item->name) : 'Item';
            $localised = trim((string) ($item->name_localised ?? ''));

            $out[] = [
                'name' => $localised !== '' ? $localised : $name,
                'brand' => trim((string) $item->brand),
                'sku' => trim((string) $item->sku),
                'variant' => implode(', ', $variant),
                'quantity' => (int) $item->quantity,
                'unitFils' => (int) $item->unit_price,
                'unitHtml' => self::html((int) $item->unit_price, $w),
                'unitPlain' => self::plain((int) $item->unit_price, $w),
                'lineFils' => (int) $item->total,
                'lineHtml' => self::html((int) $item->total, $w),
                'linePlain' => self::plain((int) $item->total, $w),
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
    private function totals(Order $order, ?int $w = null): array
    {
        $giftFee = (int) $order->gift_fee;
        $otherFees = max(0, (int) $order->fee_total - $giftFee);

        $rows = [[__('email.totals.subtotal'), (int) $order->subtotal, false]];

        if ((int) $order->discount_total !== 0) {
            // Rendered negative, because it came off the bill. The column stores
            // it positive, which is right for the column and wrong for a receipt.
            $rows[] = [
                trim((string) $order->coupon_code) !== ''
                    // The CODE is an identifier and is never translated; only
                    // the word around it is. A placeholder rather than
                    // concatenation, so Arabic can put the bracket where Arabic
                    // puts it.
                    ? __('email.totals.discount_coupon', ['code' => trim((string) $order->coupon_code)])
                    : __('email.totals.discount'),
                -abs((int) $order->discount_total),
                false,
            ];
        }

        $rows[] = [__('email.totals.delivery'), (int) $order->shipping_total, false];

        if ($giftFee > 0) {
            $rows[] = [__('email.totals.gift_wrapping'), $giftFee, false];
        }

        if ($otherFees > 0) {
            // paymentLabel() is the gateway's own name — "Cash on delivery",
            // "Card" — and is the owner's wording, not this file's. Only the
            // word "fee" beside it is translated.
            $rows[] = [__('email.totals.payment_fee', ['method' => $order->paymentLabel()]), $otherFees, false];
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
                $rows[] = [__('email.totals.vat'), (int) $order->tax_total, false];
            }
        } elseif ($taxRecord['added'] && $taxRecord['fils'] !== 0) {
            // The RATE is the order's own snapshot and is a figure, so it goes
            // in as a placeholder and its rendering is untouched.
            $rows[] = [
                __('email.totals.vat_at_rate', [
                    'rate' => (new \App\Support\TaxRule($taxRecord['rate'], $taxRecord['basis']))->printableRate(),
                ]),
                $taxRecord['fils'],
                false,
            ];
        }

        $rows[] = [__('email.totals.total'), (int) $order->total, true];

        return array_map(static fn (array $row) => [
            'label' => $row[0],
            'fils' => $row[1],
            'html' => self::html($row[1], $w),
            'plain' => self::plain($row[1], $w),
            'strong' => $row[2],
        ], $rows);
    }

    /**
     * "You're paying VAT (5%)" — the tax the order RECORDED, printed under the
     * total. Or null, when there is nothing true to say.
     *
     * THIS IS A NOTE, NOT A ROW, and the distinction survives every version of
     * the tax decision. It is rendered under the Total rather than among the
     * rows that add up to it, because it is a portion OF that total and adding
     * it as a row would make the column of figures stop summing to what was
     * charged. An `exclusive` tax is the opposite case and belongs in the rows;
     * totals() puts it there. Nothing here writes `tax_total` or moves a total.
     *
     * ── THE ORDER'S OWN RECORD, OR SILENCE — LANE DU ────────────────────────
     *
     * This method used to end by asking VatDisplay LIVE for any order that had
     * no tax record of its own:
     *
     *     $line = app(VatDisplay::class)->line((int) $order->total);
     *
     * A receipt is a document the customer keeps, and this store RESENDS it —
     * the admin's "resend confirmation" action re-renders it from scratch, at
     * today's settings. So the owner raising `vat_rate` from 5 to 20 did not
     * merely mean a stale figure sitting in an old inbox; it meant the copy the
     * customer asks for in a year's time states a tax they were never charged,
     * while `total` correctly states what they paid. The two then disagree.
     *
     * The full argument for deleting that fallback rather than freezing or
     * backfilling it is written out in InvoiceDocument::vatNote(), which took
     * the same decision for the same reason at the same time. In one line: the
     * rate on the day is not recoverable from a settings table with no history,
     * so there is nothing honest to print, and a document that states no tax is
     * not wrong where one that states the wrong tax is.
     *
     * NULL, THEREFORE, WHENEVER:
     *
     *   - the order has no tax record: placed before the engine, placed while
     *     the shop is in the shipped `display` mode, or imported from
     *     WooCommerce (that last one carries a real `tax_total` which totals()
     *     has already printed as a real row above — a second figure here would
     *     be two different tax numbers on one receipt);
     *   - the tax was `exclusive`, so it is already a row in totals() above and
     *     only a portion OF the total belongs underneath it;
     *   - the recorded figure is zero.
     *
     * THE FIGURE AND THE RATE ARE THE ORDER'S; ONLY THE SENTENCE IS THE SHOP'S.
     * `vat_label` stays live because it is wording the owner may reword — "VAT
     * included ({rate}%)" instead of "You're paying VAT ({rate}%)" — and a
     * reworded sentence around an unchanged figure restates nothing. The
     * {rate} placeholder is filled from the snapshot, never from
     * VatDisplay::label(), whose rate is today's. (The invoice restates the
     * same figure as "Includes VAT at 5%", which suits a document an accountant
     * reads — see InvoiceDocument.)
     *
     * The fils integer is re-rendered at the currency's real precision like
     * every other figure in this class, because a receipt may not round. See
     * the header.
     *
     * @return array{label:string,fils:int,html:string,plain:string}|null
     */
    private function vatNote(Order $order, ?int $w = null): ?array
    {
        $taxRecord = \App\Support\OrderTax::recorded($order);

        if ($taxRecord === null || $taxRecord['added'] || $taxRecord['fils'] <= 0) {
            return null;
        }

        $rate = (new \App\Support\TaxRule($taxRecord['rate'], $taxRecord['basis']))->printableRate();

        return [
            // The RATE is the order's. Only the WORDING around it is the
            // owner's, and `vat_label` is a sentence he may reword at will —
            // which is why the {rate} placeholder is filled from the snapshot
            // and never from VatDisplay::label(), whose rate is today's.
            'label' => str_replace(
                '{rate}',
                $rate,
                (string) app(\App\Services\SettingsService::class)->get('vat_label', "You're paying VAT ({rate}%)")
            ),
            'fils' => $taxRecord['fils'],
            'html' => self::html($taxRecord['fils'], $w),
            'plain' => self::plain($taxRecord['fils'], $w),
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

    /**
     * Receipt precision, never the storefront's rounded display.
     *
     * ── WHAT "RECEIPT PRECISION" MEANS SINCE THE WHOLE-DIRHAM POLICY ───────
     *
     * It used to mean minorExponent(), always. The principle behind that is
     * unchanged and is in this class's header: a receipt may not round,
     * because a rounded figure on a receipt is a claim about money that is not
     * true. What changed is that the shop no longer produces the figures that
     * needed the room. App\Support\WholeDirhams makes every amount the owner
     * can set a whole dirham, and on a whole-dirham order "AED 220.00" and
     * "AED 220" are the same claim — so the wide form is no longer buying
     * truth, only decimals on a shop whose owner asked for none.
     *
     * So the width is now Money::receiptDecimals() over the whole receipt,
     * decided ONCE per order in ledgerWidth() below and passed in. A receipt
     * that can be stated in whole dirhams is; one that cannot — a legacy
     * order, or one carrying a figure this shop cannot make whole — widens as
     * a whole, automatically. The default is still minorExponent(), so any
     * caller that passes no width keeps exactly the behaviour it had.
     *
     * The two copies of a receipt cannot disagree, because both compute this
     * from the same order columns: ReceiptFiguresAgreeTest parses each surface
     * independently and holds them to each other and to the columns.
     */
    public static function html(int $fils, ?int $decimals = null): string
    {
        return Money::format($fils, $decimals ?? Money::minorExponent());
    }

    /** The same figure with no markup, for the plain-text part. */
    public static function plain(int $fils, ?int $decimals = null): string
    {
        return Money::plain($fils, $decimals ?? Money::minorExponent());
    }

    /**
     * The one width every figure on THIS order's receipt prints at.
     *
     * Every money column the receipt can show is offered to
     * receiptDecimals() — including the line prices, which are printed
     * beside the totals and are part of the same column of figures a customer
     * reads down. A receipt whose subtotal is whole but whose unit price is
     * not would otherwise print the unit price rounded, and the customer could
     * not multiply the line back.
     *
     * PUBLIC, because the customer's own on-screen copies
     * (store/account/order-detail, checkout-success, the account order list
     * and the tracker) have to ask the same question of the same order and get
     * the same answer. One authority, one width; two would be the twenty-fil
     * disagreement Lane EZ closed, reopened in a new shape.
     */
    public static function ledgerWidth(\App\Models\Order $order): int
    {
        $amounts = [
            (int) $order->subtotal,
            (int) $order->discount_total,
            (int) $order->shipping_total,
            (int) $order->fee_total,
            (int) $order->gift_fee,
            (int) $order->tax_total,
            (int) $order->total,
        ];

        $record = \App\Support\OrderTax::recorded($order);

        if ($record !== null) {
            $amounts[] = (int) $record['fils'];
        }

        foreach ($order->items as $item) {
            $amounts[] = (int) $item->unit_price;
            $amounts[] = (int) $item->total;
        }

        return Money::receiptDecimals(...$amounts);
    }
}
