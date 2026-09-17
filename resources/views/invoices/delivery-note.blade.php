{{--
    The delivery note: what was handed over, and a place to sign for it.

    ── WHERE IT SITS BETWEEN THE OTHER TWO ─────────────────────────────────────

    The packing slip is a PICKING document. It is read at the bench, facing the
    shelves, so it leads with quantity, carries the SKU to pick by, has a tick
    box per line and reprints the gift message for whoever writes the card.

    This is a HANDOVER document. It is read at the door, facing the customer, so
    it leads with what is in the parcel in the customer's own words — the
    product name, the brand, the variant — and ends with a signature block, so
    the shop can show what was delivered, to whom, and on what date, if a
    customer later says a line was short.

    The invoice is the financial record. It is the only one of the three that
    carries money.

    ── AND WHAT IT LEAVES OUT, DELIBERATELY ────────────────────────────────────

    NO PRICES. Not a unit price, not a line amount, not a subtotal, not a total,
    not a currency symbol. This sheet travels inside the parcel, and a parcel
    may be a gift opened by somebody who must not learn what the sender paid.
    DispatchDocumentsTest asserts that over the whole rendered page rather than
    over a list of fields somebody has to remember to keep up to date.

    NO SKU, and no tick boxes. Those are the warehouse's vocabulary and they
    belong on the packing slip; an internal code on a customer's copy is noise
    at best and is the shop's own data at worst.

    NO GIFT MESSAGE AND NO ORDER NOTE. Both are instructions to the shop, not
    part of what was delivered. The gift message in particular has already been
    written onto the card by then, and printing it a second time on a sheet in
    the same box hands the recipient the sender's private words twice — once
    where they were meant to appear and once where they were not.
--}}
@extends('invoices.document')

@section('title', __('invoice.doc.delivery_note'))

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
        <a class="btn ghost" href="{{ $packingSlipUrl }}">{{ __('invoice.doc.packing_slip') }}</a>
    @endisset
    @isset($labelUrl)
        <a class="btn ghost" href="{{ $labelUrl }}">{{ __('invoice.doc.dispatch_label') }}</a>
    @endisset
@endsection

@section('sheet')
    <div class="head">
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
                        <div class="it-name" dir="auto">{{ $item['name'] }}</div>
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
@endsection
