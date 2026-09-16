{{--
    The category / brand page banner.

        <x-kbb-banner :banner="$banner" />

    $banner is whatever App\Support\PageBanner::forModel() returned: either
    null, or a fully resolved array with every key present. Null renders
    NOTHING AT ALL — not an empty section, not a comment — which is what makes
    "off by default" true on the page and not only in the database. The guard
    is the first thing in this file for that reason.

    THREE STYLES, THREE DIFFERENT DOCUMENTS. They are not one layout with a
    modifier class and a few CSS overrides; each branch below emits its own
    element structure, because that is the difference between three designs and
    one design wearing three hats:

      full   image edge to edge, a gradient scrim over it, copy on top.
             .kbb-banner__scrim is unique to this branch.
      split  a two-track grid: image in one track, copy on a tinted panel in
             the other. .kbb-banner__split and .kbb-banner__panel are unique
             to this branch, and there is no scrim — the copy is never over
             the photograph, so nothing needs darkening.
      tint   no photograph at all. A wash of the brand colour behind large
             type. .kbb-banner__wash is unique to this branch and there is no
             <img> element in the output.

    WHY A REAL <img> AND NOT A CSS BACKGROUND. These pages are indexed, and a
    background-image is invisible to an image crawler and to a screen reader.
    A parallel lane is moving storefront imagery off CSS backgrounds for
    exactly that reason; this ships that way rather than adding one more to
    move. It also buys the thing a background cannot do at all: `width` and
    `height` attributes, which with `aspect-ratio` in the stylesheet let the
    browser reserve the banner's box before a byte of the image has arrived.
    Without both halves the heading renders, then jumps down the page when the
    image lands.

    ESCAPING. Everything here is {{ }}. The one value that is not echoed as
    text is `tint`, which goes into a style attribute as a custom property —
    PageBanner::tint() matches it against a hex pattern whole, so there is no
    version of that string that could carry a second declaration out of it.
--}}
{{--
    `contained` is about who supplies the gutter, and nothing else.

    On the archive the banner is a top-level sibling of the page's .wrap
    blocks, so it has to carry its own max-width and side gutter or it would
    run to the window edge and out of line with the grid below it. On the brand
    landing page it sits inside .brw, which has supplied both already — a
    second set there would inset it twice and it would visibly not line up
    with the product grid underneath.
--}}
@props(['banner' => null, 'contained' => true])

@if ($banner)
    @php
        $style = $banner['style'];
        $tone = $banner['tone'];
        // Alt text describes the photograph. It falls back to the heading
        // because a banner image with no description is a real gap for a
        // screen reader on a page whose <h1> this element carries, and an
        // empty string here would be a claim the image is decorative — which
        // it is not, or the operator would not have uploaded it.
        $alt = $banner['image_alt'] !== '' ? $banner['image_alt'] : $banner['heading'];
    @endphp

    <section class="kbb-banner kbb-banner--{{ $style }} kbb-banner--{{ $tone }}{{ $contained ? '' : ' kbb-banner--flush' }}"
             data-kbb-banner-style="{{ $style }}"
             data-kbb-banner-tone="{{ $tone }}"
             @if ($banner['fallback']) data-kbb-banner-fallback="no-image" @endif
             style="--kbb-banner-scrim:{{ $banner['overlay'] / 100 }};--kbb-banner-tint:{{ $banner['tint'] }}"
             aria-labelledby="kbb-banner-heading">

        @if ($style === 'full')
            {{-- Full bleed: the photograph is the banner. --}}
            <div class="kbb-banner__media">
                <img class="kbb-banner__img"
                     src="{{ $banner['image'] }}"
                     alt="{{ $alt }}"
                     width="{{ \App\Support\PageBanner::IMG_WIDTH }}"
                     height="{{ \App\Support\PageBanner::IMG_HEIGHT }}"
                     decoding="async"
                     {{-- The banner is the first thing above the fold. Lazy
                          loading it would delay the largest paint on the page
                          rather than saving anything. --}}
                     fetchpriority="high">
            </div>
            <div class="kbb-banner__scrim" aria-hidden="true"></div>
            <div class="kbb-banner__inner">
                <div class="kbb-banner__copy">
                    <h1 class="kbb-banner__heading" id="kbb-banner-heading">{{ $banner['heading'] }}</h1>
                    @if ($banner['subheading'] !== '')
                        <p class="kbb-banner__sub">{{ $banner['subheading'] }}</p>
                    @endif
                </div>
            </div>

        @elseif ($style === 'split')
            {{-- Split: image one track, copy the other. Stacks image-above-text
                 on a phone, which is the order a reader expects and the order
                 the source is already in. --}}
            <div class="kbb-banner__split">
                <div class="kbb-banner__media">
                    <img class="kbb-banner__img"
                         src="{{ $banner['image'] }}"
                         alt="{{ $alt }}"
                         width="{{ \App\Support\PageBanner::IMG_WIDTH }}"
                         height="{{ \App\Support\PageBanner::IMG_HEIGHT }}"
                         decoding="async"
                         fetchpriority="high">
                </div>
                <div class="kbb-banner__panel">
                    <div class="kbb-banner__copy">
                        <h1 class="kbb-banner__heading" id="kbb-banner-heading">{{ $banner['heading'] }}</h1>
                        @if ($banner['subheading'] !== '')
                            <p class="kbb-banner__sub">{{ $banner['subheading'] }}</p>
                        @endif
                    </div>
                </div>
            </div>

        @else
            {{-- Soft tint: no photograph. This is the branch a banner with no
                 usable image degrades into, which is why it must look
                 deliberate rather than like a placeholder. --}}
            <div class="kbb-banner__wash" aria-hidden="true"></div>
            <div class="kbb-banner__inner">
                <div class="kbb-banner__copy">
                    <h1 class="kbb-banner__heading" id="kbb-banner-heading">{{ $banner['heading'] }}</h1>
                    @if ($banner['subheading'] !== '')
                        <p class="kbb-banner__sub">{{ $banner['subheading'] }}</p>
                    @endif
                </div>
            </div>
        @endif
    </section>
@endif
