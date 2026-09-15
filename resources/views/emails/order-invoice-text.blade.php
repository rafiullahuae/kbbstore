{{--
    The emailed invoice, as plain text.

    WHY {!! !!} AND NOT {{ }} HERE. This part is text/plain. Blade's {{ }} escapes
    for HTML, which in a text body is not a safety measure but a corruption: a
    customer called "Ben & Jerry" would be invoiced as "Ben &amp; Jerry". There is
    no markup context to inject into, so there is nothing to escape into. The HTML
    part, which does have one, escapes everything without exception.

    Money here is the presenter's `plain` variants — Money::plain(), markup-free
    by contract — never the `html` ones.

    Every conditional sits on its own line: Blade compiles a directive to
    `<?php ... ?>` and PHP swallows the newline straight after the closing tag, so
    an inline @if leaves stray indentation behind. Invisible in HTML, not in a
    document somebody files.
--}}
TAX INVOICE
@if ($doc['invoiceReference'] !== '')
Invoice {!! $doc['invoiceReference'] !!}
@endif
Order {!! $doc['orderNumber'] !!}
@if ($doc['invoicedAt'] !== '')
Issued {!! $doc['invoicedAt'] !!}
@endif
@if ($doc['placedAt'] !== '')
Ordered {!! $doc['placedAt'] !!}
@endif

FROM
{!! $doc['seller']['name'] !!}
@foreach ($doc['seller']['addressLines'] as $line)
{!! $line !!}
@endforeach
@if ($doc['seller']['trn'] !== '')
TRN {!! $doc['seller']['trn'] !!}
@endif

BILL TO
@forelse ($doc['billTo'] as $line)
{!! $line !!}
@empty
Not recorded
@endforelse

DELIVER TO
@if ($doc['sameAddress'])
Same as the billing address
@else
@forelse ($doc['shipTo'] as $line)
{!! $line !!}
@empty
Not recorded
@endforelse
@endif

ITEMS
@foreach ($doc['items'] as $item)
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
@foreach ($doc['totals'] as $row)
{!! $row['label'] !!}: {!! $row['plain'] !!}
@endforeach
@if ($doc['vatNote'] !== null)
{!! $doc['vatNote']['label'] !!}: {!! $doc['vatNote']['plain'] !!}
@endif

Payment method: {!! $doc['paymentLabel'] !!}
Delivery method: {!! $doc['deliveryMethod'] !!}
@if ($doc['seller']['footer'] !== '')

{!! $doc['seller']['footer'] !!}
@endif

— K Beauty Bliss
