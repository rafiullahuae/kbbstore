<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Order;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\VatDisplay;

/**
 * One order, turned into the exact strings an invoice and a packing slip print.
 *
 * The same argument OrderEmailPresenter makes, for the same reason: two
 * documents — the invoice and the packing slip — have to agree about what the
 * order contained, and a figure worked out twice is a figure that can disagree
 * with itself. Everything is decided once, here, and both templates render this
 * array.
 *
 * ── THE LINES ARE THE SNAPSHOT, NEVER THE LIVE PRODUCT ──────────────────────
 *
 * `order_items` carries name, brand, sku, variant, quantity and unit_price as
 * they were on the day. `product_id` is nullable and Product soft-deletes, so
 * the catalogue row behind a line may have been renamed, repriced or removed.
 * An invoice is a record of a transaction that happened; reprinting today's
 * catalogue onto it makes it a record of nothing. Nothing in this class touches
 * $item->product.
 *
 * ── MONEY IS PRINTED AT FULL PRECISION ──────────────────────────────────────
 *
 * `Money::displayDecimals()` is 0 on this store, matching the live WooCommerce
 * site, so `Money::format()` ROUNDS: an order totalling 21550 fils renders as
 * "AED 216". On a shop page that is a presentation choice. On an invoice it is
 * a misstatement of an amount someone was charged, and the document exists to
 * be filed against a bank statement. Every figure here renders at
 * `Money::minorExponent()` — the currency's real precision — and the fils
 * integer is carried beside it so a test pins the amount rather than the
 * rendering of it. This is the identical rule OrderEmailPresenter applies, and
 * InvoiceMoneyTest asserts the two produce byte-identical strings.
 *
 * ── VAT IS A DISPLAY LINE, AND THE INVOICE SAYS SO ──────────────────────────
 *
 * Decision D-64: VAT is never charged, never added to a total and never stored
 * against an order placed by this app — `tax_total` is written as 0 by
 * CheckoutController. App\Support\VatDisplay computes the portion of a total
 * that VAT represents, and that is all this invoice claims: an "of which"
 * line under the total, never an addition to it. No tax engine is invented
 * here and none should grow here.
 *
 * The one case that is not display-only is an IMPORTED order. WooCommerce
 * orders carry a real `tax_total`, already inside `total`. Where that column
 * is non-zero it is the order's own record of its tax and it is printed as the
 * authoritative figure; VatDisplay's computed line is then suppressed, because
 * two different VAT numbers on one invoice is worse than none.
 *
 * ── NOTHING IS ESCAPED HERE ─────────────────────────────────────────────────
 *
 * Names, addresses, gift messages and order notes are typed by whoever placed
 * the order. They arrive raw and leave raw; escaping belongs at the point of
 * output, where Blade's {{ }} does it once and correctly. Pre-escaping here
 * would print a literal &amp;amp; on the page and would hide from the templates
 * that these strings are hostile. The only values that arrive as HTML are the
 * money strings from Money::format(), which escapes the operator-supplied
 * currency symbol itself.
 */
class InvoiceDocument
{
    public function __construct(
        private SettingsService $settings,
        private VatDisplay $vat,
    ) {}

    /**
     * Everything both documents need.
     *
     * @return array<string, mixed>
     */
    public function present(Order $order): array
    {
        $order->loadMissing('items');

        $invoiceNumber = $order->invoice_number === null ? null : (int) $order->invoice_number;

        return [
            'orderNumber' => (string) ($order->order_number ?: $order->id),
            'orderStatus' => (string) $order->status,
            'invoiceNumber' => $invoiceNumber,
            'invoiceReference' => $invoiceNumber === null ? '' : InvoiceNumbers::format($invoiceNumber),
            'invoicedAt' => optional($order->invoiced_at)->format('j F Y') ?: '',
            'placedAt' => optional($order->created_at)->format('j F Y') ?: '',
            'paidAt' => optional($order->paid_at)->format('j F Y') ?: '',

            'seller' => $this->seller(),
            'billTo' => $this->address($order->billing_address),
            'shipTo' => $this->address($order->shipping_address ?: $order->billing_address),
            'sameAddress' => $this->address($order->billing_address) === $this->address($order->shipping_address ?: $order->billing_address),

            'email' => trim((string) $order->email),
            'phone' => trim((string) $order->phone),

            'items' => $this->items($order),
            'itemCount' => (int) $order->items->sum('quantity'),
            'totals' => $this->totals($order),
            'totalFils' => (int) $order->total,
            'totalHtml' => self::money((int) $order->total),
            'totalPlain' => self::moneyPlain((int) $order->total),
            'vatNote' => $this->vatNote($order),

            'paymentLabel' => $order->paymentLabel(),
            'paid' => $order->paid_at !== null,
            'deliveryMethod' => trim((string) $order->shipping_method) ?: 'Standard delivery',
            'couponCode' => trim((string) $order->coupon_code),
            'isGift' => (bool) $order->is_gift,
            'giftNote' => $order->is_gift ? trim((string) $order->gift_note) : '',
            'customerNote' => trim((string) $order->customer_note),
            'currency' => (string) ($order->currency ?: Money::currency()),
        ];
    }

    /**
     * Who is issuing the document.
     *
     * Read from settings with the store's own identity rows as the fallback, so
     * a fresh install prints something correct rather than "[]" — and so the
     * owner can fill in the legal name, the trade licence and the TRN without a
     * code change. The keys are, in order of preference:
     *
     *   invoice_business_name   else store_name        else 'K-Beauty Bliss'
     *   invoice_address         else '' (omitted)
     *   invoice_trn             else '' (the VAT line then says nothing about a
     *                           registration number, rather than inventing one)
     *   invoice_email           else support_email
     *   invoice_phone           else brand_whatsapp
     *   invoice_website         else ''
     *   invoice_footer          else ''  (payment terms, returns policy, bank
     *                           details — whatever the owner wants at the foot)
     *
     * A TRN is a legal identifier. It is printed only when it has been entered;
     * an invoice that displays a made-up tax registration number is worse than
     * one that displays none.
     *
     * @return array<string, mixed>
     */
    public function seller(): array
    {
        $name = $this->setting('invoice_business_name')
            ?: $this->setting('store_name')
            ?: 'K-Beauty Bliss';

        return [
            'name' => $name,
            'addressLines' => $this->lines($this->setting('invoice_address')),
            'trn' => $this->setting('invoice_trn'),
            'email' => $this->setting('invoice_email') ?: $this->setting('support_email'),
            'phone' => $this->setting('invoice_phone') ?: $this->setting('brand_whatsapp'),
            'website' => $this->setting('invoice_website'),
            'footer' => $this->setting('invoice_footer'),
        ];
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
                'unitHtml' => self::money((int) $item->unit_price),
                'unitPlain' => self::moneyPlain((int) $item->unit_price),
                'lineFils' => (int) $item->total,
                'lineHtml' => self::money((int) $item->total),
                'linePlain' => self::moneyPlain((int) $item->total),
            ];
        }

        return $out;
    }

    /**
     * The money breakdown, in the order an invoice reads.
     *
     * The same rows, labels and splits as the emailed receipt — the customer
     * has one of those in their inbox and must not find the invoice disagreeing
     * with it. Delivery prints even at zero, because "we charged you nothing to
     * deliver this" is a statement and a missing row is not.
     *
     * `fee_total` is the gift-wrapping charge plus whatever the gateway added
     * (cash on delivery is the only one that charges anything today). Split
     * back out, because a single "Fees AED 30.00" tells the reader nothing
     * about what they agreed to.
     *
     * @return list<array{label:string,fils:int,html:string,plain:string,strong:bool}>
     */
    private function totals(Order $order): array
    {
        $giftFee = (int) $order->gift_fee;
        $otherFees = max(0, (int) $order->fee_total - $giftFee);

        $rows = [['Subtotal', (int) $order->subtotal, false]];

        if ((int) $order->discount_total !== 0) {
            // Rendered negative, because it came off the bill. The column
            // stores it positive, which is right for a column and wrong for a
            // document somebody adds up by hand.
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
            // An imported order carrying real tax. Already inside `total`.
            $rows[] = ['VAT', (int) $order->tax_total, false];
        }

        $rows[] = ['Total', (int) $order->total, true];

        return array_map(static fn (array $row) => [
            'label' => $row[0],
            'fils' => $row[1],
            'html' => self::money($row[1]),
            'plain' => self::moneyPlain($row[1]),
            'strong' => $row[2],
        ], $rows);
    }

    /**
     * The "of which VAT" line printed under the total, or null.
     *
     * Null in three cases, each of which is a reason to say nothing rather than
     * to say something untrue:
     *
     *   - VAT display is switched off in settings;
     *   - the computed portion rounds to zero;
     *   - the order carries its own `tax_total`, which has already been printed
     *     as a real row above and must not be restated as a different number.
     *
     * @return array{label:string,fils:int,html:string,plain:string,trn:string}|null
     */
    private function vatNote(Order $order): ?array
    {
        if ((int) $order->tax_total !== 0) {
            return null;
        }

        $line = $this->vat->line((int) $order->total);

        if ($line === null) {
            return null;
        }

        return [
            // VatDisplay::label() is the checkout's own second-person wording
            // ("You're paying VAT (5%)"). An invoice is read by an accountant
            // as often as by the buyer, so the rate is restated in a form that
            // suits a document while the figure stays the checkout's.
            'label' => 'Includes VAT at ' . $this->rateText() . '%',
            'fils' => (int) $line['amount'],
            'html' => self::money((int) $line['amount']),
            'plain' => self::moneyPlain((int) $line['amount']),
            'trn' => $this->setting('invoice_trn'),
        ];
    }

    /** "5", "7.5" — the configured rate with trailing zeros trimmed. */
    private function rateText(): string
    {
        return rtrim(rtrim(number_format($this->vat->rate(), 2, '.', ''), '0'), '.');
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

        $city = trim((string) ($address['city'] ?? ''));
        $state = trim((string) ($address['state'] ?? ''));

        $lines = [
            trim(((string) ($address['first_name'] ?? '')) . ' ' . ((string) ($address['last_name'] ?? ''))),
            (string) ($address['company'] ?? ''),
            (string) ($address['line1'] ?? ''),
            (string) ($address['line2'] ?? ''),
            // "Dubai Dubai" is what city + state gives you in an emirate whose
            // city and emirate have the same name, which is most of the UAE.
            // Printed once on a document somebody files.
            ($state !== '' && mb_strtolower($state) !== mb_strtolower($city))
                ? trim($city . ' ' . $state)
                : $city,
            (string) ($address['postcode'] ?? ''),
            $this->countryName((string) ($address['country'] ?? '')),
            (string) ($address['phone'] ?? ''),
        ];

        return array_values(array_filter(array_map('trim', $lines), static fn (string $l) => $l !== ''));
    }

    /**
     * "United Arab Emirates", not "AE".
     *
     * The column stores ISO alpha-2 (docs/IMPORT-READINESS.md is emphatic about
     * that: Phase 0's `addresses.country` is varchar(2) and silently truncates
     * anything longer). A two-letter code is right for the database and wrong
     * for a printed address, which may be read by a courier or an accountant.
     * An unknown code is printed as it was stored rather than dropped — better
     * a code than no country at all.
     */
    private function countryName(string $code): string
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return '';
        }

        return \App\Support\Countries::NAMES[$code] ?? $code;
    }

    /**
     * A multi-line setting split into lines.
     *
     * @return list<string>
     */
    private function lines(string $value): array
    {
        $parts = preg_split('/\r\n|\r|\n/', $value) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $l) => $l !== ''));
    }

    private function setting(string $key): string
    {
        return trim((string) $this->settings->get($key, ''));
    }

    /**
     * Invoice precision, never the storefront's rounded display. See the
     * class header.
     */
    public static function money(int $fils): string
    {
        return Money::format($fils, Money::minorExponent());
    }

    /**
     * The same figure with no markup, for the text/plain part of the emailed
     * invoice. Money::plain() is markup-free by contract; the HTML variant
     * would arrive in a text body as literal <span> tags.
     */
    public static function moneyPlain(int $fils): string
    {
        return Money::plain($fils, Money::minorExponent());
    }
}
