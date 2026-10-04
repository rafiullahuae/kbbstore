@extends('layouts.store')
@section('title', __('store.newsletter.unsub_title'))

@section('content')
{{--
    Lane EK — the page behind "Unsubscribe" in a campaign email.

    It CHANGES NOTHING on its own, for the reason the newsletter's page gives:
    mail scanners and link-safety rewriters fetch every URL in a message, and a
    GET that unsubscribed would take people off the list who never pressed
    anything. The button posts to the same address, which is also where a mail
    client's one-click unsubscribe (List-Unsubscribe-Post, RFC 8058) lands.

    The address is never printed: the link is a bearer credential.
--}}
<div class="auth">
    <div class="auth-grid">
        <div class="authcard">
            <h1>{{ __('store.newsletter.unsub_title') }}</h1>
            <p class="lede">{{ __('store.newsletter.unsub_lead') }}</p>

            <form method="post" action="{{ $action }}">
                <button type="submit" class="btn btn-primary">{{ __('store.newsletter.unsub_button') }}</button>
            </form>

            <p class="muted" style="margin-top:16px;font-size:13px;">{{ __('store.newsletter.unsub_note') }}</p>
        </div>
    </div>
</div>
@endsection
