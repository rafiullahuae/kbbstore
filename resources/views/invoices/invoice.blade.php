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
        <a class="btn ghost" href="{{ $packingSlipUrl }}">{{ __('invoice.doc.packing_slip') }}</a>
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
@section('sheet')    @include('invoices.partials.sheet-invoice')@endsection
