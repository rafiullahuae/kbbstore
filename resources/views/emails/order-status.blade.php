@extends('emails.layout')

@section('body')
    @php $c = $brand['colours'] ?? \App\Services\Mail\EmailBranding::PALETTE; @endphp

    <p style="margin:0 0 14px;font-size:15px;color:{{ $c['ink2'] }};">Hello{{ $order['customerName'] !== '' ? ' ' . $order['customerName'] : '' }},</p>

    <p style="margin:0 0 10px;font-size:19px;font-weight:700;line-height:1.3;color:{{ $c['ink'] }};">{{ $heading }}</p>

    <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:{{ $c['ink2'] }};">{{ $body }}</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $c['cream'] }}" style="width:100%;border-collapse:collapse;background:{{ $c['cream'] }};border-radius:9px;">
        <tr>
            <td style="padding:13px 15px;font-size:14px;color:{{ $c['ink'] }};">
                <span style="font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:{{ $c['muted'] }};font-weight:700;">Order</span>
                <span style="font-weight:700;margin-left:7px;font-size:16px;color:{{ $c['pinkDeep'] }};">{{ $order['number'] }}</span>
            </td>
        </tr>
    </table>

    @include('emails.partials.items')
    @include('emails.partials.totals')
    @include('emails.partials.delivery')

    {{-- A table cell with a bgcolor attribute, not a styled anchor: Outlook's
         Word renderer drops padding and background from an inline <a> and
         leaves a bare blue link. --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:26px 0 10px;">
        <tr>
            <td bgcolor="{{ $c['pinkDeep'] }}" style="background:{{ $c['pinkDeep'] }};border-radius:7px;">
                <a href="{{ $order['trackUrl'] }}" style="display:inline-block;padding:13px 26px;color:{{ $c['white'] }};font-size:15px;font-weight:600;text-decoration:none;">View your order</a>
            </td>
        </tr>
    </table>

    <p style="margin:0;font-size:13px;line-height:1.55;color:{{ $c['ink2'] }};">
        That link opens on the device you ordered from. Anywhere else,
        <a href="{{ $order['accountUrl'] }}" style="color:{{ $c['pinkDeep'] }};font-weight:600;">sign in to your account</a>
        and look for {{ $order['number'] }}.
    </p>
@endsection
