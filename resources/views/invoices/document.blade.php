{{--
    The shell both printable documents render inside.

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

    NOTHING IS UNESCAPED HERE OR IN EITHER DOCUMENT EXCEPT MONEY. Names,
    addresses, gift messages and order notes are typed by whoever placed the
    order and arrive raw by design (see InvoiceDocument). Every one of them goes
    through {{ }}. The money strings come from Money::format(), which escapes
    the operator-supplied currency symbol itself, and only those use {!! !!}.
--}}<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- A print document is never a page a search engine should hold. --}}
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>@yield('title') — {{ $doc['orderNumber'] }}</title>
    <style>
        @page { size: A4; margin: 14mm 14mm 16mm; }

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
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
                         Helvetica, Arial, "Noto Sans", sans-serif;
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
        <h1>@yield('title') · {{ $doc['orderNumber'] }}</h1>
        <span class="spacer"></span>
        <span class="hint">Print, or choose “Save as PDF” in the print dialog.</span>
        @yield('toolbar')
        <button type="button" onclick="window.print()">Print</button>
    </div>

    <div class="sheet">
        @yield('sheet')
    </div>
</body>
</html>
