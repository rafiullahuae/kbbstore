{{--
The body of the handover sheet with its signature block — one order's sheet, and nothing around it.

IT LIVES HERE SO THAT THE BULK DOCUMENT AND THE SINGLE ONE CANNOT DRIFT.
resources/views/invoices/delivery-note.blade.php includes it once inside its own
@section('sheet'); invoices/bulk.blade.php includes it once per selected
order. Duplicating this markup would mean a change to a column heading here
landed on the single sheet and not on the batch of twenty the packer
actually prints, and nothing would have failed.

THE @include THAT PULLS THIS IN CARRIES NO SURROUNDING WHITESPACE, and the
comment above closes ONTO the first line of the body below with no newline
between them, for the reason document.blade.php already records: a Blade
comment is removed and the whitespace around it is not, so a stray newline
here rewrites every tracked preview under docs/invoice-previews/ with a
diff that says nothing. InvoicePreviewsTest regenerates those files on every
run, so getting this wrong shows up as a dirty tree, not as a failure.

$doc is the array Services\Invoices\InvoiceDocument::present() returns.
THE COMMENT CLOSES ONTO THE FIRST LINE OF MARKUP with no newline between
them. PHP eats one newline after a `?>`, so `@section('sheet')` followed by
a newline lost it; an `@include` echoes a string and eats nothing. A leading
newline here therefore re-indents the first element of every tracked preview
under docs/invoice-previews/ — measured, not guessed.

Nothing else is read.
--}}    <div class="head">
        <div class="who">
            @include('invoices.partials.seller')
        </div>

        <div class="what">
            <div class="doctype">{{ __('invoice.delivery_note.doctype') }}</div>
            <div class="docmeta">
                <div class="row">{!! __('email.invoice.order', ['number' => '<b>' . e($doc['orderNumber']) . '</b>']) !!}</div>
                @if ($doc['invoiceReference'] !== '')
                    <div class="row">{!! __('email.invoice.reference', ['reference' => '<b>' . e($doc['invoiceReference']) . '</b>']) !!}</div>
                @endif
                @if ($doc['placedAt'] !== '')
                    <div class="row">{{ __('email.invoice.ordered', ['date' => $doc['placedAt']]) }}</div>
                @endif
            </div>
            @if ($doc['isGift'])
                <div class="stamp">{{ __('invoice.packing.stamp_gift') }}</div>
            @endif
        </div>
    </div>

    <hr class="rule">

    {{-- $collapseSame = false. "As above" is a worse thing to read at a door
         than the address written out, and the person checking this sheet is
         checking the delivery address specifically. --}}
    @include('invoices.partials.parties', [
        'billLabel' => __('invoice.packing.ordered_by'),
        'shipLabel' => __('invoice.delivery_note.delivered_to'),
        'collapseSame' => false,
        'showEmail' => false,
    ])

    <div class="facts">
        <div class="fact">
            <div class="label">{{ __('invoice.invoice.label_delivery') }}</div>
            <div class="v">{{ $doc['deliveryMethod'] }}</div>
        </div>
        @if ($doc['phone'] !== '')
            <div class="fact">
                <div class="label">{{ __('invoice.invoice.label_phone') }}</div>
                <div class="v" dir="auto">{{ $doc['phone'] }}</div>
            </div>
        @endif
        <div class="fact">
            <div class="label">{{ __('invoice.packing.label_items') }}</div>
            <div class="v">{{ $doc['itemCount'] }}</div>
        </div>
        <div class="fact">
            <div class="label">{{ __('invoice.packing.label_status') }}</div>
            <div class="v">{{ $doc['orderStatus'] }}</div>
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th>{{ __('email.items.col_item') }}</th>
                <th class="num" style="width:24mm">{{ __('invoice.delivery_note.col_quantity') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doc['items'] as $item)
                <tr>
                    <td>
                        <div class="it-name" dir="auto">{{ $item['nameForCustomer'] }}</div>{{-- nameForCustomer, and the second sheet of the four to read it. This one GOES IN THE PARCEL: it is opened and signed by the person who ordered, so it calls each line what they called it when they bought it. The packing slip keeps `name` -- it is a picking list read at the bench, and `order_items.name` is the operator's language and its exact value. Admin\InvoiceController::deliveryNote() renders this sheet inside OrderLocale::render(), so the furniture around it is the customer's language too. See InvoiceDocument::items(). At the END of this line, because Blade removes a comment and leaves the newline it sat on -- three of those added a blank line per item to every tracked preview. --}}
                        @php
                            $sub = array_values(array_filter([$item['brand'], $item['variant']], fn ($v) => $v !== ''));
                        @endphp
                        @if ($sub !== [])
                            <div class="it-sub" dir="auto">{{ implode(' · ', $sub) }}</div>
                        @endif
                    </td>
                    <td class="num"><strong>{{ $item['quantity'] }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="sign">
        <div>
            <div class="label">{{ __('invoice.delivery_note.received_by') }}</div>
            <div class="line"></div>
            <div class="cap">{{ __('invoice.delivery_note.print_name') }}</div>
        </div>
        <div>
            <div class="label">{{ __('invoice.delivery_note.signature') }}</div>
            <div class="line"></div>
            <div class="cap">{{ __('invoice.delivery_note.on_delivery') }}</div>
        </div>
        <div>
            <div class="label">{{ __('invoice.delivery_note.date') }}</div>
            <div class="line"></div>
            <div class="cap">{{ __('invoice.delivery_note.date_format') }}</div>
        </div>
    </div>

    <div class="foot">{{ __('invoice.delivery_note.footer') }}</div>
