{{--
    The customer's own orders, newest first, paginated.

    $orders is a LengthAwarePaginator of the signed-in shopper's orders and
    nothing else — see AccountController::orders(). The previous version took a
    flat limit(50) with no pager, which made a customer's fifty-first order
    unreachable: this list is the only route to the detail page.

    Pagination is rendered by hand rather than through $orders->links(), which
    would emit Laravel's default Tailwind partial into a stylesheet that has no
    Tailwind in it.

    Class prefix is `kbbol-`; the global stylesheet already claims the short
    generic names.
--}}
@extends('layouts.store')
@php use App\Support\Money; use App\Support\Url; @endphp
@section('title', 'Orders · K-Beauty Bliss')

@push('styles')
<style>
.kbbol-list{display:flex;flex-direction:column;gap:9px}
.kbbol-row{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid var(--line-2);border-radius:12px;
    padding:14px 16px;color:inherit;text-decoration:none;transition:.15s}
.kbbol-row:hover{border-color:var(--pink,#E0567B);box-shadow:0 2px 10px rgba(42,34,40,.06)}
.kbbol-id{flex:1 1 auto;min-width:0}
.kbbol-id b{display:block;font-size:14px;font-weight:700;color:var(--ink);overflow-wrap:anywhere}
.kbbol-id span{display:block;margin-top:3px;font-size:11.5px;color:var(--muted);font-weight:600}
.kbbol-total{flex:0 0 auto;font-size:13.5px;font-weight:700;color:var(--ink);white-space:nowrap}
.kbbol-pill{flex:0 0 auto;border-radius:99px;padding:4px 11px;font-size:11px;font-weight:700;white-space:nowrap;
    background:var(--cream);color:var(--ink-2);border:1px solid var(--line-2)}
.kbbol-pill.is-done{background:#EEF8F1;border-color:#BFE0CD;color:#1F7D52}
.kbbol-pill.is-live{background:var(--pink-soft,#FFF1F5);border-color:var(--blush,#F6C9D2);color:var(--pink-deep)}
.kbbol-pill.is-hold{background:#FFF4E5;border-color:#F3DCB8;color:#B26A00}
.kbbol-pill.is-off{background:#FDECEF;border-color:#F6C9D2;color:#B3243F}
.kbbol-chev{flex:0 0 auto;color:var(--muted);font-weight:700}

.kbbol-pager{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:22px}
.kbbol-page{font-size:12.5px;color:var(--muted);font-weight:600;text-align:center;flex:1 1 auto}
.kbbol-step{flex:0 0 auto;border:1px solid var(--line-2);border-radius:10px;padding:9px 15px;font-size:12.5px;
    font-weight:700;color:var(--ink-2);background:#fff;text-decoration:none}
.kbbol-step:hover{border-color:var(--pink);color:var(--pink-deep)}
.kbbol-step.is-off{opacity:.4;pointer-events:none}

@media (max-width:560px){
  .kbbol-row{flex-wrap:wrap;gap:8px 12px}
  .kbbol-id{flex:1 1 100%}
  .kbbol-chev{display:none}
}
</style>
@endpush

@section('content')
<div class="acw wide">
  <div class="acw-in">
    <h1>Orders</h1>
    <p class="acw-sub">Everything you have ordered.</p>

    @if ($orders->isEmpty())
      <p class="acw-empty">Nothing here yet. <a href="{{ Url::to('/shop/') }}">Start shopping</a>.</p>
    @else
      <div class="kbbol-list">
        @foreach ($orders as $order)
          @php
            $status = (string) ($order->status ?: 'pending');
            $statusClass = match (true) {
                in_array($status, ['completed', 'shipped'], true) => 'is-done',
                in_array($status, ['processing', 'paid'], true) => 'is-live',
                in_array($status, ['onhold', 'on-hold', 'pending'], true) => 'is-hold',
                in_array($status, ['cancelled', 'refunded', 'failed'], true) => 'is-off',
                default => '',
            };
          @endphp
          <a class="kbbol-row" href="{{ Url::to('/my-account/orders/' . $order->id) }}">
            <span class="kbbol-id">
              <b>Order #{{ $order->order_number }}</b>
              <span>{{ $order->created_at?->format('j M Y') ?? '' }}@if (isset($order->items_count)) · {{ $order->items_count }} {{ $order->items_count === 1 ? 'item' : 'items' }}@endif</span>
            </span>
            <span class="kbbol-total">{!! Money::format((int) $order->total) !!}</span>
            <span class="kbbol-pill {{ $statusClass }}">{{ ucfirst(str_replace('-', ' ', $status)) }}</span>
            <span class="kbbol-chev">›</span>
          </a>
        @endforeach
      </div>

      @if ($orders->hasPages())
        <nav class="kbbol-pager" aria-label="Orders pages">
          <a class="kbbol-step {{ $orders->onFirstPage() ? 'is-off' : '' }}"
             href="{{ $orders->previousPageUrl() ?: '#' }}"
             @if ($orders->onFirstPage()) aria-disabled="true" tabindex="-1" @endif>&larr; Newer</a>
          <span class="kbbol-page">Page {{ $orders->currentPage() }} of {{ $orders->lastPage() }}</span>
          <a class="kbbol-step {{ $orders->hasMorePages() ? '' : 'is-off' }}"
             href="{{ $orders->nextPageUrl() ?: '#' }}"
             @unless ($orders->hasMorePages()) aria-disabled="true" tabindex="-1" @endunless>Older &rarr;</a>
        </nav>
      @endif
    @endif
  </div>
</div>
@endsection
