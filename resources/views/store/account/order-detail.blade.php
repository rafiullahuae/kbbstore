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

{{--
    RECEIPT PRECISION, NOT THE STOREFRONT'S ROUNDED DISPLAY.

    Money::displayDecimals() is 0 on this store, so a bare Money::format()
    ROUNDS to whole dirhams. On a shop tile that is a presentation choice. On
    this page it is a misstatement of three separate kinds at once, all of them
    measured on one real order (subtotal 9040, discount 60, total 8980):

        Subtotal   AED 90       the figure is 40 fils short of what was charged
        TINY     - AED 1        90 - 1 = 90, so the column does not add up
        Total      AED 90       20 fils MORE than the emailed receipt states

    The last one is the one that matters: the customer holds two copies of the
    same receipt and they disagree about the total. This project already
    settled which copy is right — App\Services\Mail\OrderEmailPresenter's
    header says it in its own words, "on a receipt it is a misstatement... a
    receipt may not round", and renders every emailed figure at
    Money::minorExponent(). This page is the customer's own copy of that same
    receipt, so it follows the same rule rather than a different one.

    DELIBERATELY NOT WIDENED HERE: the cart and the checkout ledger
    (partials/checkout/order-block, store/checkout, store/cart-inner). They
    carry the same defect, but a live basket is not a receipt for an order
    already placed, and repainting them changes how every shopping page in the
    store looks. That is a question for the owner, asked separately.

    "Free" for a zero delivery line stays "Free": zero IS what was charged, so
    the word states the truth the figure would. ReceiptFiguresAgreeTest reads
    it as 0 fils and holds it to the emailed AED 0.00.
--}}
@php
    /*
     * The order's own tax record, split the way a receipt reads it: a ROW when
     * the tax was added on top of the figures above it, a NOTE under the total
     * when it is a portion of them. Identical decision to
     * App\Services\Mail\OrderEmailPresenter::totals()/vatNote(), and it reads
     * the same App\Support\OrderTax::recorded() snapshot, so the two copies of
     * one receipt cannot disagree about whether tax was charged or at what rate.
     *
     * The RATE is the order's; only the sentence around the note is the shop's
     * (`vat_label` is wording the owner may reword), which is why {rate} is
     * filled from the snapshot and never from today's settings.
     */
    $taxRecorded = \App\Support\OrderTax::recorded($order);
    $vatRow = null;
    $vatNote = null;

    if ($taxRecorded !== null && $taxRecorded['fils'] !== 0) {
        $rate = (new \App\Support\TaxRule($taxRecorded['rate'], $taxRecorded['basis']))->printableRate();

        if ($taxRecorded['added']) {
            $vatRow = ['label' => 'VAT at ' . $rate . '%', 'fils' => $taxRecorded['fils']];
        } elseif ($taxRecorded['fils'] > 0) {
            $vatNote = [
                'label' => str_replace(
                    '{rate}',
                    $rate,
                    (string) app(\App\Services\SettingsService::class)->get('vat_label', "You're paying VAT ({rate}%)")
                ),
                'fils' => $taxRecorded['fils'],
            ];
        }
    }
@endphp
@section('title', __('store.orders.detail_title', ['number' => $order->order_number]))

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
.kbbod-vatnote{margin-inline-start:auto;max-width:340px;text-align:end;font-size:12.5px;color:var(--muted);padding-top:7px}

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

    /*
     * ONE WIDTH FOR THE WHOLE RECEIPT, decided once from every figure on it.
     *
     * The owner's rule is whole dirhams — "no decimals; if any decimals come,
     * adjust the price" — so this prints AED 220 whenever it truthfully can.
     * It widens only when some figure on this particular receipt carries fils,
     * because rounding one then understates what was charged AND breaks the
     * column's arithmetic AND puts this copy 20 fils away from the emailed one.
     *
     * Every figure is passed in, including the line items and the tax, so the
     * rows cannot print at different widths — a column quoted three ways reads
     * as a fault and cannot be added up by eye.
     */
    $receiptFigures = array_merge(
        [(int) $order->subtotal, (int) $order->discount_total, (int) $order->shipping_total,
         (int) $order->gift_fee, (int) $paymentFee, (int) $order->total,
         (int) ($taxRecorded['fils'] ?? 0)],
        $order->items->flatMap(fn ($i) => [(int) $i->unit_price, (int) $i->total])->all(),
    );
    $receiptDp = Money::receiptDecimals(...$receiptFigures);
    $receiptMoney = static fn (int $fils): string => Money::format($fils, $receiptDp);
@endphp

<div class="acw wide">
  <div class="acw-in">
    <a class="kbbod-back" href="{{ Url::to('/my-account/orders/') }}">&larr; {{ __('store.orders.all_orders') }}</a>

    <div class="kbbod-head">
      <div style="flex:1 1 auto;min-width:0">
        <h1>{{ __('store.orders.order_number', ['number' => $order->order_number]) }}</h1>
        <p class="kbbod-when">
          {{ __('store.orders.placed_on', ['date' => $order->created_at?->format('j F Y') ?? '']) }}
          @if ($items->count()) · {{ trans_choice('store.cart.item_count', $items->count()) }} @endif
        </p>
      </div>
      <span class="kbbod-pill {{ $statusClass }}">{{ \App\Support\OrderStatusLabel::for($status) }}</span>
    </div>

    <div class="kbbod-sec">
      <h2 class="kbbod-h2">{{ __('store.orders.what_you_ordered') }}</h2>
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
              <span class="kbbod-meta">{{ (int) $item->quantity }} × {!! $receiptMoney((int) $item->unit_price) !!}@if ($variant !== '') · {{ $variant }}@endif</span>
            </div>
            <div class="kbbod-linetotal">{!! $receiptMoney((int) $item->total) !!}</div>
          </div>
        @endforeach
      </div>
    </div>

    <div class="kbbod-sec">
      <div class="kbbod-totals">{{-- BOTH LANES' WORK IS IN THIS BLOCK. The
             structure and the precision come from the receipts lane; every
             LABEL comes from the text lane, so an Arabic reader gets this
             column in Arabic. The VAT row's own label is built from the order's
             snapshotted rate and the owner's `vat_label` wording, which is why
             it is not keyed here — it is his sentence, not the shop's.

             ATTACHED TO THE DIV ABOVE, not on a line of its own: a Blade
             comment is removed but the newline after it is not, and this
             column's bytes are pinned by StorefrontEnglishUnchangedTest. --}}
        <div class="kbbod-row"><span>{{ __('store.checkout.subtotal') }}</span><span>{!! $receiptMoney((int) $order->subtotal) !!}</span></div>
        @if ((int) $order->discount_total > 0)
          <div class="kbbod-row"><span>{{ $order->coupon_code ?: __('store.checkout.discount') }}</span><span>&ndash; {!! $receiptMoney((int) $order->discount_total) !!}</span></div>
        @endif
        <div class="kbbod-row">
          <span>{{ __('store.checkout.delivery') }}{{ $order->shipping_method ? ' · ' . $order->shipping_method : '' }}</span>
          <span>@if ((int) $order->shipping_total > 0){!! $receiptMoney((int) $order->shipping_total) !!}@else<span class="kbbod-free">{{ __('store.checkout.free') }}</span>@endif</span>
        </div>
        @if ((int) $order->gift_fee > 0)
          <div class="kbbod-row"><span>{{ __('store.checkout.gift_wrapping') }}</span><span>{!! $receiptMoney((int) $order->gift_fee) !!}</span></div>
        @endif
        @if ($paymentFee > 0)
          <div class="kbbod-row"><span>{{ __('store.checkout.payment_fee', ['method' => $order->paymentLabel()]) }}</span><span>{!! $receiptMoney($paymentFee) !!}</span></div>
        @endif
        {{--
            VAT, ONLY WHERE IT WAS ADDED TO THE FIGURES ABOVE IT.

            Same rule, same source of truth and same labels as
            emails/partials/totals.blade.php, because this is the same receipt.
            App\Support\OrderTax::recorded() reads the rate and basis the ORDER
            snapshotted on the day, never today's settings — an order reprinted
            next year must not restate a tax nobody was charged.

            Measured before this existed: an `exclusive` order of subtotal 9040
            and tax 452 printed Subtotal AED 90.40, Delivery Free, Total
            AED 94.92 — a column with a AED 4.52 hole in it, on the customer's
            own copy of a receipt. The emailed copy printed the row.

            `added` false (inclusive, and the shipped `display` default) keeps
            its figure OUT of the column and under the rule as a note, because
            it is a portion OF the total rather than an addition to it. Putting
            it in the rows is what would stop them summing.
        --}}
        @if ($vatRow !== null)
          <div class="kbbod-row"><span>{{ $vatRow['label'] }}</span><span>{!! $receiptMoney($vatRow['fils']) !!}</span></div>
        @endif
        <div class="kbbod-row is-total"><span>{{ __('store.checkout.total') }}</span><span>{!! $receiptMoney((int) $order->total) !!}</span></div>
      </div>
      @if ($vatNote !== null)
        <p class="kbbod-vatnote">{{ $vatNote['label'] }}: {!! $receiptMoney($vatNote['fils']) !!}</p>
      @endif
    </div>

    <div class="kbbod-sec">
      <h2 class="kbbod-h2">{{ __('store.orders.delivery_and_payment') }}</h2>
      <dl class="kbbod-facts">
        <div class="kbbod-fact">
          <dt>{{ __('store.order_received.address_heading') }}</dt>
          <dd>
            @forelse ($addressLines as $line)
              {{ $line }}@if (! $loop->last)<br>@endif
            @empty
              {{ __('store.order_received.address_unknown') }}
            @endforelse
          </dd>
        </div>
        <div class="kbbod-fact">
          <dt>{{ __('store.order_received.fact_payment') }}</dt>
          <dd>
            {{ $order->paymentLabel() }}
            @if ($order->email)<br>{{ $order->email }}@endif
          </dd>
        </div>
      </dl>

      @if ($order->customer_note)
        <div class="kbbod-note"><b>{{ __('store.order_received.your_note') }}</b>{{ $order->customer_note }}</div>
      @endif

      @if ($order->is_gift)
        <div class="kbbod-note">
          <b>{{ __('store.order_received.gift_wrapped') }}</b>
          @if ($order->gift_note)
            {{ __('store.order_received.gift_note', ['note' => $order->gift_note]) }}
          @else
            {{ __('store.order_received.gift_no_note') }}
          @endif
        </div>
      @endif
    </div>
  </div>
</div>
@endsection
