{{--
    The invoice: what was bought, what it cost, and who paid for it.

    Every figure comes from InvoiceDocument at the currency's real precision.
    Money::format() rounds to whole dirhams on the storefront (displayDecimals()
    is 0 here) and an invoice may not: 21550 fils is AED 215.50 on this page,
    never AED 216.
--}}
@extends('invoices.document')

{{-- THE DOCUMENT'S OWN NAME, not a second opinion about it — Lane DG.

     This was the literal 'Invoice', and document.blade.php prints @yield('title')
     in two places: the <title> element and the on-screen toolbar. The <title> is
     what a browser offers as the default FILENAME in the Save-as-PDF dialog, so
     an owner who had set `invoice_doctype` to "Tax Invoice" — after asking his
     accountant, which is the only reason that box exists — got a sheet headed
     Tax Invoice and filed it as "Invoice — KBB-10427". One document, two names,
     and the wrong one is the one on his disk.

     $doc['docType'] is the same string the masthead prints, so the two cannot
     disagree. With the box blank it is 'Invoice' on every order that charged no
     tax, which is what this line said before, to the byte. The packing slip
     keeps its literal: a packing slip is a packing slip whatever the invoice
     calls itself.

     AND IT IS ESCAPED EXACTLY ONCE, which is worth writing down because the two
     halves of that pull in opposite directions and this value is operator input
     from the settings table — the class of value this project has already had to
     patch twice.

     `@yield` does NOT escape: it compiles to a bare
     `echo $__env->yieldContent('title')`, and document.blade.php yields this
     into <title> and into the toolbar's <h1>. What covers it is the other end.
     `@section` WITH A VALUE compiles to `startSection('title', $value)`, and
     Laravel's ManagesLayouts::startSection() passes anything that is not a View
     through e() before storing it. So the escaping happens on the way IN, once,
     and both yield sites are safe.

     Which is also why there is no e() around this expression. Adding one would
     escape it a second time and print `&amp;lt;script&amp;gt;` in the browser's
     tab and in the saved PDF's filename — a real regression dressed as caution.
     Verified by rendering, not by reading: with a heading of
     `<script>alert(1)</script>Invoice` the page emits
     `<title>&lt;script&gt;alert(1)&lt;/script&gt;Invoice — X1</title>`, and
     InvoiceIdentitySettingsTest asserts that on the <title> element itself
     rather than on the whole document, where the masthead's own {{ }} would
     satisfy the check whatever this line did. --}}
@section('title', $doc['docType'])

{{-- EVERY CROSS-LINK IS GUARDED, and that is not defensive habit.

     These four views are rendered by InvoiceController, which passes the whole
     set, AND directly by tests and previews that pass only the one or two they
     care about. A bare {{ $deliveryNoteUrl }} turns such a caller into an
     "Undefined variable" ViewException — a 500 on a document, caused by a link
     in a toolbar that does not print and that the caller never asked for. The
     toolbar is navigation between documents, not part of any document: it is
     inside .no-print and is gone the moment anything is printed. So a missing
     link drops the button and renders the sheet. --}}
@section('toolbar')
    @isset($packingSlipUrl)
        <a class="btn ghost" href="{{ $packingSlipUrl }}">Packing slip</a>
    @endisset
    @isset($deliveryNoteUrl)
        <a class="btn ghost" href="{{ $deliveryNoteUrl }}">Delivery note</a>
    @endisset
    @isset($labelUrl)
        <a class="btn ghost" href="{{ $labelUrl }}">Dispatch label</a>
    @endisset
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
                <div class="v" dir="auto">{{ $doc['phone'] }}</div>
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
                · TRN {{ $doc['vatNote']['trn'] }}
            @endif
        </div>
    @endif

    @if ($doc['customerNote'] !== '' || $doc['giftNote'] !== '')
        <div class="notes">
            @if ($doc['customerNote'] !== '')
                <div class="note">
                    <div class="label">Order note</div>
                    <div class="body" dir="auto">{{ $doc['customerNote'] }}</div>
                </div>
            @endif
            @if ($doc['giftNote'] !== '')
                <div class="note">
                    <div class="label">Gift message</div>
                    <div class="body" dir="auto">{{ $doc['giftNote'] }}</div>
                </div>
            @endif
        </div>
    @endif

    @if ($doc['seller']['footer'] !== '')
        <div class="foot" dir="auto">{{ $doc['seller']['footer'] }}</div>
    @endif
@endsection
