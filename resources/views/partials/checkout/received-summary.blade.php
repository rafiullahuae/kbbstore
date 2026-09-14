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

    <div class="sumrow tot"><span>Total</span><span>{!! Money::format((int) $order->total) !!}</span></div>
</div>
