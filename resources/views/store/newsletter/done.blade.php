@extends('layouts.store')
@section('title', $ok ? ($action === 'confirm' ? __('store.newsletter.done_subscribed_title') : __('store.newsletter.done_unsub_title')) : __('store.newsletter.bad_link_title'))

@section('content')

{{--
    The end of both round trips, and the one failure page for both.

    ONE PAGE FOR EVERY FAILURE. A forged signature, an expired link and an id
    that was never issued all land here with the same words, because anything
    that distinguished them would say whether a given subscriber id exists —
    and ids are sequential small integers. NewsletterList::rowOrDecoy() keeps
    the work identical as well, so the timing does not say what the wording
    does not. This is the shape CLAUDE.md records for
    Api\QuizController::expertRequest, kept deliberately.

    The address is never printed, on success or failure. See the unsubscribe
    page for why.
--}}
<div class="auth">
    <div class="auth-grid">
        <div class="authcard">
            @if ($ok && $action === 'confirm')
                <h1>{{ __('store.newsletter.done_subscribed_heading') }}</h1>
                <p class="lede">{{ __('store.newsletter.done_subscribed_lead') }}</p>
                <p class="muted" style="font-size:13px;">{{ __('store.newsletter.done_subscribed_note') }}</p>
            @elseif ($ok)
                <h1>{{ __('store.newsletter.done_unsub_title') }}</h1>
                <p class="lede">{{ __('store.newsletter.done_unsub_lead') }}</p>
                <p class="muted" style="font-size:13px;">{{ __('store.newsletter.done_unsub_note') }}</p>
            @else
                <h1>{{ __('store.newsletter.bad_link_title') }}</h1>
                <p class="lede">{{ __('store.newsletter.bad_link_lead') }}</p>
                <p class="muted" style="font-size:13px;">{{ __('store.newsletter.bad_link_note') }}</p>
            @endif
        </div>
    </div>
</div>
@endsection
