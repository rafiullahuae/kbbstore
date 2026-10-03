{{--
    "Your account is ready" in look A — Lane EM, from the owner's approved
    preview 14. The words are still the owner's (Customers → Send account
    invite): the text before the {link} marker is the hero's lead, the button
    sits where the marker was, and the text after it follows the button.
--}}
@extends('emails.kit.simple')
@php
    $k = \App\Services\Mail\Kit\MailKit::for($brand ?? []);
    $kitTitle = $kitTitle ?? __('email.kit.invite_title');
    $kitPreheader = __('email.kit.pre_invite');
    $kitWhy = __('email.customer_invite.why', ['shop' => $shopName]);
    $kitBefore = [];
    $kitAfter = [];
    $kitSeen = false;
    foreach ($segments as $segment) {
        if (! empty($segment['link'])) { $kitSeen = true; continue; }
        $t = trim((string) ($segment['text'] ?? ''), "\n");
        if ($t !== '') { $kitSeen ? $kitAfter[] = $t : $kitBefore[] = $t; }
    }
    $kitPara = static fn (array $parts) => new \Illuminate\Support\HtmlString(implode('<br><br>', array_map(static fn ($p) => nl2br(e($p)), $parts)));
@endphp

@section('kit_inner')
@include('emails.kit.hero', ['icon' => 'key', 'tone' => 'pink', 'eyebrow' => __('email.kit.eyebrow_invite'), 'title' => __('email.kit.invite_title'), 'lead' => $kitPara($kitBefore)])
@include('emails.kit.button', ['label' => __('email.customer_invite.button'), 'href' => $link])
@include('emails.kit.para', ['html' => $kitAfter === [] ? '' : $kitPara($kitAfter), 'pad' => '12px 32px 28px', 'size' => 13, 'center' => true])
@endsection
