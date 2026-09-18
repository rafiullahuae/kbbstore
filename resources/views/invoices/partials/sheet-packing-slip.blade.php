{{--
The body of the picking sheet, no prices — one order's sheet, and nothing around it.

IT LIVES HERE SO THAT THE BULK DOCUMENT AND THE SINGLE ONE CANNOT DRIFT.
resources/views/invoices/packing-slip.blade.php includes it once inside its own
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
            <div class="doctype">{{ __('invoice.packing.doctype') }}</div>
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

    {{-- The order number as bars, so the bench scans the parcel back into the
         admin instead of reading nine characters off the sheet and typing them.
         Drawn in CSS by App\Support\Code128 - nothing is fetched and no font is
         loaded, and that class's header says why each of the ordinary ways of
         making a barcode is closed on this host. --}}
    <div style="margin-top:12px;display:inline-block">
        @include('invoices.partials.barcode', ['value' => $doc['orderNumber'], 'height' => '11mm'])
    </div>

    <hr class="rule">

    @include('invoices.partials.parties', [
        'billLabel' => __('invoice.packing.ordered_by'),
        'shipLabel' => __('email.invoice.deliver_to'),
        'collapseSame' => false,
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
                <th class="num" style="width:16mm">{{ __('email.items.col_qty') }}</th>
                <th>{{ __('email.items.col_item') }}</th>
                <th style="width:34mm">{{ __('invoice.packing.col_sku') }}</th>
                <th class="num" style="width:18mm">{{ __('invoice.packing.col_picked') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doc['items'] as $item)
                <tr>
                    <td class="num"><strong>{{ $item['quantity'] }}</strong></td>
                    <td>
                        <div class="it-name" dir="auto">{{ $item['name'] }}</div>
                        @php
                            $sub = array_values(array_filter([$item['brand'], $item['variant']], fn ($v) => $v !== ''));
                        @endphp
                        @if ($sub !== [])
                            <div class="it-sub" dir="auto">{{ implode(' · ', $sub) }}</div>
                        @endif
                    </td>
                    <td dir="auto">{{ $item['sku'] !== '' ? $item['sku'] : '—' }}</td>
                    {{-- A box to tick with a pen while picking. --}}
                    <td class="num" style="color:#aab0b9">☐</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($doc['customerNote'] !== '' || $doc['giftNote'] !== '')
        <div class="notes">
            @if ($doc['giftNote'] !== '')
                <div class="note">
                    <div class="label">{{ __('invoice.packing.gift_message') }}</div>
                    <div class="body" dir="auto">{{ $doc['giftNote'] }}</div>
                </div>
            @endif
            @if ($doc['customerNote'] !== '')
                <div class="note">
                    <div class="label">{{ __('invoice.packing.customer_note') }}</div>
                    <div class="body" dir="auto">{{ $doc['customerNote'] }}</div>
                </div>
            @endif
        </div>
    @endif

    <div class="foot">{{ __('invoice.packing.footer') }}</div>
