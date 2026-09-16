{{--
    Order received.

    The page a shopper lands on straight off a payment, so it has one job
    beyond saying thank you: tell them where their order is and where they are.
    That means the next actions FIRST — sign in, home, track — and the full
    order summary under them, collapsed past a few lines so a big order cannot
    push the actions off a phone screen.

    Everything on this page is a record, not a form. The one exception is the
    finish-your-account box, which is offered to guests only.

    ACCESS is decided in CheckoutController::success(), not here: this view is
    handed either an order it is allowed to render in full, or null. See that
    method for what "allowed" means and why the page had to be gated before the
    line items and the delivery address could go on it.
--}}
@extends('layouts.store')
@php use App\Support\Money; use App\Support\Url; @endphp
@section('bare', '1')
@section('title', 'Order received · K-Beauty Bliss')
@push('styles')@vite('resources/css/kbb/kbb-checkout.css')
<style>
/* Scoped to this page. Everything else on it is an existing checkout class. */
.kbb-checkout .co-received{max-width:640px;margin:0 auto;padding:28px 20px 60px}
/* The confirmation heading is the one green moment on the page: the order
   succeeded, and green says so faster than any wording. */
.kbb-checkout .sec.co-ok > h2{background:#EEF8F1;border-left-color:#2E9E68;color:#1F7D52}
.kbb-checkout .sec.co-ok > h2 .n{background:#2E9E68;color:#fff}
.kbb-checkout .co-acts{display:grid;gap:9px;margin-top:4px}
.kbb-checkout .co-act{display:flex;align-items:center;gap:11px;border:1.5px solid var(--line);border-radius:12px;padding:12px 14px;font-size:13.5px;font-weight:700;color:var(--ink);transition:.15s var(--ease)}
.kbb-checkout .co-act:hover{border-color:var(--pink);color:var(--pink-deep);background:var(--pink-soft)}
.kbb-checkout .co-act svg{width:18px;height:18px;flex-shrink:0;color:var(--pink-deep)}
.kbb-checkout .co-act .co-t{flex:1;min-width:0}
.kbb-checkout .co-brand{font-size:9.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--pink);font-weight:700}
.kbb-checkout .co-act .co-s{display:block;font-weight:500;font-size:11.5px;color:var(--muted);margin-top:2px}
.kbb-checkout .co-act .co-go{color:var(--muted);font-weight:700}
.kbb-checkout .co-act--primary{background:var(--pink);border-color:var(--pink);color:#fff;box-shadow:0 14px 26px -16px rgba(193,62,99,.65)}
.kbb-checkout .co-act--primary:hover{background:var(--pink-deep);border-color:var(--pink-deep);color:#fff}
.kbb-checkout .co-act--primary svg,.kbb-checkout .co-act--primary .co-go{color:#fff}
.kbb-checkout .co-act--primary .co-s{color:rgba(255,255,255,.82)}
.kbb-checkout .co-lines{margin-bottom:12px}
.kbb-checkout .co-lines .ci:first-child{padding-top:0}
.kbb-checkout .co-linemeta{font-size:11.5px;color:var(--muted);font-weight:600}
.kbb-checkout .co-more{margin:0}
.kbb-checkout .co-more > summary{cursor:pointer;list-style:none;display:block;text-align:center;font-size:12px;font-weight:700;color:var(--pink-deep);padding:11px 0}
.kbb-checkout .co-more > summary::-webkit-details-marker{display:none}
.kbb-checkout .co-more > summary:hover{color:var(--pink)}
.kbb-checkout .co-more .lbl-open{display:none}
.kbb-checkout .co-more[open] .lbl-open{display:inline}
.kbb-checkout .co-more[open] .lbl-shut{display:none}
.kbb-checkout .co-facts{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.kbb-checkout .co-fact{min-width:0}
.kbb-checkout .co-fact dt{font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:var(--muted);font-weight:700;margin-bottom:3px}
.kbb-checkout .co-fact dd{margin:0;font-size:12.5px;font-weight:600;color:var(--ink);line-height:1.45;overflow-wrap:anywhere}
/* The recorded delivery window, under the rate name it belongs to. Its own
   line at every width: at 390px the two-column facts grid is already tight,
   and a window running on after "Free delivery" reads as part of the name. */
.kbb-checkout .co-fact .co-when{display:block;font-weight:500;font-size:11.5px;color:var(--muted);margin-top:2px}
.kbb-checkout .co-gift{background:var(--pink-soft);border-radius:12px;padding:12px 14px;margin-top:14px;font-size:12.5px;line-height:1.5;color:var(--ink-2)}
.kbb-checkout .co-gift b{display:block;color:var(--ink);margin-bottom:3px}
.kbb-checkout .co-acct{margin-top:2px}
/* One row, and it stays one row. The field had min-width:180px and the row
   was allowed to wrap, so on a phone the button dropped underneath and looked
   like a separate step. min-width:0 lets the field shrink instead. */
.kbb-checkout .co-acct .crow{display:flex;gap:8px;margin-top:9px;flex-wrap:nowrap;align-items:stretch}
.kbb-checkout .co-acct input{flex:1 1 auto;min-width:0;border:1.6px solid var(--line);border-radius:10px;padding:12px 14px;font-family:inherit;font-size:14px;background:#fff}
.kbb-checkout .co-acct button{flex:0 0 auto;white-space:nowrap;background:var(--ink);color:#fff;border-radius:10px;padding:12px 16px;font-weight:700;font-size:13.5px}
/* The username, stated before the field rather than explained after it. */
.kbb-checkout .co-user{display:flex;align-items:center;gap:7px;background:#EEF8F1;border:1px solid #BFE0CD;border-radius:10px;padding:9px 12px;font-size:12.5px;font-weight:600;color:#1F7D52;overflow-wrap:anywhere}
.kbb-checkout .co-user svg{width:15px;height:15px;flex-shrink:0}
.kbb-checkout .co-acct button:hover{background:var(--pink-deep)}
.kbb-checkout .co-acct .kbb-acct-err{display:block;margin-top:7px;font-size:12px;font-weight:600;color:var(--sale,#c0392b)}
.kbb-checkout .co-done{background:#EEF8F1;border:1px solid #BFE0CD;border-radius:12px;padding:12px 14px;font-size:12.5px;font-weight:600;color:#1F7D52}
@media (max-width:560px){
  .kbb-checkout .co-received{padding:20px 14px 48px}
  /* The four facts stay two-up on a phone -- they are short values, and one
     column pushed everything below them off the first screen. */
  .kbb-checkout .co-facts{gap:10px 14px}
  .kbb-checkout .co-acct button{padding:12px 13px;font-size:13px}
}
</style>
@endpush

@section('content')
<section class="kbb-checkout">
    <header class="co-head"><div class="in">
        <a class="logo" href="{{ Url::to('/') }}">K-Beauty<span>Bliss</span></a>
        <span class="secure"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7"/></svg> Order received</span>
    </div></header>

    <div class="co-received">
        @if ($order)
            @php
                $signedIn = auth('customer')->check();
                $shipping = is_array($order->shipping_address) ? $order->shipping_address : [];
                $addressLines = array_values(array_filter([
                    trim(($shipping['first_name'] ?? '') . ' ' . ($shipping['last_name'] ?? '')),
                    $shipping['line1'] ?? null,
                    trim(implode(', ', array_filter([$shipping['city'] ?? null, $shipping['state'] ?? null]))),
                    // The column stores a two-letter code, which is a database
                    // value and not the last line of anybody's address. The
                    // code itself is kept when it is one we have no name for,
                    // rather than dropping the line altogether.
                    ($c = $shipping['country'] ?? null)
                        ? (\App\Support\Countries::NAMES[strtoupper((string) $c)] ?? $c)
                        : null,
                    $shipping['phone'] ?? null,
                ], fn ($line) => trim((string) $line) !== ''));
            @endphp

            <div class="formbox">
                <div class="sec co-ok">
                    <h2><span class="n">✓</span> Thank you</h2>
                    <p class="co-lead">Your order <b>#{{ $order->order_number }}</b> is confirmed. A copy is on its way to {{ $order->email }}.</p>

                    <dl class="co-facts">
                        <div class="co-fact"><dt>Order number</dt><dd>#{{ $order->order_number }}</dd></div>
                        {{-- "Total paid" was printed over every order, including
                             a cash-on-delivery one where not a dirham has moved.
                             The application's own record disagrees with that:
                             CashOnDelivery deliberately leaves `paid_at` null and
                             says why — "No money has moved; the courier collects
                             it" — so `paid_at` is what decides the word here. --}}
                        <div class="co-fact"><dt>{{ $order->paid_at ? 'Total paid' : 'Total to pay' }}</dt><dd>{!! Money::format((int) $order->total) !!}</dd></div>
                        <div class="co-fact"><dt>Payment</dt><dd>{{ $order->paymentLabel() }}</dd></div>
                        {{-- The rate name answered "what did I pay for", never
                             "when does it come" — which is the question a
                             shopper actually has on this screen. The window is
                             the one already recorded per country in
                             `delivery_texts` and shown under Place order, for
                             this order's own destination, so a country with no
                             line recorded still gets none. --}}
                        <div class="co-fact"><dt>Delivery</dt><dd>{{ $order->shipping_method }}@if (trim((string) ($deliveryText ?? '')) !== '')<span class="co-when">{{ $deliveryText }}</span>@endif</dd></div>
                    </dl>
                </div>

                {{-- Next actions, above the summary on purpose: this is what the
                     shopper came here to do, and the summary can be long. --}}
                <div class="sec">
                    <h2><span class="n">→</span> What next</h2>
                    <div class="co-acts">
                        @php
                            /* Sign in is offered only when signing in is possible.
                             * A guest who has not chosen a password yet cannot sign
                             * in, so the card would send them to a form they cannot
                             * complete; they get the password step below instead, and
                             * this card appears the moment they finish it.
                             *
                             * Driven by the session, never by whether the email
                             * already has an account -- that lookup is the
                             * enumeration oracle the silent decline exists to avoid.
                             * claim-account answers identically either way, so
                             * "your account is ready" is true in both cases. */
                            $accountReady = $signedIn || session('kbb_account_done');
                        @endphp
                        @if ($signedIn)
                            <a class="co-act co-act--primary" href="{{ Url::to('/my-account/orders/') }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 7H4M20 12H4M20 17H4"/></svg>
                                <span class="co-t">Your orders<span class="co-s">Every order on your account, including this one</span></span>
                                <span class="co-go">›</span>
                            </a>
                        @elseif ($accountReady)
                            <a class="co-act co-act--primary" href="{{ Url::to('/my-account/') }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <span class="co-t">Sign in to your account<span class="co-s">Your account is ready — use {{ $order->email }}</span></span>
                                <span class="co-go">›</span>
                            </a>
                        @endif

                        <a class="co-act" href="{{ Url::to('/track-my-order/') }}?order={{ urlencode((string) $order->order_number) }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 3h15v13H1zM16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                            <span class="co-t">Track your order<span class="co-s">Order #{{ $order->order_number }} — we will ask for your email to confirm it is you</span></span>
                            <span class="co-go">›</span>
                        </a>

                        <a class="co-act" href="{{ Url::to('/') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/></svg>
                            <span class="co-t">Go to home<span class="co-s">Back to the shop front</span></span>
                            <span class="co-go">›</span>
                        </a>
                    </div>
                </div>

                @guest('customer')
                    {{-- Offered to every guest, never conditioned on whether this
                         email already has an account — that condition is exactly
                         the enumeration oracle the silent decline exists to
                         avoid. The endpoint answers the same either way. --}}
                    @unless (session('kbb_account_done'))
                    <div class="sec co-acct">
                        <h2><span class="n">+</span> Finish your account</h2>
                            <div class="co-user">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v16H4z" opacity=".0"/><path d="M22 6l-10 7L2 6"/><path d="M2 6h20v12H2z"/></svg>
                                <span>{{ $order->email }} is your username</span>
                            </div>
                            <p class="co-lead" style="margin:7px 0 0">Set a password to finish.</p>
                            <form method="post" action="{{ Url::to('/checkout/claim-account') }}">
                                @csrf
                                <input type="hidden" name="order" value="{{ $order->order_number }}">
                                <div class="crow">
                                    <input type="password" name="account_password" aria-label="Set a password"
                                           placeholder="Password (8+ characters)"
                                           autocomplete="new-password" minlength="8" required>
                                    <button type="submit">Save</button>
                                </div>
                                @error('account_password')<span class="kbb-acct-err">{{ $message }}</span>@enderror
                            </form>
                    </div>
                    @endunless
                @endguest

                <div class="sec">
                    <h2><span class="n">☰</span> Your order</h2>
                    @include('partials.checkout.received-summary', ['order' => $order])
                </div>

                <div class="sec">
                    <h2><span class="n">⌂</span> Delivering to</h2>
                    @if ($addressLines)
                        <p class="co-lead" style="margin-bottom:0">
                            @foreach ($addressLines as $line)
                                {{ $line }}@if (! $loop->last)<br>@endif
                            @endforeach
                        </p>
                    @else
                        <p class="co-lead" style="margin-bottom:0">We will confirm your delivery address by email.</p>
                    @endif

                    @if ($order->customer_note)
                        <div class="co-gift"><b>Your note</b>{{ $order->customer_note }}</div>
                    @endif

                    @if ($order->is_gift)
                        <div class="co-gift">
                            <b>Gift wrapped 🎁</b>
                            @if ($order->gift_note)
                                “{{ $order->gift_note }}” — printed on the gift card.
                            @else
                                Your order is wrapped as a gift.
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            @php
                // Only for the browser that actually just placed this order —
                // see the comment beside where this session key is set, in
                // CheckoutController::place(). Consumed once so a stale value
                // cannot outlive the order it belongs to. success() has already
                // converted it into a durable view grant, so consuming it here
                // does not lock the shopper out of their own reload.
                $kbbJustPlacedThis = session('kbb_last_order') === $order->order_number;
                if ($kbbJustPlacedThis) { session()->forget('kbb_last_order'); }
            @endphp
            @if ($kbbJustPlacedThis)
            @push('scripts')
            {!! app(\App\Services\MarketingPixels::class)->purchase($order) !!}
            @endpush
            @endif
        @else
            {{-- The same panel for an order number that does not exist and for
                 one that exists but is not this visitor's. Two different
                 answers here would be a way to enumerate real orders. --}}
            <div class="formbox"><div class="sec"><h2>Order not found</h2><p class="co-lead">We could not find that order. If you have just placed one, the confirmation email has a link that will open it.</p></div></div>
            <p style="text-align:center;margin-top:18px"><a class="backlink" href="{{ Url::to('/track-my-order/') }}">Track an order with your email →</a></p>
        @endif

        <p style="text-align:center;margin-top:22px"><a class="backlink" href="{{ Url::to('/shop/') }}">← Continue shopping</a></p>
    </div>
</section>
@endsection
