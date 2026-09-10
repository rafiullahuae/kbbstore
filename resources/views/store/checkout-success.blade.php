@extends('layouts.store')
@php use App\Support\Money; use App\Support\Url; @endphp
@section('bare', '1')
@section('title', 'Order received · K-Beauty Bliss')
@push('styles')@vite('resources/css/kbb/kbb-checkout.css')@endpush

@section('content')
<section class="kbb-checkout">
    <header class="co-head"><div class="in">
        <a class="logo" href="{{ Url::to('/') }}">K-Beauty<span>Bliss</span></a>
        <span class="secure"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7"/></svg> Order received</span>
    </div></header>

    <div style="max-width:640px;margin:0 auto;padding:40px 20px">
        @if ($order)
            <div class="formbox">
                <div class="sec">
                    <h2><span class="n">✓</span> Thank you</h2>
                    <p class="co-lead">Your order <b>#{{ $order->order_number }}</b> is confirmed. A copy is on its way to {{ $order->email }}.</p>
                    <div class="sumrow"><span>Total</span><span class="tot">{!! \App\Support\Money::format($order->total) !!}</span></div>
                    <div class="sumrow"><span>Payment</span><span>{{ $order->payment_method_title ?: $order->payment_method }}</span></div>
                    <div class="sumrow"><span>Delivery</span><span>{{ $order->shipping_method }}</span></div>
                </div>
            </div>
            @php
                // Only for the browser that actually just placed this order —
                // see the comment beside where this session key is set, in
                // CheckoutController::place(). Consumed once so a stale value
                // cannot outlive the order it belongs to.
                $kbbJustPlacedThis = session('kbb_last_order') === $order->order_number;
                if ($kbbJustPlacedThis) { session()->forget('kbb_last_order'); }
            @endphp
            @if ($kbbJustPlacedThis)
            @push('scripts')
            {!! app(\App\Services\MarketingPixels::class)->purchase($order) !!}
            @endpush
            @endif
        @else
            <div class="formbox"><div class="sec"><h2>Order not found</h2><p class="co-lead">We could not find that order.</p></div></div>
        @endif
        <p style="text-align:center;margin-top:22px"><a class="backlink" href="{{ Url::to('/shop/') }}">← Continue shopping</a></p>
    </div>
</section>
@endsection
