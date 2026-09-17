@extends('layouts.store')
@section('title', $ok ? ($action === 'confirm' ? 'You are subscribed' : 'Unsubscribed') : 'That link did not work')

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
                <h1>You are on the list</h1>
                <p class="lede">Thank you — that is confirmed. You will hear from us when there is something worth an email.</p>
                <p class="muted" style="font-size:13px;">Every email we send carries an unsubscribe link, and it will always work.</p>
            @elseif ($ok)
                <h1>Unsubscribed</h1>
                <p class="lede">Done. This address has been taken off our marketing list and we will not add it back unless you ask us to.</p>
                <p class="muted" style="font-size:13px;">Order confirmations and delivery updates are not marketing and will still reach you.</p>
            @else
                <h1>That link did not work</h1>
                <p class="lede">It may have expired, or it may have been copied incompletely — links wrap badly in some email programs.</p>
                <p class="muted" style="font-size:13px;">Try opening it again straight from the email. If it still does not work, sign up once more from the homepage and we will send a fresh one.</p>
            @endif
        </div>
    </div>
</div>
@endsection
