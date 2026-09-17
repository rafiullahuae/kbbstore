{!! $order['customerName'] !== '' ? __('email.greeting.hello_named', ['name' => $order['customerName']]) : __('email.greeting.hello') !!}

{{-- The same two cases the HTML part renders, in the same words. A gateway
     refund has really been pushed back; a refund on a payment method with no
     refund API (cash on delivery, this store's ordinary one) has only been
     recorded and a person still has to return the money. See
     App\Mail\OrderRefunded::$settledByGateway. The closing tag and the @if sit
     on one line because a Blade comment removes its own text but not the
     newline after it, and a stray blank line is visible in a text part. --}}@if ($settledByGateway)
{!! mb_strtoupper(__('email.refunded.heading_sent')) !!}

{{-- Wrapped at 72 columns: a text/plain part does not reflow, so an unwrapped
     sentence is one long line in any client that does not soft-wrap. The
     closing tag sits on this line so the comment leaves no blank one. --}}{!! wordwrap(__('email.refunded.sent_body', ['amount' => $amountPlain, 'number' => $order['number']]) . ($isPartial ? ' ' . __('email.refunded.partial_note') : ''), 72) !!}

{!! wordwrap(__('email.refunded.statement_note'), 78) !!}
@else
{!! mb_strtoupper(__('email.refunded.heading_approved')) !!}

{!! wordwrap(__('email.refunded.approved_body', ['amount' => $amountPlain, 'number' => $order['number']]) . ($isPartial ? ' ' . __('email.refunded.partial_note') : '') . ' ' . __('email.refunded.manual_note', ['method' => $order['paymentLabel']]), 72) !!}
@endif

{!! __('email.text.refunded_row', ['amount' => $amountPlain]) !!}
{!! __('email.text.order_total_row', ['amount' => $order['totalPlain']]) !!}
{!! __('email.text.paid_by_row', ['method' => $order['paymentLabel']]) !!}

@include('emails.partials.body-text')
@if ($settledByGateway)

{!! wordwrap(__('email.refunded.chase_note', ['number' => $order['number']]), 80) !!}
@endif

@include('emails.partials.support-text')
