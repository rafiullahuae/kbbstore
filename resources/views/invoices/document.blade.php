{{--
    The shell every printable document renders inside.

    PRINT-READY HTML, NOT A PDF, AND DELIBERATELY SO. The host is shared
    Hostinger with no shell access, and vendor/ cannot travel through the
    updater at all — it is in BuildPackage::NEVER_SHIP and in
    UpdateGuard::FORBIDDEN_PREFIXES — so a composer PDF library could never
    reach the server through a package. Every browser prints to PDF; the owner
    presses ⌘P / Ctrl+P and gets a file with selectable text, correct fonts and
    no new dependency on the critical path. @page below sets A4 and the margins,
    so what comes out of the dialog is the document and not a screenshot of a
    web page.

    NO EXTERNAL ASSETS. No Vite tag, no webfont, no image from public/build.
    CLAUDE.md: the web root is a different directory from the application root
    on this host, so compiled assets under public/build/ are not where this view
    thinks they are — and a print stylesheet that 404s prints a wall of unstyled
    text. Everything is inline, in one <style>, using system fonts.

    NOTHING IS UNESCAPED HERE OR IN ANY DOCUMENT EXCEPT MONEY. Names,
    addresses, gift messages and order notes are typed by whoever placed the
    order and arrive raw by design (see InvoiceDocument). Every one of them goes
    through {{ }}. The money strings come from Money::format(), which escapes
    the operator-supplied currency symbol itself, and only those use {!! !!}.

    ARABIC. This shop trades in the UAE and a customer may well write their name
    or their address in Arabic. Two things have to be true for that to print,
    and both are set here rather than left to luck:

      A FONT THAT HAS THE GLYPHS. No webfont can be used — CLAUDE.md is explicit
      that the web root is a different directory from the application root on
      this host, so anything under public/build/ is not where a view thinks it
      is, and a @font-face that 404s prints tofu on the live site only. The
      stack below therefore names the Arabic system faces that ship with the
      operating systems anybody here prints from: Segoe UI on Windows, Geeza
      Pro on macOS and iOS, Noto Sans Arabic on Android and Linux. This is the
      print path's advantage over a generated PDF stated concretely — the
      machine doing the rendering already owns a shaping engine and a font, so
      Arabic arrives joined and correct, where a hand-built PDF would be stuck
      with the WinAnsi core fonts and could not draw an Arabic letter at all.

      A DIRECTION THE MARKUP ACTUALLY STATES. This element used to be a fixed
      lang=en dir=ltr, on the stated grounds that "the furniture — the labels,
      the headings, the totals table — is English and must not flip". That
      premise no longer holds for one of the four documents: Admin\InvoiceController
      renders the INVOICE inside OrderLocale::render(), so an order placed in
      Arabic prints an invoice whose furniture is Arabic, to match the one
      already in the customer's inbox. A sheet of Arabic labels inside
      lang="en" dir="ltr" is a lie to every screen reader and hyphenator that
      reads it.

      So both attributes follow the locale this document is actually being
      rendered in. `dir` comes from Locale::direction(), never from the language
      alone, because this shop has two switches and not one: Arabic can be on
      while the mirrored layout is still being built, and direction() is the
      single place that answers which state the shop is in.

      TWO OF THE FOUR ARE WRAPPED, not one — this paragraph used to say the
      invoice alone. The DELIVERY NOTE joined it, because that sheet goes in the
      parcel and is opened and signed by the customer, which is the same
      argument stated one document further along. The packing slip is a picking
      list for the bench and the dispatch label is an address a courier reads;
      neither is wrapped, so for those two this resolves to exactly what it was
      hard-coded to.

      WHAT IS STILL PHYSICAL. The sheet's own stylesheet below uses physical
      sides (text-align:right on the money columns, margins that assume a
      left-hand masthead). Turning the mirrored layout on gives this document
      the right TEXT direction and not yet a mirrored layout; converting these
      rules to logical properties is lane/rtl-logical-properties' work on the
      storefront stylesheet and belongs with it, not bolted on here.

      Every element that prints CUSTOMER text carries dir="auto" regardless,
      which resolves from that value's own first strong character. Without it an
      address line like "شارع 21، فيلا 7" is laid out by the paragraph's
      direction and the trailing number is placed at the wrong end of the line —
      a silently mangled address on a parcel, which is worse than a plain one.
      dir="auto" also makes each such element a bidi isolate, so one Arabic line
      cannot reorder the English line beside it. This is the identical argument
      Money::format() already makes for the currency symbol, applied to the rest
      of the document. It is not replaced by the two attributes above: those say
      what the DOCUMENT is, dir="auto" says what one customer-supplied VALUE is,
      and an Arabic customer can have an English street address.
--}}<!doctype html>
<html lang="{{ \App\Support\Locale::htmlLang() }}" dir="{{ \App\Support\Locale::direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- A print document is never a page a search engine should hold. --}}
    <meta name="robots" content="noindex, nofollow, noarchive">
    {{-- $subject, WHEN THERE IS ONE, BECAUSE A BULK DOCUMENT HAS NO ONE ORDER.
         The four single documents pass $doc and no $subject, so this resolves to
         exactly what it was before. invoices/bulk.blade.php passes $subject
         ("12 orders") and no $doc at all — the right-hand side is never
         evaluated, so an undefined $doc there is not an error. The tab name is
         also the name the browser proposes for the saved PDF, which is the one
         place the operator sees it.

         THE COMMENT CLOSES ONTO THE TAG, as the toolbar note below already has to:
         a Blade comment is removed and the whitespace around it is not, so a
         newline here rewrites all five tracked previews. --}}<title>@yield('title') — {{ $subject ?? $doc['orderNumber'] }}</title>
    <style>
        {{-- A4 unless a document says otherwise. The dispatch label is A6 and
             says so in its own `page` section; yielding here rather than
             hard-coding the rule means one place decides the sheet and the
             label cannot end up printed in the middle of an A4 page. --}}
        @hasSection('page')
            @yield('page')
        @else
            @page { size: A4; margin: 14mm 14mm 16mm; }
        @endif

        :root {
            --ink: #16161a;
            --ink-2: #4a4f58;
            --ink-soft: #767d88;
            --rule: #d9dde3;
            --rule-soft: #ecEFF3;
            --accent: #16161a;
        }

        * { box-sizing: border-box; }

        html { background: #f3f4f6; }

        body {
            margin: 0;
            padding: 0;
            color: var(--ink);
            background: #f3f4f6;
            /* The Arabic faces are named explicitly and AFTER the Latin ones:
               a font stack is consulted per character, so English still renders
               in the system UI face while an Arabic name or address falls
               through to whichever of these the printing machine has. See the
               ARABIC note at the top of this file. */
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
                         Helvetica, Arial, "Noto Sans", "Noto Sans Arabic",
                         "Geeza Pro", "Segoe UI Historic", Tahoma, sans-serif;
            font-size: 13px;
            line-height: 1.55;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* The on-screen toolbar. Gone the moment anything prints. */
        .toolbar {
            position: sticky; top: 0; z-index: 5;
            display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
            padding: 12px 16px;
            background: #16161a; color: #fff;
        }
        .toolbar h1 { margin: 0; font-size: 14px; font-weight: 650; letter-spacing: .01em; }
        .toolbar .spacer { flex: 1 1 auto; }
        .toolbar .hint { font-size: 12px; color: #b9bec7; }
        .toolbar button, .toolbar a.btn {
            font: inherit; font-size: 12.5px; font-weight: 600;
            padding: 7px 14px; border-radius: 7px; border: 1px solid transparent;
            background: #fff; color: #16161a; cursor: pointer; text-decoration: none;
        }
        .toolbar a.ghost { background: transparent; color: #fff; border-color: #4a4f58; }

        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto 40px;
            padding: 16mm 14mm;
            background: #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,.14), 0 12px 32px rgba(0,0,0,.08);
        }

        /* ---- masthead ---- */
        .head { display: flex; gap: 20px; align-items: flex-start; }
        .head .who { flex: 1 1 auto; min-width: 0; }
        .head .what { flex: 0 0 auto; text-align: right; }
        .biz { font-size: 17px; font-weight: 750; letter-spacing: .01em; }
        .biz-lines { margin-top: 5px; font-size: 12px; color: var(--ink-2); }
        .biz-lines div { line-height: 1.5; }
        .doctype {
            font-size: 22px; font-weight: 750; letter-spacing: .04em;
            text-transform: uppercase; line-height: 1.1;
        }
        .docmeta { margin-top: 8px; font-size: 12px; color: var(--ink-2); }
        .docmeta b { color: var(--ink); font-weight: 650; }
        .docmeta .row { white-space: nowrap; }

        hr.rule { border: 0; border-top: 2px solid var(--ink); margin: 16px 0 0; }

        /* ---- address blocks ---- */
        .parties { display: flex; gap: 28px; margin-top: 18px; }
        .party { flex: 1 1 0; min-width: 0; }
        .label {
            font-size: 10px; font-weight: 750; letter-spacing: .11em;
            text-transform: uppercase; color: var(--ink-soft); margin-bottom: 5px;
        }
        .party .name { font-weight: 650; }
        .party div { overflow-wrap: anywhere; }

        /* ---- facts strip ---- */
        .facts {
            display: flex; flex-wrap: wrap; gap: 0;
            margin-top: 18px; border: 1px solid var(--rule); border-radius: 6px;
            overflow: hidden;
        }
        .fact { flex: 1 1 130px; padding: 9px 12px; border-right: 1px solid var(--rule-soft); }
        .fact:last-child { border-right: 0; }
        .fact .label { margin-bottom: 2px; }
        .fact .v { font-size: 12.5px; font-weight: 600; overflow-wrap: anywhere; }

        /* ---- line items ---- */
        table.lines { width: 100%; border-collapse: collapse; margin-top: 20px; }
        table.lines thead th {
            font-size: 10px; font-weight: 750; letter-spacing: .1em;
            text-transform: uppercase; color: var(--ink-soft);
            text-align: left; padding: 0 8px 7px; border-bottom: 1.5px solid var(--ink);
        }
        table.lines th.num, table.lines td.num { text-align: right; white-space: nowrap; }
        table.lines tbody td {
            padding: 10px 8px; border-bottom: 1px solid var(--rule-soft);
            vertical-align: top;
        }
        table.lines tbody tr:last-child td { border-bottom: 1px solid var(--rule); }
        table.lines td:first-child, table.lines th:first-child { padding-left: 0; }
        table.lines td:last-child, table.lines th:last-child { padding-right: 0; }
        .it-name { font-weight: 600; }
        .it-sub { font-size: 11.5px; color: var(--ink-soft); margin-top: 2px; }

        /* ---- totals ---- */
        .totals-wrap { display: flex; justify-content: flex-end; margin-top: 14px; }
        table.totals { border-collapse: collapse; min-width: 62mm; }
        table.totals td { padding: 4px 0; font-size: 13px; color: var(--ink-2); }
        table.totals td.num { text-align: right; padding-left: 26px; white-space: nowrap; }
        table.totals tr.grand td {
            padding-top: 9px; border-top: 2px solid var(--ink);
            font-size: 15px; font-weight: 750; color: var(--ink);
        }
        .vatnote {
            margin-top: 7px; text-align: right;
            font-size: 11.5px; color: var(--ink-soft);
        }

        /* ---- notes ---- */
        .notes { margin-top: 22px; display: flex; gap: 24px; flex-wrap: wrap; }
        .note { flex: 1 1 62mm; min-width: 0; }
        .note .body {
            margin-top: 4px; padding: 9px 11px; border-radius: 6px;
            background: #f6f7f9; border: 1px solid var(--rule-soft);
            white-space: pre-wrap; overflow-wrap: anywhere; color: var(--ink-2);
        }

        .foot {
            margin-top: 26px; padding-top: 12px; border-top: 1px solid var(--rule);
            font-size: 11.5px; color: var(--ink-soft); white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .stamp {
            display: inline-block; margin-top: 10px; padding: 4px 10px;
            border: 1.5px solid var(--ink); border-radius: 4px;
            font-size: 11px; font-weight: 750; letter-spacing: .1em;
            text-transform: uppercase;
        }

        /* ---- the order number, drawn as Code 128 ----

           Bars are ELEMENTS WITH A BORDER, not a background colour, and the
           reason is the print dialog. "Background graphics" is off by default
           in Chrome's and Safari's print settings, so a bar drawn with
           `background:#000` prints as nothing at all on a default-settings
           machine — a blank strip where the barcode should be, on paper, having
           looked perfect on screen. A border is not a background and is printed
           either way. `print-color-adjust: exact` on body asks for the other
           behaviour, but it is a request a browser is free to decline and this
           does not depend on it being granted.

           Widths are in millimetres so the module is a physical size on the
           page rather than a CSS pixel the printer rescales. .bc's own padding
           is the specification's ten-module quiet zone; without it a scanner
           finds no start to the code and reads nothing. */
        .bc {
            display: flex; align-items: stretch; background: #fff;
            height: 14mm; padding: 0 3.4mm;   /* 10 modules of quiet zone */
        }
        .bc i { display: block; flex: 0 0 auto; }
        /* A bar has no width of its own; its LEFT BORDER is the black. The
           border-left-width is written inline, in mm, one element per bar. */
        .bc i.b { width: 0; border-left-style: solid; border-left-color: #000; }
        .bc-text {
            font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
            letter-spacing: .14em; font-size: 11px; text-align: center;
            margin-top: 3px; color: var(--ink);
        }

        /* ---- signature block, for a document somebody signs on receipt ---- */
        .sign { display: flex; gap: 24px; margin-top: 24px; }
        .sign > div { flex: 1 1 0; min-width: 0; }
        .sign .line {
            margin-top: 22px; border-bottom: 1px solid var(--ink);
        }
        .sign .cap { margin-top: 5px; font-size: 10.5px; color: var(--ink-soft); }

        @yield('style')

        /* ---- what printing changes ---- */
        @media print {
            html, body { background: #fff; }
            .no-print { display: none !important; }
            .sheet {
                width: auto; min-height: 0; margin: 0; padding: 0;
                box-shadow: none;
            }
            /* A line item must not be cut in half across a page break. */
            table.lines tbody tr, .note, .facts { break-inside: avoid; page-break-inside: avoid; }
            table.lines thead { display: table-header-group; }
        }

        /* ---- what a phone changes ---- */
        @media screen and (max-width: 820px) {
            .sheet { width: auto; min-height: 0; margin: 12px; padding: 18px 16px; }
            .head, .parties, .notes { flex-direction: column; gap: 14px; }
            .head .what { text-align: left; }
            table.lines thead { display: none; }
            table.lines tbody td { display: block; padding: 2px 0; border: 0; }
            table.lines tbody td.num { text-align: left; }
            table.lines tbody tr { display: block; padding: 10px 0; border-bottom: 1px solid var(--rule-soft); }
        }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        <h1>@yield('title') · {{ $subject ?? $doc['orderNumber'] }}</h1>
        <span class="spacer"></span>
        {{-- THE TOOLBAR IS THE OPERATOR'S, EVEN WHEN THE SHEET IS NOT.

             It is inside .no-print and is gone the moment anything is printed:
             it is navigation for the person standing at the screen, and that
             person is the operator whichever language the document below is in.
             So Admin\InvoiceController resolves these two before it enters the
             order's locale and passes them in. Every other caller — the three
             other documents, the previews, the tests — passes neither and gets
             the ordinary lookup, unchanged.

             THE COMMENT CLOSES ONTO THE TAG, with no newline between them. A
             Blade comment is removed and the whitespace around it is not, so a
             comment on its own lines adds a blank line to every one of the five
             tracked previews under docs/invoice-previews/ — a diff that says
             nothing and that a green test run writes under you.
        --}}<span class="hint">{{ $toolbarHint ?? __('invoice.document.print_hint') }}</span>
        @yield('toolbar')
        <button type="button" onclick="window.print()">{{ $toolbarButton ?? __('invoice.document.print_button') }}</button>
    </div>

    {{-- THE WHOLE CLASS ATTRIBUTE IS YIELDED, WITH "sheet" AS THE DEFAULT, and
         it is written this way because the two shorter ways both fail silently.

         Blade does not match a directive whose @ is preceded by a word
         character. So `class="sheet@yield('sheet-class')"` does not compile at
         all: it renders the literal text @yield('sheet-class') into the class
         attribute, the label never receives its `lbl` class, and the A6 sheet
         prints at A4 — a bug with no error anywhere, caught here only because
         a preview file is checked in and was read. The `@hasSection` form fails
         worse: the @hasSection is left as text while its @endif compiles, which
         is a PHP parse error in a file that looks correct.

         Here the directive opens the attribute value, so it compiles, and
         @yield's second argument is what every document that defines no
         `sheet-class` section gets. The dispatch label defines "sheet lbl". --}}
{{--
    THE SAMPLE BANNER. On every document, and only on a sample order.

    IT IS HERE, IN THE LAYOUT, AND NOT IN THE FOUR SHEETS. All four documents
    extend this file, so one block puts the mark on the invoice, the packing
    slip, the delivery note AND the dispatch label -- and on the fifth document
    somebody adds next year without having read this comment. Four copies in
    four partials is four chances for one of them to be forgotten, and the one
    that gets forgotten is the one that ends up in somebody's hand.

    NOT `.no-print`. The toolbar above is, because it is navigation. This is the
    opposite: a sheet that is printed or saved as a PDF and then read on paper,
    away from the screen that said what it was, is exactly the copy that most
    needs to say so itself.

    THE STYLES ARE INLINE, WHICH IS NOT THE HOUSE STYLE AND IS DELIBERATE. Every
    other rule in this document lives in the one <style> block above. Putting
    these there would change the bytes of EVERY document this shop prints,
    including the five previews checked in under docs/invoice-previews/ that
    InvoicePreviewsTest rewrites on each run -- a diff on five reviewed files to
    add a rule that no real order can ever match. Inline, the markup and the
    rule appear together or not at all. `print-color-adjust` and its -webkit-
    twin are what stop a browser dropping the background when it prints, which
    on this banner would drop the warning with it.

    AND THE WHITESPACE AROUND THE DIRECTIVES IS LOAD-BEARING, for the same
    reason the comment above closes onto its tag. `@if` compiles to a
    `<?php ... ?>` tag and PHP SWALLOWS ONE NEWLINE immediately after `?>`, so
    where these three lines begin and end decides whether a NON-sample document
    still has the blank line that has always sat between the toolbar and the
    sheet. It does not survive being tidied: written the obvious way, with this
    comment indented like its neighbours and `@if` on a line of its own, the
    four spaces move onto the `<div class="sheet">` line and all five tracked
    previews under docs/invoice-previews/ are rewritten by a GREEN test run,
    the exact failure tests/bootstrap.php's own header warns about. So this
    comment OPENS at column 0, the directive below closes onto the line that
    ends it, and the one that closes the block sits on the line the sheet's own
    div opens. None of those three may be re-indented on its own.

    (And no paragraph here may spell the comment terminator, for a reason this
    one found out: written out, it ends the comment where it is written, the
    prose after it becomes template text, and the closing directive inside that
    prose compiles with nothing to close. The document 500s.)

    WHICH IS ASSERTED, NOT REASONED ABOUT. The three paragraphs above were
    worked out by rendering, not by reading the Blade compiler: run
    InvoicePreviewsTest, which rewrites all five files from these templates, and
    `git status docs/` is clean. That is the check to repeat after touching any
    line between here and the sheet.

    THE TEXT IS A LITERAL, NOT A TRANSLATION KEY, and that is the rule-5 answer
    rather than a shortcut. It is a warning to the SHOP, not wording for a
    customer: a sample order is never sent to one, because OrderMailer refuses
    every send for it. So it is in the operator's language whatever language the
    sheet below is in -- the same argument the toolbar carries -- and being a
    literal it is a constant, which is what CLAUDE.md asks of anything printed.
    The order number beside it is the only value here, and Blade escapes it.

    WHICH IS WHY IT CARRIES dir="ltr". Being a literal English sentence, it is
    the one element on this page whose direction does NOT follow the document.
    Without it, inside an Arabic order's RTL invoice, the bidi algorithm moves
    the sentence's trailing full stop to the LEFT-hand end and the banner reads
    ".SAMPLE ORDER - NOT A REAL ORDER. NOTHING WAS BOUGHT, PAID FOR OR SHIPPED"
    -- seen in the rendered sheet, not reasoned about. dir="auto" would not fix
    it: auto takes its direction from the first strong character, which is
    Latin, so it would answer ltr for the banner and rtl for nothing, and it
    would stop working the day a word of Arabic is added to this sentence.
    dir="ltr" states what this element is rather than guessing.
--}}@if ($doc['isSample'] ?? false)
    <div dir="ltr" style="background:#B91C1C;color:#fff;padding:10px 14px;margin:0 auto 10px;max-width:210mm;border-radius:6px;font:700 13px/1.45 system-ui,-apple-system,'Segoe UI',sans-serif;letter-spacing:.04em;text-align:center;-webkit-print-color-adjust:exact;print-color-adjust:exact">
        SAMPLE ORDER &mdash; NOT A REAL ORDER. NOTHING WAS BOUGHT, PAID FOR OR SHIPPED.
        <span style="display:block;font-weight:500;letter-spacing:0;opacity:.92;margin-top:3px">{{ $doc['orderNumber'] }} &middot; created from Safety &rarr; Demo Content &rarr; Sample order, and removed from the same place.</span>
    </div>
    @endif
    <div class="@yield('sheet-class', 'sheet')">
        @yield('sheet')
    </div>
</body>
</html>
