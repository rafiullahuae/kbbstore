@extends('emails.layout')

@section('body')
    @php $c = $brand['colours'] ?? \App\Services\Mail\EmailBranding::PALETTE; @endphp

    <p style="margin:0 0 16px;font-size:19px;font-weight:700;line-height:1.3;color:{{ $c['ink'] }};">A new order has come in.</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $c['cream'] }}" style="width:100%;border-collapse:collapse;background:{{ $c['cream'] }};border-radius:9px;">
        <tr>
            <td style="padding:13px 15px;font-size:14px;line-height:1.5;color:{{ $c['ink'] }};">
                <div><span style="font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:{{ $c['muted'] }};font-weight:700;">Order</span>
                    <span style="font-weight:700;margin-left:7px;font-size:16px;color:{{ $c['pinkDeep'] }};">{{ $order['number'] }}</span></div>
                <div style="margin-top:4px;color:{{ $c['ink2'] }};">{{ $order['email'] }}@if ($order['phone'] !== '') &middot; {{ $order['phone'] }}@endif</div>
            </td>
        </tr>
    </table>

    <div style="font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:{{ $c['muted'] }};font-weight:700;margin:22px 0 -10px;">Items to pick</div>
    @include('emails.partials.items')
    @include('emails.partials.totals')
    @include('emails.partials.delivery')

    {{--
        No link to the admin. Doing so would print the `admin_path` setting into
        a mail body; CLAUDE.md lists that column among the ones that must not
        leak, and mail is the least controlled channel this app has. The order
        number is enough to find it, and is worth nothing to an interceptor.
    --}}
    <p style="margin:26px 0 0;font-size:13px;line-height:1.55;color:{{ $c['ink2'] }};">
        Open Orders in your store admin and search for {{ $order['number'] }} to pick, pack and mark it dispatched.
    </p>
@endsection
