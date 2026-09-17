{{--
    The email-confirmation message. Same shape and same reasoning as
    password-reset.blade.php: no layout, inline styles, visible URL.
--}}
<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1d1d1f;">
    <p>{{ $name ? __('email.greeting.hello_named', ['name' => $name]) : __('email.greeting.hello') }}</p>

    <p>{{ __('email.verify.lead') }}</p>

    <p style="margin:24px 0;">
        <a href="{{ $url }}" style="display:inline-block;padding:12px 22px;background:#1d1d1f;color:#ffffff;text-decoration:none;border-radius:6px;">{{ __('email.verify.button') }}</a>
    </p>

    <p style="font-size:13px;color:#555;">{{ __('email.common.paste_link') }}<br>
        <span style="word-break:break-all;">{{ $url }}</span></p>

    <p>{{ trans_choice('email.verify.expiry', (int) $hours) }}</p>

    <p>{{ __('email.verify.not_you') }}</p>

    <p style="color:#555;">{{ __('email.common.sign_off') }}</p>
</div>
