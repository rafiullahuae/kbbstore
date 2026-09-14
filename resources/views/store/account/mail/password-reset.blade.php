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
    <p>Hello{{ $name ? ' ' . $name : '' }},</p>

    <p>Somebody asked to reset the password on your K Beauty Bliss account. If that was you, open the link below and choose a new one.</p>

    <p style="margin:24px 0;">
        <a href="{{ $url }}" style="display:inline-block;padding:12px 22px;background:#1d1d1f;color:#ffffff;text-decoration:none;border-radius:6px;">Set a new password</a>
    </p>

    <p style="font-size:13px;color:#555;">Or paste this into your browser:<br>
        <span style="word-break:break-all;">{{ $url }}</span></p>

    <p>The link can be used once, and expires in {{ $minutes }} minutes.</p>

    @if ($retiresOldPassword)
        <p>Once you set a new password, your previous one — including the password you used on our old website — will stop working, and you will be signed out on any other device.</p>
    @endif

    <p>If you did not ask for this, you can ignore this message. Your password has not changed and nobody has been given access to your account.</p>

    <p style="color:#555;">— K Beauty Bliss</p>
</div>
