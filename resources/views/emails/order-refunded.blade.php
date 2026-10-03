{{--
    "Refund sent" in look A — Lane EM, from the owner's approved preview 08.

    TWO SETS OF WORDS, BECAUSE TWO DIFFERENT THINGS HAPPEN (unchanged from the
    template this replaces): a gateway refund really has been pushed back and
    lands on a statement by itself; a refund on a method with no refund API —
    cash on delivery — has only been RECORDED, and saying "we have sent it back
    to the payment method you used" to someone who paid a courier in cash names
    a transfer that never happened. OrderRefunded::$settledByGateway decides.

    The amount is the one piece of markup in the lead: <b> around the escaped
    plain amount, put in place of a placeholder AFTER the sentence is escaped.
--}}
@extends('emails.kit.order')
@php
    $k = \App\Services\Mail\Kit\MailKit::for($brand ?? []);
    $kitBold = static fn (string $key, array $replace) => str_replace('%%AMOUNT%%', '<b>' . e($amountPlain) . '</b>', e(__($key, ['amount' => '%%AMOUNT%%'] + $replace)));
    $kitLead = $settledByGateway
        ? $kitBold('email.refunded.sent_body', ['number' => $order['number']])
        : $kitBold('email.refunded.approved_body', ['number' => $order['number']]);
    if ($isPartial) {
        $kitLead .= ' ' . e(__('email.refunded.partial_note'));
    }
    if (! $settledByGateway) {
        $kitLead .= ' ' . e(__('email.refunded.manual_note', ['method' => $order['paymentLabel']]));
    }
    $kitTitle = $kitTitle ?? __('email.refunded.heading_sent');
    $kitPreheader = __('email.kit.pre_refunded', ['amount' => $amountPlain, 'number' => $order['number']]);
    $kitHero = ['back', 'green', __('email.kit.eyebrow_refund_sent'),
        $settledByGateway ? __('email.refunded.heading_sent') : __('email.refunded.heading_approved'),
        new \Illuminate\Support\HtmlString($kitLead)];
    $kitTracker = null;
    $kitShowItems = false;
    $kitShowTotals = false;
    $kitShowInfo = false;
    $kitCta = null;
    $kitWhy = __('email.kit.why_order', ['site' => $k['site']]);
@endphp

@section('kit_before')
<tr><td class="px" style="padding:0 32px;font-family:{!! $k['sans'] !!};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:18px;">
<tr><td class="ink2" style="font-family:{!! $k['sans'] !!};font-size:14px;color:#5E545A;padding:5px 0;">{{ __('email.refunded.row_refunded') }}</td><td align="right" style="font-family:{!! $k['sans'] !!};font-size:18px;font-weight:800;color:#2E9E6B;">{{ $amountPlain }}</td></tr>
<tr><td class="ink2" style="font-family:{!! $k['sans'] !!};font-size:14px;color:#5E545A;padding:5px 0;">{{ __('email.refunded.row_order_total') }}</td><td align="right" class="ink" style="font-family:{!! $k['sans'] !!};font-size:14px;color:#2A2228;">{{ $order['totalPlain'] }}</td></tr>
<tr><td class="ink2" style="font-family:{!! $k['sans'] !!};font-size:14px;color:#5E545A;padding:5px 0;">{{ __('email.refunded.row_paid_by') }}</td><td align="right" class="ink" style="font-family:{!! $k['sans'] !!};font-size:14px;color:#2A2228;">{{ $order['paymentLabel'] }}</td></tr></table></td></tr>
@if ($settledByGateway)
@include('emails.kit.para', ['html' => __('email.refunded.statement_note') . ' ' . __('email.refunded.chase_note', ['number' => $order['number']]), 'pad' => '16px 32px 0', 'size' => 14])
@endif
@endsection
