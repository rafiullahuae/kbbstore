{{--
    The account invite. (Lane PQ — Store → Customers → Send account invite.)

    Plain, like emails/back-in-stock and emails/newsletter-confirm, and for the
    same reason: emails/layout is the order-email masthead.

    EVERY TEXT SEGMENT IS THE OWNER'S PROSE WITH THE CUSTOMER'S DETAILS ALREADY
    SUBSTITUTED, AND EVERY ONE IS ESCAPED HERE. InviteTemplate::segments() hands
    over plain strings; e() runs before nl2br(), so neither the owner's text nor
    a shopper whose name is markup can put a tag into this message. The one
    piece of markup is the button below, and its only variable part is the link
    this shop minted, also escaped.
--}}
<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1d1d1f;">
    @foreach ($segments as $segment)
        @if (! empty($segment['link']))
            <p style="margin:22px 0;">
                <a href="{{ $link }}" style="display:inline-block;padding:12px 22px;background:#1d1d1f;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;">{{ __('email.customer_invite.button') }}</a>
            </p>
        @elseif (trim($segment['text'] ?? '', "\n") !== '')
            <p style="margin:0 0 14px;">{!! nl2br(e(trim($segment['text'], "\n"))) !!}</p>
        @endif
    @endforeach

    <p style="font-size:13px;color:#555;">{{ __('email.common.paste_link') }}<br>
        <span style="word-break:break-all;">{{ $link }}</span></p>

    <p style="color:#555;">— {{ $shopName }}</p>

    <p style="font-size:12px;color:#888;border-top:1px solid #eee;padding-top:12px;">{{ __('email.customer_invite.why', ['shop' => $shopName]) }}</p>
</div>
