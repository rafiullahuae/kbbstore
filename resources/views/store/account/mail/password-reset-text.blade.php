{{--
    The plain-text twin of password-reset.blade.php (Lane RK, audit B2).

    The HTML part used to be the only part. A message with no text/plain
    alternative scores worse with spam filters and is blank in a text-only
    client, and this is the one message a locked-out customer cannot do
    without. Same words, same keys, in the same order as the HTML.

    {!! !!} throughout, as every *-text view in resources/views/emails does:
    this is text/plain, there is no markup context, and escaping would print
    &#039; where a customer expects an apostrophe. The URL is the shop's own,
    built by CustomerPasswordReset::url().
--}}
{!! $name ? __('email.greeting.hello_named', ['name' => $name]) : __('email.greeting.hello') !!}

{!! __('email.reset.lead') !!}

{!! __('email.reset.button') !!}:
{!! $url !!}

{!! trans_choice('email.reset.expiry', (int) $minutes) !!}
@if ($retiresOldPassword)

{!! __('email.reset.retires_old') !!}
@endif

{!! __('email.reset.not_you') !!}

{!! __('email.common.sign_off') !!}
