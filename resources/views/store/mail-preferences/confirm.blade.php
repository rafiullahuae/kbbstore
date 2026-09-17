@extends('layouts.store')
@section('title', __('store.mail_prefs.title'))

@section('content')
@php use App\Support\Url; @endphp

{{--
    The landing page for the unsubscribe link in a back-in-stock alert or a
    basket reminder.

    Changes nothing. Same shape and same reasoning as
    store/newsletter/unsubscribe.blade.php: a mail scanner fetching this URL
    must not be able to act on it, and an unsubscribe is destructive enough to
    deserve an explicit press rather than an auto-submit.

    ONE PAGE FOR BOTH KINDS, and the `kind` travels in a hidden field. It is
    matched against a closed list in Support\OutboundOptOut::KINDS before it
    reaches anything, and an unrecognised value does exactly the same work as a
    forged signature and produces exactly the same page.

    The address is not printed, and neither is the product or the basket. The
    holder of this link is not necessarily the person it was sent to.
--}}
<div class="auth">
    <div class="auth-grid">
        <div class="authcard">
            <h1>{{ __('store.mail_prefs.title') }}</h1>
            <p class="lede">{{ __('store.mail_prefs.lead') }}</p>

            <form method="post" action="{{ Url::to('/mail-preferences') }}">
                @csrf
                <input type="hidden" name="kind" value="{{ $kind }}">
                <input type="hidden" name="id" value="{{ $id }}">
                <input type="hidden" name="expires" value="{{ $expires }}">
                <input type="hidden" name="signature" value="{{ $signature }}">

                <button type="submit" class="btn btn-primary">{{ __('store.mail_prefs.button') }}</button>
            </form>

            <p class="muted" style="margin-top:16px;font-size:13px;">{{ __('store.mail_prefs.confirm_note') }}</p>
        </div>
    </div>
</div>
@endsection
