{!! mb_strtoupper(($kitUnpaid ?? false) ? __('email.kit.eyebrow_unpaid') : __('email.text.alert_heading')) !!}

{!! __('email.text.customer', ['email' => $order['email']]) !!}@if ($order['phone'] !== '') / {!! $order['phone'] !!}@endif


@include('emails.partials.body-text', ['itemsHeading' => mb_strtoupper(__('email.text.items_heading'))])

{!! wordwrap(($kitUnpaid ?? false) ? __('email.alert.unpaid_note', ['minutes' => \App\Services\Mail\OrderReminders::FIRST_AFTER_MINUTES, 'method' => $order['paymentLabel'], 'number' => $order['number']]) : __('email.alert.next_step', ['number' => $order['number']]), 68) !!}

— {!! $brand['storeName'] !!}
