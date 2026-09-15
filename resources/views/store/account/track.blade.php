{{--
    Track an order. Public by design: a guest checkout has no account to sign
    into, so number + email is the only way in.

    THE ORDER-RECEIVED PAGE LINKS HERE WITH THE NUMBER ONLY —
    /track-my-order/?order=123 — and that must stay true. The email is the half
    that proves identity, and a URL gets shared, pasted and kept in history;
    putting the email in it would hand that away. So the number is prefilled
    from the query string and the email field is deliberately left blank for
    the shopper to type.

    ONE ANSWER FOR TWO FAILURES. "No such order" and "wrong email for this
    order" render this same block, word for word. Anything that distinguished
    them would turn a sequential order number into a lookup oracle. The
    controller matches that on the clock as well — see AccountController::track.

    Nothing that is not needed to recognise your own parcel goes on this page:
    no address, no line items, no email. It is a public form.
--}}
@extends('layouts.store')
@php use App\Support\Money; use App\Support\Url; @endphp
@section('title', 'Track my order · K-Beauty Bliss')

@push('styles')
<style>
.kbbtr-card{margin-top:24px;border:1px solid var(--line-2);border-radius:14px;overflow:hidden;background:#fff}
.kbbtr-top{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:15px 16px;background:var(--cream)}
.kbbtr-top b{flex:1 1 auto;min-width:0;font-size:14px;font-weight:800;color:var(--ink);overflow-wrap:anywhere}
.kbbtr-pill{flex:0 0 auto;border-radius:99px;padding:4px 12px;font-size:11.5px;font-weight:700;
    background:#fff;color:var(--ink-2);border:1px solid var(--line-2)}
.kbbtr-pill.is-done{background:#EEF8F1;border-color:#BFE0CD;color:#1F7D52}
.kbbtr-pill.is-live{background:var(--pink-soft,#FFF1F5);border-color:var(--blush,#F6C9D2);color:var(--pink-deep)}
.kbbtr-pill.is-hold{background:#FFF4E5;border-color:#F3DCB8;color:#B26A00}
.kbbtr-pill.is-off{background:#FDECEF;border-color:#F6C9D2;color:#B3243F}
.kbbtr-rows{padding:6px 16px 14px}
.kbbtr-row{display:flex;justify-content:space-between;gap:16px;padding:8px 0;font-size:12.5px;color:var(--ink-2);
    border-bottom:1px solid var(--line-2)}
.kbbtr-row:last-child{border-bottom:0}
.kbbtr-row span:first-child{color:var(--muted);font-weight:600}
.kbbtr-row span:last-child{text-align:right;font-weight:700;color:var(--ink);overflow-wrap:anywhere}
.kbbtr-msg{margin-top:18px;border-radius:12px;padding:12px 14px;font-size:12.5px;line-height:1.55}
.kbbtr-msg.is-miss{background:#FDECEF;border:1px solid #F6C9D2;color:#B3243F}
.kbbtr-msg.is-slow{background:#FFF4E5;border:1px solid #F3DCB8;color:#B26A00}
</style>
@endpush

@section('content')
@php $ap = app(\App\Services\AccountPanel::class); @endphp
<div class="auth {{ $ap->formClass() }}" style="{{ $ap->cssVariables() }}">
    <div class="auth-grid">
        <div class="authcard">
            <h1>Track my order</h1>
            <p class="lede">Your order number is in your confirmation email. We ask for the email as well, so only you can see where your parcel is.</p>

            <form method="get" action="{{ Url::to('/track-my-order/') }}">
                <div class="fgroup">
                    <x-field name="order" label="Order number" :value="request('order')" />
                    {{-- Never prefilled from the query string: see the note at
                         the top of this file. --}}
                    <x-field name="email" label="Email address" type="email" icon="mail" autocomplete="email" />
                </div>
                <button class="go" type="submit">Find my order</button>
            </form>

            @if ($retryAfter > 0)
                <div class="kbbtr-msg is-slow">
                    Too many tries. Wait {{ (int) ceil($retryAfter / 60) }} {{ (int) ceil($retryAfter / 60) === 1 ? 'minute' : 'minutes' }} and try again — or sign in to <a href="{{ Url::to('/my-account/orders/') }}">your orders</a>, where no number is needed.
                </div>
            @elseif ($notFound)
                {{-- The SAME words for "that order does not exist" and "that is
                     not the email on it". Two messages here would be an
                     enumeration oracle. --}}
                <div class="kbbtr-msg is-miss">
                    We couldn't find an order matching that number and email. Double-check both and try again.
                </div>
            @elseif ($order)
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
                <div class="kbbtr-card">
                    <div class="kbbtr-top">
                        <b>Order #{{ $order->order_number }}</b>
                        <span class="kbbtr-pill {{ $statusClass }}">{{ ucfirst(str_replace('-', ' ', $status)) }}</span>
                    </div>
                    <div class="kbbtr-rows">
                        <div class="kbbtr-row"><span>Placed</span><span>{{ $order->created_at?->format('j F Y') ?? '—' }}</span></div>
                        @if ($order->shipping_method)
                            <div class="kbbtr-row"><span>Delivery</span><span>{{ $order->shipping_method }}</span></div>
                        @endif
                        @if ($order->completed_at)
                            <div class="kbbtr-row"><span>Completed</span><span>{{ $order->completed_at->format('j F Y') }}</span></div>
                        @endif
                        <div class="kbbtr-row"><span>Order total</span><span>{!! Money::format((int) $order->total) !!}</span></div>
                    </div>
                </div>
                <p class="acw-fine">Signed up with us? <a href="{{ Url::to('/my-account/orders/') }}">Your orders</a> has the full receipt.</p>
            @endif
        </div>
    </div>
</div>
@endsection
