@extends('layouts.store')
@section('title', 'Order #' . ($order->order_number ?? $order->id))

@section('content')
@php use App\Support\Url; use App\Support\Money; @endphp

<div class="acw wide">
  <div class="acw-in">
    <p class="acw-sub"><a href="{{ Url::to('/my-account/orders/') }}">← All orders</a></p>
    <h1>Order #{{ $order->order_number ?? $order->id }}</h1>
    <p class="acw-sub">Placed {{ $order->created_at ?? '' }} · <span class="acw-status">{{ ucfirst((string) ($order->status ?? 'pending')) }}</span></p>

    <div class="acw-orders" style="margin-top:20px">
      @foreach ($items as $item)
        <div class="acw-order" style="cursor:default">
          <div style="flex:1">
            <b>{{ $item->name }}</b>
            @if($item->brand)<span style="display:block;color:var(--muted);font-size:12px">{{ $item->brand }}</span>@endif
          </div>
          <span>Qty {{ $item->quantity }}</span>
          <span class="acw-status">AED {{ number_format(Money::toAed($item->total ?? 0), 2) }}</span>
        </div>
      @endforeach
    </div>

    <div class="acw-orders" style="margin-top:20px;max-width:340px;margin-left:auto">
      <div class="acw-order" style="cursor:default"><span>Subtotal</span><span class="acw-status">AED {{ number_format(Money::toAed($order->subtotal ?? 0), 2) }}</span></div>
      @if(($order->discount_total ?? 0) > 0)
      <div class="acw-order" style="cursor:default"><span>Discount</span><span class="acw-status">-AED {{ number_format(Money::toAed($order->discount_total), 2) }}</span></div>
      @endif
      <div class="acw-order" style="cursor:default"><span>Shipping</span><span class="acw-status">AED {{ number_format(Money::toAed($order->shipping_total ?? 0), 2) }}</span></div>
      @if(($order->fee_total ?? 0) > 0)
      <div class="acw-order" style="cursor:default"><span>Fees</span><span class="acw-status">AED {{ number_format(Money::toAed($order->fee_total), 2) }}</span></div>
      @endif
      <div class="acw-order" style="cursor:default;font-weight:700"><span>Total</span><span class="acw-status">AED {{ number_format(Money::toAed($order->total ?? 0), 2) }}</span></div>
    </div>

    @if($order->shipping_address)
    @php $addr = is_string($order->shipping_address) ? json_decode($order->shipping_address, true) : $order->shipping_address; @endphp
    @if($addr)
    <h2 class="acw-h2">Delivery address</h2>
    <p class="acw-sub">
      {{ $addr['name'] ?? '' }}<br>
      {{ $addr['line1'] ?? '' }}{{ isset($addr['line2']) && $addr['line2'] ? ', ' . $addr['line2'] : '' }}<br>
      {{ $addr['city'] ?? '' }}{{ isset($addr['emirate']) && $addr['emirate'] ? ', ' . $addr['emirate'] : '' }}
    </p>
    @endif
    @endif
  </div>
</div>
@endsection
