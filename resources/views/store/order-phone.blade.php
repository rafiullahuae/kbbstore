{{--
    "Update my number" (2.60.562) — opened from the UAE number email. One field
    and one button, as in the preview the owner approved. See
    App\Http\Controllers\Store\OrderPhoneController for what the signed link
    may and may not do. Prints the order number and first name only.

    $order is null for a link that is forged, expired or for another order: the
    same 404 block for all three.
--}}
@extends('layouts.store')
@php use App\Support\Url; use App\Support\UaePhone; @endphp
@section('title', __('store.order_phone.page_title'))
@section('no-trust-strip', '1')

@push('styles')
<style>
.kbbph{max-width:480px;margin:0 auto;padding:28px 16px 48px}
.kbbph-card{background:#fff;border:1px solid var(--line-2);border-radius:16px;padding:24px 22px}
.kbbph-eb{font-size:11.5px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--pink-deep)}
.kbbph h1{font-size:23px;line-height:1.25;margin:6px 0 8px;color:var(--ink)}
.kbbph .lede{font-size:14.5px;line-height:1.55;color:var(--ink-2);margin:0}
.kbbph label{display:block;margin-top:18px;font-size:12.5px;font-weight:700;color:var(--ink-2)}
.kbbph-fld{margin-top:6px;display:flex;border:1.5px solid var(--pink);border-radius:12px;overflow:hidden;direction:ltr;background:#fff}
.kbbph-fld:focus-within{box-shadow:0 0 0 3px rgba(224,86,123,.18)}
.kbbph-fld span{flex:none;display:flex;align-items:center;padding:0 14px;background:#FFF0F4;font-weight:700;color:var(--pink-deep);border-right:1px solid #F3C9D5}
.kbbph-fld input{flex:1 1 auto;min-width:0;border:0;outline:0;padding:13px 14px;font:inherit;font-size:16px;color:var(--ink);background:transparent;text-align:left}
.kbbph-hint{font-size:12.5px;color:var(--muted);margin-top:7px}
.kbbph-err{margin-top:10px;font-size:13px;color:#B4472B;font-weight:600}
.kbbph-btn{margin-top:16px;width:100%;min-height:48px;border:0;border-radius:12px;background:var(--pink);color:#fff;font:inherit;font-weight:700;font-size:16px;cursor:pointer}
.kbbph-btn:hover{background:var(--pink-deep)}
.kbbph-done{margin-top:16px;background:#E9F6EE;color:#2E7D4F;border-radius:12px;padding:13px 14px;font-weight:600;font-size:14.5px;line-height:1.5}
.kbbph-num{direction:ltr;unicode-bidi:isolate;white-space:nowrap}
.kbbph-more{margin-top:14px;font-size:13.5px;color:var(--ink-2)}
</style>
@endpush

@section('content')
<div class="kbbph">
    <div class="kbbph-card">
    @if ($order === null)
        <h1>{{ __('store.order_phone.heading') }}</h1>
        <p class="lede">{{ __('store.order_phone.not_found') }}</p>
        <p class="kbbph-more"><a href="{{ Url::to('/track-my-order/') }}">{{ __('store.order_pay.find_order') }}</a></p>
    @else
        <div class="kbbph-eb">{{ __('store.order_phone.order_label', ['number' => $order->order_number]) }}</div>
        @if ($saved)
            <h1>{{ __('store.order_phone.heading') }}</h1>
            <div class="kbbph-done" role="status">&#10003; {!! __($firstName !== '' ? 'store.order_phone.done_named' : 'store.order_phone.done', ['name' => e($firstName), 'phone' => '<span class="kbbph-num">' . e(UaePhone::pretty((string) $order->phone) ?? (string) $order->phone) . '</span>']) !!}</div>
        @elseif (! $open)
            <h1>{{ __('store.order_phone.heading') }}</h1>
            <p class="lede">{{ __('store.order_phone.closed') }}</p>
        @else
            <h1>{{ __('store.order_phone.heading') }}</h1>
            <p class="lede">{{ __('store.order_phone.lead') }}</p>
            <form method="post" action="{{ Url::to('/order-phone') }}?{{ http_build_query(['order' => (string) $order->order_number, 't' => $token]) }}" novalidate>
                @csrf
                <label for="kbbphIn">{{ __('store.order_phone.field_label') }}</label>
                <div class="kbbph-fld"><span>+971</span><input id="kbbphIn" name="phone" type="tel" inputmode="tel" autocomplete="tel-national" maxlength="20" required placeholder="50 123 4567" value="{{ $typed }}"@if ($error !== '') aria-invalid="true" aria-describedby="kbbphErr"@endif></div>
                <div class="kbbph-hint">{{ __('store.order_phone.hint') }}</div>
                @if ($error !== '')<div class="kbbph-err" id="kbbphErr" role="alert">{{ $error }}</div>@endif
                <button type="submit" class="kbbph-btn">{{ __('store.order_phone.save') }}</button>
            </form>
        @endif
    @endif
    </div>
</div>
@endsection
