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
@section('sheet')    @include('invoices.partials.sheet-packing-slip')@endsection
