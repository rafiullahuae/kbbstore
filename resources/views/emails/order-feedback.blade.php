{{--
    "How is your glow? 💌" — Lane EM, from the owner's approved preview 19.
    Sent once, 3 hours after the Delivered email (Lane RL decides when). One
    row per product, five stars each; every star opens that product's page at
    its reviews (#sr). RL's wording, word for word, except the line under the
    stars: RL's said "Each button opens…", and the approved design has stars,
    not buttons (email.feedback.stars_note).

    The "Share your routine 📸" box names the shop's own Instagram and is left
    out when none is set — never a handle nobody typed.
--}}
@extends('emails.kit.simple')
@php
    $k = \App\Services\Mail\Kit\MailKit::for($brand ?? []);
    $kitTitle = $kitTitle ?? $heading;
    $kitPreheader = __('email.kit.pre_feedback');
    $kitWhy = __('email.kit.why_order', ['site' => $k['site']]);
    $kitImages = \App\Services\Mail\Kit\KitProducts::imagesForIds(array_column($products, 'productId'));
    $kitInsta = collect($k['support'])->firstWhere('kind', 'instagram');
    $kitSign = $k['signature'];
    $kitSignoff = [__('email.kit.signoff_thanks'), $kitSign !== [] ? end($kitSign) : $k['storeName']];
@endphp

@section('kit_inner')
@include('emails.kit.hero', ['icon' => 'star', 'tone' => 'pink', 'eyebrow' => __('email.kit.eyebrow_feedback'), 'title' => $heading, 'lead' => $body])
@include('emails.kit.section-title', ['text' => __('email.feedback.items_heading')])
@include('emails.kit.rate-rows', ['rates' => array_map(fn (array $p) => ['img' => $kitImages[(int) ($p['productId'] ?? 0)] ?? null, 'brand' => $p['brand'], 'name' => $p['name'], 'href' => $p['url']], $products)])
@include('emails.kit.para', ['html' => __('email.feedback.stars_note'), 'pad' => '12px 32px 0', 'size' => 12.5, 'center' => true])
@if ($kitInsta !== null)
@include('emails.kit.notice', ['tone' => 'pink', 'html' => new \Illuminate\Support\HtmlString('<b>' . e(__('email.feedback.share_heading')) . '</b> &mdash; ' . str_replace(':handle', '<b>' . e($kitInsta['value']) . '</b>', e(__('email.feedback.share_body'))))])
@endif
@if ($products !== [])
@include('emails.kit.button', ['label' => __('email.feedback.button'), 'href' => $products[0]['url']])
@endif
@include('emails.kit.para', ['html' => __('email.feedback.closing'), 'pad' => '14px 32px 0', 'size' => 13, 'center' => true])
@include('emails.kit.signoff', ['signoff' => $kitSignoff])
@endsection
