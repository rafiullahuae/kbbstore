{{--
    One order, in full.

    EVERY WORD AND NUMBER BELOW COMES OFF THE ORDER, NEVER THE CATALOGUE.
    `order_items` snapshots name, brand, sku, quantity and price at the moment
    the order was placed, precisely so this page still reads correctly after a
    product is renamed, unpublished, trashed or hard-deleted. The single thing
    that consults the catalogue is the thumbnail, and it has a gradient to fall
    back to — `product_id` is nullOnDelete and Product soft-deletes, so
    `$item->product` is simply null in that case and nothing else changes.

    ACCESS is decided in AccountController::orderDetail(), not here. The order
    is fetched through `$customer->orders()`, so this view is never handed an
    order that is not the signed-in shopper's.

    Everything shopper-supplied — the address, the gift message, the order note
    — is printed with {{ }} and never {!! !!}. The only unescaped echoes on the
    page are Money::format(), which builds its own markup and escapes the
    currency symbol itself.

    Class prefix is `kbbod-`. The global stylesheet already claims short
    generic names (`t`, `s`, `go`, `b`) and reusing one broke the layout of the
    order-received page once already.
--}}
@extends('layouts.store')
@php use App\Support\Countries; use App\Support\Gradient; use App\Support\Money; use App\Support\Url; @endphp
@section('title', 'Order #' . $order->order_number . ' · K-Beauty Bliss')

@push('styles')
<style>
.kbbod-back{display:inline-block;font-size:12.5px;font-weight:600;color:var(--pink-deep);margin-bottom:14px}
.kbbod-head{display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:22px}
.kbbod-head h1{flex:1 1 auto;min-width:0;margin:0;font-size:22px;font-weight:800;letter-spacing:-.02em;overflow-wrap:anywhere}
.kbbod-when{margin:4px 0 0;font-size:12.5px;color:var(--muted)}
.kbbod-pill{flex:0 0 auto;border-radius:99px;padding:5px 13px;font-size:11.5px;font-weight:700;letter-spacing:.02em;
    background:var(--cream);color:var(--ink-2);border:1px solid var(--line-2)}
.kbbod-pill.is-done{background:#EEF8F1;border-color:#BFE0CD;color:#1F7D52}
.kbbod-pill.is-live{background:var(--pink-soft,#FFF1F5);border-color:var(--blush,#F6C9D2);color:var(--pink-deep)}
.kbbod-pill.is-hold{background:#FFF4E5;border-color:#F3DCB8;color:#B26A00}
.kbbod-pill.is-off{background:#FDECEF;border-color:#F6C9D2;color:#B3243F}

.kbbod-sec{margin:0 0 26px}
.kbbod-sec:last-child{margin-bottom:0}
.kbbod-h2{font-size:12px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--muted);margin:0 0 11px}

.kbbod-lines{display:flex;flex-direction:column;gap:2px}
.kbbod-line{display:flex;align-items:center;gap:13px;padding:13px 0;border-bottom:1px solid var(--line-2)}
.kbbod-line:last-child{border-bottom:0}
.kbbod-thumb{flex:0 0 auto;position:relative;width:56px;height:56px;border-radius:10px;background-size:cover;
    background-position:center;border:1px solid var(--line-2)}
.kbbod-qty{position:absolute;top:-6px;inset-inline-end:-6px;min-width:20px;height:20px;border-radius:99px;background:var(--ink);
    color:#fff;font-size:11px;font-weight:700;display:grid;place-items:center;padding:0 5px}
.kbbod-name{flex:1 1 auto;min-width:0}
.kbbod-brand{font-size:9.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--pink);font-weight:700}
.kbbod-name b{display:block;font-size:13.5px;font-weight:700;color:var(--ink);line-height:1.35;overflow-wrap:anywhere}
.kbbod-meta{display:block;margin-top:3px;font-size:11.5px;color:var(--muted);font-weight:600}
.kbbod-linetotal{flex:0 0 auto;font-size:13.5px;font-weight:700;color:var(--ink);white-space:nowrap}

.kbbod-totals{margin-inline-start:auto;max-width:340px}
.kbbod-row{display:flex;justify-content:space-between;gap:16px;padding:7px 0;font-size:13px;color:var(--ink-2)}
.kbbod-row.is-total{border-top:1px solid var(--line-2);margin-top:5px;padding-top:11px;font-size:15px;font-weight:800;color:var(--ink)}
.kbbod-free{color:#1F7D52;font-weight:700}

.kbbod-facts{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.kbbod-fact dt{font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:var(--muted);font-weight:700;margin-bottom:4px}
.kbbod-fact dd{margin:0;font-size:13px;color:var(--ink-2);line-height:1.55;overflow-wrap:anywhere}

.kbbod-note{background:var(--pink-soft,#FFF1F5);border-radius:12px;padding:12px 14px;margin-top:14px;
    font-size:12.5px;line-height:1.55;color:var(--ink-2)}
.kbbod-note b{display:block;color:var(--ink);margin-bottom:3px}

@media (max-width:560px){
  .kbbod-facts{grid-template-columns:1fr}
  .kbbod-totals{max-width:none;margin-inline-start:0}
  .kbbod-thumb{width:48px;height:48px}
  .kbbod-line{gap:11px}
}
</style>
@endpush

@section('content')
@php
    $status = (string) ($order->status ?: 'pending');
    $statusClass = match (true) {
        in_array($status, ['completed', 'shipped'], true) => 'is-done',
        in_array($status, ['processing', 'paid'], true) => 'is-live',
        in_array($status, ['onhold', 'on-hold', 'pending'], true) => 'is-hold',
        in_array($status, ['cancelled', 'refunded', 'failed'], true) => 'is-off',
        default => '',
    };

    // The keys checkout actually writes (see CheckoutController::place) —
    // first_name / last_name / line1 / city / state / country / phone. The
    // previous version of this page read 'name', 'line2' and 'emirate', none
    // of which are ever written, so the recipient's name rendered blank on
    // every order that had one.
    $ship = is_array($order->shipping_address) ? $order->shipping_address : [];
    $addressLines = array_values(array_filter([
        trim(($ship['first_name'] ?? '') . ' ' . ($ship['last_name'] ?? '')),
        $ship['company'] ?? null,
        $ship['line1'] ?? null,
        $ship['line2'] ?? null,
        trim(implode(', ', array_filter([$ship['city'] ?? null, $ship['state'] ?? null, $ship['postcode'] ?? null]))),
        Countries::NAMES[strtoupper((string) ($ship['country'] ?? ''))] ?? ($ship['country'] ?? null),
        $ship['phone'] ?? null,
    ], fn ($line) => trim((string) $line) !== ''));

    // fee_total carries the gateway surcharge AND the gift fee together (see
    // CheckoutController::place). Gift wrapping has its own row below, so what
    // is left is the payment fee — shown only when there is one.
    $paymentFee = max(0, (int) $order->fee_total - (int) $order->gift_fee);
@endphp

<div class="acw wide">
  <div class="acw-in">
    <a class="kbbod-back" href="{{ Url::to('/my-account/orders/') }}">&larr; All orders</a>

    <div class="kbbod-head">
      <div style="flex:1 1 auto;min-width:0">
        <h1>Order #{{ $order->order_number }}</h1>
        <p class="kbbod-when">
          Placed {{ $order->created_at?->format('j F Y') ?? '' }}
          @if ($items->count()) · {{ $items->count() }} {{ $items->count() === 1 ? 'item' : 'items' }} @endif
        </p>
      </div>
      <span class="kbbod-pill {{ $statusClass }}">{{ ucfirst(str_replace('-', ' ', $status)) }}</span>
    </div>

    <div class="kbbod-sec">
      <h2 class="kbbod-h2">What you ordered</h2>
      <div class="kbbod-lines">
        @foreach ($items as $item)
          @php
            // The one thing on this page that looks at the catalogue, and the
            // one thing that degrades when the product is gone.
            $image = $item->product?->image;
            $thumb = $image
                ? "background-image:url('" . e($image) . "')"
                : 'background:' . Gradient::for((string) ($item->brand ?? '') . (string) $item->name);
            $variant = is_array($item->variant_attributes)
                ? implode(' · ', array_filter(array_map('strval', $item->variant_attributes)))
                : '';
          @endphp
          <div class="kbbod-line">
            <div class="kbbod-thumb" style="{{ $thumb }}"><span class="kbbod-qty">{{ (int) $item->quantity }}</span></div>
            <div class="kbbod-name">
              @if ($item->brand)<div class="kbbod-brand">{{ $item->brand }}</div>@endif
              <b>{{ $item->name }}</b>
              <span class="kbbod-meta">{{ (int) $item->quantity }} × {!! Money::format((int) $item->unit_price) !!}@if ($variant !== '') · {{ $variant }}@endif</span>
            </div>
            <div class="kbbod-linetotal">{!! Money::format((int) $item->total) !!}</div>
          </div>
        @endforeach
      </div>
    </div>

    <div class="kbbod-sec">
      <div class="kbbod-totals">
        <div class="kbbod-row"><span>Subtotal</span><span>{!! Money::format((int) $order->subtotal) !!}</span></div>
        @if ((int) $order->discount_total > 0)
          <div class="kbbod-row"><span>{{ $order->coupon_code ?: 'Discount' }}</span><span>&ndash; {!! Money::format((int) $order->discount_total) !!}</span></div>
        @endif
        <div class="kbbod-row">
          <span>Delivery{{ $order->shipping_method ? ' · ' . $order->shipping_method : '' }}</span>
          <span>@if ((int) $order->shipping_total > 0){!! Money::format((int) $order->shipping_total) !!}@else<span class="kbbod-free">Free</span>@endif</span>
        </div>
        @if ((int) $order->gift_fee > 0)
          <div class="kbbod-row"><span>Gift wrapping</span><span>{!! Money::format((int) $order->gift_fee) !!}</span></div>
        @endif
        @if ($paymentFee > 0)
          <div class="kbbod-row"><span>{{ $order->paymentLabel() }} fee</span><span>{!! Money::format($paymentFee) !!}</span></div>
        @endif
        <div class="kbbod-row is-total"><span>Total</span><span>{!! Money::format((int) $order->total) !!}</span></div>
      </div>
    </div>

    <div class="kbbod-sec">
      <h2 class="kbbod-h2">Delivery &amp; payment</h2>
      <dl class="kbbod-facts">
        <div class="kbbod-fact">
          <dt>Delivering to</dt>
          <dd>
            @forelse ($addressLines as $line)
              {{ $line }}@if (! $loop->last)<br>@endif
            @empty
              We will confirm your delivery address by email.
            @endforelse
          </dd>
        </div>
        <div class="kbbod-fact">
          <dt>Payment</dt>
          <dd>
            {{ $order->paymentLabel() }}
            @if ($order->email)<br>{{ $order->email }}@endif
          </dd>
        </div>
      </dl>

      @if ($order->customer_note)
        <div class="kbbod-note"><b>Your note</b>{{ $order->customer_note }}</div>
      @endif

      @if ($order->is_gift)
        <div class="kbbod-note">
          <b>Gift wrapped 🎁</b>
          @if ($order->gift_note)
            “{{ $order->gift_note }}” — printed on the gift card.
          @else
            This order is wrapped as a gift.
          @endif
        </div>
      @endif
    </div>
  </div>
</div>
@endsection
