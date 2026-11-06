{{--
    THE HOMEPAGE FREE-GIFTS STRIP -- Appearance -> Free gifts strip. (Lane GST)

    A thin box under the main banner: the heading in the centre, up to three
    live gifts floating on each side at their TRUE relative size, a small gift
    icon on each. App\Support\GiftStrip holds the reasons and the CSS.

    NOT A BYTE WHEN OFF. This comment runs straight into the first statement
    and every directive stands alone on its line, so a homepage with the strip
    off (the default) is byte-identical to one without this file:
    StorefrontEnglishUnchangedTest is the instrument. forPage() reads one key
    of the settings map the request already holds -- no query, on or off.

    LIGHT. Its CSS is the chosen design's only, printed inside the section
    (nothing render-blocking, nothing on a page without the strip); no script;
    every photo is the transparent cut-out with its srcset, sizes, width and
    height, lazy and async. The CSS is a constant chosen by a normalised key;
    every word and the href are escaped, and the href was scheme-checked when
    it was saved and again when it was read.
--}}@php $kgs = \App\Support\GiftStrip::forPage(app(\App\Services\SettingsService::class)->all(), request()); @endphp
@if ($kgs !== null)
<section class="{{ $kgs['cls'] }}" aria-label="{{ $kgs['h'] }}"><style>{!! $kgs['css'] !!}</style>@if ($kgs['icon']){!! \App\Support\GiftStrip::GIFT_SYMBOL !!}@endif<div class="wrap"><div class="kgs{{ $kgs['anim'] ? '' : ' kgs-still' }}"><div class="kgs-bg">@if ($kgs['style'] === 'S5')<div class="kgs-fx"></div><div class="kgs-shine"></div>@endif</div>
@foreach (['l' => $kgs['left'], 'r' => $kgs['right']] as $kgsSide => $kgsItems)
@if ($kgsSide === 'r')<div class="kgs-mid"><h2 class="kgs-h">{{ $kgs['h'] }}</h2><span class="kgs-sub">{{ $kgs['sub'] }}</span><a class="kgs-link" href="{{ $kgs['href'] }}">{{ $kgs['link'] }}</a></div>@endif
<div class="kgs-side kgs-{{ $kgsSide }}">@foreach ($kgsItems as $kgsP)<div class="kgs-p" style="--h:{{ $kgsP['cm'] }}"><img src="{{ $kgsP['src'] }}" srcset="{{ $kgsP['srcset'] }}" sizes="{{ $kgsP['sizes'] }}" width="{{ $kgsP['w'] }}" height="{{ $kgsP['h'] }}" alt="{{ $kgsP['alt'] }}" loading="lazy" decoding="async">@if ($kgs['icon']){!! \App\Support\GiftStrip::GIFT_ICON !!}@endif</div>@endforeach</div>
@endforeach
</div></div></section>
@endif
