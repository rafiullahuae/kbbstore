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

    EMAIL HTML IS NOT WEB HTML, and this file is where that is paid for:

      - Every table carries width/cellpadding/cellspacing/border attributes as
        well as CSS. Outlook renders with Word, which honours the attributes and
        ignores half the CSS.
      - Every colour is a flat colour. Gradients and box-shadows are dropped
        without a fallback; border-radius is dropped too, which is why nothing
        here depends on a rounded corner to read correctly.
      - Backgrounds are set with bgcolor AND style, because Word ignores the
        style and Gmail's web client has historically ignored the attribute.
      - The outer table is the page background. Gmail and Outlook.com both strip
        a wrapping <div>'s background, so a coloured page has to be a table cell.
      - Widths are `width="600"` plus `style="width:100%;max-width:600px"`, so a
        narrow phone client gets a fluid column and Outlook gets a fixed one.
      - No media queries anywhere. Gmail's web client strips <style> blocks from
        a message body, so a layout that needs one is a layout that breaks in the
        client most of these customers read mail in. Everything below has to work
        at one width.

    THE COLOURS ARE THE STORE'S OWN, read from EmailBranding::PALETTE, which took
    them from the :root block in resources/css/kbb/kbb.css. Not retyped here —
    a hex in this file would be a fourth place the brand pink lives.

    NOTHING IS UNESCAPED HERE OR IN ANY PARTIAL EXCEPT MONEY. Names, addresses,
    gift messages and the operator's own signature are typed by a person, and
    they arrive here raw by design — see OrderEmailPresenter and EmailBranding.
    Every one of them goes through {{ }}. The money strings come from
    Money::format(), which escapes the operator-supplied currency symbol itself,
    and only those use {!! !!}.
--}}
@php
    $c = $brand['colours'] ?? \App\Services\Mail\EmailBranding::PALETTE;
    [$markInk, $markAccent] = $brand['wordmark'] ?? ['', ''];
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $c['cream'] }}" style="width:100%;border-collapse:collapse;background:{{ $c['cream'] }};margin:0;padding:0;">
    <tr>
        <td align="center" style="padding:24px 12px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">

            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" align="center" style="width:100%;max-width:600px;border-collapse:collapse;background:{{ $c['white'] }};border-radius:12px;">

                {{-- Masthead. The logo when the owner has uploaded one and left
                     the switch on; the site's own two-part wordmark otherwise.
                     Never both, and never a broken <img> — EmailBranding returns
                     null rather than a URL it could not make absolute. --}}
                <tr>
                    <td align="center" bgcolor="{{ $c['pinkSoft'] }}" style="background:{{ $c['pinkSoft'] }};padding:26px 24px 20px;border-radius:12px 12px 0 0;">
                        @if (! empty($brand['logoUrl']))
                            <img src="{{ $brand['logoUrl'] }}" alt="{{ $brand['storeName'] ?? '' }}" width="180" style="display:block;margin:0 auto;max-width:180px;height:auto;border:0;outline:none;text-decoration:none;">
                        @else
                            <div style="font-size:23px;font-weight:800;letter-spacing:-.02em;color:{{ $c['ink'] }};">{{ $markInk }}<span style="color:{{ $c['pinkDeep'] }};">{{ $markAccent }}</span></div>
                        @endif

                        <div style="margin-top:7px;font-size:11.5px;letter-spacing:.14em;text-transform:uppercase;color:{{ $c['pinkDeep'] }};">Authentic K-Beauty, curated for you</div>
                    </td>
                </tr>

                {{-- The brand rule. A 3px band of flat colour: the one piece of
                     decoration in this template that every client renders. --}}
                <tr>
                    <td bgcolor="{{ $c['pink'] }}" style="background:{{ $c['pink'] }};font-size:0;line-height:0;height:3px;">&nbsp;</td>
                </tr>

                <tr>
                    <td style="padding:26px 24px 8px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:{{ $c['ink'] }};">
                        @yield('body')
                    </td>
                </tr>

                @if (! empty($brand['hasSupport']))
                    {{-- The support block.
                         Asked for in the owner's own words: "focus more on
                         support by showing our WhatsApp, email, Instagram etc,
                         so the user will feel more trust and comfort."

                         One row per channel rather than three side by side. A
                         three-column row is the version that collapses into
                         unreadable slivers on a 320px phone, and there is no
                         media query available to rescue it. Stacked rows are the
                         same at every width.

                         Every value is real: EmailBranding reads them from
                         settings and prints nothing for a channel the store has
                         not configured. --}}
                    <tr>
                        <td style="padding:6px 24px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $c['pinkSoft'] }}" style="width:100%;border-collapse:collapse;background:{{ $c['pinkSoft'] }};border-radius:10px;">
                                <tr>
                                    <td style="padding:16px 18px 6px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
                                        <div style="font-size:15px;font-weight:700;color:{{ $c['ink'] }};">We are here if you need us</div>
                                        <div style="margin-top:3px;font-size:13px;line-height:1.55;color:{{ $c['ink2'] }};">A real person answers. Ask us anything — a question about your order, or about what to use it with.</div>
                                    </td>
                                </tr>
                                @foreach ($brand['support'] as $channel)
                                    <tr>
                                        <td style="padding:5px 18px {{ $loop->last ? '18px' : '0' }};font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
                                                <tr>
                                                    <td width="34" style="width:34px;vertical-align:middle;padding:6px 0;">
                                                        <div style="width:26px;height:26px;line-height:26px;text-align:center;font-size:13px;font-weight:700;color:{{ $c['white'] }};background:{{ $channel['kind'] === 'whatsapp' ? $c['green'] : $c['pinkDeep'] }};border-radius:13px;">{{ $channel['kind'] === 'whatsapp' ? 'W' : ($channel['kind'] === 'instagram' ? 'I' : '@') }}</div>
                                                    </td>
                                                    <td style="vertical-align:middle;padding:6px 0;font-size:14px;color:{{ $c['ink'] }};">
                                                        <a href="{{ $channel['url'] }}" style="color:{{ $c['ink'] }};text-decoration:none;font-weight:600;">{{ $channel['value'] }}</a>
                                                        <span style="color:{{ $c['muted'] }};font-size:12.5px;">&nbsp;·&nbsp;{{ $channel['label'] }}</span>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                @endif

                @if (! empty($brand['signature']))
                    {{-- The owner's sign-off, set in Store → Mail.

                         Operator input rendered into HTML, so every line goes
                         through {{ }} and the line break is a <br> this template
                         supplies. EmailBranding hands over an array of lines
                         precisely so that the alternative — one string carrying
                         its own markup, printed unescaped — never arises. --}}
                    <tr>
                        <td style="padding:22px 24px 0;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.55;color:{{ $c['ink'] }};">
                            @foreach ($brand['signature'] as $line)
                                {{ $line }}@if (! $loop->last)<br>@endif
                            @endforeach
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding:20px 24px 26px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
                            <tr>
                                <td style="border-top:1px solid {{ $c['line'] }};padding-top:14px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:{{ $c['muted'] }};">
                                    {{-- The merchant's own alert is not a message to a customer, and
                                         telling the owner they are receiving it "because an order was
                                         placed using this email address" is nonsense addressed to the
                                         one person who already knows why.

                                         THE INVITATION TO REPLY IS GONE, because a reply reaches
                                         nobody on an install that has not been configured.

                                         Nothing in this application sets a Reply-To header — there is
                                         no replyTo() call anywhere in app/ and config/mail.php has no
                                         entry for one — so a reply goes to the From address, and
                                         MailSettings::fromAddress() derives `no-reply@<domain>` from
                                         APP_URL whenever the owner has left the From box empty. That
                                         is the default state of this screen, deliberately: the server
                                         transport needs nothing filled in, which is the whole reason
                                         it is the default. So the sentence promising that a reply
                                         "reaches us" was, on the shipped configuration, an invitation
                                         to write to a mailbox named for not being read.

                                         Nothing invented in its place. The support block above this
                                         one already prints the channels the owner HAS configured —
                                         WhatsApp, email, Instagram — each one omitted when it has no
                                         value, and each one a real address rather than a guess about
                                         where this message came from. --}}
                                    @if ($brand['customerFacing'] ?? true)
                                        You are receiving this because an order was placed with {{ $brand['storeName'] ?? '' }} using this email address.
                                    @else
                                        This is your store's new-order alert. It goes to the address set under Store → Mail,
                                        and you can switch it off under Store → Modules → Order emails.
                                    @endif
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

        </td>
    </tr>
</table>
