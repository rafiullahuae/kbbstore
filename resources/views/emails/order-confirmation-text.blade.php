Thank you{!! $order['customerName'] !== '' ? ', ' . $order['customerName'] : '' !!}

Your order is in and we are packing it with care. Everything you chose is
listed below, exactly as it was when you ordered — keep this email, it is
your receipt.

@include('emails.partials.body-text')

TRACK YOUR ORDER
{!! $order['trackUrl'] !!}

That link opens on the device you ordered from. Anywhere else, sign in to your
account and your orders are all listed there under {!! $order['number'] !!}:
{!! $order['accountUrl'] !!}

@include('emails.partials.support-text')
