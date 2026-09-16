{{--
    The invoice: what was bought, what it cost, and who paid for it.

    Every figure comes from InvoiceDocument at the currency's real precision.
    Money::format() rounds to whole dirhams on the storefront (displayDecimals()
    is 0 here) and an invoice may not: 21550 fils is AED 215.50 on this page,
    never AED 216.
--}}
@extends('invoices.document')

@section('title', 'Invoice')

@section('toolbar')
    <a class="btn ghost" href="{{ $packingSlipUrl }}">Packing slip</a>
@endsection

@section('sheet')
    <div class="head">
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
                    <div class="row">Invoice <b>{{ $doc['invoiceReference'] }}</b></div>
                @endif
                <div class="row">Order <b>{{ $doc['orderNumber'] }}</b></div>
                @if ($doc['invoicedAt'] !== '')
                    <div class="row">Issued {{ $doc['invoicedAt'] }}</div>
                @endif
                @if ($doc['placedAt'] !== '')
                    <div class="row">Ordered {{ $doc['placedAt'] }}</div>
                @endif
            </div>
            @if ($doc['paid'])
                <div class="stamp">Paid</div>
            @endif
        </div>
    </div>

    <hr class="rule">

    @include('invoices.partials.parties')

    <div class="facts">
        <div class="fact">
            <div class="label">Payment</div>
            <div class="v">{{ $doc['paymentLabel'] }}</div>
        </div>
        <div class="fact">
            <div class="label">Delivery</div>
            <div class="v">{{ $doc['deliveryMethod'] }}</div>
        </div>
        @if ($doc['phone'] !== '')
            <div class="fact">
                <div class="label">Phone</div>
                <div class="v">{{ $doc['phone'] }}</div>
            </div>
        @endif
        <div class="fact">
            <div class="label">Currency</div>
            <div class="v">{{ $doc['currency'] }}</div>
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th>Item</th>
                <th class="num">Qty</th>
                <th class="num">Unit price</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doc['items'] as $item)
                <tr>
                    <td>
                        <div class="it-name">{{ $item['name'] }}</div>
                        @php
                            $sub = array_values(array_filter([
                                $item['brand'],
                                $item['variant'],
                                $item['sku'] !== '' ? 'SKU ' . $item['sku'] : '',
                            ], fn ($v) => $v !== ''));
                        @endphp
                        @if ($sub !== [])
                            <div class="it-sub">{{ implode(' · ', $sub) }}</div>
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
                · TRN {{ $doc['vatNote']['trn'] }}
            @endif
        </div>
    @endif

    @if ($doc['customerNote'] !== '' || $doc['giftNote'] !== '')
        <div class="notes">
            @if ($doc['customerNote'] !== '')
                <div class="note">
                    <div class="label">Order note</div>
                    <div class="body">{{ $doc['customerNote'] }}</div>
                </div>
            @endif
            @if ($doc['giftNote'] !== '')
                <div class="note">
                    <div class="label">Gift message</div>
                    <div class="body">{{ $doc['giftNote'] }}</div>
                </div>
            @endif
        </div>
    @endif

    @if ($doc['seller']['footer'] !== '')
        <div class="foot">{{ $doc['seller']['footer'] }}</div>
    @endif
@endsection
