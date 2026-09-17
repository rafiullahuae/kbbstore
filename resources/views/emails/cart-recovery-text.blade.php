{!! __('email.greeting.hello') !!}

{{ $body }}
@if (! empty($items))

{!! __('email.text.your_basket') !!}
@foreach ($items as $item)
- {{ $item['name'] }} x {{ $item['quantity'] }}  {{ \App\Support\Money::plain($item['unit_price'] * $item['quantity']) }}
@endforeach
@endif

{!! __('email.text.view_your_basket') !!}
{{ $cartUrl }}

{!! wordwrap(__('email.cart_recovery.why_text'), 78) !!}

- {{ $brand['storeName'] ?? config('app.name') }}

{!! __('email.text.unsubscribe_here_reminders') !!}
{{ $unsubscribeUrl }}
