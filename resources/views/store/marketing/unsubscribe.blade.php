@extends('layouts.store')
@section('title', __('store.mkt_unsub.title'))

@section('content')
@php use App\Support\Url; @endphp

{{--
    Marketing Emails → the unsubscribe link at the foot of every campaign
    (Lane MK). Changes nothing: a mail scanner fetching this URL must not be
    able to unsubscribe anybody, so the work is the button's POST — to the
    same address, which is also the RFC 8058 one-click endpoint and needs no
    CSRF token (routes/marketing-public.php).

    The address is never printed; the holder of a link is not necessarily the
    person it was sent to. Same shape as store/mail-preferences/confirm.
--}}
<div class="auth">
    <div class="auth-grid">
        <div class="authcard">
            <h1>{{ __('store.mkt_unsub.title') }}</h1>
            <p class="lede">{{ __('store.mkt_unsub.lead') }}</p>

            <form method="post" action="{{ Url::to('/email/u/' . $token) }}">
                <button type="submit" class="btn btn-primary">{{ __('store.mkt_unsub.button') }}</button>
            </form>

            <p class="muted" style="margin-top:16px;font-size:13px;">{{ __('store.mkt_unsub.keeps') }}</p>
        </div>
    </div>
</div>
@endsection
