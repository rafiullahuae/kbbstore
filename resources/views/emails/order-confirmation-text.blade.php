Hello{!! $order['customerName'] !== '' ? ' ' . $order['customerName'] : '' !!},

Thank you — we have your order and are getting it ready. Here is everything
that was on it.

@include('emails.partials.body-text')

TRACK YOUR ORDER
{!! $order['trackUrl'] !!}

That link opens on the device you ordered from. Anywhere else, sign in to your
account and your orders are all listed there under {!! $order['number'] !!}:
{!! $order['accountUrl'] !!}

— K Beauty Bliss
