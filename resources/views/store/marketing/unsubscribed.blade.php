@extends('layouts.store')
@section('title', $ok ? __('store.mkt_unsub.done_title') : __('store.newsletter.bad_link_title'))

@section('content')
{{--
    The end of a campaign unsubscribe (Lane MK). One page for every way the
    link can fail — a forged token and a token for a row that was never
    written look exactly alike (App\Services\Marketing\UnsubscribeToken).
    The address is never printed.
--}}
<div class="auth">
    <div class="auth-grid">
        <div class="authcard">
            @if ($ok)
                <h1>{{ __('store.mkt_unsub.done_title') }}</h1>
                <p class="lede">{{ __('store.mkt_unsub.done_lead') }}</p>
                <p class="muted" style="font-size:13px;">{{ __('store.mkt_unsub.keeps') }}</p>
            @else
                <h1>{{ __('store.newsletter.bad_link_title') }}</h1>
                <p class="lede">{{ __('store.newsletter.bad_link_lead') }}</p>
                <p class="muted" style="font-size:13px;">{{ __('store.mkt_unsub.bad_note') }}</p>
            @endif
        </div>
    </div>
</div>
@endsection
