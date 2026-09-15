A NEW ORDER HAS COME IN

Customer: {!! $order['email'] !!}@if ($order['phone'] !== '') / {!! $order['phone'] !!}@endif


@include('emails.partials.body-text', ['itemsHeading' => 'ITEMS'])

Open Orders in your store admin and search for {!! $order['number'] !!} to pick,
pack and mark it dispatched.

— K Beauty Bliss
