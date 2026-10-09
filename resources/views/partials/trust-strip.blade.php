{{--
    The trust strip (Lane TS) — App\Support\TrustStrip carries the reasoning.
    $v: card (homepage), bare (Super Sale), foot (above the footer). $cls: the
    device class and, on the homepage, the order class. Raw output is the icon
    constants only; every word is an escaped interface string. The sub-line
    is drawn only above the footer and shown only on a laptop.
--}}@php
    /* Literal keys, so StorefrontStringsAreKeyedTest can see each one is defined. */
    $ktrWords = [
        'auth' => [__('store.trust_strip.auth_a'), __('store.trust_strip.auth_b'), __('store.trust_strip.auth_sub')],
        'del' => [__('store.trust_strip.del_a'), __('store.trust_strip.del_b'), __('store.trust_strip.del_sub')],
        'pay' => [__('store.trust_strip.pay_a'), __('store.trust_strip.pay_b'), __('store.trust_strip.pay_sub')],
        'sup' => [__('store.trust_strip.sup_a'), __('store.trust_strip.sup_b'), __('store.trust_strip.sup_sub')],
    ];
@endphp<section class="ktr ktr-{{ $v }}{{ $cls !== '' ? ' '.$cls : '' }}" aria-label="{{ __('store.trust_strip.label') }}"><ul>@foreach (\App\Support\TrustStrip::ITEMS as $ktrItem)<li><i>{!! \App\Support\TrustStrip::ICONS[$ktrItem] !!}</i><b>{{ $ktrWords[$ktrItem][0] }}<br> {{ $ktrWords[$ktrItem][1] }}</b>@if ($v === 'foot')<small>{{ $ktrWords[$ktrItem][2] }}</small>@endif</li>@endforeach</ul></section>
