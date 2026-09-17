{{--
    The packing slip: the same order, with every price removed.

    THE WHOLE POINT IS WHAT IS MISSING. This sheet goes in the parcel. A gift
    order arrives at the recipient's door, and a document in the box that prints
    what the sender paid ruins the gift — which is why there is no unit price,
    no line amount, no subtotal, no discount, no delivery charge and no total
    anywhere below, and why InvoicePackingSlipTest asserts that no money string
    and no currency symbol reaches the rendered page at all.

    What the person packing the box needs instead is on it: what to put in, how
    many of each, which variant, the SKU to pick by, where it goes, and the gift
    message to write on the card.
--}}
@extends('invoices.document')

@section('title', __('invoice.doc.packing_slip'))

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
    @isset($invoiceUrl)
        <a class="btn ghost" href="{{ $invoiceUrl }}">{{ __('invoice.doc.invoice') }}</a>
    @endisset
    @isset($deliveryNoteUrl)
        <a class="btn ghost" href="{{ $deliveryNoteUrl }}">{{ __('invoice.doc.delivery_note') }}</a>
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
@endsection
