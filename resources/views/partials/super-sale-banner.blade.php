{{--
    The live Super Sale banner (Lane SSB): App\Support\SuperSaleBanner holds the
    shape, the words and every byte of CSS printed here. Drawn on /super-sale/
    only, in the place of the Super Sale header's picture.

    Raw output is the CSS, GIFT_ICON and PaymentMarkArt constants only; every
    word and address goes through the escaping echo. A line emptied in the
    admin is not drawn at all. Each gift photo offers its img-cache copies
    (ImageVariants::srcsetFor) with a 200px src, never the full-size original.
--}}
<style id="kbb-ssb-css">{!! \App\Support\SuperSaleBanner::CSS !!}</style>
<div class="kbb-ssb" dir="{{ $ssb['ar'] ? 'rtl' : 'ltr' }}" data-ssb><div class="ssb-in">
<div class="ssb-hero"><div class="ssb-copy">
@if ($ssb['logos'] || $ssb['t']['pay'] !== '')<div class="ssb-pay">{!! $ssb['tabby'] !!}{!! $ssb['tamara'] !!}@if ($ssb['t']['pay'] !== '')<span class="ssb-paytxt">{{ $ssb['t']['pay'] }}</span>@endif</div>@endif
@if ($ssb['t']['tagline'] !== '')<span class="ssb-tag">{{ $ssb['t']['tagline'] }}</span>@endif
@if ($ssb['t']['title'] !== '')<div class="ssb-super">{{ $ssb['t']['title'] }}</div>@endif
@if ($ssb['code'] !== '')<span class="ssb-coupon">@if ($ssb['t']['coupon'] !== '')<span>{{ $ssb['t']['coupon'] }}</span>@endif<b>{{ $ssb['code'] }}</b></span>@endif
</div>@if ($ssb['model'])<div class="ssb-model"><img src="{{ $ssb['model']['src'] }}"@if ($ssb['model']['srcset'] !== '') srcset="{{ $ssb['model']['srcset'] }}" sizes="(max-width: 900px) 130px, 220px"@endif alt="" width="288" height="464" fetchpriority="high" decoding="async"></div>@endif</div>
@if ($ssb['gifts'] !== [])<div class="ssb-gifts"><span class="ssb-gi">{!! \App\Support\SuperSaleBanner::GIFT_ICON !!}@if ($ssb['t']['gifts'] !== '')<span>{{ $ssb['t']['gifts'] }}</span>@endif</span><span class="ssb-circs">@foreach ($ssb['gifts'] as $ssbG)<span class="ssb-c"><img src="{{ $ssbG['src'] }}"@if ($ssbG['srcset'] !== '') srcset="{{ $ssbG['srcset'] }}" sizes="(max-width: 900px) 36px, 62px"@endif alt="{{ $ssbG['alt'] }}" width="62" height="62" loading="lazy" decoding="async"></span>@endforeach</span>@if ($ssb['t']['free'] !== '')<span class="ssb-free">{{ $ssb['t']['free'] }}</span>@endif</div>@endif
</div></div>
