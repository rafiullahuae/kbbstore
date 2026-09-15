@extends('emails.layout')

@section('body')
    <p style="margin:0 0 16px;font-size:16px;font-weight:600;">A new order has come in.</p>

    <div style="padding:12px 14px;background:#f5f5f7;border-radius:8px;margin:0 0 4px;">
        <div><span style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#9096a1;">Order</span>
            <span style="font-weight:700;margin-left:6px;">{{ $order['number'] }}</span></div>
        <div style="margin-top:4px;color:#4b5563;">{{ $order['email'] }}@if ($order['phone'] !== '') &middot; {{ $order['phone'] }}@endif</div>
    </div>

    <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#9096a1;margin:20px 0 -6px;">Items</div>
    @include('emails.partials.items')
    @include('emails.partials.totals')
    @include('emails.partials.delivery')

    {{--
        No link to the admin. Doing so would print the `admin_path` setting into
        a mail body; CLAUDE.md lists that column among the ones that must not
        leak, and mail is the least controlled channel this app has. The order
        number is enough to find it, and is worth nothing to an interceptor.
    --}}
    <p style="margin:26px 0 0;font-size:13px;color:#6b7280;">
        Open Orders in your store admin and search for {{ $order['number'] }} to pick, pack and mark it dispatched.
    </p>
@endsection
