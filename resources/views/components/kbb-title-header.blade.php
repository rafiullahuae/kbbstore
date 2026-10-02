{{--
    The category / brand TITLE HEADER. (Lanes PT and PY)

        <x-kbb-title-header :header="$titleHeader" />

    $header is App\Support\TitleHeader::forModel()'s answer: null, or a fully
    resolved array. Null renders NOTHING -- the callers also guard with @if so
    that not even a newline is added to a page without one, which is what keeps
    /shop/, a search, the brand filter and a brand with no picture
    byte-identical.

    TWO SHAPES. `kind` is `img` -- the category's picture, darkened behind the
    words -- or `box`, the light box a category with no picture gets, with a
    soft pattern of beauty-product line icons when its style carries one.

    ESCAPING. The heading and subtitle are {{ }}. The description is {!! !!}
    because it is HTML -- and it reaches here through RichText::forDisplay(),
    the allowlist, on every render (TitleHeader::forModel). `class` is built
    only from words TitleHeader checked against its own lists, and `style`
    carries custom properties built from clamped integers and checked #RRGGBB
    colours under constant names.

    The picture is a real <img> rather than a CSS background, for the reason
    components/kbb-banner gives: width/height reserve the box before the bytes
    arrive. It is decorative here -- the words over it are the <h1> -- so its
    alt is empty rather than a second copy of the heading for a screen reader.
    The icon layer is decoration too, and hidden from assistive technology.
--}}
@props(['header' => null, 'contained' => true])
@if ($header)
<section class="{{ $header['class'] }}{{ $contained ? '' : ' kbb-th--flush' }}" style="{{ $header['style'] }}" data-kbb-title-header aria-labelledby="kbb-th-title">
@if ($header['image'] !== null)
@if ($header['whole'] ?? false)
    <img class="kbb-th__fill" src="{{ $header['image'] }}" alt="" aria-hidden="true" decoding="async">
@endif
    <img class="kbb-th__img" src="{{ $header['image'] }}" alt="" width="{{ \App\Support\TitleHeader::IMG_WIDTH }}" height="{{ \App\Support\TitleHeader::IMG_HEIGHT }}" decoding="async" fetchpriority="high">
@elseif ($header['icons'])
    <div class="kbb-th__icons" aria-hidden="true"></div>
@endif
    <div class="kbb-th__scrim" aria-hidden="true"></div>
    <div class="kbb-th__inner">
        <div class="kbb-th__text">
            <h1 class="kbb-th__title" id="kbb-th-title">{{ $header['heading'] }}</h1>
            @if ($header['subtitle'] !== '')
                <p class="kbb-th__sub">{{ $header['subtitle'] }}</p>
            @endif
            @if ($header['description'] !== '')
                @if ($header['long'])
                    <input type="checkbox" id="kbb-th-more" class="kbb-th__toggle">
                    <div class="kbb-th__desc kbb-th__desc--clamp">{!! $header['description'] !!}</div>
                    <label for="kbb-th-more" class="kbb-th__more"><span class="kbb-th__more-open">{{ __('store.shop.header_more') }}</span><span class="kbb-th__more-close">{{ __('store.shop.header_less') }}</span></label>
                @else
                    <div class="kbb-th__desc{{ ($header['clamp'] ?? false) ? ' kbb-th__desc--clamp' : '' }}">{!! $header['description'] !!}</div>
                @endif
            @endif
        </div>
    </div>
</section>
@endif
