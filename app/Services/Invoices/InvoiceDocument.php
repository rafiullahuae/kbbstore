<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Order;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\StoreTime;

/**
 * One order, turned into the exact strings its four printable documents print.
 *
 * The same argument OrderEmailPresenter makes, for the same reason: the invoice,
 * the packing slip, the delivery note and the dispatch label have to agree about
 * what the order contained and where it is going, and a figure worked out twice
 * is a figure that can disagree with itself. Everything is decided once, here,
 * and every template renders this array. The label naming a different door from
 * the delivery note is not a possible bug, because there is one `shipTo`.
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
 * ── EVERY TAX FIGURE ON THIS DOCUMENT COMES OFF THE ORDER ───────────────────
 *
 * Nothing in this class asks the settings table what the tax rate is. D-64 —
 * "VAT is a display line, never charged, never stored" — was overturned by the
 * owner on 2026-09-16 and App\Support\VatDisplay's header records that in his
 * own words. What replaced it is a rate, a basis and an amount SNAPSHOTTED
 * onto the order when it is placed, read back by App\Support\OrderTax, and
 * read back by nothing else.
 *
 * That is the whole design and it has one consequence worth stating: an order
 * that recorded no tax gets no tax figure printed. Not a computed one, not a
 * remembered one, not a backfilled one. See vatNote() for why each of those
 * three was rejected. The three orders in that position are the pre-engine
 * ones, the ones placed while the shop sits in the shipped `display` mode, and
 * imported WooCommerce orders — and the last of those is the one exception
 * that proves the rule, because it carries a real `tax_total` already inside
 * `total`, which totals() prints as its own authoritative row.
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
    ) {}

    /**
     * Everything both documents need.
     *
     * @return array<string, mixed>
     */
    public function present(Order $order): array
    {
        $order->loadMissing('items');

        /*
         * ONE WIDTH FOR THE WHOLE DOCUMENT, decided once and handed to every
         * figure on it — the same call the emailed receipt and the customer's
         * on-screen copy make about the same order, so an invoice and a
         * receipt for one order cannot state the money two ways. See
         * OrderEmailPresenter::ledgerWidth() and Money::receiptDecimals().
         */
        $w = \App\Services\Mail\OrderEmailPresenter::ledgerWidth($order);

        $invoiceNumber = $order->invoice_number === null ? null : (int) $order->invoice_number;

        return [
            'orderNumber' => (string) ($order->order_number ?: $order->id),
            'orderStatus' => (string) $order->status,
            'invoiceNumber' => $invoiceNumber,
            'invoiceReference' => $invoiceNumber === null ? '' : InvoiceNumbers::format($invoiceNumber),
            /*
             * Dated on the SHOP'S clock, not on UTC.
             *
             * These were `->format('j F Y')` on the Eloquent cast, and that
             * cast is in `config('app.timezone')`, which is UTC. An order
             * placed at 01:30 in Dubai is stored as 21:30 the previous day, so
             * its invoice — the document the customer keeps and the tax
             * authority may read — was dated a day early. StoreTime converts
             * the stored instant for display; it does not reinterpret it, and
             * there is a test pinning that distinction.
             */
            'invoicedAt' => StoreTime::formatDate($order->invoiced_at),
            'placedAt' => StoreTime::formatDate($order->created_at),
            'paidAt' => StoreTime::formatDate($order->paid_at),

            'seller' => $this->seller(),
            'billTo' => $this->address($order->billing_address),
            'shipTo' => $this->address($order->shipping_address ?: $order->billing_address),
            /*
             * The same address with its phone line left off, and the number on
             * its own beside it.
             *
             * For the dispatch label, where the phone is not one more line of
             * an address but the single field a driver acts on. address() puts
             * it last, in the same size as the street, which is right on an
             * invoice and wrong on a label — and printing the block as-is AND a
             * prominent Tel line puts the number on the parcel twice, which
             * reads as two different numbers to anybody in a hurry.
             *
             * The number prefers the DELIVERY address's own phone and falls
             * back to the order's. Those differ exactly when somebody sends a
             * gift: the order carries the buyer's number and the shipping
             * address carries the recipient's, and it is the recipient who is
             * standing behind the door.
             */
            'shipToPostal' => $this->address($order->shipping_address ?: $order->billing_address, false),
            'shipPhone' => $this->shipPhone($order),
            'sameAddress' => $this->address($order->billing_address) === $this->address($order->shipping_address ?: $order->billing_address),

            'email' => trim((string) $order->email),
            'phone' => trim((string) $order->phone),

            // What this document calls itself. See docType() — it is the one
            // claim on an invoice that a developer may not settle alone.
            'docType' => $this->docType($order),

            'items' => $this->items($order, $w),
            'itemCount' => (int) $order->items->sum('quantity'),
            'totals' => $this->totals($order, $w),
            'totalFils' => (int) $order->total,
            'totalHtml' => self::money((int) $order->total, $w),
            'totalPlain' => self::moneyPlain((int) $order->total, $w),
            'vatNote' => $this->vatNote($order, $w),

            'paymentLabel' => $order->paymentLabel(),

            /*
             * WAS THIS ORDER'S MONEY COLLECTED? — not "did a provider confirm
             * it", which is the different question `paid_at` answers.
             *
             * THE BUG. This read `$order->paid_at !== null`. PaymentCapturer
             * settles a cash-on-delivery order by writing `captured_at` and
             * `captured_total` and deliberately NOT `paid_at`, because on COD
             * there is no provider and nothing was ever authorised — the note
             * on App\Services\Payments\Gateways\CashOnDelivery says so in as
             * many words. So a COD order whose cash the courier had handed
             * over, which the operator had captured, and which the refund
             * engine would let you refund in full, printed an invoice with no
             * Paid stamp on it. The customer was handed a document saying the
             * shop had not been paid, by the shop, after paying.
             *
             * WHY THIS IS NOT FIXED BY SETTING `paid_at` ON COD CAPTURE. That
             * column is a claim about a provider and five other things read it
             * as one: PaymentConfirmer's idempotency guard (a non-null
             * `paid_at` is how a replayed webhook is recognised and refused),
             * PaymentCapturer's own not-authorised check, PaymentRefunder's
             * refundable ceiling, the order-invoice email's "Paid ... on
             * <date>" line, and the order detail screen's payment note.
             * Writing it on COD would make a replayed webhook look handled and
             * would have every one of those read "a provider confirmed this"
             * about an order no provider ever saw. The invoice's question is
             * narrower and is answerable from columns that already mean
             * exactly it.
             *
             * `captured_at` IS THE RIGHT COLUMN AND MEANS THE FULL AMOUNT.
             * PaymentCapturer captures `(int) $order->total` and nothing else
             * — there is no partial capture in this application — and it
             * releases `captured_at` back to null when the provider call
             * fails, precisely so that a non-null value is never a lie. So
             * `captured_at !== null` is "the whole of this order's money was
             * collected", which is what a Paid stamp asserts.
             *
             * `paid_at` stays in the test because the two are not redundant:
             * a card order authorised and confirmed but not yet captured has
             * `paid_at` and no `captured_at`, and its invoice said Paid before
             * this change and still does. Nothing that used to stamp Paid
             * stops doing so; COD starts.
             *
             * `paidAt` above is deliberately NOT widened to match. It is a
             * date labelled "Paid ... on" in the invoice email, and the date a
             * COD order was captured is the date the shop marked the cash
             * received, which is not always the day the courier took it. An
             * empty date is less wrong than a confident wrong one, and
             * printing the collection date is an owner's call, not this
             * method's.
             */
            'paid' => self::moneyCollected($order),
            'codToCollect' => $this->codToCollect($order, $w),
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
     * What the document calls itself, at the top of the page.
     *
     * ── WHY THIS IS NOT A LITERAL ANY MORE ──────────────────────────────────
     *
     * Both templates were headed "Tax Invoice", unconditionally, on every
     * order. While `tax_total` was always 0 and the VAT figure was a display
     * note under the Total, that was an empty phrase on a document that made no
     * tax claim anywhere else. App\Support\OrderTax ended that: one invoice is
     * now three different documents, and only two of them state a tax that was
     * actually charged or actually contained.
     *
     * ── THE DECISION THIS METHOD DOES NOT MAKE ──────────────────────────────
     *
     * "Tax Invoice" is a term of art in the UAE and the wider GCC and it
     * usually implies a TRN. What a VAT-registered shop's invoice must be
     * headed, whether a zero-rated or out-of-scope sale still takes that
     * heading, and whether a "Simplified Tax Invoice" is what this shop issues
     * below the threshold, are questions for the owner and his accountant. They
     * are not questions a developer may answer by picking a phrase, and no tax
     * document convention is invented here.
     *
     * `invoice_doctype` is where that answer goes. Blank by default, printed
     * verbatim when filled in, and it wins in every state — including the
     * untaxed one, because the person who asked an accountant knows something
     * this code does not. It sits beside `invoice_trn`, `invoice_address` and
     * `invoice_footer`, the other seller-identity rows this class reads and no
     * screen yet writes.
     *
     * ── AND THE DEFAULT, WHICH IS RESTRAINT ─────────────────────────────────
     *
     * Until he answers, the document claims only what is on it. The stronger
     * heading needs BOTH halves of what it asserts:
     *
     *   tax that was really charged or really contained  — a recorded basis of
     *       `inclusive` or `exclusive` with a figure above zero, or an imported
     *       WooCommerce order carrying its own `tax_total`. NOT `flat`, which
     *       is printed and never charged, and not the shipped display mode,
     *       where `tax_basis` is NULL and nothing was taken.
     *
     *   a registration number under the seller's name — `invoice_trn`, which
     *       ships blank. A document headed as a tax document by a seller who
     *       has stated no registration number is a claim with nothing behind
     *       it, and this project prints none of those.
     *
     * Anything short of both is headed "Invoice", which is true of every
     * invoice ever issued and is what the document is either way. The VAT note
     * and the VAT row are untouched by this method: the figures are stated
     * exactly as before, whatever the heading says.
     */
    public function docType(Order $order): string
    {
        $chosen = $this->setting('invoice_doctype');

        if ($chosen !== '') {
            return $chosen;
        }

        return $this->statesChargedTax($order) && $this->setting('invoice_trn') !== ''
            ? 'Tax Invoice'
            : 'Invoice';
    }

    /**
     * Does this document state a tax that was actually charged or contained?
     *
     * The same two branches totals() and vatNote() take, asked as one question.
     * `flat` answers false by design — TaxRule's header is explicit that it
     * charges nothing and contains nothing, and the screen offers it as
     * "Printed only — charges nothing".
     */
    private function statesChargedTax(Order $order): bool
    {
        $recorded = \App\Support\OrderTax::recorded($order);

        if ($recorded !== null) {
            return $recorded['basis'] !== \App\Support\TaxRule::FLAT && $recorded['fils'] > 0;
        }

        // No record of its own: an order placed before the tax engine, or
        // placed while the shop is in display mode. A non-zero `tax_total` on
        // one of those is an imported WooCommerce order's real tax, already
        // inside `total` and printed as an authoritative row above.
        return (int) $order->tax_total !== 0;
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

            $out[] = [
                'name' => trim((string) $item->name) !== '' ? trim((string) $item->name) : 'Item',
                'brand' => trim((string) $item->brand),
                'sku' => trim((string) $item->sku),
                'variant' => implode(', ', $variant),
                'quantity' => (int) $item->quantity,
                'unitFils' => (int) $item->unit_price,
                'unitHtml' => self::money((int) $item->unit_price, $w),
                'unitPlain' => self::moneyPlain((int) $item->unit_price, $w),
                'lineFils' => (int) $item->total,
                'lineHtml' => self::money((int) $item->total, $w),
                'linePlain' => self::moneyPlain((int) $item->total, $w),
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
    private function totals(Order $order, ?int $w = null): array
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

        /*
         * VAT AS A ROW — only when it was ADDED to the figures above it.
         *
         * This column of figures has to sum to Total. An `exclusive` order was
         * charged the tax on top, so it belongs here; an `inclusive` order's
         * tax is a portion of the prices already listed, so putting it here
         * would make the column add up to more than was charged, and it goes
         * under the Total as an "of which" note instead (see vatNote()).
         *
         * An order with NO tax record — everything placed before this lane, and
         * everything placed while the shop is in display mode — takes the
         * branch it always took: an imported WooCommerce order carrying a real
         * `tax_total` prints it as a row, exactly as before.
         */
        $taxRecord = \App\Support\OrderTax::recorded($order);

        if ($taxRecord === null) {
            if ((int) $order->tax_total !== 0) {
                // An imported order carrying real tax. Already inside `total`.
                $rows[] = ['VAT', (int) $order->tax_total, false];
            }
        } elseif ($taxRecord['added'] && $taxRecord['fils'] !== 0) {
            $rows[] = ['VAT at ' . (new \App\Support\TaxRule($taxRecord['rate'], $taxRecord['basis']))->printableRate() . '%', $taxRecord['fils'], false];
        }

        $rows[] = ['Total', (int) $order->total, true];

        return array_map(static fn (array $row) => [
            'label' => $row[0],
            'fils' => $row[1],
            'html' => self::money($row[1], $w),
            'plain' => self::moneyPlain($row[1], $w),
            'strong' => $row[2],
        ], $rows);
    }

    /**
     * The "of which VAT" line printed under the total, or null.
     *
     * ── IT IS THE ORDER'S OWN RECORD OR IT IS NOTHING — LANE DU ─────────────
     *
     * This method had two halves. The first read the order's snapshot. The
     * second, reached by every order that has no snapshot, asked VatDisplay
     * LIVE:
     *
     *     $line = $this->vat->line((int) $order->total);
     *
     * That second half is gone. The argument for removing it rather than
     * leaving it, freezing it later, or backfilling a rate for the orders that
     * took it:
     *
     *   1. IT MADE A FILED DOCUMENT CHANGE ITS OWN TAX FIGURE. Every order
     *      placed before the tax engine, and every order placed while the shop
     *      sits in the shipped `display` mode, reached it. Raise the global
     *      `vat_rate` from 5 to 20 and the note on last year's invoice reprints
     *      as "Includes VAT at 20% — AED 20.00" where it had read "Includes VAT
     *      at 5% — AED 5.71". No money moves; `total` is a column and stays
     *      what was charged. A number on a document somebody filed moves,
     *      silently, and that is the whole of the harm.
     *
     *   2. BACKFILLING IS NOT AVAILABLE, BECAUSE THE RATE IS NOT RECOVERABLE.
     *      `settings` is `key, value, autoload, timestamps` — one row per key,
     *      overwritten in place, with no history table anywhere in the schema.
     *      Nothing records what `vat_rate` held on the day any past order was
     *      placed. Writing today's rate onto yesterday's order would not be a
     *      backfill, it would be a fabrication carrying a migration's
     *      authority, and TaxEngineTest already pins that we do not do it.
     *
     *   3. FREEZING AT FIRST RENDER BUYS A WEAKER GUARANTEE THAT LOOKS LIKE A
     *      STRONGER ONE. An order placed in January, a rate edited in February
     *      and an invoice first opened in March would freeze MARCH'S rate for
     *      good: permanently wrong, now permanently unfixable, and wearing
     *      every appearance of being authoritative. It also means a GET that
     *      renders a document writes to the orders table, and a preview would
     *      spend the one freeze the order gets.
     *
     *   4. THE NOTE WAS ALREADY CONTRADICTING THE DOCUMENT AROUND IT. For an
     *      order with no record, statesChargedTax() is false, so docType() is
     *      "Invoice" and not "Tax Invoice" — the document declines, in its own
     *      heading, to be a tax document. Printing a VAT rate and a VAT amount
     *      underneath that heading states a tax on a paper that says it is not
     *      stating one. Saying nothing is the coherent answer.
     *
     *   5. THE OWNER HAS A SUPPORTED WAY TO GET THE NOTE BACK, AND IT IS BETTER
     *      THAN THE FALLBACK WAS. Store -> Ecommerce -> Tax: set `tax_mode` to
     *      `live` with an `inclusive` basis. An inclusive rule adds nothing —
     *      VatDisplay::quote() returns `added` false and `total` equal to the
     *      base — so not one order total changes by a fil, and from that moment
     *      every order records its own rate, basis and amount, this note prints
     *      from that snapshot, and the invoice earns the "Tax Invoice" heading
     *      it was previously only half claiming. A truthful permanent note is
     *      one switch away; an untruthful one is not worth keeping meanwhile.
     *
     * So: null whenever the order has no tax record of its own, and otherwise
     * null in the cases that were always null —
     *
     *   exclusive — already printed as a row above; a second figure under the
     *               total would be the same tax stated twice;
     *   a recorded figure that rounds to zero.
     *
     * An imported WooCommerce order's own `tax_total` is untouched by all of
     * this: it has no rate beside it, so it has no record, totals() prints it
     * as a real row above the Total exactly as before, and this method — which
     * must not restate it as a second, different number — returns null.
     *
     * @return array{label:string,fils:int,html:string,plain:string,trn:string}|null
     */
    private function vatNote(Order $order, ?int $w = null): ?array
    {
        $taxRecord = \App\Support\OrderTax::recorded($order);

        if ($taxRecord === null || $taxRecord['added'] || $taxRecord['fils'] <= 0) {
            return null;
        }

        $rate = (new \App\Support\TaxRule($taxRecord['rate'], $taxRecord['basis']))->printableRate();

        return [
            'label' => 'Includes VAT at ' . $rate . '%',
            'fils' => $taxRecord['fils'],
            'html' => self::money($taxRecord['fils'], $w),
            'plain' => self::moneyPlain($taxRecord['fils'], $w),
            'trn' => $this->setting('invoice_trn'),
        ];
    }

    /**
     * The amount a cash-on-delivery driver has to come back with, or null.
     *
     * ── THE ONE FIGURE THAT IS ALLOWED ONTO A DISPATCH LABEL ────────────────
     *
     * The label goes on the outside of the parcel and the delivery note goes
     * inside it, and neither carries a price — that is the whole of what makes
     * them safe to send with a gift. This is the single exception, and it is
     * not a loosening of the rule so much as the reason the rule has an edge:
     * on a COD order the driver is collecting money at the door, and a driver
     * who does not know the amount either asks the customer what they owe or
     * brings back the wrong sum. Withholding it does not protect anybody; it
     * just moves the mistake to the doorstep.
     *
     * So it is printed when BOTH halves hold, and never otherwise:
     *
     *   the order is cash on delivery — `payment_method` is exactly `cod`,
     *       which is this app's gateway id (GatewayRegistry) and also the id
     *       WooCommerce used, so imported orders answer correctly too. A
     *       card-paid or Tabby order is not COD however its title reads;
     *
     *   nothing has been collected yet — `paid_at` is null. A COD order that
     *       has already been settled (paid in the shop, or a reattempted
     *       delivery) must not send a driver out to collect a second time.
     *
     * Anything else returns null and the documents print no money at all. A
     * gift bought on a card therefore still arrives with no figure anywhere on
     * it, which is the case the packing slip was built for in the first place.
     *
     * The figure is `total` — what was charged — at invoice precision, for the
     * same reason every other figure on these documents is: a driver holding a
     * label that says AED 474 and a customer holding an emailed receipt that
     * says AED 473.50 have a disagreement at the door over 50 fils.
     *
     * @return array{fils:int,html:string,plain:string}|null
     */
    /**
     * Was the whole of this order's money actually collected?
     *
     * Not "did a payment provider confirm it", which is the different and
     * narrower question `paid_at` answers, and the distinction is the reason
     * this method exists at all rather than five call sites each testing a
     * column. Both the invoice's Paid stamp and the dispatch label's
     * collect-on-delivery box ask THIS question; they had drifted to two
     * different answers within one file.
     *
     * WHY `paid_at` ALONE WAS WRONG. PaymentCapturer settles a cash-on-delivery
     * order by writing `captured_at` and `captured_total` and deliberately NOT
     * `paid_at` — the note on App\Services\Payments\Gateways\CashOnDelivery
     * says so in as many words, because on COD there is no provider and nothing
     * was ever authorised. So a COD order whose cash had been handed over,
     * captured in the console, and which the refund engine would let you refund
     * in full, printed an invoice with no Paid stamp: a document from the shop
     * telling a customer who had just paid that the shop had not been paid.
     *
     * WHY `paid_at` IS NOT SIMPLY SET ON COD CAPTURE INSTEAD. That column is a
     * claim about a provider and five other things read it as one:
     * PaymentConfirmer's idempotency guard (a non-null `paid_at` is how a
     * replayed webhook is recognised and refused), PaymentCapturer's own
     * not-authorised check, PaymentRefunder's refundable ceiling, the invoice
     * email's "Paid ... on <date>" line, and the order detail screen's payment
     * note. Writing it on COD would make a replayed webhook look handled, and
     * would have every one of those read "a provider confirmed this" about an
     * order no provider ever saw.
     *
     * WHY `captured_at` MEANS THE FULL AMOUNT. PaymentCapturer captures
     * `(int) $order->total` and nothing else — there is no partial capture in
     * this application — and it releases `captured_at` back to null when the
     * provider call fails, precisely so a non-null value is never a lie.
     *
     * Both columns are tested, because they are not redundant: a card order
     * authorised and confirmed but not yet captured has `paid_at` and no
     * `captured_at`, and its invoice said Paid before any of this and still
     * does. Nothing that used to stamp Paid stops doing so; COD starts.
     *
     * The `paidAt` DATE is deliberately not widened to match. It is labelled
     * "Paid ... on" and the date a COD order was captured is the date the shop
     * marked the cash received, which is not always the day the courier took
     * it. An empty date is less wrong than a confident wrong one.
     */
    private static function moneyCollected(Order $order): bool
    {
        return $order->paid_at !== null || $order->captured_at !== null;
    }

    private function codToCollect(Order $order, ?int $w = null): ?array
    {
        /*
         * `paid_at` WAS THE WRONG COLUMN HERE, AND WRONG IN THE WAY THAT COSTS
         * MONEY AT THE DOOR. This guard was written as `$order->paid_at !== null`
         * — a test that on a cash-on-delivery order can never be true, because
         * PaymentCapturer deliberately never writes `paid_at` for COD (there is
         * no provider, so there is nothing to claim one confirmed). The guard
         * therefore excluded nothing: a COD order whose cash the courier had
         * already handed over, and which the operator had captured in the
         * console, still printed "COLLECT AED 215.50" on its dispatch label.
         * The driver collects it twice, or argues with a customer holding a
         * receipt.
         *
         * Two lanes reached this file in the same round from opposite ends —
         * one making the invoice's Paid stamp honest on COD, one adding the
         * label — and each wrote its own reading of "has this been collected".
         * They are one question, so they are now one method: moneyCollected().
         * Its long note explains why `captured_at` is the column that means it
         * and why `paid_at` must not be widened to match.
         */
        if (trim((string) $order->payment_method) !== 'cod' || self::moneyCollected($order)) {
            return null;
        }

        return [
            'fils' => (int) $order->total,
            'html' => self::money((int) $order->total, $w),
            'plain' => self::moneyPlain((int) $order->total, $w),
        ];
    }

    /**
     * A postal address as lines, empty parts dropped.
     *
     * @param  mixed  $address
     * @return list<string>
     */
    private function address($address, bool $withPhone = true): array
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
            $withPhone ? (string) ($address['phone'] ?? '') : '',
        ];

        return array_values(array_filter(array_map('trim', $lines), static fn (string $l) => $l !== ''));
    }

    /**
     * The number to ring about this delivery.
     *
     * The delivery address's own phone first, the order's second. See the
     * shipToPostal note in present() for why the distinction matters on a gift.
     */
    private function shipPhone(Order $order): string
    {
        $address = $order->shipping_address ?: $order->billing_address;

        $onAddress = is_array($address) ? trim((string) ($address['phone'] ?? '')) : '';

        return $onAddress !== '' ? $onAddress : trim((string) $order->phone);
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
     * Invoice precision, never the storefront's rounded display.
     *
     * The width is now the ORDER's rather than the currency's flat maximum —
     * OrderEmailPresenter::ledgerWidth(), the same authority the emailed
     * receipt and the customer's own on-screen copies use, so an invoice
     * cannot state a figure differently from the receipt for the same order.
     *
     * The principle in this class's header is unchanged: an invoice may not
     * round. What changed is that on an order whose every figure is a whole
     * dirham there is nothing left to round — see App\Support\WholeDirhams and
     * Money::receiptDecimals(). An order that does carry fils widens the
     * whole document back to full precision automatically.
     *
     * The default stays minorExponent(), so a caller that passes no width
     * behaves exactly as it did.
     */
    public static function money(int $fils, ?int $decimals = null): string
    {
        return Money::format($fils, $decimals ?? Money::minorExponent());
    }

    /**
     * The same figure with no markup, for the text/plain part of the emailed
     * invoice. Money::plain() is markup-free by contract; the HTML variant
     * would arrive in a text body as literal <span> tags.
     */
    public static function moneyPlain(int $fils, ?int $decimals = null): string
    {
        return Money::plain($fils, $decimals ?? Money::minorExponent());
    }
}
