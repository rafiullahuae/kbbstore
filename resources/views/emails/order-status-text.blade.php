{!! $order['customerName'] !== '' ? __('email.greeting.hello_named', ['name' => $order['customerName']]) : __('email.greeting.hello') !!}

{!! strtoupper($heading) !!}

{{-- Wrapped at 72 columns. A text/plain part has no reflow: an unwrapped
     paragraph is one very long line, and a mail client that does not soft-wrap
     shows it running off the side. Both strings come from
     OrderStatusChanged::WORDING, so there is nothing customer-supplied here to
     wrap awkwardly. The closing tag sits on this line because a Blade comment
     removes its own text but not the newline after it, and a stray blank line
     is visible in a text part. --}}{!! wordwrap($body, 72) !!}

@if ($note !== '')
{!! __('email.order_status.onhold_need') !!} {!! wordwrap($note, 72) !!}
@endif
@if ($status === 'onhold')
{!! wordwrap(__('email.order_status.onhold_reply'), 72) !!}

@endif
@if ($trackable)
{!! __('email.order_status.tracking_number', ['number' => $order['number']]) !!}
{!! wordwrap(__('email.order_status.tracking_where'), 72) !!}

@endif
@include('emails.partials.body-text')

{!! mb_strtoupper($ctaLabel) !!}
{!! $ctaUrl !!}

{!! wordwrap($status === 'failed' ? __('email.reminder.button_note') : __('email.order_status.track_note'), 72) !!}

@include('emails.partials.support-text')
