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

{{-- THE SHEET BODY LIVES IN A PARTIAL, AND THE FOUR SPACES BEFORE @include ARE
     LOAD-BEARING.

     The partial is shared with invoices/bulk.blade.php, which includes the same
     file once per selected order — so a column heading changed here reaches the
     batch of twenty a packer prints and the single sheet at the same time,
     which two copies of this markup could not promise.

     The whole thing is ONE LINE, and the indent is on THIS side of the
     @include, because of two measured behaviours that pull in opposite
     directions. PHP eats one newline after a `?>`, so the newline that used to
     follow @section('sheet') was never in the output; put it back by writing
     the directives on separate lines and every tracked preview under
     docs/invoice-previews/ gains a blank line. And Illuminate\View\Engines\
     PhpEngine::evaluatePath returns ltrim(ob_get_clean()), so leading
     whitespace inside an included file is stripped before it is echoed — the
     partial cannot carry its own indent. Four spaces here reproduce the
     previous output byte for byte, which is how this refactor leaves those
     five reviewed files untouched. --}}
@section('sheet')    @include('invoices.partials.sheet-delivery-note')@endsection
