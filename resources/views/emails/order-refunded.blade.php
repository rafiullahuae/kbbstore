@extends('emails.layout')

@section('body')
    @php $c = $brand['colours'] ?? \App\Services\Mail\EmailBranding::PALETTE; @endphp

    <p style="margin:0 0 14px;font-size:15px;color:{{ $c['ink2'] }};">Hello{{ $order['customerName'] !== '' ? ' ' . $order['customerName'] : '' }},</p>

    <p style="margin:0 0 10px;font-size:19px;font-weight:700;line-height:1.3;color:{{ $c['ink'] }};">Your refund is on its way</p>

    <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:{{ $c['ink2'] }};">
        We have sent {!! $amountHtml !!} back to the payment method you used for order {{ $order['number'] }}.
        @if ($isPartial)
            This is a partial refund — the rest of the order is unaffected.
        @endif
        Refunds usually appear on a card statement within five to ten working days, depending on your bank.
    </p>

    <div style="padding:13px 15px;background:{{ $c['cream'] }};border-radius:9px;margin:0 0 4px;font-size:14px;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;">
            <tr>
                <td style="color:{{ $c['ink2'] }};">Refunded</td>
                <td align="right" style="text-align:right;font-weight:700;white-space:nowrap;color:{{ $c['pinkDeep'] }};">{!! $amountHtml !!}</td>
            </tr>
            <tr>
                <td style="color:{{ $c['ink2'] }};padding-top:4px;">Order total</td>
                <td align="right" style="text-align:right;white-space:nowrap;padding-top:4px;color:{{ $c['ink'] }};">{!! $order['totalHtml'] !!}</td>
            </tr>
            <tr>
                <td style="color:{{ $c['ink2'] }};padding-top:4px;">Paid by</td>
                <td align="right" style="text-align:right;padding-top:4px;color:{{ $c['ink'] }};">{{ $order['paymentLabel'] }}</td>
            </tr>
        </table>
    </div>

    @include('emails.partials.items')
    @include('emails.partials.totals')

    <p style="margin:26px 0 0;font-size:13px;line-height:1.55;color:{{ $c['ink2'] }};">
        If the money has not reached you in ten working days, reply to this message with order number {{ $order['number'] }} and we will chase it.
    </p>
@endsection
