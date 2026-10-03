{{--
    "Confirm your subscription" in look A — Lane EM, from the owner's
    approved preview 15. Double opt-in: nobody is added until the button is
    pressed. The footer keeps the unsubscribe link the List-Unsubscribe header
    already names.
--}}
@extends('emails.kit.simple')
@php
    $k = \App\Services\Mail\Kit\MailKit::for($brand ?? []);
    $kitTitle = $kitTitle ?? __('email.kit.newsletter_title');
    $kitPreheader = __('email.kit.pre_newsletter');
    $kitWhy = __('email.kit.why_newsletter', ['site' => $k['site']]);
    $kitUnsubscribe = $unsubscribeUrl;
    $kitLead = new \Illuminate\Support\HtmlString(
        e(__('email.newsletter.somebody_asked', ['store' => $brand['storeName'] ?? __('email.newsletter.our')])) . ' '
        . str_replace('%%EMPHASIS%%', '<b>' . e(__('email.newsletter.not_yet_emphasis')) . '</b>', e(__('email.newsletter.not_yet', ['emphasis' => '%%EMPHASIS%%'])))
    );
@endphp

@section('kit_inner')
@include('emails.kit.hero', ['icon' => 'spark', 'tone' => 'pink', 'eyebrow' => __('email.kit.eyebrow_newsletter'), 'title' => __('email.kit.newsletter_title'), 'lead' => $kitLead])
@include('emails.kit.button', ['label' => __('store.newsletter.confirm_button'), 'href' => $confirmUrl])
@include('emails.kit.para', ['html' => trans_choice('email.newsletter.link_expiry', (int) $days) . ' ' . __('email.newsletter.do_nothing'), 'pad' => '14px 32px 28px', 'size' => 13, 'center' => true])
@endsection
