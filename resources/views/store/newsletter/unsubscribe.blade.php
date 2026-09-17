@extends('layouts.store')
@section('title', __('store.newsletter.unsub_title'))

@section('content')
@php use App\Support\Url; @endphp

{{--
    The landing page for an unsubscribe link.

    Changes nothing, for the reason the confirm page changes nothing: a mail
    scanner fetching this URL must not remove somebody from a list they still
    want to be on.

    NO AUTO-SUBMIT HERE, and the difference from confirm.blade.php is
    deliberate. Confirming is what the recipient just asked for by pressing a
    button in their inbox, so doing it without a second press is a convenience.
    Unsubscribing is destructive and is often reached by a mis-press or a
    forwarded email, so it gets an explicit press on this page.

    The address is not printed. A link is a bearer credential and anyone holding
    it can open this page; echoing the address would turn a link that grants one
    harmless action into a way to read the address it belongs to.
--}}
<div class="auth">
    <div class="auth-grid">
        <div class="authcard">
            <h1>{{ __('store.newsletter.unsub_title') }}</h1>
            <p class="lede">{{ __('store.newsletter.unsub_lead') }}</p>

            <form method="post" action="{{ Url::to('/newsletter/unsubscribe') }}">
                @csrf
                <input type="hidden" name="id" value="{{ $id }}">
                <input type="hidden" name="expires" value="{{ $expires }}">
                <input type="hidden" name="signature" value="{{ $signature }}">

                <button type="submit" class="btn btn-primary">{{ __('store.newsletter.unsub_button') }}</button>
            </form>

            <p class="muted" style="margin-top:16px;font-size:13px;">{{ __('store.newsletter.unsub_note') }}</p>
        </div>
    </div>
</div>
@endsection
