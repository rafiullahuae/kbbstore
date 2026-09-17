@extends('layouts.store')
@section('title', $ok ? __('store.mail_prefs.done_title') : __('store.newsletter.bad_link_title'))

@section('content')

{{--
    The end of the opt-out, and the one failure page for every way it can fail.

    ONE PAGE FOR EVERY FAILURE. A forged signature, an expired link, a kind that
    is not a kind and an id that was never issued all land here with the same
    words, because anything that distinguished them would say whether a given
    row exists — and "does stock_alerts row 41 exist" is "did somebody ask to be
    told when that product came back", which is a question about a person.
    Support\OutboundOptOut::resolve() keeps the WORK identical as well, so the
    timing does not say what the wording does not.

    The address is never printed, on success or failure. See
    store/newsletter/unsubscribe.blade.php for why.
--}}
<div class="auth">
    <div class="auth-grid">
        <div class="authcard">
            @if ($ok)
                <h1>{{ __('store.mail_prefs.done_title') }}</h1>
                <p class="lede">{{ __('store.mail_prefs.done_lead') }}</p>
                <p class="muted" style="font-size:13px;">{{ __('store.mail_prefs.done_note') }}</p>
            @else
                <h1>{{ __('store.newsletter.bad_link_title') }}</h1>
                <p class="lede">{{ __('store.newsletter.bad_link_lead') }}</p>
                <p class="muted" style="font-size:13px;">{{ __('store.mail_prefs.bad_link_note') }}</p>
            @endif
        </div>
    </div>
</div>
@endsection
