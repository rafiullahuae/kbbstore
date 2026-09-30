{{--
    THE PICTURE SLIDER — Phase 22 round 8, Lane BN2.

    The owner, in full: "I want another banner type with only images slider with
    proper beautiful left right arrows. and thin bars the bottom of the banner
    to control all sliders. give me some nice previews to chooose from."

    So: one picture at a time, no headline and no copy layer, a real previous
    and a real next, and a row of thin bars along the bottom — one per picture —
    that BOTH say which picture is up and jump to it when tapped. Four
    treatments, chosen on Appearance → Banners → (a set) → Banner type → Look.

    ══ IT IS THE SAME SET, THE SAME TABLE AND THE SAME SCREEN ═════════════════

    `banner_sets.kind` is `cards` or `slider` and this file draws the second.
    Everything the two kinds share — the module switch, which set the homepage
    shows, the publish flag, the order, the background, the corner radius, the
    shadow, the autoplay switch, the dwell — is the SAME COLUMN read by both, so
    there is no second screen, no second module and no second homepage slot.
    BannerSet::homePartial() is the one place the choice is made, and the
    homepage, the stored preview and the buffered preview all ask it.

    ══ WHY THERE IS A SCRIPT HERE WHEN cards-banner.blade.php HAS NONE ════════

    CLAUDE.md rule 4 forbids JAVASCRIPT THAT MEASURES LAYOUT and names the
    element-measuring APIs. It does not forbid script, and this file keeps the
    rule exactly:

      · NOTHING IS MEASURED. The script holds ONE INTEGER — which picture is up
        — and writes it into `--kbbs-i`. Every pixel is arithmetic the browser
        does from the stylesheet: the track moves by
        `calc(var(--kbbs-i) * var(--kbbs-dirn) * 100cqi)`, and `100cqi` is the
        container query unit, which is the browser's own measurement of its own
        box. There is no resize listener because there is nothing for one to
        recompute, and a window that changes width re-lays the slider out with
        no script running at all.

      · THE BANNED LIST IS ASSERTED, NOT PROMISED. SliderBannerTest scans this
        file for getBoundingClientRect, offsetWidth/Height/Top, clientWidth,
        clientHeight, scrollWidth, scrollLeft, scrollTop, innerWidth,
        getComputedStyle, ResizeObserver and IntersectionObserver, and fails on
        any of them.

    The cards banner reached the opposite answer and both are right for what
    they are. That section is a MARQUEE: it has no "current card", so a CSS
    animation over a doubled track is the whole of it. This one has a current
    picture, and three of the owner's four sentences are about controls that
    change it. The scriptless version of this exists — it is `::scroll-button()`
    plus `:target`, which is what the cards banner uses — and it cannot do the
    two things he actually asked for: arrows outside Chrome and Edge, and a bar
    that keeps telling the truth after a swipe. Both were written up as the
    known limits of that section and both are the request here.

    WITHOUT JAVASCRIPT THIS IS STILL A SLIDER. The frame ships as a native
    scroll-snap rail: every picture is reachable by swipe and by keyboard, at
    the right size, with no layout shift. The arrows and the bars are not drawn
    at all until the script adds `is-js` — a control that looks like a control
    and is not one is the failure this repository has already paid for once, on
    the cards banner's own arrows.

    ══ AUTOPLAY THAT CAN ACTUALLY BE STOPPED ═════════════════════════════════

    Four ways, and the first three are floors rather than settings:

      · the pointer is over it            (always — not the set's choice)
      · anything inside it has focus      (always — a keyboard user cannot use
                                           a control that moves under them)
      · prefers-reduced-motion: reduce    (never starts, and stops if the
                                           setting changes while the page is up)
      · the pause button                  (a real button, with a real name)

    …and it does not run while the tab is hidden, which is a battery matter
    rather than an accessibility one.

    `pause_on_hover` is the CARDS banner's switch and is deliberately not read
    here. WCAG 2.2.2 is not a preference, and a slider whose owner can turn the
    stop off is a slider that cannot be stopped.

    ══ THE ARABIC SIDE IS ONE NUMBER, NOT A [dir] RULE ═══════════════════════

    Everything about the layout mirrors by itself, because every offset is a
    logical property — `inset-inline-start`, `padding-inline`, `margin-inline` —
    and the two flex rows (the track and the bars) reverse with the document.
    There is no `[dir="rtl"]` selector in this file.

    The one thing that cannot be logical is `transform`, which is physical by
    definition. `Banners::sliderVariables()` writes its SIGN as `--kbbs-dirn`,
    from `Locale::isRtl()` — the same source `<html dir>` is written from, so
    the two cannot disagree. `--kbbs-flip` turns the chevrons round so
    "previous" points at the inline start in both directions. That method's
    docblock has the argument in full.

    ══ EVERY STRING FROM A SETTING IS ESCAPED; THE SCRIPT IS A CONSTANT ══════

    Rule 5. The set's name and each picture's alt text are boxes an operator
    types into and both go through {{ }}. A picture's link goes through
    Banners::safeUrl() before it becomes an href. The picture's own path is a
    path MediaRegistrar allowlisted.

    NOTHING IS INTERPOLATED INTO THE <script> EXCEPT THE SET'S ID, which is cast
    to an int. Every other value the script needs — the dwell, the direction,
    the sentences it announces — is read at runtime out of a data attribute that
    was written through {{ }}, so there is no path by which an operator's string
    becomes JavaScript source.

    ══ WHY THE STYLESHEET IS INLINE ══════════════════════════════════════════

    cards-banner.blade.php's own note, unchanged and for the same reason: the
    storefront serves BUILT css through @vite, package.json defines no build
    script, and nobody runs `npx vite build` during an update. A rule added to
    resources/css therefore ships INERT until somebody rebuilds the bundle — a
    slider that silently does not move. Emitted here it is part of the page and
    cannot be stale.
--}}
@php
    /*
     * Imported here and not borrowed from the parent, for the reason
     * cards-banner.blade.php records: a `use` inside a Blade @php block is
     * scoped to the one compiled file it is written in, and the admin preview
     * renders this partial with no parent at all.
     */
    use App\Services\Banners;
    use App\Support\ImageVariants;
    use App\Support\Locale;
    use App\Support\Url;

    /** @var \App\Models\BannerSet $set */
    /** @var list<\App\Models\BannerCard> $cards */
    $bsUid = 'kbbs-'.(int) $set->id;
    $bsCount = count($cards);
    $bsRtl = Locale::isRtl();

    /*
     * The dwell, and 0 is the single spelling of "it does not move at all" —
     * the set said no, or there is one picture and there is nowhere to go.
     * Banners::sliderDwell() is the one place that decides it, so the
     * stylesheet's `is-auto` class and the script's timer cannot disagree.
     */
    $bsDwell = Banners::sliderDwell($set, $cards);

    /*
     * The arrows and the bars are only drawn when there is somewhere to go.
     * One picture with a previous and a next is two controls that do nothing,
     * which is the fault the cards banner's arrows shipped with once.
     */
    $bsSteerable = $bsCount > 1;
    $bsArrows = $bsSteerable && (bool) $set->show_arrows;
    $bsBars = $bsSteerable && (bool) $set->show_dots;

    $bsStyle = $set->sliderStyle();
    $bsBgMode = $set->bgMode();
    $bsVars = Banners::sliderVariables($set, $cards, $bsRtl);
    $bsBgVars = Banners::sectionVariables($set);

    // The set's own name is the region's label where there is one; the shop's
    // own words where there is not. Never an empty aria-label, which is a
    // labelled region with no label.
    $bsLabel = trim((string) $set->name) !== '' ? $set->name : __('store.home.banner_slider_label');

    /*
     * ── THE PICTURES ARE DELIVERED AT THE SIZE THE FRAME ASKS FOR ───────────
     *
     * This section shipped with `src` alone, which is the defect PERF found
     * and fixed in cards-banner.blade.php on the same afternoon: a 1600px
     * original painted into a 346px frame on a phone, downloaded whole.
     *
     * rootRelative() IS NOT DECORATION, for the reason the cards banner
     * records: `banner_cards.image` is stored BARE (`uploads/x.jpg`, no leading
     * slash) and detailSrcsetFor() splits a ROOT-RELATIVE path, so handing it
     * the raw column answers '' every time and the whole fix is a no-op that
     * looks applied.
     *
     * Memoised per image rather than per card. detailSrcsetFor() stats up to
     * three files per call, and the FIRST picture is asked for twice on every
     * render — once by the <link rel="preload"> above the markup and once by
     * its own slide — before counting a set where the owner has picked the
     * same photograph on two slides.
     */
    $bsSizes = ImageVariants::bannerSliderSizesAttribute();
    $bsSrcsets = [];
    $bsSrcsetFor = static function (string $image) use (&$bsSrcsets): string {
        return $bsSrcsets[$image] ??= ImageVariants::detailSrcsetFor(ImageVariants::rootRelative($image));
    };

    /*
     * ── THE PHONE PICTURE, AND THE ONE BREAKPOINT BOTH HALVES MUST AGREE ON ─
     *                                                              (Lane SEC)
     *
     * The owner gave two sizes — "for desktop the size should be 1920 x 550 and
     * in mobile 500 x 600" — so a slide carries two pictures and this section
     * draws whichever belongs to the frame on screen.
     *
     * `--kbbs-arm` IS THE DEFAULT AND `--kbbs-ar` IS ASSIGNED AT 768px, in the
     * stylesheet below, so the phone frame is everything BELOW 768. The source
     * element's `media` and the `sizes` condition are written from this one
     * constant for that reason: two spellings of the same breakpoint is how a
     * narrow tablet ends up with the portrait picture in the landscape frame,
     * and the fault would show on one width nobody tests at.
     *
     * 767.98 AND NOT 767, because a viewport is not always an integer — a
     * zoomed desktop and several Android handsets report fractional CSS pixel
     * widths, and `(max-width:767px)` plus `(min-width:768px)` leaves a gap
     * between them that a real device does land in. The frame's own rule is
     * `min-width:768px`, so everything under it is this.
     */
    $bsPhoneQuery = '(max-width: 767.98px)';

    /*
     * The two frame shapes as numbers, read from the set, so the arithmetic
     * below follows the pixels the browser lays out with rather than the label
     * on the preset.
     */
    $bsArD = $set->sliderRatioValue();
    $bsArM = $set->sliderRatioMobileValue();

    /*
     * WHAT EACH BREAKPOINT WILL ACTUALLY DRAW, decided once per card and here
     * rather than in the markup, because five things are read off it — the
     * source element, the img, both `sizes` and the preload — and a condition
     * repeated five times is a condition that gets fixed in four places.
     *
     * The FALLBACK is the whole subtlety: a slide with no phone picture shows
     * its DESKTOP picture in the portrait phone frame, cropped by `cover` to
     * about a quarter of its width. That is today's behaviour and it is not
     * changed here — what changes is that `sizes` now asks for the width
     * covering that frame actually needs, instead of the frame's own width.
     * Soft is worse than heavy for a shop that is being shown a crop it did not
     * choose either way; ImageVariants::bannerSliderCoverSizes() carries the
     * reasoning and the numbers are in BannerPhonePictureTest.
     */
    $bsCropToken = $set->sliderRatioMobileToken();

    $bsPhoneFor = static function ($card) use ($bsArM, $bsCropToken): array {
        /*
         * ── THREE WAYS TO FILL THE PHONE FRAME, BEST FIRST ──────────────────
         *
         * 1. HIS OWN PHONE PICTURE. Nothing to crop, nothing to over-ask for.
         *
         * 2. A SERVER-MADE CROP of the desktop picture, at the frame's shape.
         *    The pixels are EXACTLY the ones `object-fit: cover` was going to
         *    show — ImageVariants::coverRect() is the same rectangle the
         *    browser picks — so this changes what is downloaded and cannot
         *    change what is seen. Measured on a photographic 1920 x 550: the
         *    native crop is 458 x 550 and 38.6 KB against the 152.6 KB whole
         *    picture the third case has to ask for. Four times smaller for
         *    identical pixels.
         *
         * 3. THE WHOLE PICTURE, asked for at the width covering the frame
         *    needs. The honest heavy answer, and it stays because a crop can be
         *    missing for reasons that are nobody's fault: no GD on the host, an
         *    SVG, a restored database whose backfill has not run. Every one of
         *    those must still draw the picture.
         */
        if ($card->hasPhonePicture()) {
            return [
                'mode' => 'own',
                'image' => (string) $card->image_m,
                'srcset' => null,
                'w' => $card->image_m_w,
                'h' => $card->image_m_h,
                'sizes' => ImageVariants::bannerSliderCoverSizes($card->image_m_w, $card->image_m_h, $bsArM),
            ];
        }

        $crop = ImageVariants::cropSrcsetFor(ImageVariants::rootRelative((string) $card->image), $bsCropToken);

        if ($crop !== '') {
            /*
             * The crop IS the frame's shape, so the width is binding again and
             * the flat expression is exactly right — the same reason an
             * uploaded phone picture needs no factor. The width and height
             * printed on the element are the crop rectangle's, not the
             * source's, or the browser reserves a box of the wrong shape.
             */
            [, , $cropW, $cropH] = ImageVariants::coverRect(
                (int) $card->image_w, (int) $card->image_h, $bsArM
            );

            return [
                'mode' => 'crop',
                'image' => (string) $card->image,
                'srcset' => $crop,
                'w' => $card->image_w && $card->image_h ? $cropW : null,
                'h' => $card->image_w && $card->image_h ? $cropH : null,
                'sizes' => ImageVariants::bannerSliderCoverSizes($cropW, $cropH, $bsArM),
            ];
        }

        return [
            'mode' => 'whole',
            'image' => (string) $card->image,
            'srcset' => null,
            'w' => $card->image_w,
            'h' => $card->image_h,
            'sizes' => ImageVariants::bannerSliderCoverSizes($card->image_w, $card->image_h, $bsArM),
        ];
    };

    /*
     * And the desktop frame's own. It is the flat expression for every picture
     * this shop has ever had — a source no wider than its frame is width-bound
     * and the factor collapses to 1 — so this is byte-identical on the whole
     * existing catalogue and only speaks up for a picture wider than 1920:550.
     */
    $bsSizesFor = static function ($card) use ($bsArD): string {
        return ImageVariants::bannerSliderCoverSizes($card->image_w, $card->image_h, $bsArD);
    };

    /*
     * ── AND THE FALLBACK'S `sizes` HAS TO CARRY BOTH FRAMES IN ONE STRING ───
     *
     * A slide WITHOUT a phone picture has no source element, so its <img> is
     * the only element on the page and one `sizes` has to answer for two frames
     * of different shapes. `sizes` takes media conditions for exactly this —
     * `(max-width: …) <length>, <length>` — and the condition is the same
     * constant the source element and the preload use.
     *
     * WHEN THE TWO ANSWERS ARE THE SAME THE CONDITION IS OMITTED, and that is
     * not tidiness: it is what keeps this byte-identical for every picture no
     * wider than its frame, which is the whole existing catalogue and
     * PerfDeliveryTest's 810 x 1440 fixture. A condition emitted for a pair of
     * identical lengths is a changed page with no meaning in it, and
     * StorefrontEnglishUnchangedTest cannot tell that from a real one.
     *
     * A SLIDE WITH a phone picture does NOT use this: its <img> never applies
     * below 768px, because the source element wins there, so a phone term on it
     * would describe a frame it will never be measured against.
     */
    $bsImgSizesFor = static function ($card) use ($bsSizesFor, $bsPhoneFor, $bsPhoneQuery): string {
        $desktop = $bsSizesFor($card);
        $phone = $bsPhoneFor($card);

        /*
         * A SOURCE ELEMENT WINS BELOW 768px, so whenever one is drawn — for his
         * own phone picture OR for a server-made crop — the <img> is a desktop
         * element only and a phone term on it would describe a frame it is
         * never measured against. Only the third case, the whole picture in
         * both frames, needs one attribute to answer for two shapes.
         */
        if ($phone['mode'] !== 'whole') {
            return $desktop;
        }

        return $phone['sizes'] === $desktop
            ? $desktop
            : $bsPhoneQuery.' '.$phone['sizes'].', '.$desktop;
    };

    $bsFirst = $cards[0] ?? null;
    /*
     * Computed HERE, in this block, and not in a second inline PHP block
     * inside the preload's condition below. Two reasons, and the second one
     * cost a red suite before it was written down:
     *
     * 1. An inline PHP block on a line of its own leaves that line's own
     *    indentation behind in the output — a whitespace change on every
     *    homepage carrying this section, which is what
     *    StorefrontEnglishUnchangedTest is for.
     * 2. BLADE LOOKS FOR THE CLOSING DIRECTIVE AS TEXT, so writing one inside
     *    a PHP comment in here ends this block early and the rest of it is
     *    printed as markup — "syntax error, unexpected token" on a line that
     *    is a comment. It is the same trap the cart page hit with a Blade
     *    comment closer, one directive along. Name the directives in prose,
     *    never spell them.
     */
    $bsLcpSrcset = $bsFirst !== null ? $bsSrcsetFor((string) $bsFirst->image) : '';
    /*
     * The preload's `imagesizes` mirrors the element it is a preload FOR, or
     * the browser preloads one candidate and the parser then asks for another.
     * The single unconditioned preload below stands in for the <img>, so it
     * takes the <img>'s combined string; the media-scoped pair stands in for
     * one element each and takes theirs.
     */
    $bsLcpSizes = $bsFirst !== null ? $bsImgSizesFor($bsFirst) : '';
    $bsLcpDeskSizes = $bsFirst !== null ? $bsSizesFor($bsFirst) : '';

    /*
     * THE FIRST SLIDE'S PHONE HALF, for the preload below. Resolved here for
     * the two reasons the block above this line gives, and for a third: it is
     * what decides whether the page emits ONE preload or a MEDIA-SCOPED PAIR,
     * and that decision cannot be made inside the condition it controls.
     */
    $bsLcpPhone = $bsFirst !== null ? $bsPhoneFor($bsFirst) : null;
    $bsLcpPhoneSrcset = $bsLcpPhone === null ? '' : match ($bsLcpPhone['mode']) {
        'crop' => (string) $bsLcpPhone['srcset'],
        'own' => $bsSrcsetFor($bsLcpPhone['image']),
        default => '',
    };
@endphp
{{-- THE FIRST PICTURE IS THE ONE THE PAGE PRELOADS. With this section on it is
     the largest element in its part of the page and, above the fold, the
     homepage's LCP element. `fetchpriority="high"` on the <img> below asks for
     the same thing; this asks for it before the parser has reached the markup,
     which is the half that matters on a slow connection. Only the first — a
     preload of all five would spend the whole connection on pictures four of
     which nobody has asked to see.

     ▲ IT IS PUSHED TO <head> NOW, AND IT USED TO BE PRINTED RIGHT HERE.
                                                                     (Lane SEC)

     Which was two thirds of the fix and looked like all of it. "Before the
     parser has reached the markup" was true and not the claim that mattered:
     printed in place, the tag sits wherever this section sits in the document —
     measured on the shipped homepage with this section as the banner, 20.4 KB
     in — and the preload scanner does not reach it until that much of the body
     has arrived. cards-banner.blade.php's own note records the owner's
     measurement of exactly this shape: "Resource load delay 2,260 ms", with
     Lighthouse's LCP request discovery PASSING, on a 51 KiB document over a
     1,638 kb/s link. The tag has to be in the first kilobyte, not merely
     earlier than the picture.

     It was invisible until this round because the slider was an optional
     mid-page section, and a preload for a picture two screens down buys little
     either way. It is the homepage's banner now, so its first picture IS the
     LCP element and PerfDeliveryTest's `it names the first banner picture in
     the head of the homepage` covers this partial as well as the cards one —
     it went red on the kind change, which is how this was found.

     THE GUARD IS THE CARDS BANNER'S, WORD FOR WORD AND FOR ITS REASONS.
     `$sections` comes from the parent view and is guarded because the admin
     preview renders this partial with no parent at all; `deviceClassFor()`
     returns '' only when NEITHER device switch is off, because `d-off` and
     `m-off` are `display:none !important` and a preload for a picture that
     device never draws is bandwidth taken from whatever its LCP really is.

     AND THE PUSH IS INSIDE THE CONDITION RATHER THAN AROUND IT, so a shop
     whose switches hide the section pushes nothing at all instead of pushing
     an empty line into every homepage's head. --}}
@if ($bsFirst !== null && isset($sections) && $sections->deviceClassFor('cards_banner') === '')
  @push('head')
@if ($bsLcpPhone['mode'] !== 'whole')
{{-- TWO TAGS, ONE FETCH. A slide with its own phone picture has two different
     files that could be the LCP element, and which one it is depends on the
     viewport — so each preload carries the `media` that decides it and a
     browser acts on exactly one. Written as a single unconditioned preload the
     phone would fetch the DESKTOP file it is never going to paint, which is the
     whole 1920px of it on the connection that can least afford it.

     THE MEDIA IS THE SAME CONSTANT THE SOURCE ELEMENT BELOW USES, for the
     reason that constant is declared at all: a preload that disagrees with the
     picture by one pixel of breakpoint downloads both files on the width in
     between. --}}
<link rel="preload" as="image" fetchpriority="high" media="{{ $bsPhoneQuery }}" href="{{ Banners::imageUrl($bsLcpPhone['image']) }}"@if ($bsLcpPhoneSrcset !== '') imagesrcset="{{ $bsLcpPhoneSrcset }}" imagesizes="{{ $bsLcpPhone['sizes'] }}"@endif>
<link rel="preload" as="image" fetchpriority="high" media="(min-width: 768px)" href="{{ Banners::imageUrl($bsFirst->image) }}"@if ($bsLcpSrcset !== '') imagesrcset="{{ $bsLcpSrcset }}" imagesizes="{{ $bsLcpDeskSizes }}"@endif>
@else
{{-- ONE TAG, because there is one file: the slide has no phone picture, so both
     frames draw the desktop one and a `media` would say nothing. --}}
<link rel="preload" as="image" fetchpriority="high" href="{{ Banners::imageUrl($bsFirst->image) }}"@if ($bsLcpSrcset !== '') imagesrcset="{{ $bsLcpSrcset }}" imagesizes="{{ $bsLcpSizes }}"@endif>
@endif
  @endpush
@endif
<style>
/* Prefix kbbs-, used nowhere else in this application. (The cards banner is
   kbbn-; the two sections share no selector and no custom property except the
   two BACKGROUND ones below, which are written by one method for both.) */
.kbbs{--kbbs-gut:var(--site-gutter,18px);position:relative}

/* ── THE SECTION'S OWN BACKGROUND ────────────────────────────────────────────
   The same control, the same method and the same bleed idiom the cards banner
   uses, so a shop with one of each does not have two ideas about what "a
   background" is. `Banners::sectionVariables()` writes `--kbbn-bg` /
   `--kbbn-bgimg` and the property names are shared on purpose: one method
   decides what a background is, and a second set of names would be a second
   place to change when it moves.

   THE CLASS IS ONLY ON THE ELEMENT WHEN THE SET ASKS FOR ONE, so a slider with
   no background has no padding, no negative margin and no paint.

   `margin-inline:-gutter` + `padding-inline:gutter` are EQUAL AND OPPOSITE, so
   the contents are where they were and only the paint moved — which is why
   this cannot give the page horizontal scroll at any width. */
.kbbs.has-bg{background-color:var(--kbbn-bg,transparent);
  background-image:var(--kbbn-bgimg,none);background-size:cover;
  background-position:center;background-repeat:no-repeat;
  margin-inline:calc(var(--kbbs-gut) * -1);
  padding-inline:var(--kbbs-gut);padding-block:18px}

/* ── THE STAGE ───────────────────────────────────────────────────────────────
   The positioning context for the arrows, the bars and the pause button. Its
   block-end padding is `--kbbs-below`, which is 0 for the two treatments that
   lay the bars ON the picture and the height of the bar row for the two that
   put them under it — so "below the picture" costs the stage exactly the room
   the bars take and the frame above is untouched. */
.kbbs-stage{position:relative;padding-block-end:var(--kbbs-below,0px)}

/* ── THE FRAME: THE BOX IS RESERVED BEFORE ANY PICTURE ARRIVES ───────────────
   `aspect-ratio` is on the frame and comes from the SET, so the height is
   known from the stylesheet at first paint and a picture that arrives late
   moves nothing. There is no layout shift as this loads, and that is a
   property of this declaration rather than of the <img> attributes below it.

   TWO RATIOS, because one is not enough. A 21:9 hero is a 44px band on a 390px
   screen. The phone's shape is the default and the desktop's is assigned at
   768px, which is the same direction of travel the cards banner takes with
   `--kbbn-per` and for the same reason: the narrow value is the one that must
   win when nothing else matches.

   `container-type:inline-size` is what makes `100cqi` below mean "this frame",
   and it is the sanctioned answer to rule 4 rather than a way round it — the
   browser measuring its own box, once, in the layout engine.

   THE SCROLLER IS THE NO-SCRIPT SLIDER. Before `is-js` is added this is a
   native scroll-snap rail: every picture reachable by swipe, by trackpad and
   by keyboard, at the right size. */
.kbbs-vp{aspect-ratio:var(--kbbs-arm,4 / 3);container-type:inline-size;
  border-radius:var(--kbbs-r,18px);box-shadow:var(--kbbs-sh,none);
  background:var(--line2,#F4EEF1);overflow-x:auto;overflow-y:hidden;
  scroll-snap-type:x mandatory;scroll-behavior:smooth;
  scrollbar-width:none;-webkit-overflow-scrolling:touch}
.kbbs-vp::-webkit-scrollbar{display:none}
@media (min-width:768px){.kbbs-vp{aspect-ratio:var(--kbbs-ar,16 / 9)}}

/* With the script running the frame stops being a scroll container and the
   track is moved instead. `touch-action:pan-y` is the half that keeps a swipe
   from fighting the page: the browser keeps the vertical axis and never waits
   on this element to decide whether a downward drag was a scroll. */
.kbbs.is-js .kbbs-vp{overflow-x:hidden;scroll-snap-type:none;touch-action:pan-y}

.kbbs-tr{display:flex;width:max-content;height:100%}
.kbbs.is-js .kbbs-tr{transform:translateX(calc(var(--kbbs-i,0) * var(--kbbs-dirn,-1) * 100cqi));
  transition:transform .55s cubic-bezier(.22,.61,.36,1)}

/* One picture is exactly one frame wide, in `cqi` rather than in `%`, because
   a percentage would resolve against the TRACK — which is the width of all of
   them together — and `100%` would be the whole slider. */
.kbbs-s{flex:0 0 100cqi;width:100cqi;height:100%;scroll-snap-align:start}
.kbbs-a{display:block;width:100%;height:100%;text-decoration:none;-webkit-user-select:none;user-select:none}
/* ▲ THE PICTURE MUST NOT BE DRAGGABLE, and this is a FIX rather than a polish.
   An <img> is draggable by default: pressing on one and moving starts the
   browser's own drag-and-drop, which swallows the `pointerup` the swipe handler
   is waiting for. Measured with a real pointer drag across the frame — the
   slider did not move, and the handler had never run. The attribute on the tag
   and this declaration are the two halves browsers actually honour. */
/* ▲ `picture` IS IN THIS SELECTOR AND IT IS A FIX, NOT TIDINESS. (Lane SEC)
   A <picture> is an inline element with no height of its own, so wrapping the
   <img> in one to art-direct the phone crop puts a box between the slide and
   the picture and `height:100%` on the <img> then resolves against nothing —
   the frame keeps its reserved height and the photograph collapses to its
   intrinsic one inside it. Measured before the rule was added: the desktop
   frame stayed 344.5px and the image drew 366px tall in it.
   Only slides WITH a phone picture have the wrapper, so this declaration is
   inert on every other one. */
.kbbs-a picture{display:block;width:100%;height:100%}
.kbbs-a img{display:block;width:100%;height:100%;object-fit:cover;
  -webkit-user-drag:none;user-select:none;pointer-events:none}
.kbbs-a:focus-visible{outline:3px solid var(--ink,#2A2228);outline-offset:-3px}

/* ── THE CONTROL LAYER ───────────────────────────────────────────────────────
   Stretched over the FRAME and not over the stage — `inset-block-end` takes
   the bar row's own strip back off — so an arrow centred at 50% is centred on
   the picture in all four treatments, including the two that put a row of bars
   underneath it.

   `pointer-events:none` on the layer and `auto` on each button, so the layer
   never eats a click meant for the picture underneath. */
.kbbs-ctl{position:absolute;inset:0;inset-block-end:var(--kbbs-below,0px);pointer-events:none}

/* ── THE ARROWS ──────────────────────────────────────────────────────────────
   REAL <button> ELEMENTS with real accessible names, which is the whole
   difference between these and the cards banner's. They are focusable, they
   are in the tab order, they announce themselves, and they work in every
   browser rather than in Chrome and Edge only.

   Not drawn at all until the script is running: `.is-js.is-arrows`. */
.kbbs-nav{pointer-events:auto;position:absolute;top:50%;transform:translateY(-50%);
  display:none;align-items:center;justify-content:center;padding:0;border:0;
  width:var(--kbbs-navw,40px);height:var(--kbbs-navh,40px);
  border-radius:var(--kbbs-navr,999px);cursor:pointer;
  background:var(--kbbs-navbg,rgba(255,255,255,.93));color:var(--ink,#2A2228);
  box-shadow:var(--kbbs-navsh,0 6px 18px -8px rgba(42,34,40,.55));
  transition:background .18s,opacity .18s,transform .18s}
.kbbs.is-js.is-arrows .kbbs-nav{display:inline-flex}
.kbbs-prev{inset-inline-start:var(--kbbs-navx,12px)}
.kbbs-next{inset-inline-end:var(--kbbs-navx,12px)}
.kbbs-nav:hover{background:var(--kbbs-navhv,#fff)}
.kbbs-nav:focus-visible{outline:2px solid var(--ink,#2A2228);outline-offset:2px}
.kbbs-nav svg{width:18px;height:18px;display:block}
/* The chevron is drawn once, pointing at the inline start, and turned round by
   a sign rather than by a second path or a [dir] rule. */
.kbbs-prev svg{transform:scaleX(var(--kbbs-flip,1))}
.kbbs-next svg{transform:scaleX(calc(var(--kbbs-flip,1) * -1))}

/* ── THE PAUSE BUTTON ────────────────────────────────────────────────────────
   WCAG 2.2.2 wants a mechanism to stop moving content, and hovering is not one
   for a shopper who is not holding a mouse. It is drawn only when there is
   something to pause, and it names what it will do next rather than what the
   slider is doing now. */
.kbbs-pp{pointer-events:auto;position:absolute;top:12px;inset-inline-end:12px;
  display:none;align-items:center;justify-content:center;width:30px;height:30px;
  padding:0;border:0;border-radius:999px;cursor:pointer;
  background:rgba(255,255,255,.86);color:var(--ink,#2A2228);
  box-shadow:0 4px 12px -6px rgba(42,34,40,.5);transition:background .18s,opacity .18s}
.kbbs.is-js.is-auto .kbbs-pp{display:inline-flex}
.kbbs-pp:hover{background:#fff}
.kbbs-pp:focus-visible{outline:2px solid var(--ink,#2A2228);outline-offset:2px}
.kbbs-pp svg{width:13px;height:13px;display:block}
.kbbs-pp .kbbs-play{display:none}
.kbbs-pp.is-play .kbbs-play{display:block}
.kbbs-pp.is-play .kbbs-pause{display:none}

/* ── THE BARS ────────────────────────────────────────────────────────────────
   "thin bars the bottom of the banner to control all sliders."

   One per picture, each a real <button> that says which picture it is. The
   VISIBLE bar is `--kbbs-barh` thin — 3 to 5px — and the BUTTON around it is
   `--kbbs-hit` tall, which is the touch target. A 3px tap target is not a
   control; a 3px bar inside an 18px button is.

   `justify-content` is `center` or `start`, both of which are the LOGICAL
   keywords, so the row starts at the inline start in Arabic with no rule. */
/* ON THE STAGE, NOT IN THE CONTROL LAYER, and that is the whole of "on the
   picture or under it". The layer above stops at the frame; the stage carries
   `--kbbs-below` of padding underneath it. So `bottom:0` here is the bottom of
   the PICTURE when that padding is 0 and the bottom of the BAR STRIP when it is
   not, and a treatment moves the bars from one place to the other by changing
   one number rather than by moving an element. */
.kbbs-bars{position:absolute;inset-inline:0;bottom:var(--kbbs-barb,0px);
  display:none;align-items:center;justify-content:var(--kbbs-barj,center);
  gap:var(--kbbs-barg,7px);padding-block:var(--kbbs-barpb,0 10px);padding-inline:var(--kbbs-barpi,12px)}
.kbbs.is-js.is-bars .kbbs-bars{display:flex}
.kbbs-bar{flex:0 0 auto;display:flex;align-items:center;padding:0;border:0;
  background:none;cursor:pointer;width:var(--kbbs-barw,26px);height:var(--kbbs-hit,18px)}
.kbbs-line{display:block;width:100%;height:var(--kbbs-barh,3px);overflow:hidden;
  border-radius:var(--kbbs-barr,999px);background:var(--kbbs-barbg,rgba(255,255,255,.45));
  transition:background .2s}
.kbbs-bar.is-on .kbbs-line{background:var(--kbbs-baron,#fff)}
.kbbs-bar:hover .kbbs-line{background:var(--kbbs-baron,#fff)}
/* A TWO-TONE FOCUS RING, because the bar is the one control whose own colour
   changes with the treatment: `--kbbs-baron` is the shop's ink under the two
   that sit below the picture and plain white under the two that sit on it, and
   a white ring over a pale photograph is no ring at all. The dark halo behind
   it is what makes the keyboard user's only feedback visible over both. */
.kbbs-bar:focus-visible{outline:2px solid var(--kbbs-baron,#fff);outline-offset:1px;
  border-radius:2px;box-shadow:0 0 0 4px rgba(18,12,16,.35)}

/* The filling rail is ONE treatment's, and the fill grows by `inline-size`
   rather than by a transform on purpose: an inline size grows from the inline
   START, so it fills left-to-right in English and right-to-left in Arabic with
   no origin to set and no rule to write. */
.kbbs-fill{display:none}
.kbbs.is-fill .kbbs-bar.is-on .kbbs-fill{display:block;height:100%;inline-size:0;
  background:var(--kbbs-baron,#fff)}
/* ▲ WHILE IT IS ACTUALLY RUNNING the current segment keeps the TRACK colour and
   the growing fill is the bright part — otherwise the segment is solid before
   the fill has grown a pixel and there is nothing to watch. The moment it stops
   (paused, or autoplay off) the solid highlight comes back, because a frozen
   fill at 0% would leave no mark on the current picture at all. Caught in the
   first contact sheet: the rail's first segment was solid dark and the fill was
   invisible underneath it. */
.kbbs.is-fill.is-auto:not(.is-paused) .kbbs-bar.is-on .kbbs-line{
  background:var(--kbbs-barbg,rgba(255,255,255,.45))}
.kbbs.is-fill.is-js.is-auto:not(.is-paused) .kbbs-bar.is-on .kbbs-fill{
  animation:kbbs-fill var(--kbbs-dwell,5s) linear forwards}
@keyframes kbbs-fill{from{inline-size:0}to{inline-size:100%}}

/* The live region. Off-screen rather than `display:none`, because a hidden
   element is not announced. */
.kbbs-live{position:absolute;width:1px;height:1px;margin:-1px;padding:0;
  overflow:hidden;clip-path:inset(50%);white-space:nowrap;border:0}

/* ══ TREATMENT 1 — ON THE PICTURE ═══════════════════════════════════════════
   Round arrows over the picture at each edge, and the bars are segments
   running the whole width along the bottom of it. `flex:1 1 auto` on the bar
   and `gap:0` are what make them read as one divided strip rather than as
   dots that grew. The scrim is not decoration: white segments over a pale
   photograph are invisible, and this is the same argument the cards banner
   makes for the scrim behind its title. */
.kbbs.is-inset{--kbbs-below:0px;--kbbs-barb:0px;--kbbs-barg:0px;--kbbs-barh:4px;
  --kbbs-barr:0px;--kbbs-barpb:44px 0;--kbbs-barpi:0;--kbbs-hit:20px;
  --kbbs-barbg:rgba(255,255,255,.40);--kbbs-baron:#fff}
.kbbs.is-inset .kbbs-bar{flex:1 1 auto;width:auto}
/* The strip is clipped to the frame's OWN corner radius. Without this the
   scrim is a square-cornered block sitting past two rounded corners, which is
   the kind of thing only a screenshot finds. */
.kbbs.is-inset .kbbs-bars{background-image:linear-gradient(to top,rgba(18,12,16,.46) 0%,rgba(18,12,16,.16) 46%,rgba(18,12,16,0) 100%);
  border-end-start-radius:var(--kbbs-r,18px);border-end-end-radius:var(--kbbs-r,18px);overflow:hidden}

/* ══ TREATMENT 2 — BESIDE THE PICTURE ══════════════════════════════════════
   The arrows sit OUTSIDE the frame, in padding the stage carries, so nothing
   is ever over the photograph; the bars are short ticks centred underneath it
   in the shop's own pink. On a narrow screen the padding would eat the picture,
   so below 640px the arrows come back onto it — the one place a treatment
   changes shape, and it changes for the reason the shop's own rails do. */
.kbbs.is-outside{--kbbs-below:26px;--kbbs-navx:0px;--kbbs-navw:36px;--kbbs-navh:36px;
  --kbbs-barb:0px;--kbbs-barg:7px;--kbbs-barh:3px;--kbbs-barw:28px;--kbbs-barpb:0;--kbbs-barpi:0;
  --kbbs-hit:18px;--kbbs-navbg:#fff;--kbbs-barbg:var(--line,#EADCE2);--kbbs-baron:var(--pink,#E8919F)}
.kbbs.is-outside .kbbs-stage{padding-inline:46px}
/* AND THE PAUSE BUTTON COMES OFF THE PICTURE TOO, or this treatment's whole
   promise — "nothing is ever over the photograph" — would be true of two
   controls out of three. `--kbbs-below` negated puts it in the strip the bars
   are in, at the inline start, where it reads as part of that row. */
.kbbs.is-outside .kbbs-pp{top:auto;bottom:calc(var(--kbbs-below,0px) * -1);
  inset-inline-start:0;inset-inline-end:auto;width:24px;height:24px;
  background:var(--line2,#F4EEF1);box-shadow:none}
.kbbs.is-outside .kbbs-pp:hover{background:var(--line,#EADCE2)}
.kbbs.is-outside .kbbs-pp svg{width:11px;height:11px}
@media (max-width:639px){
  .kbbs.is-outside .kbbs-stage{padding-inline:0}
  .kbbs.is-outside{--kbbs-navx:10px;--kbbs-navbg:rgba(255,255,255,.93)}
}

/* ══ TREATMENT 3 — CLEAN ═══════════════════════════════════════════════════
   Nothing over the picture at rest on a desktop: the arrows are flat plates
   flush to the frame's inline edges and they fade in on hover or on focus.
   Underneath, ONE continuous rail whose current segment fills as the picture
   rests.

   THE FADE IS DESKTOP-ONLY AND THAT IS NOT A DETAIL. There is no hover on a
   phone, so a control that appears on hover is a control a phone shopper never
   gets. Below 1024px they are simply there. `:focus-within` is on the same rule
   as `:hover`, so a keyboard user reaches them without a mouse at any width. */
.kbbs.is-veil{--kbbs-below:20px;--kbbs-navx:0px;--kbbs-navw:42px;--kbbs-navh:66px;
  --kbbs-navr:0px;--kbbs-navsh:none;--kbbs-navbg:rgba(255,255,255,.90);
  --kbbs-barb:0px;--kbbs-barg:3px;--kbbs-barh:3px;--kbbs-barpb:0;--kbbs-barpi:0;--kbbs-hit:16px;
  --kbbs-barbg:var(--line,#EADCE2);--kbbs-baron:var(--ink,#2A2228)}
.kbbs.is-veil .kbbs-bar{flex:1 1 auto;width:auto}
/* The plate is square against the frame's edge and ROUNDED ON THE INNER ONE, so
   it reads as a tab growing out of the picture rather than as a white rectangle
   dropped on it. Logical corners, so the pair swaps ends in Arabic. Found in the
   390 contact sheet, where a phone shows these permanently. */
.kbbs.is-veil .kbbs-prev{border-start-end-radius:9px;border-end-end-radius:9px}
.kbbs.is-veil .kbbs-next{border-start-start-radius:9px;border-end-start-radius:9px}
@media (min-width:1024px){
  .kbbs.is-veil .kbbs-nav,.kbbs.is-veil .kbbs-pp{opacity:0}
  .kbbs.is-veil:hover .kbbs-nav,.kbbs.is-veil:focus-within .kbbs-nav,
  .kbbs.is-veil:hover .kbbs-pp,.kbbs.is-veil:focus-within .kbbs-pp{opacity:1}
}

/* ══ TREATMENT 4 — CORNERED ════════════════════════════════════════════════
   Both arrows together as one capsule in the block-end inline-end corner, and
   the bars as short thick ticks in the other. The two halves of the capsule are
   `inset-inline-end` offsets — one of them by a constant that is the other
   button's own width — so the pair swaps ends in Arabic and stays joined. */
.kbbs.is-corner{--kbbs-below:0px;--kbbs-navw:40px;--kbbs-navh:36px;--kbbs-navr:0px;
  --kbbs-navbg:rgba(255,255,255,.94);--kbbs-barb:0px;--kbbs-barg:5px;--kbbs-barh:5px;
  --kbbs-barw:20px;--kbbs-barj:start;--kbbs-barpb:0 16px;--kbbs-barpi:18px 0;--kbbs-hit:20px;
  --kbbs-barbg:rgba(255,255,255,.45);--kbbs-baron:#fff}
.kbbs.is-corner .kbbs-nav{top:auto;bottom:16px;transform:none}
.kbbs.is-corner .kbbs-prev{inset-inline-start:auto;inset-inline-end:calc(18px + 41px);
  border-start-start-radius:999px;border-end-start-radius:999px}
.kbbs.is-corner .kbbs-next{inset-inline-end:18px;
  border-start-end-radius:999px;border-end-end-radius:999px}

/* ── NOTHING MOVES FOR A SHOPPER WHO ASKED FOR NOTHING TO MOVE ───────────────
   STOPPED, not slowed, and in three places at once: the script never starts
   its timer (it asks matchMedia, and it asks again if the setting changes
   while the page is open), the track jumps rather than glides, and the
   progress fill does not run. The arrows and the bars stay exactly as they
   were — under this query they are the ONLY way to move the slider, so hiding
   them would be the opposite of what was asked for. */
@media (prefers-reduced-motion: reduce){
  .kbbs.is-js .kbbs-tr{transition:none}
  .kbbs .kbbs-fill{animation:none}
  .kbbs-vp{scroll-behavior:auto}
  .kbbs-nav,.kbbs-pp,.kbbs-line{transition:none}
  .kbbs.is-veil .kbbs-nav,.kbbs.is-veil .kbbs-pp{opacity:1}
}
</style>
<div class="kbbs is-{{ $bsStyle }}{{ $bsArrows ? ' is-arrows' : '' }}{{ $bsBars ? ' is-bars' : '' }}{{ $bsDwell > 0 ? ' is-auto' : '' }}{{ $set->sliderFills() ? ' is-fill' : '' }}{{ $bsBgMode === 'none' ? '' : ' has-bg' }}"
     id="{{ $bsUid }}"
     style="{{ $bsVars }}{{ $bsBgVars === '' ? '' : ';'.$bsBgVars }}"
     role="region"
     aria-roledescription="carousel"
     aria-label="{{ $bsLabel }}"
     data-dwell="{{ $bsDwell }}"
     data-flip="{{ $bsRtl ? -1 : 1 }}"
     data-live="{{ __('store.home.banner_slider_live', ['n' => ':n', 'total' => $bsCount]) }}"
     data-pause="{{ __('store.home.banner_slider_pause') }}"
     data-play="{{ __('store.home.banner_slider_play') }}">
  <div class="kbbs-stage">
    <div class="kbbs-vp">
      <div class="kbbs-tr">
        @foreach ($cards as $bsI => $bsCard)
          @php
            /*
             * ONE <a> PER PICTURE AND NEVER A NESTED ONE — there is nothing
             * else on a slide to put a link on, which is the point of this
             * banner type. A picture whose URL safeUrl() refused, or which has
             * none, draws an <a> WITH NO href: not a link, not focusable, not
             * announced, and the same element either way so a closing tag
             * cannot end up disagreeing with its opening one.
             */
            $bsHref = Banners::safeUrl($bsCard->button_url);
            $bsAlt = trim((string) $bsCard->alt) !== '' ? $bsCard->alt : '';
            $bsSrcset = $bsSrcsetFor((string) $bsCard->image);
            $bsDeskSizes = $bsImgSizesFor($bsCard);
            $bsPhone = $bsPhoneFor($bsCard);
            /* A crop has its OWN candidate list — the crop files, not the
               whole picture's variants — so it is carried on the array rather
               than looked up again here. An uploaded phone picture is an
               ordinary picture and goes through the shared memo. */
            $bsPhoneSrcset = match ($bsPhone['mode']) {
                'crop' => (string) $bsPhone['srcset'],
                'own' => $bsSrcsetFor($bsPhone['image']),
                default => '',
            };
          @endphp
          <div class="kbbs-s" role="group" aria-roledescription="slide"
               aria-label="{{ __('store.home.banner_slider_slide', ['n' => $bsI + 1, 'total' => $bsCount]) }}">
            {{-- `draggable="false"` ON THE ANCHOR AS WELL AS ON THE PICTURE.
                 A link is draggable by default too, and it was the ANCHOR that
                 was still starting a drag after the <img> had been stopped:
                 measured, `dragstart` fired and the browser sent
                 `pointercancel`, so the swipe handler never saw its
                 `pointerup` and the slider did not move. --}}
            <a class="kbbs-a" draggable="false"@if ($bsHref !== '') href="{{ Url::to($bsHref) }}"@endif>
              {{-- THE FIRST PICTURE IS EAGER AND HIGH PRIORITY and every other
                   one is lazy: a slider that lazy-loads its own first picture
                   is a slider that made the page slower.

                   width/height come from the row, read off the file once when
                   the picture was saved. A picture whose dimensions are unknown
                   prints NEITHER attribute — a guessed width reserves the wrong
                   box, and the frame's aspect-ratio is already holding the
                   space, so there is nothing to gain by guessing.

                   An empty alt is CORRECT here rather than lazy: a decorative
                   picture with no description of its own should be skipped, not
                   read out as a filename. The box on the screen says so. --}}
@if ($bsPhone['mode'] !== 'whole')<picture>
{{-- ── THE PHONE PICTURE, AND WHY IT IS A <source> AND NOT A SECOND srcset ──

     `srcset` with `w` descriptors lets the BROWSER pick a size of the same
     picture. This is a different picture — a different crop, framed for a
     portrait box — which is art direction, and art direction is what <source
     media> is for. A `w` candidate cannot express "and below 768px it is this
     other photograph"; the browser would treat the two as interchangeable and
     hand a desktop screen the phone crop whenever it happened to want that
     width.

     The <source> carries its OWN srcset and its OWN sizes, because the
     candidates and the covered width both belong to the picture rather than to
     the slide. The <img> underneath stays exactly the element it was — it is
     the fallback the spec requires and the one every desktop draws.

     ONLY EMITTED WHEN THERE IS A PHONE PICTURE. A slide without one renders the
     <img> alone, with no <picture> wrapper at all, which is byte for byte what
     this section emitted before the column existed.

     THE CLOSER IS GLUED TO THE SOURCE TAG, as four other blocks in this file
     are and for the measured reason they record: a Blade comment is replaced by
     the empty string and ITS TRAILING NEWLINE SURVIVES, so a comment ending on
     its own line puts a blank line inside every <picture> on the page. --}}<source media="{{ $bsPhoneQuery }}"
                      srcset="{{ $bsPhoneSrcset !== '' ? $bsPhoneSrcset : Banners::imageUrl($bsPhone['image']) }}"
                      @if ($bsPhoneSrcset !== '') sizes="{{ $bsPhone['sizes'] }}" @endif
                      @if ($bsPhone['w'] && $bsPhone['h']) width="{{ (int) $bsPhone['w'] }}" height="{{ (int) $bsPhone['h'] }}" @endif>
@endif
              <img src="{{ Banners::imageUrl($bsCard->image) }}"
                   alt="{{ $bsAlt }}"
                   draggable="false"
                   @if ($bsSrcset !== '') srcset="{{ $bsSrcset }}" sizes="{{ $bsDeskSizes }}" @endif
                   @if ($bsCard->image_w && $bsCard->image_h) width="{{ (int) $bsCard->image_w }}" height="{{ (int) $bsCard->image_h }}" @endif
                   @if ($bsI === 0) fetchpriority="high" @else loading="lazy" @endif
                   decoding="async">@if ($bsPhone['mode'] !== 'whole')</picture>@endif
            </a>
          </div>
        @endforeach
      </div>
    </div>
    <div class="kbbs-ctl">
      @if ($bsArrows)
        <button class="kbbs-nav kbbs-prev" type="button" aria-label="{{ __('store.home.banner_slider_prev') }}">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 4.5 7.5 12l7.5 7.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <button class="kbbs-nav kbbs-next" type="button" aria-label="{{ __('store.home.banner_slider_next') }}">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 4.5 7.5 12l7.5 7.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
      @endif
      @if ($bsDwell > 0)
        <button class="kbbs-pp" type="button" aria-label="{{ __('store.home.banner_slider_pause') }}">
          <svg class="kbbs-pause" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8 4.5v15M16 4.5v15" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
          <svg class="kbbs-play" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 4.5 19.5 12 7 19.5Z" fill="currentColor"/></svg>
        </button>
      @endif
    </div>
    @if ($bsBars)
      <div class="kbbs-bars" role="group" aria-label="{{ __('store.home.banner_slider_bars') }}">
        @foreach ($cards as $bsI => $bsCard)
          <button class="kbbs-bar{{ $bsI === 0 ? ' is-on' : '' }}" type="button"
                  data-kbbs-go="{{ $bsI }}"
                  aria-label="{{ __('store.home.banner_slider_go', ['n' => $bsI + 1]) }}"><span class="kbbs-line"><span class="kbbs-fill"></span></span></button>
        @endforeach
      </div>
    @endif
  </div>
  <p class="kbbs-live" role="status" aria-live="polite" aria-atomic="true"></p>
</div>
<script>
/*
 * ONE INTEGER. THAT IS THE WHOLE STATE.
 *
 * CLAUDE.md rule 4: no JavaScript that measures layout. Nothing below reads a
 * size, a position or a scroll offset from any element. The only numbers that
 * come out of the DOM are two data attributes the server wrote and the pointer
 * coordinates of a swipe, which are properties of the EVENT and not of an
 * element. `--kbbs-i` goes out, and the stylesheet turns it into pixels.
 *
 * Written as a classic IIFE with `var`, no arrow functions and no optional
 * chaining, to match every other inline script this storefront serves.
 */
(function(){
  var root = document.getElementById('kbbs-{{ (int) $set->id }}');

  /* The admin preview injects this markup into a live page and the homepage
     may be re-rendered by the section editor, so the same element can arrive
     twice. Wiring it twice would give it two timers running at the same dwell
     and half a second apart, which reads as a slider that sometimes skips. */
  if (!root || root.getAttribute('data-wired') === '1') return;
  root.setAttribute('data-wired', '1');

  var track = root.querySelector('.kbbs-tr');
  var slides = root.querySelectorAll('.kbbs-s');
  var bars = root.querySelectorAll('.kbbs-bar');
  var live = root.querySelector('.kbbs-live');
  var vp = root.querySelector('.kbbs-vp');
  var pp = root.querySelector('.kbbs-pp');
  var n = slides.length;

  if (!track || !vp || n < 1) return;

  var dwell = Number(root.getAttribute('data-dwell')) || 0;
  var flip = Number(root.getAttribute('data-flip')) || 1;
  var says = root.getAttribute('data-live') || '';
  var sayPause = root.getAttribute('data-pause') || '';
  var sayPlay = root.getAttribute('data-play') || '';

  var at = 0;
  var timer = null;
  var hovered = false;
  var focused = false;
  var stopped = false;

  /* prefers-reduced-motion, asked rather than assumed, and asked AGAIN if it
     changes while the page is open — somebody who turns the setting on while
     looking at a moving slider has just told us to stop. matchMedia reads a
     media query; it measures no element. */
  var calm = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;

  function quiet(){ return !!(calm && calm.matches); }

  /*
   * WHAT IS PAINTED, AND WHY `inert`.
   *
   * The pictures that are not showing are still in the document, just moved
   * out of the frame. A link inside one of them is a tab stop that focuses
   * something nobody can see — and because the frame is `overflow:hidden` with
   * a transform, the browser cannot even scroll it into view. `inert` takes the
   * whole subtree out of the tab order and out of the accessibility tree at
   * once; the explicit tabIndex beside it is the fallback for a browser that
   * does not have `inert`, and neither of them is a measurement.
   */
  function paint(){
    for (var k = 0; k < n; k++) {
      var on = k === at;
      var slide = slides[k];

      try { slide.inert = !on; } catch (e) {}

      var link = slide.querySelector('a[href]');
      if (link) link.tabIndex = on ? 0 : -1;

      if (bars[k]) {
        if (on) {
          bars[k].className = 'kbbs-bar is-on';
          bars[k].setAttribute('aria-current', 'true');
        } else {
          bars[k].className = 'kbbs-bar';
          bars[k].removeAttribute('aria-current');
        }
      }
    }

    track.style.setProperty('--kbbs-i', at);
  }

  /*
   * `tell` is false for an autoplay step and true for everything a shopper
   * did. A live region that announced every autoplay tick would read the whole
   * slider aloud for ever, which is worse than saying nothing — so it speaks
   * only in answer to an action.
   */
  function go(k, tell){
    at = ((k % n) + n) % n;
    paint();

    if (tell && live && says) {
      live.textContent = says.replace(':n', String(at + 1));
    }
  }

  function running(){
    return dwell > 0 && !stopped && !hovered && !focused && !quiet() && !document.hidden;
  }

  /* One place starts and stops the timer, and it also writes the class the
     progress fill is paused by — so the bar and the picture cannot disagree
     about whether the slider is moving. */
  function beat(){
    if (timer) { clearInterval(timer); timer = null; }

    if (running()) {
      root.classList.remove('is-paused');
      timer = setInterval(function(){ go(at + 1, false); }, dwell);
    } else {
      root.classList.add('is-paused');
    }
  }

  var prev = root.querySelector('.kbbs-prev');
  var next = root.querySelector('.kbbs-next');

  if (prev) prev.addEventListener('click', function(){ go(at - 1, true); beat(); });
  if (next) next.addEventListener('click', function(){ go(at + 1, true); beat(); });

  for (var b = 0; b < bars.length; b++) {
    (function(bar){
      bar.addEventListener('click', function(){
        go(Number(bar.getAttribute('data-kbbs-go')) || 0, true);
        beat();
      });
    })(bars[b]);
  }

  if (pp) {
    pp.addEventListener('click', function(){
      stopped = !stopped;
      pp.className = stopped ? 'kbbs-pp is-play' : 'kbbs-pp';
      pp.setAttribute('aria-label', stopped ? sayPlay : sayPause);
      beat();
    });
  }

  /* The three floors. Hover and focus are not the set's choice: a control that
     slides out from under a keyboard user is unusable, and a shopper reading a
     picture has said which one he is reading. */
  root.addEventListener('mouseenter', function(){ hovered = true; beat(); });
  root.addEventListener('mouseleave', function(){ hovered = false; beat(); });
  root.addEventListener('focusin', function(){ focused = true; beat(); });
  root.addEventListener('focusout', function(){ focused = false; beat(); });
  document.addEventListener('visibilitychange', beat);

  if (calm) {
    if (calm.addEventListener) calm.addEventListener('change', beat);
    else if (calm.addListener) calm.addListener(beat);
  }

  /*
   * KEYBOARD. The arrow keys follow what the shopper can SEE, so left is
   * "the picture on the left" in both directions — which is the previous one in
   * English and the next one in Arabic. `flip` is the same sign the chevrons
   * are turned round by.
   */
  root.addEventListener('keydown', function(ev){
    if (ev.key === 'ArrowLeft') { go(at - flip, true); beat(); ev.preventDefault(); }
    else if (ev.key === 'ArrowRight') { go(at + flip, true); beat(); ev.preventDefault(); }
  });

  /*
   * SWIPE, AND IT DOES NOT FIGHT THE PAGE.
   *
   * `touch-action:pan-y` in the stylesheet gives the vertical axis to the
   * browser outright, so a downward drag scrolls the page immediately and never
   * waits on this handler. What is left is a horizontal gesture, and it is
   * decided from the POINTER'S OWN COORDINATES — `clientX` and `clientY` are
   * properties of the event, not measurements of an element, and no layout is
   * read to compare them.
   *
   * 44px is the shop's touch-target size and is the threshold on purpose: a
   * drag shorter than a thumb is a tap that wobbled.
   *
   * A real swipe also SUPPRESSES THE CLICK that follows it, in the capture
   * phase, because every picture may be a link and a shopper who dragged the
   * slider did not ask to leave the page.
   */
  var downX = 0, downY = 0, dragging = false, swiped = false;

  vp.addEventListener('pointerdown', function(ev){
    if (ev.pointerType === 'mouse' && ev.button !== 0) return;
    dragging = true;
    swiped = false;
    downX = ev.clientX;
    downY = ev.clientY;
  });

  vp.addEventListener('pointerup', function(ev){
    if (!dragging) return;
    dragging = false;

    var dx = ev.clientX - downX;
    var dy = ev.clientY - downY;

    if (Math.abs(dx) < 44 || Math.abs(dy) > Math.abs(dx)) return;

    swiped = true;
    go(at + (dx * flip < 0 ? 1 : -1), true);
    beat();
  });

  vp.addEventListener('pointercancel', function(){ dragging = false; });

  vp.addEventListener('click', function(ev){
    if (!swiped) return;
    swiped = false;
    ev.preventDefault();
    ev.stopPropagation();
  }, true);

  /* `is-js` LAST, so the frame only stops being a native scroll rail once
     everything that replaces it is wired. A script that threw halfway down
     leaves a working slider behind rather than a dead one. */
  root.classList.add('is-js');
  go(0, false);
  beat();
})();
</script>
