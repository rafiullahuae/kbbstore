{{--
    The back-in-stock alert.

    Plain, and deliberately NOT built on emails/layout.blade.php — the same
    choice emails/newsletter-confirm.blade.php makes and for a related reason.
    That layout is the ORDER-email masthead: it carries the shop's marketing
    footer and its support block, and this message goes to somebody who may
    never have bought anything and has agreed to exactly one email about one
    product. Dressing it as a branded campaign is how one consent turns into a
    newsletter nobody signed up for.

    EVERY SENTENCE HERE IS EITHER THE OWNER'S OR A FACT. `$body` is the owner's
    prose, printed once. The product name and the link are facts. The line about
    why this arrived, and the unsubscribe, are the shop keeping its own promise.
    There is no default marketing sentence in this file, because
    StockAlerts::messageWording() refuses to send when the owner has written
    none and a fallback here would defeat it.
--}}
<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1d1d1f;">
    <p>Hello,</p>

    {{--
        nl2br(e()) and not {!! !!}. The owner types prose into an admin box and
        that box is not a rich-text editor; escaping first means a shop whose
        admin account is compromised cannot use this to put markup — or a
        tracking pixel, or a different link — into an email sent in the shop's
        own name. Support\CheckoutLegalNotice escapes before substituting for
        the same reason.
    --}}
    <p>{!! nl2br(e($body)) !!}</p>

    <p style="margin:24px 0;">
        <a href="{{ $productUrl }}" style="display:inline-block;padding:12px 22px;background:#1d1d1f;color:#ffffff;text-decoration:none;border-radius:6px;">{{ $productName }}</a>
    </p>

    <p style="font-size:13px;color:#555;">Or paste this into your browser:<br>
        <span style="word-break:break-all;">{{ $productUrl }}</span></p>

    {{--
        WHY THIS MESSAGE ARRIVED, IN WORDS.

        Not politeness. A message a recipient cannot account for is a message
        they report as spam, and a spam complaint costs this shop's whole domain
        far more than one alert is worth. It is also true, and checkable: the
        row in `stock_alerts` was written when they pressed the button, and
        nothing else can produce this email.
    --}}
    <p style="font-size:13px;color:#555;">You asked to be told when this product came back in stock. This is that one message — we will not email you about it again unless you ask us to.</p>

    <p style="color:#555;">— {{ $brand['storeName'] ?? config('app.name') }}</p>

    <p style="font-size:12px;color:#888;border-top:1px solid #eee;padding-top:12px;">
        Never want email like this?
        <a href="{{ $unsubscribeUrl }}" style="color:#888;">Unsubscribe</a>.
    </p>
</div>
