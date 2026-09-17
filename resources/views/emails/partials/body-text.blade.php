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

    QUANTITY IS LABELLED HERE TOO. The HTML part gained a Qty column; this one
    gained the word. It used to read "2 x AED 199.00 = AED 398.00", one run of
    figures in which the quantity is the one that looks least like a quantity.
    Now each line names all three — how many, what each, what that came to — so
    the text part and the HTML part say the same thing in the same order, which
    is the whole reason both render the same presenter array.
--}}
{!! __('email.text.order_line', ['number' => $order['number']]) !!}@if ($order['placedAt'] !== '') — {!! __('email.confirmation.placed_on', ['date' => $order['placedAt']]) !!}@endif


{!! $itemsHeading ?? mb_strtoupper(__('store.orders.what_you_ordered')) !!}
@foreach ($order['items'] as $item)
- {!! $item['name'] !!}@if ($item['brand'] !== '') ({!! $item['brand'] !!})@endif

@if ($item['variant'] !== '')
  {!! $item['variant'] !!}
@endif
@if ($item['sku'] !== '')
  {!! __('email.items.sku', ['sku' => $item['sku']]) !!}
@endif
  {!! __('email.text.item_line', ['quantity' => $item['quantity'], 'unit' => $item['unitPlain'], 'line' => $item['linePlain']]) !!}
@endforeach

{!! mb_strtoupper(__('email.text.totals_heading')) !!}
@foreach ($order['totals'] as $row)
{!! $row['label'] !!}: {!! $row['plain'] !!}
@endforeach
@if (($order['vatNote'] ?? null) !== null)
{{-- Below the rows, not among them: a portion OF the total, never an addition
     to it (D-64). The blank line above it separates it from the figures that
     do add up. See OrderEmailPresenter::vatNote(). --}}
{!! $order['vatNote']['label'] !!}: {!! $order['vatNote']['plain'] !!}
@endif

{!! mb_strtoupper(__('email.delivery.address_heading')) !!}
@forelse ($order['address'] as $line)
{!! $line !!}
@empty
{!! __('email.delivery.not_recorded') !!}
@endforelse

{!! __('email.text.delivery_method', ['method' => $order['deliveryMethod']]) !!}
{!! __('email.text.payment_method', ['method' => $order['paymentLabel']]) !!}
@if ($order['giftNote'] !== '')

{!! mb_strtoupper(__('email.delivery.gift_heading')) !!}
{!! $order['giftNote'] !!}
@endif
@if ($order['customerNote'] !== '')

{!! mb_strtoupper(__('email.delivery.note_heading')) !!}
{!! $order['customerNote'] !!}
@endif
