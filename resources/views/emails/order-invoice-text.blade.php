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
{{-- Upper-cased here rather than stored that way: the heading is the owner's
     own words when he has set `invoice_doctype`, and this part's headings are
     all capitals. mb_ because the phrase may not be ASCII. --}}
{!! mb_strtoupper($doc['docType']) !!}
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
{{-- THE SIGN-OFF, FROM THE SAME PLACE THE HTML HALF READS IT — Lane DI.

     This line was the shop's name spelled out, while emails/layout.blade.php
     — the HTML part of this same message — printed `$brand['signature']`. So one
     message could go out signed two different ways: an owner who set a signature
     on Store → Mail, or who renamed the shop, changed the HTML half and not this
     one, and a customer comparing the two halves would find the shop calling
     itself two things.

     The lines and the guard are `emails/partials/support-text.blade.php`'s,
     which is what every other customer-facing text part uses. That partial is
     not included wholesale here because it also prints the support block, and
     whether the emailed invoice grows one is a separate decision from whether
     its two halves sign the same way. EmailBranding::signature() always returns
     at least one line unless branding could not be read at all, in which case
     the HTML half prints nothing either. --}}
@if (! empty($brand['signature']))
@foreach ($brand['signature'] as $line)
{!! $line !!}
@endforeach
@endif
