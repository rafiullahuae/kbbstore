@extends('layouts.store')
@section('title', 'Email preferences')

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
            <h1>Email preferences</h1>
            <p class="lede">Stop sending stock alerts and basket reminders to this address?</p>

            <form method="post" action="{{ Url::to('/mail-preferences') }}">
                @csrf
                <input type="hidden" name="kind" value="{{ $kind }}">
                <input type="hidden" name="id" value="{{ $id }}">
                <input type="hidden" name="expires" value="{{ $expires }}">
                <input type="hidden" name="signature" value="{{ $signature }}">

                <button type="submit" class="btn btn-primary">Yes, stop these emails</button>
            </form>

            <p class="muted" style="margin-top:16px;font-size:13px;">
                This stops both back-in-stock alerts and basket reminders, for good, at this address.
                Order confirmations, delivery updates and receipts are not affected — they are how you
                find out what is happening to something you paid for.
            </p>
        </div>
    </div>
</div>
@endsection
