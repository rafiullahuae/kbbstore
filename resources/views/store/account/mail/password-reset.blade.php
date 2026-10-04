{{--
    "Reset your password" in look A — Lane EM, from the owner's approved
    preview 17. No shop links in the header (an account-security email asks
    for one action only). The button's href is the reset link itself; the
    wording is the existing keyed wording (email.reset.*).
--}}
@extends('emails.kit.simple')
@php
    $brand = $brand ?? \App\Services\Mail\EmailBranding::forMailable(true, 'CustomerPasswordReset');
    $k = \App\Services\Mail\Kit\MailKit::for($brand);
    $kitTitle = __('email.kit.reset_title');
    $kitPreheader = __('email.kit.pre_reset');
    $kitWhy = __('email.kit.why_reset');
    $kitNav = false;
    $kitTemplate = 'password_reset';
@endphp

@section('kit_inner')
@kitsec('hero')
@include('emails.kit.hero', ['icon' => 'key', 'tone' => 'ink', 'eyebrow' => __('email.kit.eyebrow_security'), 'title' => __('email.kit.reset_title'), 'lead' => __('email.reset.lead')])
@endkitsec
@kitsec('button')
@include('emails.kit.button', ['label' => __('email.reset.button'), 'href' => $url])
@endkitsec
@kitsec('expiry')
@include('emails.kit.para', ['html' => trans_choice('email.reset.expiry', (int) $minutes) . ($retiresOldPassword ? ' ' . __('email.reset.retires_old') : ''), 'pad' => '18px 32px 0', 'size' => 13.5])
@endkitsec
@kitsec('notice')
@include('emails.kit.notice', ['tone' => 'ink', 'html' => __('email.reset.not_you')])
@endkitsec
@include('emails.kit.gap', ['h' => 28])
@endsection
