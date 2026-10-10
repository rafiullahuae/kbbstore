{{--
    "Let us know what you think!" — the review request (Lane RV), N days after
    the order was completed (Emails → Customer emails → Review request →
    Schedule & rules). The owner's example was a Judge.me email; this is the
    shop's own kit with what that one lacks: a card per product, stars that
    each open the review page with that rating chosen, the Arabic version
    through the same wording system, and a dark-mode-safe card.

    THE UNSUBSCRIBE LINE IS GLUE, NOT A SECTION: it sits outside every
    @kitsec, so no layout the owner saves can switch it off or move it out of
    the email. Its address is also the List-Unsubscribe header.
--}}
@extends('emails.kit.simple')
@php
    $k = \App\Services\Mail\Kit\MailKit::for($brand ?? []);
    $kitTitle = $kitTitle ?? $heading;
    $kitPreheader = __('email.review_request.preheader');
    $kitWhy = __('email.review_request.why', ['store' => $k['storeName']]);
    $kitTemplate = 'review_request';
    $rrSignoff = [__('email.review_request.signoff_thanks'), __('email.review_request.signoff_team', ['store' => $k['storeName']])];
@endphp

@section('kit_inner')
@kitsec('hero')
@include('emails.kit.hero', ['icon' => 'star', 'tone' => 'pink', 'eyebrow' => __('email.review_request.eyebrow'), 'title' => $heading, 'lead' => $body])
@endkitsec
@kitsec('products')
@include('emails.kit.review-cards', ['cards' => $products, 'starHex' => $starHex])
@endkitsec
@kitsec('button')
@include('emails.kit.button', ['label' => __('email.review_request.button'), 'href' => $pageUrl])
@endkitsec
@kitsec('note')
@include('emails.kit.para', ['html' => __('email.review_request.note', ['days' => $linkDays]), 'pad' => '14px 32px 0', 'size' => 12.5, 'center' => true])
@endkitsec
@kitsec('closing')
@include('emails.kit.para', ['html' => __('email.review_request.closing'), 'pad' => '18px 32px 0', 'size' => 14, 'center' => true])
@endkitsec
@kitsec('signoff')
@include('emails.kit.signoff', ['signoff' => $rrSignoff])
@endkitsec
<tr><td class="px" align="center" style="padding:0 32px 26px;font-family:{!! $k['sans'] !!};font-size:12px;line-height:1.6;color:#8C828A;"><a class="muted" href="{{ $stopUrl }}" style="color:#8C828A;text-decoration:underline;">{{ __('email.review_request.unsubscribe') }}</a></td></tr>
@endsection
