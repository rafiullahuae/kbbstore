{{--
    The password reset email.

    Plain HTML with inline styles and no layout include, on purpose: this is
    rendered into a message body and read by mail clients that discard
    stylesheets, strip classes and rewrite anything clever. The storefront
    layout would also pull in the site header, the cart count and Vite's asset
    tags, none of which mean anything in an inbox and one of which (the asset
    manifest) is not present in the application directory on this host at all —
    see CLAUDE.md on public/build.

    The link text IS the link. A message whose visible text differs from its
    href is the shape of every phishing email a customer has ever been warned
    about, and this is the one message from this shop that asks them to click
    something and type a password.
--}}
<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1d1d1f;">
    <p>{{ $name ? __('email.greeting.hello_named', ['name' => $name]) : __('email.greeting.hello') }}</p>

    <p>{{ __('email.reset.lead') }}</p>

    <p style="margin:24px 0;">
        <a href="{{ $url }}" style="display:inline-block;padding:12px 22px;background:#1d1d1f;color:#ffffff;text-decoration:none;border-radius:6px;">{{ __('email.reset.button') }}</a>
    </p>

    <p style="font-size:13px;color:#555;">{{ __('email.common.paste_link') }}<br>
        <span style="word-break:break-all;">{{ $url }}</span></p>

    <p>{{ trans_choice('email.reset.expiry', (int) $minutes) }}</p>

    @if ($retiresOldPassword)
        <p>{{ __('email.reset.retires_old') }}</p>
    @endif

    <p>{{ __('email.reset.not_you') }}</p>

    <p style="color:#555;">{{ __('email.common.sign_off') }}</p>
</div>
