{{-- The A6 sheet the dispatch label prints on.

     SHARED WITH invoices/bulk.blade.php, which prints a run of labels on the
     same stock and must set the same page box and the same .sheet.lbl rules.
     A second copy of these twenty-six lines is a second answer to how big a
     label is, and the two would disagree the first time one was touched.

     THE INDENT IS ON THE @include SIDE, not in this file: PhpEngine::
     evaluatePath returns ltrim(ob_get_clean()), so leading whitespace in an
     included file never reaches the output. See the longer note beside the
     sheet partials. --}}    @page { size: 105mm 148mm; margin: 6mm; }
