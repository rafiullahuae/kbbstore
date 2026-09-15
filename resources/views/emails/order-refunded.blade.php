@extends('emails.layout')

@section('body')
    <p style="margin:0 0 14px;">Hello{{ $order['customerName'] !== '' ? ' ' . $order['customerName'] : '' }},</p>

    <p style="margin:0 0 10px;font-size:17px;font-weight:700;">Your refund is on its way</p>

    <p style="margin:0 0 18px;">
        We have sent {!! $amountHtml !!} back to the payment method you used for order {{ $order['number'] }}.
        @if ($isPartial)
            This is a partial refund — the rest of the order is unaffected.
        @endif
        Refunds usually appear on a card statement within five to ten working days, depending on your bank.
    </p>

    <div style="padding:12px 14px;background:#f5f5f7;border-radius:8px;margin:0 0 4px;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;">
            <tr>
                <td style="color:#4b5563;">Refunded</td>
                <td style="text-align:right;font-weight:700;white-space:nowrap;">{!! $amountHtml !!}</td>
            </tr>
            <tr>
                <td style="color:#4b5563;padding-top:4px;">Order total</td>
                <td style="text-align:right;white-space:nowrap;padding-top:4px;">{!! $order['totalHtml'] !!}</td>
            </tr>
            <tr>
                <td style="color:#4b5563;padding-top:4px;">Paid by</td>
                <td style="text-align:right;padding-top:4px;">{{ $order['paymentLabel'] }}</td>
            </tr>
        </table>
    </div>

    @include('emails.partials.items')
    @include('emails.partials.totals')

    <p style="margin:26px 0 0;font-size:13px;color:#6b7280;">
        If the money has not reached you in ten working days, reply to this message with order number {{ $order['number'] }} and we will chase it.
    </p>
@endsection
