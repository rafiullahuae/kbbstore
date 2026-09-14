@extends('layouts.store')
@section('title', 'Track my order')

@section('content')
@php use App\Support\Url; use App\Support\Money; @endphp

@php $ap = app(\App\Services\AccountPanel::class); @endphp
<div class="auth {{ $ap->formClass() }}" style="{{ $ap->cssVariables() }}">
    <div class="auth-grid">
        <div class="authcard">
            <h1>Track my order</h1>
            <p class="lede">Your order number is in your confirmation email.</p>

            <form method="get" action="{{ Url::to('/track-my-order/') }}">
                <div class="fgroup">
                    <x-field name="order" label="Order number" :value="request('order')" />
                    <x-field name="email" label="Email address" type="email"
                             :value="request('email')" icon="mail" autocomplete="email" />
                </div>
                <button class="go" type="submit">Find my order</button>
            </form>

            @if($notFound)
                <p class="lede" style="margin-top:18px;color:var(--sale,#c0392b)">We couldn't find an order matching that number and email. Double-check both and try again.</p>
            @elseif($order)
                <div class="acw-orders" style="margin-top:24px">
                    <div class="acw-order" style="cursor:default">
                        <div style="flex:1">
                            <b>Order #{{ $order->order_number ?? $order->id }}</b>
                            <span style="display:block;color:var(--muted);font-size:12px">Placed {{ $order->created_at ?? '' }}</span>
                        </div>
                        <span class="acw-status">{{ ucfirst((string) ($order->status ?? 'pending')) }}</span>
                    </div>
                    <div class="acw-order" style="cursor:default"><span>Order total</span><span class="acw-status">AED {{ number_format(Money::toAed($order->total ?? 0), 2) }}</span></div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
