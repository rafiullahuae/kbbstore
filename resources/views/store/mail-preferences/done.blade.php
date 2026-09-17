@extends('layouts.store')
@section('title', $ok ? 'Stopped' : 'That link did not work')

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
                <h1>Stopped</h1>
                <p class="lede">Done. We will not send stock alerts or basket reminders to this address again, and any that were already waiting have been cancelled.</p>
                <p class="muted" style="font-size:13px;">Order confirmations, delivery updates and receipts are not affected — they are how you find out what is happening to something you paid for.</p>
            @else
                <h1>That link did not work</h1>
                <p class="lede">It may have expired, or it may have been copied incompletely — links wrap badly in some email programs.</p>
                <p class="muted" style="font-size:13px;">Try opening it again straight from the email. If it still does not work, reply to any message from us and we will take the address off by hand.</p>
            @endif
        </div>
    </div>
</div>
@endsection
