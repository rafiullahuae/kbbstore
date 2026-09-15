@extends('emails.layout')

@section('body')
    <p style="margin:0 0 14px;">Hello{{ $order['customerName'] !== '' ? ' ' . $order['customerName'] : '' }},</p>

    <p style="margin:0 0 18px;">Thank you — we have your order and are getting it ready. Here is everything that was on it.</p>

    <div style="padding:12px 14px;background:#f5f5f7;border-radius:8px;margin:0 0 4px;">
        <span style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#9096a1;">Order</span>
        <span style="font-weight:700;margin-left:6px;">{{ $order['number'] }}</span>
        @if ($order['placedAt'] !== '')
            <span style="color:#6b7280;margin-left:10px;">placed {{ $order['placedAt'] }}</span>
        @endif
    </div>

    @include('emails.partials.items')
    @include('emails.partials.totals')
    @include('emails.partials.delivery')

    <p style="margin:26px 0 8px;">
        <a href="{{ $order['trackUrl'] }}" style="display:inline-block;padding:12px 22px;background:#1d1d1f;color:#ffffff;text-decoration:none;border-radius:6px;">Track your order</a>
    </p>

    {{--
        The link above only opens on the browser that placed the order — the
        order-received page is gated to that session, and no link from this app
        carries its own authority (OrderEmailPresenter::trackUrl explains why).
        Said here rather than discovered on a phone.
    --}}
    <p style="margin:0;font-size:13px;color:#6b7280;">
        That link opens on the device you ordered from. Anywhere else,
        <a href="{{ $order['accountUrl'] }}" style="color:#1d1d1f;">sign in to your account</a>
        and your orders are all listed there under {{ $order['number'] }}.
    </p>
@endsection
