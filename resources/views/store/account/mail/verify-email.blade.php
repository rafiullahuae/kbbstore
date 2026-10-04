{{--
    "Confirm your email" in look A — Lane EM, from the owner's approved
    preview 18. No shop links in the header; the button's href is the signed
    confirmation link; the wording is the existing keyed wording
    (email.verify.*).
--}}
@extends('emails.kit.simple')
@php
    $brand = $brand ?? \App\Services\Mail\EmailBranding::forMailable(true, 'CustomerEmailVerification');
    $k = \App\Services\Mail\Kit\MailKit::for($brand);
    $kitTitle = __('email.kit.verify_title');
    $kitPreheader = __('email.kit.pre_verify');
    $kitWhy = __('email.kit.why_verify', ['site' => $k['site']]);
    $kitNav = false;
    $kitTemplate = 'verify_email';
@endphp

@section('kit_inner')
@kitsec('hero')
@include('emails.kit.hero', ['icon' => 'mail', 'tone' => 'pink', 'eyebrow' => __('email.kit.eyebrow_verify'), 'title' => __('email.kit.verify_title'), 'lead' => __('email.verify.lead') . ' ' . trans_choice('email.verify.expiry', (int) $hours)])
@endkitsec
@kitsec('button')
@include('emails.kit.button', ['label' => __('email.verify.button'), 'href' => $url])
@endkitsec
@include('emails.kit.gap', ['h' => 28])
@endsection
