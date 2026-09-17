{!! $order['customerName'] !== '' ? __('email.greeting.hello_named', ['name' => $order['customerName']]) : __('email.greeting.hello') !!}

{!! strtoupper($heading) !!}

{{-- Wrapped at 72 columns. A text/plain part has no reflow: an unwrapped
     paragraph is one very long line, and a mail client that does not soft-wrap
     shows it running off the side. Both strings come from
     OrderStatusChanged::WORDING, so there is nothing customer-supplied here to
     wrap awkwardly. The closing tag sits on this line because a Blade comment
     removes its own text but not the newline after it, and a stray blank line
     is visible in a text part. --}}{!! wordwrap($body, 72) !!}

@include('emails.partials.body-text')

{!! mb_strtoupper(__('email.order_status.view_order')) !!}
{!! $order['trackUrl'] !!}

{!! wordwrap(__('email.text.device_note_status', ['number' => $order['number']]), 78) !!}
{!! $order['accountUrl'] !!}

@include('emails.partials.support-text')
