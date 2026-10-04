{{--
    THE SINGLE-IMAGE BANNER -- Lane RC.

    The owner: "i need here option single image ... in case of single image,
    the height will be as per the image height itself. in desktop and mobile
    both to fit auto to the screen size without overlaping or cutting."

    So: ONE picture -- the set's first published one, with its link and its alt
    text -- full width, and its height is whatever its own proportions make it
    at that width. There is no frame shape to choose and therefore nothing to
    crop: `width:100%; height:auto` on the <img>, and the browser scales the
    picture as a whole. No arrows, no bars, no autoplay and no script at all.

    NO LAYOUT SHIFT, AND NO MEASURING. The <img> and the phone <source> carry
    width/height attributes from the stored size of each file (read off it on
    save; BannerCard::naturalSize() reads the header once if a row predates
    the columns). A browser maps those attributes to the box's aspect-ratio
    before a byte of the picture arrives, so the space is reserved at first
    paint -- the same promise the slider's `aspect-ratio` keeps, made by the
    picture's own numbers instead of a preset.

    THE PHONE GETS ITS OWN PICTURE when the slide has one, at ITS OWN
    proportions (500 x 600 is 390 x 468 on a 390 phone), through <source
    media>: art direction, the slider's own argument for it. Without one the
    phone draws the desktop picture whole -- 390 x 112 for 1920 x 550 -- which
    is small and is not cut. The breakpoint is the slider's constant, written
    the same way, so a set switched between the two kinds changes picture at
    the same width.

    THE STYLESHEET IS INLINE for the reason the slider gives: a rule added to
    resources/css ships inert until somebody rebuilds the bundle. Prefix kbbi-,
    used nowhere else.

    Every operator string goes through {{ }}; the link through
    Banners::safeUrl(); the picture path is one MediaRegistrar allowlisted; and
    the only CSS values are the shared corner/shadow/background properties,
    each printed by Banners from a clamped integer or a constant.
--}}
@php
    use App\Services\Banners;
    use App\Support\ImageVariants;
    use App\Support\Url;

    /** @var \App\Models\BannerSet $set */
    /** @var list<\App\Models\BannerCard> $cards */
    $biCard = $cards[0] ?? null;
@endphp
@if ($biCard !== null)
@php
    $biUid = 'kbbi-'.(int) $set->id;
    $biPhoneQuery = '(max-width: 767.98px)';
    $biSizes = ImageVariants::bannerSliderSizesAttribute();
    $biHref = Banners::safeUrl($biCard->button_url);
    $biAlt = trim((string) $biCard->alt) !== '' ? $biCard->alt : '';
    $biDesk = $biCard->naturalSize();
    $biPhone = $biCard->phoneNaturalSize();
    $biHasPhone = $biCard->hasPhonePicture();
    // (Lane PF2) The banner tier: the slider's own srcset method, for the slider's reason.
    $biSrcset = ImageVariants::bannerSrcsetFor(ImageVariants::rootRelative((string) $biCard->image));
    $biPhoneSrcset = $biHasPhone ? ImageVariants::bannerSrcsetFor(ImageVariants::rootRelative((string) $biCard->image_m)) : '';
    $biBgMode = $set->bgMode();
    $biBgVars = Banners::sectionVariables($set);
    $biVars = Banners::singleVariables($set);
@endphp
@if (isset($sections) && $sections->deviceClassFor('cards_banner') === '')
  @push('head')
@if ($biHasPhone)
<link rel="preload" as="image" fetchpriority="high" media="{{ $biPhoneQuery }}" href="{{ Banners::imageUrl((string) $biCard->image_m) }}"@if ($biPhoneSrcset !== '') imagesrcset="{{ $biPhoneSrcset }}" imagesizes="{{ $biSizes }}"@endif>
<link rel="preload" as="image" fetchpriority="high" media="(min-width: 768px)" href="{{ Banners::imageUrl((string) $biCard->image) }}"@if ($biSrcset !== '') imagesrcset="{{ $biSrcset }}" imagesizes="{{ $biSizes }}"@endif>
@else
<link rel="preload" as="image" fetchpriority="high" href="{{ Banners::imageUrl((string) $biCard->image) }}"@if ($biSrcset !== '') imagesrcset="{{ $biSrcset }}" imagesizes="{{ $biSizes }}"@endif>
@endif
  @endpush
@endif
<style>
.kbbi{--kbbi-gut:var(--site-gutter,18px);position:relative}
/* The section background: the same control, method and bleed idiom as the
   slider and the cards row (`--kbbn-bg`/`--kbbn-bgimg` from
   Banners::sectionVariables()). Equal and opposite margin and padding, so the
   picture is where it was and only the paint moves. */
.kbbi.has-bg{background-color:var(--kbbn-bg,transparent);
  background-image:var(--kbbn-bgimg,none);background-size:cover;
  background-position:center;background-repeat:no-repeat;
  margin-inline:calc(var(--kbbi-gut) * -1);
  padding-inline:var(--kbbi-gut);padding-block:0 18px}
/* No band ABOVE the picture, for the slider's reason: "remove any space
   between header and banner". */
.kbbi-a{display:block;text-decoration:none}
.kbbi-a picture{display:block}
/* THE WHOLE RULE: full width, height from the picture's own proportions.
   `height:auto` with the width/height attributes is what reserves the box
   before the file arrives; there is no `object-fit` because there is no frame
   for the picture to be fitted into -- the box IS the picture. */
.kbbi-a img{display:block;width:100%;height:auto;max-width:100%;
  border-radius:var(--kbbi-r,0px);box-shadow:var(--kbbi-sh,none)}
.kbbi-a:focus-visible{outline:3px solid var(--ink,#2A2228);outline-offset:-3px}
</style>
<div class="kbbi{{ $biBgMode === 'none' ? '' : ' has-bg' }}"
     id="{{ $biUid }}"
     style="{{ $biVars }}{{ $biBgVars === '' ? '' : ';'.$biBgVars }}"
     role="region"
     aria-label="{{ trim((string) $set->name) !== '' ? $set->name : __('store.home.banner_slider_label') }}">
  <a class="kbbi-a"@if ($biHref !== '') href="{{ Url::to($biHref) }}"@endif><picture>@if ($biHasPhone)<source media="{{ $biPhoneQuery }}" srcset="{{ $biPhoneSrcset !== '' ? $biPhoneSrcset : Banners::imageUrl((string) $biCard->image_m) }}"@if ($biPhoneSrcset !== '') sizes="{{ $biSizes }}"@endif @if ($biPhone !== null) width="{{ (int) $biPhone[0] }}" height="{{ (int) $biPhone[1] }}"@endif>@endif<img src="{{ Banners::imageUrl((string) $biCard->image) }}"
         alt="{{ $biAlt }}"
         @if ($biSrcset !== '') srcset="{{ $biSrcset }}" sizes="{{ $biSizes }}" @endif
         @if ($biDesk !== null) width="{{ (int) $biDesk[0] }}" height="{{ (int) $biDesk[1] }}" @endif
         fetchpriority="high"
         decoding="async"></picture></a>
</div>
@endif
