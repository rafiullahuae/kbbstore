{{--
    The email-confirmation message. Same shape and same reasoning as
    password-reset.blade.php: no layout, inline styles, visible URL.
--}}
<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1d1d1f;">
    <p>Hello{{ $name ? ' ' . $name : '' }},</p>

    <p>Please confirm this is your email address so we can send you order updates.</p>

    <p style="margin:24px 0;">
        <a href="{{ $url }}" style="display:inline-block;padding:12px 22px;background:#1d1d1f;color:#ffffff;text-decoration:none;border-radius:6px;">Confirm my email</a>
    </p>

    <p style="font-size:13px;color:#555;">Or paste this into your browser:<br>
        <span style="word-break:break-all;">{{ $url }}</span></p>

    <p>The link expires in {{ $hours }} hours. Confirming does not change your password and does not sign you in.</p>

    <p>If you did not create an account with us, you can ignore this message.</p>

    <p style="color:#555;">— K Beauty Bliss</p>
</div>
