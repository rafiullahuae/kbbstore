{!! $order['customerName'] !== '' ? __('email.text.thank_you_named', ['name' => $order['customerName']]) : __('email.text.thank_you') !!}

{!! wordwrap(__('email.confirmation.lead'), 78) !!}

@include('emails.partials.body-text')

{!! mb_strtoupper(__('email.confirmation.track_button')) !!}
{!! $trackSignedUrl !!}

{!! wordwrap(__('email.order_status.track_note'), 78) !!}

@include('emails.partials.support-text')
