{{-- The dispatch label's own stylesheet.

     SHARED WITH invoices/bulk.blade.php, which prints a run of labels on the
     same stock and must set the same page box and the same .sheet.lbl rules.
     A second copy of these twenty-six lines is a second answer to how big a
     label is, and the two would disagree the first time one was touched.

     THE INDENT IS ON THE @include SIDE, not in this file: PhpEngine::
     evaluatePath returns ltrim(ob_get_clean()), so leading whitespace in an
     included file never reaches the output. See the longer note beside the
     sheet partials. --}}    .sheet.lbl { width: 105mm; min-height: 148mm; padding: 8mm; }
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
