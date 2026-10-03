@extends('emails.layout')

{{--
    TWO STRINGS CONVERTED, DELIBERATELY (Lane EP).

    An email is where the order's language has to be honoured, because this
    message is sent LATER — from a queue, or from an admin pressing a status
    button weeks after checkout — in a process that has no memory of the
    request. Whatever locale that process happens to be in is English, so
    without the order's own locale an Arabic customer gets an Arabic checkout
    and English paperwork forever.

    The language is restored from orders.locale, either with
    App\Support\OrderLocale::render($order, fn () => ...) or with Laravel's own
    $mailable->locale($order->locale). Wiring that into the five mailables is
    Phase 6 and belongs to the lane that owns them; the column, the helper and
    these two strings are the foundation it needs.
--}}

{{--
    Lane RL. Three additions, each drawn only when it applies, so a status
    without them renders exactly as before:
      - $note: the owner's own sentence, typed when he pressed "Send on-hold
        email". Plain text, escaped; line breaks kept.
      - $trackable: "Your tracking number is your order number". The owner:
        "tracking number is the same order number ... the order can be tracked
        on our website, and whatever we put the status of the order, it will
        show." No courier reference exists in this shop and none is invented.
      - the button is a signed link (App\Support\OrderLinks) that opens on any
        device, so the old "that link opens on the device you ordered from"
        note is no longer true and is gone. A payment that failed gets
        "Complete your order", to the page where it can be paid, instead.
--}}

@section('body')
    @php $c = $brand['colours'] ?? \App\Services\Mail\EmailBranding::PALETTE; @endphp

    <p style="margin:0 0 14px;font-size:15px;color:{{ $c['ink2'] }};">{{ $order['customerName'] !== '' ? __('email.greeting.hello_named', ['name' => $order['customerName']]) : __('email.greeting.hello') }}</p>

    <p style="margin:0 0 10px;font-size:19px;font-weight:700;line-height:1.3;color:{{ $c['ink'] }};">{{ $heading }}</p>

    <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:{{ $c['ink2'] }};">{{ $body }}</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $c['cream'] }}" style="width:100%;border-collapse:collapse;background:{{ $c['cream'] }};border-radius:9px;">
        <tr>
            <td style="padding:13px 15px;font-size:14px;color:{{ $c['ink'] }};">
                <span style="font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:{{ $c['muted'] }};font-weight:700;">{{ __('email.order_status.order_label') }}</span>
                <span style="font-weight:700;margin-left:7px;font-size:16px;color:{{ $c['pinkDeep'] }};">{{ $order['number'] }}</span>
            </td>
        </tr>
    </table>

@if ($note !== '')
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;margin-top:14px;">
            <tr>
                <td style="padding:13px 15px;font-size:14px;line-height:1.55;color:{{ $c['ink'] }};border:1px solid {{ $c['line'] ?? '#EADFE2' }};border-radius:9px;">
                    <b>{{ __('email.order_status.onhold_need') }}</b> {!! nl2br(e($note)) !!}
                </td>
            </tr>
        </table>
@endif
@if ($trackable)
        <p style="margin:14px 0 0;font-size:14px;line-height:1.55;color:{{ $c['ink2'] }};">
            <b style="color:{{ $c['ink'] }};">{{ __('email.order_status.tracking_number', ['number' => $order['number']]) }}</b><br>
            {{ __('email.order_status.tracking_where') }}
        </p>
@endif
    @include('emails.partials.items')
    @include('emails.partials.totals')
    @include('emails.partials.delivery')

    {{-- A table cell with a bgcolor attribute, not a styled anchor: Outlook's
         Word renderer drops padding and background from an inline <a> and
         leaves a bare blue link. --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:26px 0 10px;">
        <tr>
            <td bgcolor="{{ $c['pinkDeep'] }}" style="background:{{ $c['pinkDeep'] }};border-radius:7px;">
                <a href="{{ $ctaUrl }}" style="display:inline-block;padding:13px 26px;color:{{ $c['white'] }};font-size:15px;font-weight:600;text-decoration:none;">{{ $ctaLabel }}</a>
            </td>
        </tr>
    </table>

    <p style="margin:0;font-size:13px;line-height:1.55;color:{{ $c['ink2'] }};">
        {{ $status === 'failed' ? __('email.reminder.button_note') : __('email.order_status.track_note') }}
    </p>
@endsection
