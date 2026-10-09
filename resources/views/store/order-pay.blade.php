{{--
    "Complete your order" — Lane RL. Opened from the button in the reminder and
    payment-failed emails: one unpaid order, paid with the methods the checkout
    already offers, through the same gateway code. See
    App\Http\Controllers\Store\OrderPayController for what the signed link may
    and may not do.

    Nothing private is printed: no address, no email — only what the email
    itself already showed (number, items, total).

    $order is null for a link that is forged, expired or for another order: the
    same 404 block for all three.
--}}
@extends('layouts.store')
@php use App\Support\Money; use App\Support\Url; @endphp
@section('title', __('store.order_pay.page_title'))
@section('no-trust-strip', '1')

@push('styles')
<style>
.kbbop{max-width:560px;margin:0 auto;padding:28px 16px 48px}
.kbbop h1{font-size:24px;line-height:1.25;margin:0 0 8px;color:var(--ink)}
.kbbop .lede{font-size:14px;line-height:1.55;color:var(--ink-2);margin:0 0 18px}
.kbbop-card{border:1px solid var(--line-2);border-radius:14px;background:#fff;overflow:hidden;margin-bottom:16px}
.kbbop-head{padding:12px 16px;background:var(--cream);font-size:11.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
.kbbop-row{display:flex;justify-content:space-between;gap:14px;padding:10px 16px;border-top:1px solid var(--line-2);font-size:13.5px;color:var(--ink-2)}
.kbbop-row:first-of-type{border-top:0}
.kbbop-row b{color:var(--ink);font-weight:700;overflow-wrap:anywhere}
.kbbop-row span:last-child{flex:0 0 auto;text-align:end;font-weight:700;color:var(--ink)}
.kbbop-row small{display:block;color:var(--muted);font-size:12px;margin-top:2px}
.kbbop-total{display:flex;justify-content:space-between;padding:13px 16px;border-top:1px solid var(--line-2);font-size:15px;font-weight:800;color:var(--ink)}
.kbbop-m{display:flex;gap:10px;align-items:flex-start;padding:13px 16px;border-top:1px solid var(--line-2);cursor:pointer;font-size:14px;color:var(--ink)}
.kbbop-m:first-of-type{border-top:0}
.kbbop-m input{margin-top:3px;accent-color:var(--pink-deep)}
.kbbop-m small{display:block;color:var(--muted);font-size:12.5px;margin-top:2px;line-height:1.45}
.kbbop-card-fields{display:none;padding:0 16px 14px}
.kbbop-card-fields.is-on{display:block}
.kbbop-f{margin-top:10px}
.kbbop-f span{display:block;font-size:12px;font-weight:700;color:var(--ink-2);margin-bottom:5px}
.kbbop-box{border:1px solid var(--line-2);border-radius:10px;padding:12px;min-height:44px;box-sizing:border-box;background:#fff}
.kbbop-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.kbbop-go{display:block;width:100%;min-height:50px;border:0;border-radius:12px;background:var(--pink-deep);color:#fff;font-size:15.5px;font-weight:800;cursor:pointer}
.kbbop-go[disabled]{opacity:.6;cursor:default}
.kbbop-err{display:none;margin:0 0 14px;border-radius:12px;padding:12px 14px;font-size:13px;line-height:1.5;background:#FDECEF;border:1px solid #F6C9D2;color:#B3243F}
.kbbop-err.is-on{display:block}
.kbbop-note{font-size:12.5px;line-height:1.55;color:var(--muted);margin-top:12px;text-align:center}
</style>
@endpush

@section('content')
<div class="kbbop">
    @if ($order === null)
        <h1>{{ __('store.order_pay.heading') }}</h1>
        <div class="kbbop-err is-on">{{ __('store.order_pay.not_found') }}</div>
        <p><a href="{{ Url::to('/track-my-order/') }}">{{ __('store.order_pay.find_order') }}</a></p>
    @else
        @php $width = \App\Services\Mail\OrderEmailPresenter::ledgerWidth($order); @endphp
        <h1>{{ __('store.order_pay.heading') }}</h1>
        <p class="lede">{{ __('store.order_pay.lead', ['number' => $order->order_number]) }}</p>

        <div class="kbbop-err @if ($errors->any()) is-on @endif" id="kbbopErr" role="alert">{{ $errors->first() }}</div>

        <div class="kbbop-card">
            <div class="kbbop-head">{{ __('store.order_pay.your_items') }}</div>
            @foreach ($order->items as $item)
                <div class="kbbop-row">
                    <span><b>{{ $item->name }}</b><small>{{ __('store.order_pay.qty', ['qty' => (int) $item->quantity]) }}</small></span>
                    <span>{!! Money::format((int) $item->total, $width) !!}</span>
                </div>
            @endforeach
            <div class="kbbop-total"><span>{{ __('store.order_pay.total') }}</span><span>{!! Money::format((int) $order->total, $width) !!}</span></div>
        </div>

        <form method="post" action="{{ Url::to('/checkout/order-pay') }}" id="kbbopForm">
            @csrf
            <input type="hidden" name="order" value="{{ $order->order_number }}">
            <input type="hidden" name="t" value="{{ $token }}">

            <div class="kbbop-card">
                <div class="kbbop-head">{{ __('store.order_pay.how_to_pay') }}</div>
                @forelse ($methods as $i => $m)
                    <label class="kbbop-m">
                        <input type="radio" name="method" value="{{ $m['id'] }}" @checked($m['id'] === $order->payment_method || ($i === 0 && ! collect($methods)->contains('id', $order->payment_method)))>
                        <span>{{ $m['title'] }}@if (! empty($m['description']))<small>{{ $m['description'] }}</small>@endif</span>
                    </label>
                    @if ($m['id'] === 'stripe' && $stripeKey !== '')
                        <div class="kbbop-card-fields" id="kbbopCard">
                            <div class="kbbop-f"><span>{{ __('store.checkout.card_number_label') }}</span><div class="kbbop-box" id="kbbopNumber"></div></div>
                            <div class="kbbop-2">
                                <div class="kbbop-f"><span>{{ __('store.checkout.card_expiry_label') }}</span><div class="kbbop-box" id="kbbopExpiry"></div></div>
                                <div class="kbbop-f"><span>{{ __('store.checkout.card_cvc_label') }}</span><div class="kbbop-box" id="kbbopCvc"></div></div>
                            </div>
                        </div>
                    @endif
                @empty
                    <p class="kbbop-row" style="margin:0">{{ __('store.order_pay.no_methods') }}</p>
                @endforelse
            </div>

            @if ($methods !== [])
                <button type="submit" class="kbbop-go" id="kbbopGo">{{ __('store.order_pay.pay_button') }}</button>
            @endif
        </form>
        <p class="kbbop-note">{{ __('email.reminder.button_note') }}</p>
    @endif
</div>
@endsection

@if ($order !== null && $methods !== [])
@push('scripts')
@if ($stripeKey !== '')
<script src="https://js.stripe.com/v3"></script>
@endif
<script>
(function () {
  'use strict';
  var form = document.getElementById('kbbopForm');
  if (!form) return;
  var err = document.getElementById('kbbopErr');
  var go = document.getElementById('kbbopGo');
  var cardBox = document.getElementById('kbbopCard');
  var PAID_URL = @json(Url::to('/checkout/card/paid'));
  var TEXT = { working: @json(__('store.order_pay.working')), pay: @json(__('store.order_pay.pay_button')), generic: @json(__('store.order_pay.generic_error')) };
  var stripe = null, number = null;

  function chosen() { var r = form.querySelector('input[name=method]:checked'); return r ? r.value : ''; }
  function sync() { if (cardBox) cardBox.classList.toggle('is-on', chosen() === 'stripe'); }
  function show(msg) { err.textContent = msg || TEXT.generic; err.classList.add('is-on'); }
  function busy(on) { go.disabled = on; go.textContent = on ? TEXT.working : TEXT.pay; }

  @if ($stripeKey !== '')
  if (cardBox && typeof Stripe === 'function') {
    stripe = Stripe(@json($stripeKey), { locale: @json(\App\Support\StripeLocale::current()) });
    var els = stripe.elements({ locale: @json(\App\Support\StripeLocale::current()) });
    number = els.create('cardNumber'); number.mount('#kbbopNumber');
    els.create('cardExpiry').mount('#kbbopExpiry');
    els.create('cardCvc').mount('#kbbopCvc');
  }
  @endif

  form.addEventListener('change', sync); sync();

  async function post(url, body) {
    var r = await fetch(url, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                 'X-CSRF-TOKEN': form.querySelector('input[name=_token]').value },
      body: JSON.stringify(body) });
    var j = {}; try { j = await r.json(); } catch (e) {}
    return { ok: r.ok, body: j };
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    err.classList.remove('is-on');
    var method = chosen();
    if (!method) { show(); return; }
    if (method === 'stripe' && !number) { show(); return; }
    busy(true);
    try {
      var started = await post(form.action, { order: form.order.value, t: form.t.value, method: method });
      var b = started.body || {};
      if (!started.ok || b.ok !== true) { show(b.error); busy(false); return; }
      if (b.action === 'redirect') { window.location.assign(b.url); return; }
      if (b.action !== 'confirm' || !stripe) { show(); busy(false); return; }
      var result = await stripe.confirmCardPayment(b.client_secret, { payment_method: { card: number } });
      if (result.error) { show(result.error.message); busy(false); return; }
      try { await post(PAID_URL, { order: b.order }); } catch (x) { /* the webhook has it */ }
      window.location.assign(b.success_url);
    } catch (x) { show(); busy(false); }
  });
})();
</script>
@endpush
@endif
