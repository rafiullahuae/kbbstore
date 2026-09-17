{!! $order['customerName'] !== '' ? __('email.text.thank_you_named', ['name' => $order['customerName']]) : __('email.text.thank_you') !!}

{!! wordwrap(__('email.confirmation.lead'), 78) !!}

@include('emails.partials.body-text')

{!! mb_strtoupper(__('email.confirmation.track_button')) !!}
{!! $order['trackUrl'] !!}

{!! wordwrap(__('email.text.device_note_confirmation', ['number' => $order['number']]), 78) !!}
{!! $order['accountUrl'] !!}

@include('emails.partials.support-text')
