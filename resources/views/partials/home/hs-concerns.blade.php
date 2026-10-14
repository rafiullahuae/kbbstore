@php
/*
    Shop by skin concern — design E, the concern chips.             (Lane CX)
    $cc   App\Support\HomeConcerns::section(): classes and custom properties
          built from option keys, integers and checked colours; the words
          already plain text; each chip's url the category's own url().
    $cls  HomepageSections::classFor('concerns') — the Laptop / Phone switches.

    THE WHOLE CAPSULE IS THE LINK: one <a> per chip, the icon and the label
    inside it, so a tap anywhere on the pill opens the collection. The icons
    are constants (HomeConcerns::ICONS), printed raw for that reason, each
    with its own width and height. A sideways row is CSS scroll-snap; its
    arrows (2.60.509) are resources/js/kbb/ymal.js, already on the page.
*/
@endphp
<section class="sec {{ $cc['classes'] }} {{ $cls }}" style="{{ $cc['style'] }}"@if ($cc['title'] !== '') aria-labelledby="hs-concerns-h"@endif{!! $cc['arrows'] ? ' data-ymal' : '' !!}><div class="wrap">
@include('partials.home.hs-head', ['hid' => 'hs-concerns-h', 'h' => $cc])
@if ($cc['arrows'])<div class="hs-cc-stage{{ $cc['arrows_d'] ? '' : ' hs-cc-noarr-d' }}{{ $cc['arrows_m'] ? '' : ' hs-cc-noarr-m' }}"><button type="button" class="hs-cc-arr hs-cc-prev" data-ymal-prev aria-label="{{ __('store.home.slider_previous') }}" disabled><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 5.5 8 12l6.5 6.5"/></svg></button>@endif
<ul class="hs-cc-list"{!! $cc['arrows'] ? ' data-ymal-track' : '' !!}>@foreach ($cc['chips'] as $chip)<li><a class="hs-cc-i" href="{{ $chip['url'] }}" style="{{ $chip['style'] }}"><span class="hs-cc-ic">{!! $chip['icon'] !!}</span><span class="hs-cc-t">{{ $chip['label'] }}</span></a></li>@endforeach</ul>
@if ($cc['arrows'])<button type="button" class="hs-cc-arr hs-cc-next" data-ymal-next aria-label="{{ __('store.home.slider_next') }}"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9.5 5.5 6.5 6.5-6.5 6.5"/></svg></button></div>@endif
@include('partials.home.hs-foot', ['h' => $cc])
</div></section>
