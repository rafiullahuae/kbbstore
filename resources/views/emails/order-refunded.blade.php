@extends('emails.layout')

@section('body')
    @php $c = $brand['colours'] ?? \App\Services\Mail\EmailBranding::PALETTE; @endphp

    <p style="margin:0 0 14px;font-size:15px;color:{{ $c['ink2'] }};">{{ $order['customerName'] !== '' ? __('email.greeting.hello_named', ['name' => $order['customerName']]) : __('email.greeting.hello') }}</p>

    {{--
        TWO SETS OF WORDS, BECAUSE TWO DIFFERENT THINGS HAPPEN.

        A gateway refund really has been pushed back and lands on a statement by
        itself. A refund on a payment method with no refund API — cash on
        delivery, which is this store's ordinary one — has only been RECORDED;
        PaymentRefunder settles it `recorded_only` and notes "return the money
        by hand". Saying "we have sent it back to the payment method you used,
        it will appear on your card statement in five to ten working days" to
        someone who paid a courier in cash is not a rounding error in tone: it
        names a transfer that never happened, to an account that does not exist,
        and it buys ten days of silence while the customer waits for it.

        OrderRefunded::$settledByGateway asks the registry the same question
        PaymentRefunder asks before it settles, so the two cannot disagree about
        whether money moved.
    --}}
    <p style="margin:0 0 10px;font-size:19px;font-weight:700;line-height:1.3;color:{{ $c['ink'] }};">
        {{ $settledByGateway ? __('email.refunded.heading_sent') : __('email.refunded.heading_approved') }}
    </p>

    @if ($settledByGateway)
        <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:{{ $c['ink2'] }};">
            {!! __('email.refunded.sent_body', ['amount' => $amountHtml, 'number' => e($order['number'])]) !!}
            @if ($isPartial)
                {{ __('email.refunded.partial_note') }}
            @endif
            {{ __('email.refunded.statement_note') }}
        </p>
    @else
        <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:{{ $c['ink2'] }};">
            {!! __('email.refunded.approved_body', ['amount' => $amountHtml, 'number' => e($order['number'])]) !!}
            @if ($isPartial)
                {{ __('email.refunded.partial_note') }}
            @endif
            {{ __('email.refunded.manual_note', ['method' => $order['paymentLabel']]) }}
        </p>
    @endif

    <div style="padding:13px 15px;background:{{ $c['cream'] }};border-radius:9px;margin:0 0 4px;font-size:14px;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;">
            <tr>
                <td style="color:{{ $c['ink2'] }};">{{ __('email.refunded.row_refunded') }}</td>
                <td align="right" style="text-align:right;font-weight:700;white-space:nowrap;color:{{ $c['pinkDeep'] }};">{!! $amountHtml !!}</td>
            </tr>
            <tr>
                <td style="color:{{ $c['ink2'] }};padding-top:4px;">{{ __('email.refunded.row_order_total') }}</td>
                <td align="right" style="text-align:right;white-space:nowrap;padding-top:4px;color:{{ $c['ink'] }};">{!! $order['totalHtml'] !!}</td>
            </tr>
            <tr>
                <td style="color:{{ $c['ink2'] }};padding-top:4px;">{{ __('email.refunded.row_paid_by') }}</td>
                <td align="right" style="text-align:right;padding-top:4px;color:{{ $c['ink'] }};">{{ $order['paymentLabel'] }}</td>
            </tr>
        </table>
    </div>

    @include('emails.partials.items')
    @include('emails.partials.totals')

    {{-- "Chasing it" only means something once it has been sent. On a refund
         that has been recorded rather than pushed, the paragraph above has
         already said what happens next and who to reply to. --}}
    @if ($settledByGateway)
        <p style="margin:26px 0 0;font-size:13px;line-height:1.55;color:{{ $c['ink2'] }};">
            {{ __('email.refunded.chase_note', ['number' => $order['number']]) }}
        </p>
    @endif
@endsection
