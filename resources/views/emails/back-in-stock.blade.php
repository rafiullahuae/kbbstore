{{--
    "Back in stock" in look A — Lane EM, from the owner's approved preview 12.
    The message itself ($body) is still the owner's, from Store → Modules →
    Back in stock; the kit draws it as the hero's lead, under the product's
    own card (picture, brand and today's price — KitProducts::card()).
--}}
@extends('emails.kit.simple')
@php
    $k = \App\Services\Mail\Kit\MailKit::for($brand ?? []);
    $kitTitle = $kitTitle ?? __('email.kit.eyebrow_stock');
    $kitPreheader = __('email.kit.pre_stock', ['product' => $productName]);
    $kitWhy = __('email.kit.why_stock');
    $kitUnsubscribe = $unsubscribeUrl;
    $kitCard = \App\Services\Mail\Kit\KitProducts::card($productUrl, $productName);
@endphp

@section('kit_inner')
@include('emails.kit.hero', ['icon' => 'bell', 'tone' => 'pink', 'eyebrow' => __('email.kit.eyebrow_stock'), 'title' => __('email.kit.stock_title'), 'lead' => new \Illuminate\Support\HtmlString(nl2br(e($body)))])
@include('emails.kit.product-grid', ['products' => [$kitCard], 'cols' => 1, 'cta' => __('email.kit.stock_cta')])
@include('emails.kit.para', ['html' => __('email.kit.stock_once'), 'pad' => '18px 32px 28px', 'size' => 13])
@endsection
