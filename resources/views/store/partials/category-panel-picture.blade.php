{{--
    The category banner's picture (Lane CB), inside store/partials/brand-panel.

    The brand page draws a bare <img>; a category's banner is offered at the
    img-cache widths instead (BrandPanel::forCategory -> ImageVariants::
    bannerSrcsetFor, read from the disk only), so a 390px phone is not handed
    the 2400px original, and `sizes` counts the crop (BrandPanel::sizes): a
    wide picture covering a short phone banner is drawn wider than the
    screen. When the category has its own phone picture (the
    "Edit header" panel's, `header_style.img_phone`) phones under 900px get
    that one -- the shop's one phone breakpoint, as the title header uses.

    Decorative, so alt="" (the <h1> carries the name). width/height give the
    ratio; the box's height is the stylesheet's min-height, so nothing moves
    when the bytes arrive. fetchpriority="high": it is the page's LCP, exactly
    as on the brand page. Every URL went through TitleHeader::safeImage().
--}}
<picture class="brw-ph__pic">@if ($panel['image_phone'] !== null)<source media="(max-width: 899.98px)" srcset="{{ $panel['srcset_phone'] !== '' ? $panel['srcset_phone'] : $panel['image_phone'] }}"@if ($panel['srcset_phone'] !== '') sizes="{{ $panel['sizes_phone'] }}"@endif>@endif<img class="brw-ph__img" src="{{ $panel['image'] }}"@if ($panel['srcset'] !== '') srcset="{{ $panel['srcset'] }}" sizes="{{ $panel['sizes'] }}"@endif alt="" width="{{ \App\Support\TitleHeader::IMG_WIDTH }}" height="{{ \App\Support\TitleHeader::IMG_HEIGHT }}" decoding="async" fetchpriority="high"></picture>
