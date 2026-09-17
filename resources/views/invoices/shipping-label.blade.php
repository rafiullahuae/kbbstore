{{--
    The dispatch label: where the parcel goes, and nothing else.

    ── WHAT THIS IS, AND WHAT IT IS NOT ────────────────────────────────────────

    It is NOT a courier's waybill. A waybill carries the courier's own tracking
    number, their routing barcode and their account, and only the courier can
    issue one — Aramex, Emirates Post and the rest mint them from their own
    sequences through their own systems. This shop's `orders` table holds no
    carrier and no tracking number at all (see the schema), so anything printed
    here that looked like a tracking code would be invented, and a parcel
    carrying an invented tracking code is a parcel nobody can trace.

    What it IS is a sender's address label: the thing that is stuck on the box
    so the box arrives, with the shop's own order number on it in a form a
    scanner can read. The courier's waybill goes on beside it.

    ── "SHIPPING LABEL" AND "DISPATCH LABEL" ARE ONE DOCUMENT ──────────────────

    The order screen lists five documents, and two of them are this one. The
    route is /shipping-label because that is the name the console's first button
    already used and a live path is not worth renaming; the sheet calls itself a
    Dispatch Label because that is what it is — the label a dispatcher puts on a
    parcel. There is no third thing a "dispatch label" could be that a "shipping
    label" is not, and inventing a difference to justify a fifth button would
    produce two near-identical sheets and a standing question about which one to
    print. The integrator should point BOTH buttons at shipping_label_url, or
    drop one of them; that decision lives in resources/views/admin/app.blade.php,
    which this lane does not own.

    ── WHAT IT DELIBERATELY DOES NOT SHOW ──────────────────────────────────────

    The label is on the OUTSIDE of the parcel. Everybody who handles the box
    reads it: the driver, the sorting hub, the neighbour who takes it in, anyone
    who walks past it on a doorstep. So it carries the address and the reference
    and stops:

      NO ITEM NAMES, NO BRANDS, NO SKUS, NO PHOTOGRAPH. A label that announces
      what is inside is a label that tells a thief which box to take, and on a
      gift it tells the recipient what they are about to unwrap.

      NO EMAIL ADDRESS. The driver rings the phone; nobody on the route needs
      the customer's inbox, and it is the one field on the order that is also a
      login identifier elsewhere.

      NO GIFT MESSAGE AND NO ORDER NOTE. Private words on the outside of a box.

      NO INVOICE NUMBER, NO PAYMENT METHOD, NO TOTAL — with the single exception
      below, which exists for the opposite reason.

      AND IT DOES NOT ALLOCATE AN INVOICE NUMBER. Only the invoice does. Sending
      a parcel is not issuing a financial document, and a label printed for an
      order that is later cancelled must not have burned a number out of a legal
      sequence.

    ── THE ONE EXCEPTION: CASH ON DELIVERY ─────────────────────────────────────

    When, and only when, the order is cash on delivery AND nothing has been
    collected yet, the amount to collect is printed — large, because it is the
    number the driver has to come back with. InvoiceDocument::codToCollect()
    carries the argument for why withholding it protects nobody. On every other
    order there is no figure on this label at all.
--}}
@extends('invoices.document')

@section('title', 'Dispatch label')

{{-- A6, 105 x 148 mm: the ordinary self-adhesive label stock, and four to an A4
     sheet if the shop prints on plain paper and cuts. The margin is small
     because the sheet is small; the .sheet rule below matches it so what is on
     screen is what comes out. --}}
@section('page')
    @page { size: 105mm 148mm; margin: 6mm; }
@endsection

@section('style')
    .sheet.lbl { width: 105mm; min-height: 148mm; padding: 8mm; }
    .lbl .from {
        font-size: 10px; color: var(--ink-2); line-height: 1.45;
        padding-bottom: 6px; border-bottom: 1px solid var(--rule);
    }
    .lbl .from .n { font-weight: 700; color: var(--ink); font-size: 11px; }
    .lbl .to { margin-top: 10px; }
    .lbl .to .name { font-size: 16px; font-weight: 750; line-height: 1.25; }
    .lbl .to .addr { margin-top: 3px; font-size: 13.5px; line-height: 1.45; }
    .lbl .to div { overflow-wrap: anywhere; }
    .lbl .tel { margin-top: 7px; font-size: 14px; font-weight: 700; }
    .lbl .strip {
        display: flex; gap: 10px; margin-top: 10px;
        border-top: 1px solid var(--rule); padding-top: 7px;
        font-size: 11px; color: var(--ink-2);
    }
    .lbl .strip b { display: block; color: var(--ink); font-size: 12.5px; }
    .lbl .cod {
        margin-top: 10px; padding: 7px 10px;
        border: 2px solid var(--ink); border-radius: 5px; text-align: center;
    }
    .lbl .cod .k {
        font-size: 10px; font-weight: 750; letter-spacing: .1em;
        text-transform: uppercase; color: var(--ink-2);
    }
    .lbl .cod .v { font-size: 19px; font-weight: 800; margin-top: 1px; }
    .lbl .code { margin-top: 12px; }
    @media print {
        .sheet.lbl { width: auto; min-height: 0; padding: 0; }
    }
@endsection

{{-- The FULL class attribute, `sheet` included: document.blade.php yields this
     as the whole value, with "sheet" as the default for every document that
     defines no such section. See the note beside that element. --}}
@section('sheet-class', 'sheet lbl')

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
    @isset($deliveryNoteUrl)
        <a class="btn ghost" href="{{ $deliveryNoteUrl }}">Delivery note</a>
    @endisset
    @isset($packingSlipUrl)
        <a class="btn ghost" href="{{ $packingSlipUrl }}">Packing slip</a>
    @endisset
@endsection

@section('sheet')
    <div class="from">
        <span class="n" dir="auto">From: {{ $doc['seller']['name'] }}</span>
        @foreach ($doc['seller']['addressLines'] as $line)
            <div dir="auto">{{ $line }}</div>
        @endforeach
        @if ($doc['seller']['phone'] !== '')
            <div dir="auto">{{ $doc['seller']['phone'] }}</div>
        @endif
    </div>

    <div class="to">
        <div class="label">Deliver to</div>
        {{-- The shipping snapshot, falling back to billing exactly as
             InvoiceDocument::present() already decided — one definition of
             "where this order goes", so the label and the delivery note cannot
             name different doors. --}}
        {{-- shipToPostal, not shipTo: the same address WITHOUT its phone line.
             The phone is the one thing on a label a driver reads before they
             set off, so it is printed once, large, on its own line below —
             printing it twice, small and inside the address block, is how a
             label ends up with two numbers on it that a rushed reader assumes
             are different. --}}
        @forelse ($doc['shipToPostal'] as $i => $line)
            <div dir="auto" @class(['name' => $i === 0, 'addr' => $i !== 0])>{{ $line }}</div>
        @empty
            <div class="name">&mdash;</div>
        @endforelse

        @if ($doc['shipPhone'] !== '')
            <div class="tel" dir="auto">Tel {{ $doc['shipPhone'] }}</div>
        @endif
    </div>

    <div class="strip">
        <div>Order<b dir="auto">{{ $doc['orderNumber'] }}</b></div>
        <div>Items<b>{{ $doc['itemCount'] }}</b></div>
        <div>Service<b>{{ $doc['deliveryMethod'] }}</b></div>
    </div>

    @if ($doc['codToCollect'] !== null)
        <div class="cod">
            <div class="k">Cash on delivery &mdash; collect</div>
            <div class="v">{!! $doc['codToCollect']['html'] !!}</div>
        </div>
    @endif

    <div class="code">
        @include('invoices.partials.barcode', ['value' => $doc['orderNumber'], 'height' => '13mm'])
    </div>
@endsection
