Hello{!! $order['customerName'] !== '' ? ' ' . $order['customerName'] : '' !!},

{!! strtoupper($heading) !!}

{{-- Wrapped at 72 columns. A text/plain part has no reflow: an unwrapped
     paragraph is one very long line, and a mail client that does not soft-wrap
     shows it running off the side. Both strings come from
     OrderStatusChanged::WORDING, so there is nothing customer-supplied here to
     wrap awkwardly. The closing tag sits on this line because a Blade comment
     removes its own text but not the newline after it, and a stray blank line
     is visible in a text part. --}}{!! wordwrap($body, 72) !!}

@include('emails.partials.body-text')

VIEW YOUR ORDER
{!! $order['trackUrl'] !!}

That link opens on the device you ordered from. Anywhere else, sign in to your
account and look for {!! $order['number'] !!}:
{!! $order['accountUrl'] !!}

— K Beauty Bliss
