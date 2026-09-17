{{--
    The order summary on the order-received page.

    Reads the ORDER, never the cart and never the catalogue. Every line carries
    its own name, unit price, quantity and total, written at the moment the
    order was placed, so a product that has since been renamed, unpublished or
    deleted still prints what the shopper actually bought — `product_id` is
    nullable and Product soft-deletes, so `$item->product` is simply null then
    and only the thumbnail falls back.

    Money is integer fils throughout. Money::format() is the only thing that
    turns it into a price; there is no division anywhere in this file.

    THE SHOW/HIDE IS <details>, not script. This page is reached once, straight
    off a payment, and a toggle that depends on JavaScript having loaded is a
    toggle that can strand the shopper with their own order hidden. The first
    few lines are always in the document; only the tail sits inside <details>,
    so with CSS and JS both gone the page degrades to a plain, complete list.
--}}
@php
    use App\Support\Money;

    $items = $order->items;

    // How many lines stay open. Small enough that the actions above stay on a
    // phone screen, large enough that the usual two- or three-line order never
    // sees a toggle at all.
    $openCount = 3;

    $head = $items->take($openCount);
    $tail = $items->slice($openCount);

    // fee_total is the gateway surcharge AND the gift fee added together (see
    // place()). The gift fee has its own column and its own row below, so what
    // is left over is the payment surcharge — shown only when there is one,
    // rather than printing "Payment fee AED0" on every card order.
    $paymentFee = max(0, (int) $order->fee_total - (int) $order->gift_fee);

    /*
     * VAT, read from the ORDER'S OWN RECORD and never recomputed here.
     *
     * This page was the only document in the shop that said nothing about tax.
     * The confirmation email, the invoice and the admin order screen all print
     * it; the receipt the customer is looking at the second they have paid did
     * not, so an exclusive-tax order showed a Total larger than the parts above
     * it with nothing on screen to explain the difference.
     *
     * THE SPLIT IS THE ONE EVERY OTHER DOCUMENT MAKES, and it is not cosmetic.
     * An `exclusive` order was charged the tax ON TOP, so it is a row among the
     * charges. An `inclusive` (or printed-only) order's tax is a portion OF the
     * prices already listed, so it goes UNDER the Total as an "of which" note —
     * putting it above would read as a second charge that was never made. See
     * OrderEmailPresenter::totals() and ::vatNote(), which this mirrors branch
     * for branch so the screen and the emailed receipt for one order cannot
     * word the same fact two ways.
     *
     * THE WORDING IS THE RECEIPT'S, NOT THE INVOICE'S. Both agree on an added
     * row: "VAT at 5%". For the note the invoice says "Includes VAT at 5%",
     * which suits an accountant, and the receipt uses `vat_label` — the
     * shopper's own second-person sentence, the very words the checkout page
     * showed them a moment ago. This is the shopper's copy, so it follows the
     * receipt, and no fourth wording is introduced.
     *
     * NOTHING IS RECOMPUTED FROM TODAY'S SETTINGS once the order carries its
     * own record: both the amount and the rate come out of the snapshot
     * OrderTax reads back, so changing the rate tomorrow cannot alter what this
     * page prints for an order placed today. Only the sentence template is
     * live, and it carries no figure of its own.
     *
     * The two fallbacks are the ones every other document already takes. An
     * imported WooCommerce order has no record but a real `tax_total`, and
     * prints it as the plain row it has always printed. An order placed in the
     * shop's shipped display mode has neither, and falls back to VatDisplay
     * exactly as the checkout page it came from does — there is no snapshot to
     * prefer there, and staying silent is the bug being fixed.
     */
    $taxRecord = \App\Support\OrderTax::recorded($order);

    // Charged ON TOP: belongs above the Total, among the charges.
    $vatRow = null;
    // Contained WITHIN: belongs under the Total, as a note about it.
    $vatNote = null;

    if ($taxRecord !== null) {
        $vatRate = (new \App\Support\TaxRule($taxRecord['rate'], $taxRecord['basis']))->printableRate();

        if ($taxRecord['added']) {
            if ($taxRecord['fils'] !== 0) {
                $vatRow = ['label' => 'VAT at ' . $vatRate . '%', 'fils' => $taxRecord['fils']];
            }
        } elseif ($taxRecord['fils'] > 0) {
            $vatNote = [
                'label' => str_replace(
                    '{rate}',
                    $vatRate,
                    (string) app(\App\Services\SettingsService::class)->get('vat_label', "You're paying VAT ({rate}%)")
                ),
                'fils' => $taxRecord['fils'],
            ];
        }
    } elseif ((int) $order->tax_total !== 0) {
        $vatRow = ['label' => 'VAT', 'fils' => (int) $order->tax_total];
    } else {
        $vatLine = app(\App\Support\VatDisplay::class)->line((int) $order->total);

        if ($vatLine !== null) {
            $vatNote = ['label' => (string) $vatLine['label'], 'fils' => (int) $vatLine['amount']];
        }
    }
@endphp

<div class="co-lines">
    @foreach ($head as $item)
        @include('partials.checkout.received-line', ['item' => $item])
    @endforeach

    @if ($tail->isNotEmpty())
        <details class="co-more">
            <summary>
                <span class="lbl-shut">Show {{ $tail->count() }} more {{ $tail->count() === 1 ? 'item' : 'items' }}</span>
                <span class="lbl-open">Show fewer items</span>
            </summary>
            @foreach ($tail as $item)
                @include('partials.checkout.received-line', ['item' => $item])
            @endforeach
        </details>
    @endif
</div>

<div class="co-totals">
    {{-- Subtotal removed at the owner's request: the line items above already
         show it, and on a phone it pushed the total further down for no gain. --}}

    @if ((int) $order->discount_total > 0)
        <div class="sumrow disc">
            <span>{{ $order->coupon_code ?: 'Discount' }}</span>
            <span>&ndash; {!! Money::format((int) $order->discount_total) !!}</span>
        </div>
    @endif

    <div class="sumrow">
        <span>Delivery{{ $order->shipping_method ? ' · ' . $order->shipping_method : '' }}</span>
        <span>@if ((int) $order->shipping_total > 0){!! Money::format((int) $order->shipping_total) !!}@else<span style="color:var(--green);font-weight:700">Free</span>@endif</span>
    </div>

    @if ((int) $order->gift_fee > 0)
        <div class="sumrow"><span>Gift wrapping</span><span>{!! Money::format((int) $order->gift_fee) !!}</span></div>
    @endif

    @if ($paymentFee > 0)
        <div class="sumrow"><span>{{ $order->paymentLabel() }} fee</span><span>{!! Money::format($paymentFee) !!}</span></div>
    @endif

    @if ($vatRow !== null)
        <div class="sumrow vat"><span>{{ $vatRow['label'] }}</span><span>{!! Money::format($vatRow['fils']) !!}</span></div>
    @endif

    <div class="sumrow tot"><span>Total</span><span>{!! Money::format((int) $order->total) !!}</span></div>

    @if ($vatNote !== null)
        <div class="sumrow vat"><span>{{ $vatNote['label'] }}</span><span>{!! Money::format($vatNote['fils']) !!}</span></div>
    @endif
</div>
