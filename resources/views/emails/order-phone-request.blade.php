{{--
    "Ask for UAE number" (2.60.562) — App\Mail\OrderPhoneRequest. Option H, the
    owner's pick: the soft "Your UAE phone number is missing" line above the
    heading, the number on the order struck through in grey, the UAE number
    wanted in green, one button to the signed page (OrderLinks::phoneUrl), and
    the order chip, help box, sign-off and footer every order email carries.

    No items, totals or address: this email is about one thing.
--}}
@extends('emails.kit.order')
@php
    $k = \App\Services\Mail\Kit\MailKit::for($brand ?? []);
    $kitTitle = $kitTitle ?? $heading;
    $kitPreheader = __('email.phone_request.preheader');
    $kitHeroView = 'emails.kit.hero-pill';
    $kitHero = ['spark', 'pink', $eyebrow, $heading, $body];
    $kitTracker = null;
    $kitShowItems = false;
    $kitShowTotals = false;
    $kitShowInfo = false;
    $kitCta = [__('email.phone_request.button'), $phoneUrl, __('email.phone_request.note')];
    $kitWhy = __('email.kit.why_order', ['site' => $k['site']]);
    $kitTemplate = 'order_phone_request';
@endphp

@section('kit_before')
<tr><td class="px" style="padding:18px 24px 4px;font-family:{!! $k['sans'] !!};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#FFF5F8" style="background:#FFF5F8;border:1px solid #F7DDE6;border-radius:14px;">
<tr><td class="ink2" style="padding:14px 8px 12px 16px;font-family:{!! $k['sans'] !!};font-size:13.5px;color:#5E545A;border-bottom:1px dashed #F0D6C6;">{{ __('email.phone_request.row_have') }}</td>
<td align="right" style="padding:14px 16px 12px 8px;font-family:{!! $k['sans'] !!};font-size:14.5px;color:#9A9095;text-decoration:line-through;border-bottom:1px dashed #F0D6C6;white-space:nowrap;">{!! $currentPhone === '' ? '&mdash;' : \App\Services\Mail\Kit\KitRtl::ltr($currentPhone) !!}</td></tr>
<tr><td class="ink2" style="padding:12px 8px 14px 16px;font-family:{!! $k['sans'] !!};font-size:13.5px;color:#5E545A;">{{ __('email.phone_request.row_need') }}</td>
<td align="right" style="padding:12px 16px 14px 8px;font-family:{!! $k['sans'] !!};font-size:14.5px;font-weight:700;color:#2E7D4F;white-space:nowrap;">&#10003; {!! \App\Services\Mail\Kit\KitRtl::ltr('05X XXX XXXX') !!}</td></tr>
</table></td></tr>
@endsection
