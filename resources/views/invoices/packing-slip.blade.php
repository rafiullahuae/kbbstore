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

@section('title', 'Packing slip')

@section('toolbar')
    <a class="btn ghost" href="{{ $invoiceUrl }}">Invoice</a>
@endsection

@section('sheet')
    <div class="head">
        <div class="who">
            @include('invoices.partials.seller')
        </div>

        <div class="what">
            <div class="doctype">Packing Slip</div>
            <div class="docmeta">
                <div class="row">Order <b>{{ $doc['orderNumber'] }}</b></div>
                @if ($doc['invoiceReference'] !== '')
                    <div class="row">Invoice <b>{{ $doc['invoiceReference'] }}</b></div>
                @endif
                @if ($doc['placedAt'] !== '')
                    <div class="row">Ordered {{ $doc['placedAt'] }}</div>
                @endif
            </div>
            @if ($doc['isGift'])
                <div class="stamp">Gift</div>
            @endif
        </div>
    </div>

    <hr class="rule">

    @include('invoices.partials.parties', [
        'billLabel' => 'Ordered by',
        'shipLabel' => 'Deliver to',
        'collapseSame' => false,
    ])

    <div class="facts">
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
            <div class="label">Items</div>
            <div class="v">{{ $doc['itemCount'] }}</div>
        </div>
        <div class="fact">
            <div class="label">Status</div>
            <div class="v">{{ $doc['orderStatus'] }}</div>
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th class="num" style="width:16mm">Qty</th>
                <th>Item</th>
                <th style="width:34mm">SKU</th>
                <th class="num" style="width:18mm">Picked</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doc['items'] as $item)
                <tr>
                    <td class="num"><strong>{{ $item['quantity'] }}</strong></td>
                    <td>
                        <div class="it-name">{{ $item['name'] }}</div>
                        @php
                            $sub = array_values(array_filter([$item['brand'], $item['variant']], fn ($v) => $v !== ''));
                        @endphp
                        @if ($sub !== [])
                            <div class="it-sub">{{ implode(' · ', $sub) }}</div>
                        @endif
                    </td>
                    <td>{{ $item['sku'] !== '' ? $item['sku'] : '—' }}</td>
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
                    <div class="label">Gift message — write this on the card</div>
                    <div class="body">{{ $doc['giftNote'] }}</div>
                </div>
            @endif
            @if ($doc['customerNote'] !== '')
                <div class="note">
                    <div class="label">Note from the customer</div>
                    <div class="body">{{ $doc['customerNote'] }}</div>
                </div>
            @endif
        </div>
    @endif

    <div class="foot">No prices are shown on this sheet. It is safe to put in the parcel, including for a gift.</div>
@endsection
