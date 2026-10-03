@extends('emails.layout')

{{--
    "Complete your order" — Lane RL. Reminder 1 (30 minutes) and reminder 2
    (24 hours) for an order nobody has paid for. Built on the shared layout and
    partials (owned by another lane; a later package restyles every template),
    with the order-status email's own blocks so the two look like each other.

    The button is a signed link (App\Support\OrderLinks::payUrl) to the page
    where THIS order can be paid with any method the checkout offers, on any
    device — not the order-received page, which only the placing browser opens.
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
                <span style="float:right;font-size:12px;font-weight:700;color:{{ $c['pinkDeep'] }};">{{ __('email.reminder.not_paid') }}</span>
            </td>
        </tr>
    </table>

    {{-- The owner: "we need to include and focus on the fast delivery, 100%
         original products from the brand and Free random samples with order."
         A plain three-line list on the current layout; the look-A restyle
         comes later. Strings: email.reminder.why_* (InterfaceStrings). --}}
    <p style="margin:18px 0 6px;font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:{{ $c['muted'] }};font-weight:700;">{{ __('email.reminder.why_heading') }}</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
        @foreach ([
            ['🚚', __('email.reminder.why_fast'), __('email.reminder.why_fast_note')],
            ['✔', __('email.reminder.why_original'), __('email.reminder.why_original_note')],
            ['🎁', __('email.reminder.why_samples'), __('email.reminder.why_samples_note')],
        ] as [$icon, $title, $line])
            <tr>
                <td width="28" valign="top" style="padding:5px 0;font-size:16px;line-height:1.3;">{{ $icon }}</td>
                <td style="padding:5px 0;font-size:14px;line-height:1.45;color:{{ $c['ink2'] }};"><b style="color:{{ $c['ink'] }};">{{ $title }}</b> — {{ $line }}</td>
            </tr>
        @endforeach
    </table>

    @include('emails.partials.items')
    @include('emails.partials.totals')

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:26px 0 10px;">
        <tr>
            <td bgcolor="{{ $c['pinkDeep'] }}" style="background:{{ $c['pinkDeep'] }};border-radius:7px;">
                <a href="{{ $payUrl }}" style="display:inline-block;padding:13px 26px;color:{{ $c['white'] }};font-size:15px;font-weight:600;text-decoration:none;">{{ __('email.reminder.button') }}</a>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 10px;font-size:13px;line-height:1.55;color:{{ $c['ink2'] }};">{{ __('email.reminder.button_note') }}</p>

    <p style="margin:0;font-size:13px;line-height:1.55;color:{{ $c['muted'] }};">{{ $closing }}</p>
@endsection
