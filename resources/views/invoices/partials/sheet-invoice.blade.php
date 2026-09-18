{{--
The body of the printable tax invoice — one order's sheet, and nothing around it.

IT LIVES HERE SO THAT THE BULK DOCUMENT AND THE SINGLE ONE CANNOT DRIFT.
resources/views/invoices/invoice.blade.php includes it once inside its own
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
            {{-- NOT A LITERAL. Whether this document may call itself a tax
                 document depends on whether tax was actually charged on the
                 order and whether the seller has a registration number to put
                 under his name — and what the right phrase is at all is the
                 owner's question for his accountant, answered in
                 `invoice_doctype`. InvoiceDocument::docType() carries the
                 whole argument. --}}
            <div class="doctype">{{ $doc['docType'] }}</div>
            <div class="docmeta">
                @if ($doc['invoiceReference'] !== '')
                    <div class="row">{!! __('email.invoice.reference', ['reference' => '<b>' . e($doc['invoiceReference']) . '</b>']) !!}</div>
                @endif
                <div class="row">{!! __('email.invoice.order', ['number' => '<b>' . e($doc['orderNumber']) . '</b>']) !!}</div>
                @if ($doc['invoicedAt'] !== '')
                    <div class="row">{{ __('email.invoice.issued', ['date' => $doc['invoicedAt']]) }}</div>
                @endif
                @if ($doc['placedAt'] !== '')
                    <div class="row">{{ __('email.invoice.ordered', ['date' => $doc['placedAt']]) }}</div>
                @endif
            </div>
            @if ($doc['paid'])
                <div class="stamp">{{ __('invoice.invoice.stamp_paid') }}</div>
            @endif
        </div>
    </div>

    <hr class="rule">

    @include('invoices.partials.parties')

    <div class="facts">
        <div class="fact">
            <div class="label">{{ __('invoice.invoice.label_payment') }}</div>
            <div class="v">{{ $doc['paymentLabel'] }}</div>
        </div>
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
            <div class="label">{{ __('invoice.invoice.label_currency') }}</div>
            <div class="v">{{ $doc['currency'] }}</div>
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th>{{ __('email.items.col_item') }}</th>
                <th class="num">{{ __('email.items.col_qty') }}</th>
                <th class="num">{{ __('invoice.invoice.col_unit_price') }}</th>
                <th class="num">{{ __('email.invoice.col_amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doc['items'] as $item)
                <tr>
                    <td>
                        <div class="it-name" dir="auto">{{ $item['name'] }}</div>
                        @php
                            $sub = array_values(array_filter([
                                $item['brand'],
                                $item['variant'],
                                $item['sku'] !== '' ? 'SKU ' . $item['sku'] : '',
                            ], fn ($v) => $v !== ''));
                        @endphp
                        @if ($sub !== [])
                            <div class="it-sub" dir="auto">{{ implode(' · ', $sub) }}</div>
                        @endif
                    </td>
                    <td class="num">{{ $item['quantity'] }}</td>
                    <td class="num">{!! $item['unitHtml'] !!}</td>
                    <td class="num">{!! $item['lineHtml'] !!}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals-wrap">
        <table class="totals">
            @foreach ($doc['totals'] as $row)
                <tr @class(['grand' => $row['strong']])>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{!! $row['html'] !!}</td>
                </tr>
            @endforeach
        </table>
    </div>

    @if ($doc['vatNote'] !== null)
        {{--
            VAT is a display line only (decision D-64). It is stated as a
            portion OF the total, never added to it, so the figures above still
            add up exactly as charged.
        --}}
        <div class="vatnote">
            {{ $doc['vatNote']['label'] }}: {!! $doc['vatNote']['html'] !!}
            @if ($doc['vatNote']['trn'] !== '')
                · {{ __('email.invoice.trn', ['trn' => $doc['vatNote']['trn']]) }}
            @endif
        </div>
    @endif

    @if ($doc['customerNote'] !== '' || $doc['giftNote'] !== '')
        <div class="notes">
            @if ($doc['customerNote'] !== '')
                <div class="note">
                    <div class="label">{{ __('email.delivery.note_heading') }}</div>
                    <div class="body" dir="auto">{{ $doc['customerNote'] }}</div>
                </div>
            @endif
            @if ($doc['giftNote'] !== '')
                <div class="note">
                    <div class="label">{{ __('email.delivery.gift_heading') }}</div>
                    <div class="body" dir="auto">{{ $doc['giftNote'] }}</div>
                </div>
            @endif
        </div>
    @endif

    @if ($doc['seller']['footer'] !== '')
        <div class="foot" dir="auto">{{ $doc['seller']['footer'] }}</div>
    @endif
