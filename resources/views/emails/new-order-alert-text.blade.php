{!! mb_strtoupper(__('email.text.alert_heading')) !!}

{!! __('email.text.customer', ['email' => $order['email']]) !!}@if ($order['phone'] !== '') / {!! $order['phone'] !!}@endif


@include('emails.partials.body-text', ['itemsHeading' => mb_strtoupper(__('email.text.items_heading'))])

{!! wordwrap(__('email.alert.next_step', ['number' => $order['number']]), 68) !!}

— {!! $brand['storeName'] !!}
