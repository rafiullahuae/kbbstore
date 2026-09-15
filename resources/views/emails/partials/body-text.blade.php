{{--
    The order, as plain text. Shared by all four text parts.

    WHY {!! !!} AND NOT {{ }} HERE. This part is text/plain. Blade's {{ }} escapes
    for HTML, which in a text body is not a safety measure but a corruption: a
    customer called "Ben & Jerry" would be receipted as "Ben &amp; Jerry", and a
    price rendered by Money would arrive with its markup as literal text. There is
    no markup context to inject into, so there is nothing to escape into. The HTML
    parts, which DO have one, escape everything through {{ }} without exception.

    The money strings here are the presenter's `plain` variants — Money::plain(),
    which is markup-free by contract — never the `html` ones.

    Every conditional sits on a line of its own, because Blade compiles a
    directive to `<?php ... ?>` and PHP swallows the newline straight after the
    closing tag. Inline @if in a text template leaves stray indentation behind,
    which is invisible in HTML and not in a receipt.

    $itemsHeading lets the merchant alert say "ITEMS" where the customer's copy
    says "WHAT YOU ORDERED" — the merchant did not order anything.
--}}
Order {!! $order['number'] !!}@if ($order['placedAt'] !== '') — placed {!! $order['placedAt'] !!}@endif


{!! $itemsHeading ?? 'WHAT YOU ORDERED' !!}
@foreach ($order['items'] as $item)
- {!! $item['name'] !!}@if ($item['brand'] !== '') ({!! $item['brand'] !!})@endif

@if ($item['variant'] !== '')
  {!! $item['variant'] !!}
@endif
@if ($item['sku'] !== '')
  SKU {!! $item['sku'] !!}
@endif
  {!! $item['quantity'] !!} x {!! $item['unitPlain'] !!} = {!! $item['linePlain'] !!}
@endforeach

TOTALS
@foreach ($order['totals'] as $row)
{!! $row['label'] !!}: {!! $row['plain'] !!}
@endforeach

DELIVERY ADDRESS
@forelse ($order['address'] as $line)
{!! $line !!}
@empty
Not recorded
@endforelse

Delivery method: {!! $order['deliveryMethod'] !!}
Payment method: {!! $order['paymentLabel'] !!}
@if ($order['giftNote'] !== '')

GIFT MESSAGE
{!! $order['giftNote'] !!}
@endif
@if ($order['customerNote'] !== '')

ORDER NOTE
{!! $order['customerNote'] !!}
@endif
