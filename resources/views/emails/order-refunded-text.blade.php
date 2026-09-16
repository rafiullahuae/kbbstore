Hello{!! $order['customerName'] !== '' ? ' ' . $order['customerName'] : '' !!},

{{-- The same two cases the HTML part renders, in the same words. A gateway
     refund has really been pushed back; a refund on a payment method with no
     refund API (cash on delivery, this store's ordinary one) has only been
     recorded and a person still has to return the money. See
     App\Mail\OrderRefunded::$settledByGateway. The closing tag and the @if sit
     on one line because a Blade comment removes its own text but not the
     newline after it, and a stray blank line is visible in a text part. --}}@if ($settledByGateway)
YOUR REFUND IS ON ITS WAY

{{-- Wrapped at 72 columns: a text/plain part does not reflow, so an unwrapped
     sentence is one long line in any client that does not soft-wrap. The
     closing tag sits on this line so the comment leaves no blank one. --}}{!! wordwrap('We have sent ' . $amountPlain . ' back to the payment method you used for order ' . $order['number'] . '.' . ($isPartial ? ' This is a partial refund — the rest of the order is unaffected.' : ''), 72) !!}

Refunds usually appear on a card statement within five to ten working days,
depending on your bank.
@else
YOUR REFUND HAS BEEN APPROVED

{!! wordwrap('We have approved a refund of ' . $amountPlain . ' on order ' . $order['number'] . '.' . ($isPartial ? ' This is a partial refund — the rest of the order is unaffected.' : '') . ' You paid by ' . $order['paymentLabel'] . ', which we cannot refund automatically, so we will arrange the money with you directly. If you have not heard from us, reply to this message and we will sort it out.', 72) !!}
@endif

Refunded: {!! $amountPlain !!}
Order total: {!! $order['totalPlain'] !!}
Paid by: {!! $order['paymentLabel'] !!}

@include('emails.partials.body-text')
@if ($settledByGateway)

If the money has not reached you in ten working days, reply to this message with
order number {!! $order['number'] !!} and we will chase it.
@endif

@include('emails.partials.support-text')
