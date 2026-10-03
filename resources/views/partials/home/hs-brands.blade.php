@php
/*
    Section 3, Shop Top Korean Beauty Brands.                        (Lane HA)

    The owner's pick, 3 October: brand design A from docs/home-preview/
    cards.html — a photo with a frosted label — WITHOUT the product count, on a
    laptop; a logo-only grid on a phone.

    ONE LIST OF LINKS, STYLED PER DEVICE — "no content shown twice to
    Google". Each brand is one <a> carrying both looks: kbb.css shows the
    photo and the label above 900px and the logo tile at or below it. The
    hidden half's <img> is loading="lazy", and a lazy image under
    display:none is never fetched, so a phone does not download the photos.

    The PHOTO is the brand's own banner picture (Catalogue → Brands → banner),
    else its logo on a soft panel, else the panel alone — the name is on the
    label either way. The phone's LOGO falls back to the name set in type.
*/
@endphp
<section class="sec {{ $b['classes'] }} {{ $cls }}" style="{{ $b['style'] }}"@if ($b['title'] !== '') aria-labelledby="hs-brands-h"@endif><div class="wrap">
@include('partials.home.hs-head', ['hid' => 'hs-brands-h', 'h' => $b])
<div class="hs-brandlist">
@foreach ($brandRows as $brand)
@php
    $hsName = (string) $brand->t('name');
    $hsLogo = \App\Support\HomeSections::image((string) data_get($brand, 'logo'));
    $hsPhoto = \App\Support\HomeSections::brandPhoto($brand);
@endphp
<a class="hs-brand{{ $hsLogo !== '' ? ' has-logo' : '' }}" href="{{ $brand->url() }}"><span class="hs-bph" style="background:{{ \App\Support\Gradient::for($hsName) }}">@if ($hsPhoto['src'] !== '')<img src="{{ $hsPhoto['src'] }}" alt="{{ $hsPhoto['alt'] !== '' ? $hsPhoto['alt'] : $hsName }}" width="400" height="500" loading="lazy" decoding="async">@elseif ($hsLogo !== '')<img class="hs-bph-logo" src="{{ $hsLogo }}" alt="{{ $hsName }}" width="400" height="500" loading="lazy" decoding="async">@endif</span>@if ($hsLogo !== '')<img class="hs-blogo" src="{{ $hsLogo }}" alt="{{ $hsName }}" width="160" height="80" loading="lazy" decoding="async">@endif<span class="hs-blb"><b>{{ $hsName }}</b></span></a>
@endforeach
</div>
@include('partials.home.hs-foot', ['h' => $b])
</div></section>
