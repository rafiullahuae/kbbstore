{{--
    The plain-text twin of verify-email.blade.php (Lane RK, audit B2).
    Same reasoning as password-reset-text.blade.php.
--}}
{!! $name ? __('email.greeting.hello_named', ['name' => $name]) : __('email.greeting.hello') !!}

{!! __('email.verify.lead') !!}

{!! __('email.verify.button') !!}:
{!! $url !!}

{!! trans_choice('email.verify.expiry', (int) $hours) !!}

{!! __('email.verify.not_you') !!}

{!! __('email.common.sign_off') !!}
