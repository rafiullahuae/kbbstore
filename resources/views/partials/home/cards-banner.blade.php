{{--
    THE CARDS BANNER — Phase 22, Lane BN.

    The owner: "I need multiple cards type with auto scroll smooth scroll, each
    card will have image banner and downside 1-2 lines text with right side
    small beautiful button", and then: "need same sizes of the cards, and also
    in mobile such half cut etc design i need. on desktop 4 cards and 5 and 6
    will cut little bit ... with full control to turn on off bottom text etc."

    ══ THE WHOLE THING IS CSS. THERE IS NO JAVASCRIPT IN THIS FILE. ═══════════

    CLAUDE.md rule 4: "No JavaScript that measures layout — this project sizes
    with calc() for a reason, and two tests forbid the element-measuring APIs by
    name." A carousel that advances by reading offsetWidth is precisely the
    shape that rule exists to stop, and it is the shape almost every carousel
    on the web has.

    So:

      · THE MOTION is one CSS animation over a track that holds the cards TWICE
        and translates by -50%. At -50% the second copy sits exactly where the
        first began, so the loop has no seam and nothing has to be measured,
        appended, recycled or timed in script. The browser runs it on the
        compositor.

      · THE SIZE is one calc() on the card. `--kbbn-per` full cards plus
        `--kbbn-peek` of the next one fill the row:

            flex-basis: (100cqi - gap × per) / (per + peek)

        `100cqi` is the inline size of the scroller, which is a container query
        unit and therefore the browser's own measurement of its own box — the
        thing a resize listener exists to recompute, done by the layout engine
        once, for free, and again by itself when the window changes. "Auto
        adjustment from large screens to small" is that one declaration plus
        three media queries that change `--kbbn-per` and `--kbbn-peek` and
        nothing else.

      · THE GAP IS A MARGIN ON THE CARD, NOT `gap` ON THE TRACK, and that is
        not a style preference — it is what makes the loop seamless. A flex
        `gap` puts (2n − 1) gaps in a track of 2n cards, so half the track is
        n cards and n − ½ gaps while one copy is n cards and n gaps: the loop
        jumps by half a gap every cycle. As a margin-inline-end each card
        carries its own gap, half the track is exactly one copy, and -50% lands
        on the seam.

    ══ WHY THE STYLESHEET IS INLINE HERE AND NOT IN resources/css ════════════

    HomepageSections::orderStyle() already records the mechanism and it applies
    unchanged: the storefront serves BUILT css through @vite, package.json
    defines no build script, and nobody runs `npx vite build` during an update.
    A rule added to kbb.css therefore ships INERT until somebody rebuilds the
    bundle — a carousel that silently does not move. Emitted here it is part of
    the page and cannot be stale.

    It costs the page nothing when the section is off, because the section is
    not rendered at all: home.blade.php reaches this file only when
    Banners::forHome() has returned a set AND its cards.

    ══ EVERY STRING FROM A SETTING IS ESCAPED, EVERY URL IS SCHEME-CHECKED ═══

    Rule 5. The heading, the body, the alt text and the button label are boxes
    an operator types into and all four go through {{ }}. The button's URL goes
    through Banners::safeUrl() BEFORE it becomes an href — which decodes
    entities and strips control characters before reading the scheme, because a
    browser resolves `jav&#x09;ascript:` and a naive prefix test does not.

    Nothing an operator can type reaches the <style> element. The four values
    that are printed into CSS — the aspect ratio, the shadow, the animation
    name and the radius — are CONSTANTS looked up in BannerSet's own enums, and
    a stored value that is not one of that enum's keys falls back to the
    default rather than being printed.
--}}
@php
    /*
     * The imports this partial needs, declared HERE and not borrowed from
     * store/home.blade.php. A `use` inside a Blade @php block is scoped to the
     * ONE compiled file it is written in, and an @include compiles to a second
     * file — so a partial that relies on its parent's imports dies with
     * `Class "Url" not found` the first time it is rendered, which is what this
     * file did before this line existed. The admin preview renders this partial
     * with no parent at all, which is the other reason it cannot inherit them.
     */
    use App\Services\Banners;
    use App\Support\Url;

    /** @var \App\Models\BannerSet $set */
    /** @var list<\App\Models\BannerCard> $cards */
    $bnAnimates = $set->animates();
    $bnBand = (bool) $set->show_text;
    $bnButtons = $bnBand && $set->show_button;
    $bnAnim = $set->animationCss();
    $bnDots = (bool) $set->show_dots;
    $bnArrows = (bool) $set->show_arrows;
    $bnUid = 'kbbn-'.(int) $set->id;
@endphp
<style>
/* Prefix kbbn-, used nowhere else in this application. */
.kbbn{--kbbn-gut:var(--site-gutter,18px)}

/* ── THE SCROLLER ────────────────────────────────────────────────────────────
   `container-type:inline-size` is what makes `100cqi` below mean "this box",
   and it is the sanctioned answer to rule 4 rather than a way round it — the
   same technique resources/views/ugc/assets.blade.php and the product editor
   already use in this tree.

   The negative margin and the matching padding are the shop's own rail idiom
   (kbb.css `.kbb-home .rail`): the box widens to the full width of `.wrap`'s
   border box so cards are cut at the page's content edge rather than inside
   it, and the padding puts the first card back on the gutter so the peek reads
   as design instead of as a bug. The two are EQUAL AND OPPOSITE, which is why
   this cannot give the page horizontal scroll at any width. */
.kbbn-vp{container-type:inline-size;overflow-x:auto;overflow-y:hidden;
  margin-inline:calc(var(--kbbn-gut) * -1);padding-inline:var(--kbbn-gut);
  scroll-padding-inline:var(--kbbn-gut);scroll-snap-type:x mandatory;
  scroll-behavior:smooth;scrollbar-width:none;-webkit-overflow-scrolling:touch}
.kbbn-vp::-webkit-scrollbar{display:none}

/* Autoplay: the row is a marquee, so it is not a hand-scrolled rail as well.
   Hiding the overflow is what stops a transform and a scroll offset fighting
   each other over the same pixels. */
.kbbn.is-auto .kbbn-vp{overflow-x:hidden;scroll-snap-type:none}

.kbbn-tr{display:flex;align-items:stretch;width:max-content;
  animation:var(--kbbn-anim,none) var(--kbbn-dur,40s) linear infinite}

/* Pause on focus-within ALWAYS, so a keyboard user can reach a card's button
   without it sliding out from under them. Pause on hover only when the set
   asks for it. */
.kbbn-vp:focus-within .kbbn-tr{animation-play-state:paused}
.kbbn.is-hoverpause .kbbn-vp:hover .kbbn-tr{animation-play-state:paused}

@keyframes kbbn-slide{from{transform:translate3d(0,0,0)}to{transform:translate3d(-50%,0,0)}}
@keyframes kbbn-slide-rev{from{transform:translate3d(-50%,0,0)}to{transform:translate3d(0,0,0)}}

/* ── THE CARD: ONE SIZE, ALWAYS ──────────────────────────────────────────────
   "need same sizes of the cards" is a RULE here and not a default. The height
   comes from `aspect-ratio` and from nothing else, so a card with two lines of
   text and a card with none are identical, and so are a card holding a
   portrait photograph and one holding a landscape photograph — the picture
   fills its box with object-fit:cover and the box does not move. */
.kbbn-c{flex:0 0 calc((100cqi - var(--kbbn-gap,16px) * var(--kbbn-per)) / (var(--kbbn-per) + var(--kbbn-peek)));
  width:calc((100cqi - var(--kbbn-gap,16px) * var(--kbbn-per)) / (var(--kbbn-per) + var(--kbbn-peek)));
  margin-inline-end:var(--kbbn-gap,16px);aspect-ratio:var(--kbbn-ar,3 / 4);
  display:flex;flex-direction:column;min-width:0;overflow:hidden;
  border-radius:var(--kbbn-r,18px);background:var(--line2,#F4EEF1);
  box-shadow:var(--kbbn-sh,none);scroll-snap-align:start;scroll-margin-block:14px}

/* The picture bleeds to all four corners of its area. `min-height:0` is what
   lets it give way to the fixed band instead of pushing the card taller — a
   flex item's default min-height is auto, which is how a "fixed" card grows. */
.kbbn-im{flex:1 1 auto;min-height:0;display:block;position:relative;overflow:hidden;
  background:var(--line2,#F4EEF1);text-decoration:none}
.kbbn-im img{display:block;width:100%;height:100%;object-fit:cover}

/* ── THE BAND: FIXED HEIGHT, CLAMPED COPY ───────────────────────────────────
   A long heading TRUNCATES; it does not resize the card. The clamp is the
   -webkit-line-clamp every browser in use implements, and `max-height` behind
   it is the real fallback rather than a decorative second declaration: without
   it a browser that ignored the clamp would let a three-line heading push the
   band open and break the one rule this whole section is built on. */
.kbbn-bd{flex:0 0 var(--kbbn-band,58px);height:var(--kbbn-band,58px);
  display:flex;align-items:center;gap:10px;min-width:0;
  padding:0 12px;background:#fff}
.kbbn-tx{min-width:0;flex:1 1 auto;display:flex;flex-direction:column;justify-content:center;
  gap:2px;max-height:calc(var(--kbbn-lh,17px) * 2);overflow:hidden}
.kbbn-h,.kbbn-b{display:-webkit-box;-webkit-box-orient:vertical;overflow:hidden;
  line-height:var(--kbbn-lh,17px);overflow-wrap:anywhere}
.kbbn-h{-webkit-line-clamp:1;max-height:var(--kbbn-lh,17px);
  font-size:13px;font-weight:650;color:var(--ink,#2A2228)}
.kbbn-b{-webkit-line-clamp:1;max-height:var(--kbbn-lh,17px);
  font-size:11.5px;color:var(--muted,#8A7F86)}
/* A card with no body line lets its heading use both lines rather than leaving
   the second one empty. `:only-child` is the whole mechanism — no measurement,
   no second template. */
.kbbn-h:only-child{-webkit-line-clamp:2;max-height:calc(var(--kbbn-lh,17px) * 2)}

/* "right side small beautiful button". flex:0 0 auto so it never shrinks into
   an ellipsis, and the text beside it gives way instead. */
.kbbn-btn{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;
  height:30px;padding:0 13px;border-radius:999px;font-size:11.5px;font-weight:650;
  text-decoration:none;white-space:nowrap;
  background:var(--pink,#E8919F);color:#fff;transition:.2s}
.kbbn-btn:hover{filter:brightness(.94)}
.kbbn-btn:focus-visible{outline:2px solid var(--ink,#2A2228);outline-offset:2px}

/* ── THE NAVIGATION, AND IT IS ALSO SCRIPTLESS ──────────────────────────────
   A dot is an ordinary in-page link to the card it names. Following it scrolls
   the nearest scrollable ancestor — this rail — and nothing else on the page
   moves, because the row is already in view vertically. That is a browser
   behaviour, not a feature this file implements, which is why it needs no
   script and cannot measure anything.

   The dots are drawn only where they can do something: hidden while the row is
   a marquee (there is nothing to steer — the overflow is hidden), and brought
   back by the reduced-motion query below, which turns the marquee into a rail
   the shopper scrolls by hand. */
.kbbn-nav{display:flex;align-items:center;justify-content:center;gap:7px;
  margin-top:12px;flex-wrap:wrap}
.kbbn.is-auto .kbbn-nav{display:none}
.kbbn-dot{width:8px;height:8px;border-radius:50%;background:var(--line,#EADCE2);
  transition:.2s}
.kbbn-dot:hover,.kbbn-dot:focus-visible{background:var(--pink,#E8919F);outline:none}

/* ── ARROWS: THE BROWSER'S OWN SCROLL BUTTONS, OR NONE AT ALL ───────────────
   ::scroll-button() is the only way to move a scroll container from CSS. Where
   the browser has it the arrows are real; where it does not, @supports fails
   and no arrow is drawn — rather than a button that looks like a control and
   is not one. The screen's own help text says so in as many words, because an
   owner who switches this on and sees nothing deserves the reason on the page
   and not in a commit message.

   ▲ AND EVERY RULE IS UNDER `.kbbn.is-arrows`, WHICH IS THE SET'S OWN SWITCH.
   Written without it — which is how this shipped into the first round of
   screenshots — the browser drew a pair of scroll buttons on EVERY cards
   banner, including one whose owner had left "Show arrows" off. An option that
   ships on is a default nobody chose, and the first picture of this section
   caught it. */
@supports selector(::scroll-button(inline-start)){
  .kbbn.is-arrows .kbbn-vp::scroll-button(inline-start),
  .kbbn.is-arrows .kbbn-vp::scroll-button(inline-end){
    content:'‹' / '';width:34px;height:34px;border-radius:50%;border:0;
    background:#fff;color:var(--ink,#2A2228);font-size:19px;line-height:1;
    box-shadow:0 6px 18px -8px rgba(42,34,40,.5);cursor:pointer}
  .kbbn.is-arrows .kbbn-vp::scroll-button(inline-end){content:'›' / ''}
  .kbbn.is-arrows .kbbn-vp::scroll-button(*):disabled{opacity:.35;cursor:default}
}

/* ── RESPONSIVE: THREE MEDIA QUERIES, TWO NUMBERS EACH ──────────────────────
   Everything else about the row is already a calc() of these two, so this is
   the whole of "auto adjustment from large screens to small".

   THE SERVER'S NUMBERS ARE WRITTEN AS `--kbbn-per-lg`, NOT `--kbbn-per`, AND
   THE SUFFIX IS LOAD-BEARING. They arrive in an inline `style` attribute; an
   inline declaration beats every stylesheet rule including one inside a media
   query, so a phone would be stuck on the desktop's four-across if the server
   wrote the name the card reads. The stylesheet owns `--kbbn-per`, ships it at
   the phone's value, and only at the widest breakpoint assigns it FROM the
   property the server wrote. */
.kbbn-vp{--kbbn-per:1;--kbbn-peek:.5;--kbbn-band:52px;--kbbn-lh:16px}
@media (min-width:520px){.kbbn-vp{--kbbn-per:2;--kbbn-peek:.45}}
@media (min-width:768px){.kbbn-vp{--kbbn-per:3;--kbbn-peek:.4;--kbbn-band:58px;--kbbn-lh:17px}}
@media (min-width:1024px){.kbbn-vp{--kbbn-per:var(--kbbn-per-lg,4);--kbbn-peek:var(--kbbn-peek-lg,.38)}}

/* ── NOTHING MOVES FOR A SHOPPER WHO ASKED FOR NOTHING TO MOVE ──────────────
   STOPPED, not slowed. The track's animation is removed and its transform with
   it, the second copy of the cards is taken out of the layout entirely so the
   row is the list once, the scroller goes back to being a rail the shopper
   pushes by hand, and the dots come back because now there is something for
   them to do. resources/views/ugc/assets.blade.php makes the same decision for
   the shoppable-video rail; this follows it. */
@media (prefers-reduced-motion: reduce){
  .kbbn-tr{animation:none;transform:none}
  .kbbn.is-auto .kbbn-vp{overflow-x:auto;scroll-snap-type:x mandatory}
  .kbbn.is-auto .kbbn-nav{display:flex}
  .kbbn-dup{display:none}
  .kbbn-vp{scroll-behavior:auto}
  .kbbn-btn{transition:none}
}
</style>
<div class="kbbn{{ $bnAnimates ? ' is-auto' : '' }}{{ $set->pause_on_hover ? ' is-hoverpause' : '' }}{{ $bnArrows ? ' is-arrows' : '' }}">
  <div class="kbbn-vp" style="{{ Banners::cssVariables($set, $cards) }}">
    <div class="kbbn-tr" style="--kbbn-anim:{{ $bnAnim === '' ? 'none' : $bnAnim }}">
      @for ($bnCopy = 0; $bnCopy < ($bnAnimates ? 2 : 1); $bnCopy++)
        @foreach ($cards as $bnI => $bnCard)
          @php
            /*
             * ONE <a> PER CARD, AND IT IS ALWAYS A REAL ONE.
             *
             * A card wrapped in a link that also contains a link is invalid
             * markup and unusable with a screen reader, so the anchor is placed
             * once: on the BUTTON when there is a button to draw, and on the
             * PICTURE when there is not. A card whose URL was refused by
             * safeUrl() — or which has none — draws neither, rather than a dead
             * control.
             */
            $bnHref = Banners::safeUrl($bnCard->button_url);
            $bnHasBtn = $bnButtons && trim((string) $bnCard->button_label) !== '' && $bnHref !== '';
            $bnImgLink = ! $bnHasBtn && $bnHref !== '';
            $bnDup = $bnCopy === 1;
            $bnAlt = trim((string) $bnCard->alt) !== '' ? $bnCard->alt : $bnCard->heading;
            $bnHasText = trim((string) $bnCard->heading) !== '' || trim((string) $bnCard->body) !== '';
          @endphp
          <div class="kbbn-c{{ $bnDup ? ' kbbn-dup' : '' }}"
               @if (! $bnDup) id="{{ $bnUid }}-{{ $bnI }}" @endif
               @if ($bnDup) aria-hidden="true" @endif>
            {{-- ALWAYS AN <a>, WITH OR WITHOUT AN href, and that is deliberate
                 rather than lazy. An anchor with no href is not a link: it is
                 not focusable, it has no role, and a screen reader walks past
                 it — so the one element serves both shapes without this
                 template building its own tag name out of a ternary, which is
                 how a closing tag ends up disagreeing with its opening one. --}}
            <a class="kbbn-im"@if ($bnImgLink) href="{{ Url::to($bnHref) }}"@if ($bnDup) tabindex="-1"@endif @endif>
              {{--
                   THE FIRST CARD'S PICTURE IS THE HOMEPAGE'S LCP ELEMENT once
                   this section is on, so it is EAGER with fetchpriority="high"
                   and every later one is lazy. A carousel that lazy-loads its
                   own first image is a carousel that made the page slower, and
                   it is the single commonest way a hero rail regresses LCP.

                   The duplicate copy is never the LCP candidate — it is the
                   same file, already in the cache by the time it is reached —
                   so every card in it is lazy including its first.

                   width/height come from the row, read off the file ONCE when
                   the card was saved. A card whose dimensions are unknown
                   prints NEITHER attribute: a guessed width reserves the wrong
                   box and shifts the page anyway, which is worse than leaving
                   the card's own aspect-ratio to hold the space. --}}
              <img src="{{ Banners::imageUrl($bnCard->image) }}"
                   alt="{{ $bnDup ? '' : $bnAlt }}"
                   @if ($bnCard->image_w && $bnCard->image_h) width="{{ (int) $bnCard->image_w }}" height="{{ (int) $bnCard->image_h }}" @endif
                   @if ($bnI === 0 && ! $bnDup) fetchpriority="high" @else loading="lazy" @endif
                   decoding="async">
            </a>
            @if ($bnBand && ($bnHasText || $bnHasBtn))
              {{-- The band is drawn at its FIXED height whenever it is drawn at
                   all, and the pieces inside it close up around whatever is
                   missing: no heading element when there is no heading, no
                   button element when there is no button, and `justify-content:
                   center` on the text column so one line sits where two would
                   have been centred. That is why a card with one line and a
                   card with two are the same card. --}}
              <div class="kbbn-bd">
                @if ($bnHasText)
                  <div class="kbbn-tx">
                    @if (trim((string) $bnCard->heading) !== '')<span class="kbbn-h">{{ $bnCard->heading }}</span>@endif
                    @if (trim((string) $bnCard->body) !== '')<span class="kbbn-b">{{ $bnCard->body }}</span>@endif
                  </div>
                @endif
                @if ($bnHasBtn)
                  <a class="kbbn-btn" href="{{ Url::to($bnHref) }}" @if ($bnDup) tabindex="-1" @endif>{{ $bnCard->button_label }}</a>
                @endif
              </div>
            @endif
          </div>
        @endforeach
      @endfor
    </div>
  </div>
  @if ($bnDots && count($cards) > 1)
    <nav class="kbbn-nav" aria-label="{{ __('store.home.cards_banner_nav') }}">
      @foreach ($cards as $bnI => $bnCard)
        <a class="kbbn-dot" href="#{{ $bnUid }}-{{ $bnI }}"
           aria-label="{{ __('store.home.cards_banner_go', ['n' => $bnI + 1]) }}"></a>
      @endforeach
    </nav>
  @endif
</div>
