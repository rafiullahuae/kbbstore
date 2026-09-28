{!! __('email.greeting.hello') !!}

{{ $body }}
@if (! empty($items))

{!! __('email.text.your_basket') !!}
@foreach ($items as $item)
- {{ $item['name'] }} x {{ $item['quantity'] }}  {{ \App\Support\Money::plain($item['unit_price'] * $item['quantity']) }}
@foreach ($item['setContents'] ?? [] as $kbbSetLine)
  * {!! $kbbSetLine !!}
@endforeach
@endforeach{{-- (Lane SE) The same member list the HTML half prints, so the two parts of one reminder cannot describe the basket differently. Nothing is emitted for a line that is not a set, and each directive sits alone on its own line — the form that emits nothing, because Blade compiles a directive to a PHP close tag and PHP swallows the newline straight after one. A reminder for a basket with no set in it is byte-for-byte the reminder it was.

     {!! !!} ON THE MEMBER LINE. This part is text/plain: there is no markup context to inject into, so {{ }} would not be a safety measure but a corruption — a member called "Ben & Jerry's Balm" would be listed as "Ben &amp; Jerry&#039;s Balm". It is the rule emails/order-invoice-text.blade.php states in its own header and applies throughout. The two lines ABOVE use {{ }} and have since before this lane; they are left exactly as they are, because changing a message that already sends is not this patch's decision to make. It is named in the hand-back. --}}
@endif

{!! __('email.text.view_your_basket') !!}
{{ $cartUrl }}

{!! wordwrap(__('email.cart_recovery.why_text'), 78) !!}

- {{ $brand['storeName'] ?? config('app.name') }}

{!! __('email.text.unsubscribe_here_reminders') !!}
{{ $unsubscribeUrl }}
