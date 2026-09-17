@extends('layouts.store')
@section('title', 'Confirm your subscription')

@section('content')
@php use App\Support\Url; @endphp

{{--
    The landing page for a confirmation link.

    This page CHANGES NOTHING. The controller's header explains why at length:
    mail scanners and inbox previewers fetch every URL in a message before a
    human sees it, so a GET that subscribed somebody would let a link-safety
    scanner grant consent on the recipient's behalf — which is double opt-in
    with the opt-in removed.

    The form auto-submits where scripts run, so the ordinary shopper presses
    once (in their email) rather than twice. Without scripts the button is
    simply there, which is why it is written as a real button first and
    progressively enhanced, never as a scripted link.
--}}
<div class="auth">
    <div class="auth-grid">
        <div class="authcard">
            <h1>One more press</h1>
            <p class="lede">Confirm that you would like emails from us at this address.</p>

            <form method="post" action="{{ Url::to('/newsletter/confirm') }}" id="nl-confirm">
                @csrf
                <input type="hidden" name="id" value="{{ $id }}">
                <input type="hidden" name="expires" value="{{ $expires }}">
                <input type="hidden" name="signature" value="{{ $signature }}">

                <button type="submit" class="btn btn-primary">Yes, subscribe me</button>
            </form>

            <p class="muted" style="margin-top:16px;font-size:13px;">
                If you did not ask for this, close this page. Nothing is added unless you press the button.
            </p>
        </div>
    </div>
</div>

{{--
    Submitted on load, not on a timer and not with a fake click: requestSubmit()
    runs the same path the button does, including validation, so the scripted
    and unscripted routes cannot diverge.
--}}
<script>
    (function () {
        var form = document.getElementById('nl-confirm');

        if (form && typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        }
    })();
</script>
@endsection
