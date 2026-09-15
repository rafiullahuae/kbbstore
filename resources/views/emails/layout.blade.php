{{--
    The shell every order email renders inside.

    Plain HTML with inline styles and no layout include from the storefront, for
    the same reasons store/account/mail/password-reset.blade.php gives: this is a
    message body, read by clients that discard stylesheets, strip classes and
    rewrite anything clever — and the storefront layout would pull in Vite's asset
    tags, which do not resolve in the application directory on this host at all
    (CLAUDE.md, public/build).

    No <html>, <head> or <body> either. Symfony Mime wraps the part; every mail
    client re-wraps it; a second document element is one more thing for Outlook to
    mangle. Tables rather than flexbox, because Outlook renders neither grid nor
    flex.

    NOTHING IS UNESCAPED HERE OR IN ANY PARTIAL EXCEPT MONEY. Names, addresses and
    gift messages are typed by whoever placed the order, and they arrive here raw
    by design — see OrderEmailPresenter. Every one of them goes through {{ }}.
    The money strings come from Money::format(), which escapes the operator's
    currency symbol itself, and only those use {!! !!}.
--}}
<div style="margin:0;padding:24px 12px;background:#f5f5f7;">
    <div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:10px;padding:28px 26px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1d1d1f;">

        <div style="font-size:17px;font-weight:700;letter-spacing:.02em;margin-bottom:22px;color:#1d1d1f;">K Beauty Bliss</div>

        @yield('body')

        <hr style="border:0;border-top:1px solid #e6e6ea;margin:28px 0 16px;">

        <p style="margin:0;font-size:12.5px;color:#6b7280;line-height:1.55;">
            You are receiving this because an order was placed with K Beauty Bliss using this email address.
            Reply to this message if anything looks wrong.
        </p>
    </div>
</div>
