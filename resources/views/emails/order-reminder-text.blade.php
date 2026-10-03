{!! $order['customerName'] !== '' ? __('email.greeting.hello_named', ['name' => $order['customerName']]) : __('email.greeting.hello') !!}

{!! strtoupper($heading) !!}

{!! wordwrap($body, 72) !!}

@include('emails.partials.body-text')

{!! mb_strtoupper(__('email.reminder.button')) !!}
{!! $payUrl !!}

{!! wordwrap(__('email.reminder.button_note'), 72) !!}

{!! wordwrap($closing, 72) !!}

@include('emails.partials.support-text')
