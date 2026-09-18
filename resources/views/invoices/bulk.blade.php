{{--
    Many orders, one document, one Print.

    THE WHOLE FEATURE IS THE PAGE BREAK. Everything else here is plumbing: the
    sheets are drawn by exactly the partials the single documents use, the
    chrome is document.blade.php's, and the print stylesheet is the one that was
    already measured at A4 594.96x841.92pt and A6 298.08x420pt. What this file
    adds is the rule that puts each order on its own sheet of paper — and that
    is also the only thing here that can fail invisibly. Two orders that share a
    page look perfect in a browser window and come out of the printer as a
    packing slip with somebody else's delivery address halfway down it, which
    goes in a box and reaches a customer.

    So the rule is stated twice, in the modern property and the legacy one, and
    read back out of a real print run rather than trusted: see
    docs/GC-BULK-PRINTING.md for the page counts and geometry Chromium reported.

    WHY NOT ONE <iframe> PER ORDER, or a zip of files, or twenty tabs. A zip
    needs somewhere to build it and gives the operator twenty files to open. An
    iframe per order does not paginate — a browser prints the frame it can see.
    Twenty tabs is what the owner is doing today. One document with real page
    breaks is the only shape where the operator's single Print → Save as PDF
    produces one file containing twenty correct sheets.

    NO $doc HERE. Each sheet was rendered to a string by
    Admin\BulkDocumentController before this view ran, because the invoice — and
    only the invoice — has to compile inside the language its own order was
    placed in, and a View rendered later is rendered after that locale has been
    put back. $subject stands in for $doc['orderNumber'] in the shell.
--}}
@extends('invoices.document')

@section('title', __($selection->titleKey()))

{{-- The label run prints on A6 stock, exactly as a single label does, and gets
     the same page box and the same .sheet.lbl rules from the same partials. An
     invoice, packing slip or delivery note run defines no `page` section at all
     and so inherits document.blade.php's A4 default. --}}
@if ($selection->isLabel())
@section('page')    @include('invoices.partials.page-dispatch-label')@endsection
@endif

@section('style')
@if ($selection->isLabel())
    @include('invoices.partials.style-dispatch-label')
@endif

    /* ---- the page break, which is the whole point of this document ----

       EVERY SHEET BUT THE LAST. `break-after: page` on all of them would end
       the document with an empty final page — a blank sheet fed out of the
       printer for every batch, and a trailing blank page in every saved PDF,
       which is exactly the kind of small wrongness that makes an operator stop
       trusting a button.

       BOTH SPELLINGS. `break-after` is the current property and
       `page-break-after` is the one Chrome shipped first; Chrome still honours
       the legacy name and treats the modern one as an alias, but Safari on the
       shop's Mac and older WebKit builds want the legacy spelling. Writing one
       of them is a bet on which browser the owner prints from.

       `break-inside: avoid` on the sheet itself is deliberately NOT set. A
       single invoice for an order with thirty lines legitimately runs onto a
       second page, and forbidding that would either shrink it or cut it off.
       The document already refuses to split a line ITEM across a break, which
       is the rule that actually matters. */
    .bulkdoc > .sheet { break-after: page; page-break-after: always; }
    .bulkdoc > .sheet:last-child { break-after: auto; page-break-after: auto; }

    /* The counter above each sheet, and the report of anything that could not
       be found, are both .no-print: the printed sheets are byte for byte what
       the single-order document prints, which is the promise that lets an
       operator reprint one order from the order screen and get the same paper.
       BulkDocumentsTest asserts that equality rather than describing it. */
    .bulkseq {
        width: 210mm; max-width: 100%; margin: 26px auto -8px;
        font-size: 11.5px; font-weight: 650; letter-spacing: .04em;
        text-transform: uppercase; color: var(--ink-soft);
    }
    .bulkmiss {
        width: 210mm; max-width: 100%; margin: 20px auto 0;
        padding: 12px 14px; border-radius: 8px;
        background: #fff6e5; border: 1px solid #e7c98f; color: #6b4b06;
        font-size: 12.5px; line-height: 1.55;
    }
    .bulkmiss b { display: block; margin-bottom: 3px; }

    @media screen and (max-width: 820px) {
        .bulkseq, .bulkmiss { width: auto; margin-left: 12px; margin-right: 12px; }
    }
@endsection

@section('toolbar')
    {{-- No cross-links to the other three documents. On a single order those
         buttons mean "the same order, a different sheet"; here the batch is the
         operator's own selection and the honest way to print a different
         document for it is to go back and choose one, which the console does
         without losing the ticks. --}}
@endsection

@section('sheet-class', 'bulkdoc')

@section('sheet')
    @if ($missing !== [])
        <div class="bulkmiss no-print">
            <b>{{ __('invoice.bulk.missing_headline', ['count' => count($missing)]) }}</b>
            {{ __('invoice.bulk.missing_body', ['ids' => implode(', ', $missing)]) }}
        </div>
    @endif

    @foreach ($sheets as $i => $sheet)
        <div class="bulkseq no-print">
            {{ __('invoice.bulk.sheet_of', ['n' => $i + 1, 'total' => count($sheets)]) }}
            &middot; <span dir="auto">{{ $sheet['orderNumber'] }}</span>
        </div>
        {{-- lang AND dir PER SHEET, not once on <html>. A batch can hold an
             Arabic order and an English one, and the invoice follows the order.
             The shell around them is the operator's language. --}}
        {{-- `sheet lbl` for a label run, where a SINGLE label gets that class from
             its `sheet-class` section; here that section names the batch wrapper,
             so the inner sheets have to be told. Decided inline rather than by a
             second @section, because a @yield inside this one would be evaluated
             while this section is being captured — before a section defined
             lower in the file exists. --}}
        <div class="{{ $selection->isLabel() ? 'sheet lbl' : 'sheet' }}" lang="{{ $sheet['lang'] }}" dir="{{ $sheet['dir'] }}">{!! $sheet['html'] !!}</div>
    @endforeach
@endsection
