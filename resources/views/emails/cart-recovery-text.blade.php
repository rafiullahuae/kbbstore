Hello,

{{ $body }}
@if (! empty($items))

Your basket:
@foreach ($items as $item)
- {{ $item['name'] }} x {{ $item['quantity'] }}  {{ \App\Support\Money::plain($item['unit_price'] * $item['quantity']) }}
@endforeach
@endif

View your basket:
{{ $cartUrl }}

You asked us to remind you about this basket when you left your email address
with us. If you have since placed your order, thank you - please ignore this.

- {{ $brand['storeName'] ?? config('app.name') }}

Don't want these reminders? Unsubscribe here and we will stop, for good:
{{ $unsubscribeUrl }}
