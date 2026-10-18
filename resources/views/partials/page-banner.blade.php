{{--
    Pages → Page banners: a custom page's promo picture and the thin strip of
    ticks beneath it. (Lane SS) App\Services\PageBanners holds the shape and
    every byte of CSS printed here.

    NOTHING AT ALL when the page has no banner: $pageBanner is null and this
    file emits not one byte. Raw output is the CSS and ICON constants only;
    every word and address the owner typed goes through the escaping echo, and
    the style attribute carries colours and pixel sizes PageBanners has already
    validated. A strip item for one device only gets one of two constant
    classes, kbb-pb-d or kbb-pb-m, as an UNQUOTED attribute so the escaping
    echo prints it unchanged (an @if glued to the tag name is not seen by
    Blade); an item for both gets none, and prints as it always did. (Lane PH) No picture stored means no <img> — never a broken icon.
--}}
@if (! empty($pageBanner))
<style id="kbb-pb-css">{!! \App\Services\PageBanners::CSS !!}</style>
<div class="kbb-pb" style="{{ $pageBanner['style'] }}" data-banner="{{ $pageBanner['id'] }}">
@if ($pageBanner['img'])
@php($pbImg = $pageBanner['img'])
{{-- (Lane SPD) Each picture offers its img-cache copies, so a phone downloads
     a phone-sized file: the phone <source> at 100vw (it is never wider than the
     screen), asking a 3x phone for a 2x picture as the product photograph does
     since 2.60.465; the laptop <img> as the homepage banner declares itself. No copies
     on disk, no srcset: the markup is what it was. --}}
@php($pbSd = \App\Services\PageBanners::srcset($pbImg['d']))
@php($pbSm = $pbImg['two'] ? \App\Services\PageBanners::srcset($pbImg['m']) : '')
@if ($pbImg['href'] !== '')<a class="kbb-pb-img" href="{{ $pbImg['href'] }}">@else<div class="kbb-pb-img">@endif<picture>@if ($pbImg['two'])<source media="{{ $pageBanner['media'] }}" srcset="{{ $pbSm !== '' ? $pbSm : $pbImg['m'] }}"@if ($pbSm !== '') sizes="(min-resolution: 2.5dppx) 67vw, 100vw"@endif{{ '' }}@if ($pbImg['wm'] && $pbImg['hm']) width="{{ $pbImg['wm'] }}" height="{{ $pbImg['hm'] }}"@endif>@endif<img src="{{ $pbImg['d'] }}" alt="{{ $pbImg['alt'] }}"@if ($pbSd !== '') srcset="{{ $pbSd }}" sizes="{{ \App\Support\ImageVariants::bannerSliderSizesAttribute() }}"@endif{{ '' }}@if ($pbImg['wd'] && $pbImg['hd']) width="{{ $pbImg['wd'] }}" height="{{ $pbImg['hd'] }}"@endif fetchpriority="high" decoding="async"></picture>@if ($pbImg['href'] !== '')</a>@else</div>@endif

@endif
@if ($pageBanner['items'] !== [])
<ul class="kbb-pb-strip{{ $pageBanner['strip_cls'] ?? '' }}">@foreach ($pageBanner['items'] as $pbN => $pbItem)<li{{ ['d' => ' class=kbb-pb-d', 'm' => ' class=kbb-pb-m'][$pageBanner['devs'][$pbN] ?? 'both'] ?? '' }}>{!! \App\Services\PageBanners::ICON !!}<span>{{ $pbItem }}</span></li>@endforeach</ul>
@endif
</div>
@endif
