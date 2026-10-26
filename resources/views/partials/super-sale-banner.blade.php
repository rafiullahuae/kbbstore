{{--
    The live Super Sale banner (Lane SSB): App\Support\SuperSaleBanner holds the
    shape, the words and every byte of CSS printed here. Drawn on /super-sale/
    only, in the place of the Super Sale header's picture.

    Raw output is css(), GIFT_ICON and the PaymentMarkArt constants only; every
    word, address and style value goes through the escaping echo. A line
    emptied in the admin is not drawn at all. The style attribute carries only
    what the owner changed (SuperSaleBanner::styleAttr()), so an untouched
    banner prints none.

    The model is ONE <picture>: the computer's <source> and the phone's <img>,
    so each device downloads only its own picture. Each offers its img-cache
    copies; the gift photos have a 200px src, never the full-size original.
    Nothing here is for an administrator: the on-page "Edit banner" is drawn by
    the storefront admin layer's script, from an authenticated endpoint, and is
    never in this HTML.
--}}
<style id="kbb-ssb-css">{!! \App\Support\SuperSaleBanner::css() !!}</style>
<div class="kbb-ssb" dir="{{ $ssb['ar'] ? 'rtl' : 'ltr' }}" data-ssb{{ '' }}@if ($ssb['style'] !== '') style="{{ $ssb['style'] }}"@endif><div class="ssb-in">
<div class="ssb-hero"><div class="ssb-copy">
@if ($ssb['logos'] || $ssb['t']['pay'] !== '')<div class="ssb-pay">{!! $ssb['tabby'] !!}{!! $ssb['tamara'] !!}@if ($ssb['t']['pay'] !== '')<span class="ssb-paytxt">{{ $ssb['t']['pay'] }}</span>@endif</div>@endif
@if ($ssb['t']['tagline'] !== '')<span class="ssb-tag">{{ $ssb['t']['tagline'] }}</span>@endif
@if ($ssb['t']['title'] !== '')<div class="ssb-super">{{ $ssb['t']['title'] }}</div>@endif
@if ($ssb['code'] !== '')<span class="ssb-coupon">@if ($ssb['t']['coupon'] !== '')<span>{{ $ssb['t']['coupon'] }}</span>@endif<b>{{ $ssb['code'] }}</b></span>@endif
</div>@if ($ssb['model'])@php($ssbM = $ssb['model'])<div class="ssb-model {{ $ssbM['cls'] }}"><picture>@if ($ssbM['two'])<source media="(min-width: 901px)" srcset="{{ $ssbM['d']['srcset'] !== '' ? $ssbM['d']['srcset'] : $ssbM['d']['src'] }}"@if ($ssbM['d']['srcset'] !== '') sizes="340px"@endif>@endif<img src="{{ $ssbM['m']['src'] }}"@if ($ssbM['m']['srcset'] !== '') srcset="{{ $ssbM['m']['srcset'] }}" sizes="(max-width: 900px) 130px, 200px"@endif alt="" width="381" height="500" fetchpriority="high" decoding="async"></picture></div>@endif</div>
@if ($ssb['gifts'] !== [])<div class="ssb-gifts"><span class="ssb-gi">{!! \App\Support\SuperSaleBanner::GIFT_ICON !!}@if ($ssb['t']['gifts'] !== '')<span>{{ $ssb['t']['gifts'] }}</span>@endif</span><span class="ssb-circs">@foreach ($ssb['gifts'] as $ssbG)<span class="ssb-c"><img src="{{ $ssbG['src'] }}"@if ($ssbG['srcset'] !== '') srcset="{{ $ssbG['srcset'] }}" sizes="(max-width: 900px) 36px, 62px"@endif alt="{{ $ssbG['alt'] }}" width="62" height="62" loading="lazy" decoding="async"></span>@endforeach</span>@if ($ssb['t']['free'] !== '')<span class="ssb-free">{{ $ssb['t']['free'] }}</span>@endif</div>@endif
</div></div>
