{{--
    A bulk print that did not happen, said in a sentence.

    Its own view and not a JSON body, for the reason InvoiceController::missing()
    gives: this route sits in a JSON group but the window it answers was opened
    by window.open(), and a browser tab showing {"error":"too_many"} as raw text
    is a worse answer than a sentence to somebody standing at a printer.

    NO EXTERNAL ASSETS, like every other document here: the web root is a
    different directory from the application root on this host, so anything
    under public/build/ is not where a view thinks it is.

    IT SAYS WHAT DID NOT HAPPEN as well as what went wrong. On an invoice run
    the question the operator will actually have is whether numbers were burnt
    out of the sequence by the attempt, and the answer is always no — every
    refusal is decided before an order is loaded. The over-the-cap message says
    so in as many words.
--}}<!doctype html>
<html lang="{{ \App\Support\Locale::htmlLang() }}" dir="{{ \App\Support\Locale::direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>{{ __('invoice.bulk.refused_title') }}</title>
    <style>
        body {
            margin: 0; padding: 48px 24px; background: #f3f4f6; color: #16161a;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
                         Helvetica, Arial, "Noto Sans", "Noto Sans Arabic",
                         "Geeza Pro", Tahoma, sans-serif;
            font-size: 14px; line-height: 1.6;
        }
        .box {
            max-width: 560px; margin: 0 auto; background: #fff; border-radius: 10px;
            padding: 26px 28px; box-shadow: 0 1px 3px rgba(0,0,0,.14), 0 12px 32px rgba(0,0,0,.08);
        }
        h1 { margin: 0 0 10px; font-size: 17px; font-weight: 700; }
        p { margin: 0; color: #4a4f58; }
        .back { margin-top: 18px; }
        .back button {
            font: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
            padding: 8px 16px; border-radius: 7px; border: 1px solid #d9dde3;
            background: #16161a; color: #fff;
        }
    </style>
</head>
<body>
    <div class="box">
        <h1 dir="auto">{{ $headline }}</h1>
        <p dir="auto">{{ $advice }}</p>
        {{-- window.close() rather than a link back: this tab was opened by
             window.open() from the Orders screen, which is still behind it with
             the operator's ticks intact. Sending them to a fresh Orders screen
             would throw the selection away. A browser that refuses to close a
             tab it did not open by script simply does nothing, which is no
             worse than having no button. --}}
        <div class="back"><button type="button" onclick="window.close()">{{ __('invoice.bulk.refused_close') }}</button></div>
    </div>
</body>
</html>
