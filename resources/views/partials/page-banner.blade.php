{{--
    Pages → Page banners: a custom page's promo picture and the thin strip of
    ticks beneath it. (Lane SS) App\Services\PageBanners holds the shape and
    every byte of CSS printed here.

    NOTHING AT ALL when the page has no banner: $pageBanner is null and this
    file emits not one byte. Raw output is the CSS and ICON constants only;
    every word and address the owner typed goes through the escaping echo, and
    the style attribute carries colours and pixel sizes PageBanners has already
    validated. A strip item for one device only gets one of two constant
    classes, kbb-pb-d or kbb-pb-m (raw PHP tags, because Blade does not see an
    @if glued to a tag name); an item for both gets none. (Lane PH) No picture stored means no <img> — never a broken icon.
--}}
@if (! empty($pageBanner))
<style id="kbb-pb-css">{!! \App\Services\PageBanners::CSS !!}</style>
<div class="kbb-pb" style="{{ $pageBanner['style'] }}" data-banner="{{ $pageBanner['id'] }}">
@if ($pageBanner['img'])
@php($pbImg = $pageBanner['img'])
@if ($pbImg['href'] !== '')<a class="kbb-pb-img" href="{{ $pbImg['href'] }}">@else<div class="kbb-pb-img">@endif<picture>@if ($pbImg['two'])<source media="{{ $pageBanner['media'] }}" srcset="{{ $pbImg['m'] }}"@if ($pbImg['wm'] && $pbImg['hm']) width="{{ $pbImg['wm'] }}" height="{{ $pbImg['hm'] }}"@endif>@endif<img src="{{ $pbImg['d'] }}" alt="{{ $pbImg['alt'] }}"@if ($pbImg['wd'] && $pbImg['hd']) width="{{ $pbImg['wd'] }}" height="{{ $pbImg['hd'] }}"@endif fetchpriority="high" decoding="async"></picture>@if ($pbImg['href'] !== '')</a>@else</div>@endif

@endif
@if ($pageBanner['items'] !== [])
<ul class="kbb-pb-strip">@foreach ($pageBanner['items'] as $pbN => $pbItem)<li<?php if (($pageBanner['devs'][$pbN] ?? 'both') !== 'both'): ?> class="<?php echo $pageBanner['devs'][$pbN] === 'd' ? 'kbb-pb-d' : 'kbb-pb-m'; ?>"<?php endif; ?>>{!! \App\Services\PageBanners::ICON !!}<span>{{ $pbItem }}</span></li>@endforeach</ul>
@endif
</div>
@endif
