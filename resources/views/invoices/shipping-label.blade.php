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

@section('title', __('invoice.doc.dispatch_label'))

{{-- A6, 105 x 148 mm: the ordinary self-adhesive label stock, and four to an A4
     sheet if the shop prints on plain paper and cuts. The margin is small
     because the sheet is small; the .sheet rule below matches it so what is on
     screen is what comes out. --}}
@section('page')    @include('invoices.partials.page-dispatch-label')@endsection

@section('style')    @include('invoices.partials.style-dispatch-label')@endsection

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
        <a class="btn ghost" href="{{ $deliveryNoteUrl }}">{{ __('invoice.doc.delivery_note') }}</a>
    @endisset
    @isset($packingSlipUrl)
        <a class="btn ghost" href="{{ $packingSlipUrl }}">{{ __('invoice.doc.packing_slip') }}</a>
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
@section('sheet')    @include('invoices.partials.sheet-dispatch-label')@endsection
