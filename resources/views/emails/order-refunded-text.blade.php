Hello{!! $order['customerName'] !== '' ? ' ' . $order['customerName'] : '' !!},

YOUR REFUND IS ON ITS WAY

{{-- Wrapped at 72 columns: a text/plain part does not reflow, so an unwrapped
     sentence is one long line in any client that does not soft-wrap. The
     closing tag sits on this line so the comment leaves no blank one. --}}{!! wordwrap('We have sent ' . $amountPlain . ' back to the payment method you used for order ' . $order['number'] . '.' . ($isPartial ? ' This is a partial refund — the rest of the order is unaffected.' : ''), 72) !!}

Refunds usually appear on a card statement within five to ten working days,
depending on your bank.

Refunded: {!! $amountPlain !!}
Order total: {!! $order['totalPlain'] !!}
Paid by: {!! $order['paymentLabel'] !!}

@include('emails.partials.body-text')

If the money has not reached you in ten working days, reply to this message with
order number {!! $order['number'] !!} and we will chase it.

@include('emails.partials.support-text')
