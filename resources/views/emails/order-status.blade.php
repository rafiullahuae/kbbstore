@extends('emails.layout')

@section('body')
    <p style="margin:0 0 14px;">Hello{{ $order['customerName'] !== '' ? ' ' . $order['customerName'] : '' }},</p>

    <p style="margin:0 0 10px;font-size:17px;font-weight:700;">{{ $heading }}</p>

    <p style="margin:0 0 18px;">{{ $body }}</p>

    <div style="padding:12px 14px;background:#f5f5f7;border-radius:8px;margin:0 0 4px;">
        <span style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#9096a1;">Order</span>
        <span style="font-weight:700;margin-left:6px;">{{ $order['number'] }}</span>
    </div>

    @include('emails.partials.items')
    @include('emails.partials.totals')
    @include('emails.partials.delivery')

    <p style="margin:26px 0 8px;">
        <a href="{{ $order['trackUrl'] }}" style="display:inline-block;padding:12px 22px;background:#1d1d1f;color:#ffffff;text-decoration:none;border-radius:6px;">View your order</a>
    </p>

    <p style="margin:0;font-size:13px;color:#6b7280;">
        That link opens on the device you ordered from. Anywhere else,
        <a href="{{ $order['accountUrl'] }}" style="color:#1d1d1f;">sign in to your account</a>
        and look for {{ $order['number'] }}.
    </p>
@endsection
