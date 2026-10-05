@php
/*
    Section 3, Shop Top Korean Beauty Brands.                        (Lane HA)

    The owner's pick, 3 October: brand design A from docs/home-preview/
    cards.html — a photo with a frosted label — WITHOUT the product count, on a
    laptop. (Lane BS, 5 October) "for desktop image + name, no logo!", and on a
    phone "logo + image or only image or only text. keep by default only
    text" — Appearance → Homepage content → Top brands → Edit content → Layout.

    ONE LIST OF LINKS, STYLED PER DEVICE — "no content shown twice to
    Google". Each brand is one <a> carrying every look; kbb.css picks per
    device and per `hs-brm-*` class.

    THE PICTURE is the one chosen for the brand on the Brands tab
    (`home_br_imgs`), else its banner picture (Catalogue → Brands → banner),
    else — only on Look · laptop "the previous look" — its logo on a soft
    panel, else the panel alone; the name is on the label either way.

    WHAT EACH DEVICE DOWNLOADS. On Text only the photo's <picture> carries a
    blank source for the phone width, and the logo is printed only on Logo +
    image, with a blank source for the laptop width — so neither device
    fetches a picture it does not show, whatever the browser does with a lazy
    image under display:none. Rows beyond a device's count stay lazy and
    hidden, as before.
*/
@endphp
<section class="sec {{ $b['classes'] }} {{ $cls }}" style="{{ $b['style'] }}"@if ($b['title'] !== '') aria-labelledby="hs-brands-h"@endif><div class="wrap">
@include('partials.home.hs-head', ['hid' => 'hs-brands-h', 'h' => $b])
<div class="hs-brandlist">
@php $hsLook = $b['look_m']; @endphp
@foreach ($brandRows as $brand)
@php
    $hsName = (string) $brand->t('name');
    $hsLogo = $hsLook === 'logo_image' ? \App\Support\HomeSections::image((string) data_get($brand, 'logo')) : '';
    $hsOwn = $b['imgs'][(int) data_get($brand, 'id')] ?? '';
    $hsPhoto = $hsOwn !== '' ? ['src' => $hsOwn, 'alt' => ''] : \App\Support\HomeSections::brandPhoto($brand);
    $hsPanelLogo = $hsPhoto['src'] === '' && $b['look_d'] === 'image_logo' ? \App\Support\HomeSections::image((string) data_get($brand, 'logo')) : '';
    $hsPic = $hsPhoto['src'] !== '' ? $hsPhoto['src'] : $hsPanelLogo;
    // Right-sized copies, as every other photograph on the shop has: a brand
    // photo uploaded at full size (903 KiB for one, PageSpeed 6 Oct) was sent
    // whole into a 400x500 frame. '' until copies exist; srcsetFor() makes
    // them after the response, so the next view gets them.
    $hsSet = $hsPic !== '' ? \App\Support\ImageVariants::srcsetFor($hsPic) : '';
@endphp
<a class="hs-brand{{ $hsPic !== '' ? ' has-img' : '' }}{{ $hsLogo !== '' ? ' has-logo' : '' }}" href="{{ $brand->url() }}"><span class="hs-bph" style="background:{{ \App\Support\Gradient::for($hsName) }}">@if ($hsPic !== '')<picture>@if ($hsLook === 'text')<source media="(max-width:900px)" srcset="{{ \App\Support\HomeSections::BLANK }}">@endif<img src="{{ $hsPic }}"@if ($hsSet !== '') srcset="{{ $hsSet }}" sizes="{{ \App\Support\ImageVariants::homeBrandSizesAttribute() }}"@endif{!! $hsPhoto['src'] === '' ? ' class="hs-bph-logo"' : '' !!} alt="{{ $hsPhoto['alt'] !== '' ? $hsPhoto['alt'] : $hsName }}" width="400" height="500" loading="lazy" decoding="async"></picture>@endif</span>@if ($hsLogo !== '')<picture class="hs-blogo"><source media="(min-width:901px)" srcset="{{ \App\Support\HomeSections::BLANK }}"><img src="{{ $hsLogo }}" alt="{{ $hsName }}" width="160" height="80" loading="lazy" decoding="async"></picture>@endif<span class="hs-blb"><b>{{ $hsName }}</b></span></a>
@endforeach
</div>
@include('partials.home.hs-foot', ['h' => $b])
</div></section>
