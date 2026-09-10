@extends('layouts.store')
@section('title', 'Orders')

@section('content')
@php use App\Support\Url; @endphp
<div class="acw wide">
  <div class="acw-in">
    <h1>Orders</h1><p class="acw-sub">Everything you have ordered.</p>
    @if (empty($orders))
        <p class="acw-empty">Nothing here yet. <a href="{{ Url::to('/shop/') }}">Start shopping</a>.</p>
    @else
        <div class="acw-orders">
            @foreach ($orders as $order)
                <div class="acw-order"><b>#{{ $order->number ?? $order->id }}</b>
                    <span>{{ $order->created_at ?? '' }}</span>
                    <span class="acw-status">{{ ucfirst((string) ($order->status ?? 'pending')) }}</span></div>
            @endforeach
        </div>
    @endif
  </div>
</div>
@endsection
