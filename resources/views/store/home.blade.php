@extends('layouts.store')
@php
/*
 * THE FLAG STRIP IS THIS PAGE'S TO PLACE. (Lane SEC) The layout draws it above
 * the header unless the page says otherwise; this page draws it under the
 * banner instead, which is what the owner's arrow asked for. See the note at
 * the include further down, and the one in layouts/store.blade.php.
 *
 * A PHP COMMENT AND NOT A BLADE ONE, for the reason layouts/store.blade.php
 * gives over its own copy: Blade strips a Blade comment by regex and leaves
 * the newline that followed it, so a note written that way adds one byte to
 * every homepage. Written the first way it cost exactly that, and
 * StorefrontEnglishUnchangedTest reported it at byte 24715.
 *
 * (Lane LZ) The first section under the banner, per device: its first row is
 * in the first viewport, so its cards are not lazy. Read once, here.
 */
$homeFirst = $sections->firstOnScreen();
@endphp
@section('flagbar-placed', '1')
@php use App\Support\Money; use App\Support\Url; use App\Support\Gradient; @endphp

@section('title', 'K-Beauty Bliss · Authentic Korean skincare in the UAE')

@push('styles')
{{-- SECTION ORDER — Lane FR.

     Appearance → Homepage has had ↑/↓ on every row since it shipped;
     HomepageSections::save() wrote an `order`, ::all() sorted by it, and the
     five layout presets set it. This file rendered in template order and read
     `order` nowhere, so the arrows moved a row on a screen and nothing on the
     shop. docs/FO-HOMEPAGE-INVENTORY.md §3 has the reproduction.

     THE WHOLE FIX ON THIS SIDE IS THE EXPRESSION ON THE NEXT LINE. Every one of
     the seventeen sections already calls $sections->classFor(), which now adds
     the ordering class as well as the visibility and divider ones, so not one
     section had to be touched — the alternative costed in that document was a
     635-line rewrite of this file into partials, in a week when four other
     lanes were editing it.

     IT SHARES A LINE WITH THE DIRECTIVE BELOW, for the reason this file already
     records twice further down: orderStyle() returns '' while the order is the
     template's own, and an expression on a line of its own would leave that
     line's indent and newline in the output. StorefrontEnglishUnchangedTest
     compares this page against itself byte for byte and cannot tell whitespace
     that moved from a sentence that changed. Written this way a shop that has
     never opened the screen emits the identical bytes, which is the whole
     promise of the feature being gated on orderIsDefault().

     AND @endpush SHARES THE LINE TOO, which looks wrong and is not. Blade
     compiles a raw echo to `<?php echo ...; ?>` and DOUBLES the whitespace that
     followed it, because PHP swallows one newline after a closing tag; with the
     directive on the next line one of the two survives into <head>. That is a
     stray newline in the document for every shop on earth, and
     StorefrontEnglishUnchangedTest reported it, correctly, as a changed page —
     at byte 2276, a diff with no words in it, which is exactly the kind that
     file's header warns costs the next reader an hour to prove is nothing. With
     nothing after the echo there is no whitespace to double.

     Directive names inside this comment are safe, incidentally, and were
     checked rather than assumed: BladeCompiler strips comments before it
     tokenises, so the statements pass never sees them.

     THE SECTION-HEADING SIZES RIDE THE SAME LINE, FOR THE SAME REASON (Lane
     PF). HomeHeadings::style() is '' while Appearance → Homepage content →
     Section headings holds its defaults — kbb.css already says them — so a
     shop that never opens the tab emits not one byte more.

     THE GRID SHEET IS PRINTED INLINE ON THE REQUEST THAT OPENS THE SHOP (Lane
     CC): the same rules, at the same place, one render-blocking request fewer
     on a phone's first paint. A navigation from inside the shop still gets the
     <link>, byte for byte, so a cached copy is never downloaded twice.
     App\Support\LandingCss carries the measurement. --}}    {!! \App\Support\LandingCss::tags('resources/css/kbb/kbb-grid-skins.css') !!}{!! $sections->orderStyle() !!}{!! \App\Support\HomeHeadings::style($homeSettings) !!}{{-- Lane FS: Homepage content → a section → Fonts & size. '' until a value there moves; one <style> for every section, App\Support\SectionType has the rule-5 note. --}}{!! \App\Support\SectionType::style($sections->typeMap(), \App\Support\SiteFonts::families(), \App\Support\Locale::current() !== \App\Support\Locale::DEFAULT) !!}@endpush

@section('content')
<div class="kbb-home">
@unless ($sections->hidden('topstrip'))@include('partials.home.top-strip', ['cls' => $sections->classFor('topstrip'), 'ts' => \App\Support\HomeStrips::top($homeSettings)])@endunless

{{-- The home page rendered no <h1> at all. The first hero slide's headline is
     the right one to promote — it is the largest, first thing on the page and
     it says what the site sells — so that is what becomes the <h1> below.

     But the hero is a section the owner can switch off from the admin, and the
     headline lives inside a @foreach over the banners, so "promote the heading"
     alone would give the page one <h1>, several, or none depending on
     configuration. The flag settles it in one place: the slide loop emits the
     <h1> only on the first pass and only when it is going to run at all, and
     this fallback covers the case where it will not. Exactly one, always. --}}
{{-- Block form, not @php(...). This file already has a @php ... @endphp block
     down in the newsletter section, and Blade pairs those with one non-greedy
     regex over the whole template: an INLINE @php(...) above a block has no
     @endphp of its own, so it pairs with that block's, and everything in
     between — 300 lines, every @unless and @foreach on this page — is stored
     as one raw PHP block and never compiled. The page 500s on an "unexpected
     endif" three hundred lines below the actual mistake. --}}
@php
  /*
   * ── THE PICTURE BANNER IS READ FIRST, BECAUSE THE HERO YIELDS TO IT ──────
   *                                                              (Lane SEC)
   * The owner: "the banner i need to change to simple image banners, not
   * cards, simple only images banner". The hero below is not, and cannot be
   * made into, an image banner — App\Services\HomepageContent has no image
   * field of any kind; a hero slide is two `linear-gradient` strings, a
   * headline, a line of text and a button. So "change the banner to images" is
   * answered by drawing the picture banner WHERE THE HERO WAS and standing the
   * hero's rotation down while it does, rather than by growing a second
   * uploader on a second screen for the same job.
   *
   * ONE BANNER ON THE PAGE, NEVER TWO. That is the whole reason this read is
   * hoisted up here from the section further down: $heroCarriesH1 has to know
   * about it, and a page with a picture banner AND a gradient rotation above it
   * is two banners and one of them is the thing he asked to be rid of.
   *
   * IT COSTS NOTHING EXTRA. forHome() short-circuits on the module switch
   * before any settings or table read, it is ONE joined query when the module
   * is on, and it was already being called on this page — this moves the call,
   * it does not add one. CardsBannerQueryCostTest holds the figure.
   *
   * AND A SHOP WITH NO BANNER PICTURES IS BYTE-IDENTICAL. forHome() answers
   * null, so $heroCarriesH1 is exactly what it was, the hero draws exactly what
   * it drew, and the moved section emits nothing from its new place just as it
   * emitted nothing from its old one.
   */
  $bnSection = app(\App\Services\Banners::class)->forHome();

  /*
   * AND THE SECTION LIST IS TOLD, ONCE, WHEN THE BANNER IS ACTUALLY THERE.
   *
   * `cards_banner` is the first key in HomepageSections::REGISTRY now, and it
   * is the one section whose <section> lives inside its own @if — so on a shop
   * with no banner picture the document's first section is the hero while the
   * ORDER still says the banner. The divider mark is suppressed above "the
   * first section"; without this the hero counted as second and gained a tick
   * rule directly under the header on every phone homepage of every shop that
   * has not uploaded a picture.
   *
   * DECLARED WHEN PRESENT AND NOT WHEN ABSENT, which is the way round that
   * matters: HomepageSections::draws() says why in full, and the short version
   * is that a reader which has never heard of this call must get the page the
   * shop rendered yesterday.
   *
   * The verdict is handed over rather than looked up because forHome() is a
   * joined query and frameClass() runs once per section: the page has the
   * answer already, in the variable above.
   */
  if ($bnSection !== null) {
      $sections->draws('cards_banner');
  }

  $heroCarriesH1 = ! $sections->hidden('hero') && count($banners) > 0 && $bnSection === null;

  // THE SLIDER CARRIES THE HERO'S OWN Desktop/Mobile, AND THE BAND NO LONGER
  // DOES — Lane FW. See the note over the <section> below. Composed here and
  // not in the attribute because the class is EMPTY for a shop that has not
  // used those switches, and interpolating an empty class into the attribute
  // would leave a trailing space there on every page on earth —
  // StorefrontEnglishUnchangedTest compares these bytes and cannot tell a
  // space that appeared from a word that changed.
  $heroOwnVis = $sections->deviceClassFor('hero');
  $heroSliderClass = $heroOwnVis === '' ? 'slider' : 'slider ' . $heroOwnVis;
@endphp
{{--

     THE CARDS BANNER — Lane BN.

     The owner: "I need multiple cards type with auto scroll smooth scroll, each
     card will have image banner and downside 1-2 lines text with right side
     small beautiful button ... we can turn on off card banners, and inside each
     banner section we can create multiple cards and the whole section will have
     full control options to choose which banner will show on homepage".

     ── THE SHAPE IS THE VIDEO RAIL'S AND THE INSTAGRAM BLOCK'S, DELIBERATELY ─

     Both blocks below carry the full argument; every word of it applies here and
     is not repeated:

       · the <section> is INSIDE the @if, not around it, so a shop that has not
         switched this on — which is every shop the day the package applies —
         emits NOT ONE BYTE more than it does today. An empty wrapper with a
         classFor() on it is still a changed page: a new element, a new class
         attribute, and a divider rule above it from SectionDividers.
       · the @unless is kept as well, and it is not redundant: it is what makes
         the Desktop/Mobile switches on Appearance → Homepage → Cards banner
         work at all, and it short-circuits before any read on a shop that has
         switched the row off for both.
       · the directive is GLUED to the end of this comment. A Blade comment is
         replaced by the empty string and ITS TRAILING NEWLINE SURVIVES, where a
         line holding only a directive contributes nothing — the video block
         below cost two newlines on every homepage on earth written the readable
         way, and StorefrontEnglishUnchangedTest reported it at byte 51624.

     ── ONE CALL, AND IT IS THE GATE AS WELL AS THE READ ─────────────────────

     Banners::forHome() returns null for all three ways this section draws
     nothing — the module is off, no set is chosen, or the chosen set is
     missing, drafted or empty of drawable cards — so the template has one
     question to ask rather than four. It short-circuits on the module switch
     BEFORE any settings or table read, so a shop with this off pays exactly
     what it paid before the package applied; with it on it is ONE query, a
     join, for the set and its cards together. That class's header has the
     measurement and CardsBannerQueryCostTest holds it.

     Nothing from a setting is interpolated into this file: the partial escapes
     the four operator strings itself and scheme-checks the button's URL before
     it becomes an href.
     --}}@unless ($sections->hidden('cards_banner'))
@if ($bnSection !== null)
{{-- THE PARTIAL IS THE SET'S OWN, AND IT IS A LOOKUP IN A CONSTANT — Lane BN2.
     `banner_sets.kind` chose between two banner types and BannerSet::homePartial()
     maps its two tokens to two literal view names; a set whose kind is anything
     else, including null, returns the cards partial this line named before the
     column existed. The section, its class, its padding and its query are
     unchanged, which is why a shop with no slider renders the same bytes.

     ▲ THE TOP PADDING IS THE KIND'S NOW (Lane RC): "remove any space between
     header and banner". It was a literal `padding-top:8px`, measured as an
     8px strip of page background between the header's bottom edge and the
     picture at 390 and at 1280. BannerSet::SECTION_STYLES keeps 8px for the
     cards row and gives the two picture kinds 0. --}}
<section class="sec {{ $sections->classFor('cards_banner') }}" style="{{ $bnSection[0]->homeSectionStyle() }}"><div class="wrap">@include($bnSection[0]->homePartial(), ['set' => $bnSection[0], 'cards' => $bnSection[1]])</div></section>
@endif
@endunless
@php
/*
 * THE FLAG STRIP, UNDER THE BANNER. (Lane SEC)
 *
 * The owner's red arrow ran from this strip at the top of the page down to
 * here: "The top countries bar, i need under banner". layouts/store.blade.php
 * draws it above the header on every page; this page claims it with
 * @section('flagbar-placed') above and draws it itself, here.
 *
 * OUTSIDE BOTH GUARDS ABOVE, AND THAT IS THE DECISION. The banner section is
 * conditional twice over -- the owner can hide it on Appearance -> Homepage,
 * and forHome() answers null when no set is chosen or the module is off -- so
 * "under the banner" has to mean something on a homepage with no banner. It
 * means HERE: the top of the content, which is where the strip effectively
 * was. The alternative was to put it inside the @if, and then turning the
 * banner off would silently take the strip with it, which he did not ask for
 * and would read as a second bug.
 *
 * ▲ (Lane HC) Gated on the `countries` SECTION now, not on flagBarOn(): on
 * this page the strip is a homepage section, movable and switched per device
 * on its Homepage row (phones only by default, as the owner asked), with the
 * Flag bar's words, colours and sizes. Other pages still read flagBarOn().
 * Off on both devices, it adds no bytes here.
 */
$kfbRow = $sections->all()['countries'] ?? ['mobile' => false, 'desktop' => false];
$kfbHomeClass = trim(app(\App\Services\HeaderSettings::class)->flagBarClass((bool) $kfbRow['mobile'], (bool) $kfbRow['desktop']).' '.$sections->classFor('countries'));
@endphp
@unless ($sections->hidden('countries'))@include('partials.flag-bar', ['kfbClass' => $kfbHomeClass])@endunless
{{-- `?:` AND NOT get()'s SECOND ARGUMENT — Lane FW.

     `site_title` has a box now (Store → Business Details → Store identity;
     AdminController::SETTING_RULES carries the reasoning). A cleared box
     stores '' rather than deleting the row, and SettingsService::get() answers
     its default only when the ROW IS ABSENT — so the form this line used to
     take printed an EMPTY <h1> on the shop's own front page the first time
     anybody cleared the field, which is the one thing this element must never
     be. `?:` covers '' and null alike.

     Byte-neutral: with no row at all get() answers null either way and the
     literal is the same literal.

     THE DIRECTIVE SHARES THIS COMMENT'S LAST LINE, as three other blocks in
     this file do and for the same measured reason: a Blade comment compiles to
     nothing and leaves the newline after it, so a comment on its own lines adds
     a blank line to every rendered page. Caught here by diffing two fetches —
     89,288 bytes against 89,289, one `>` at line 322. --}}@unless ($heroCarriesH1)
  <h1 class="kbb-h1-quiet">{{ $settings->get('site_title') ?: 'K-Beauty Bliss — authentic Korean skincare in the UAE' }}</h1>
@endunless

{{-- HERO.

     THE BAND IS THREE SECTIONS, AND ITS VISIBILITY IS NOW THEIR UNION — Lane FW.

     This `<section>` is the hero, the delivery strip AND the promo ticker: the
     latter two are `<div>`s below, inside this element's `.wrap`, because the
     band is one visual unit. It used to carry `$sections->classFor('hero')`,
     which is the HERO's own d-off/m-off — and `.d-off{display:none !important}`
     takes the whole subtree with it. So switching the hero off for desktop
     switched the delivery strip and the ticker off for desktop too, with their
     own Desktop switches still on and nothing said; and hiding the hero on both
     devices dropped the `@unless` and took two switched-ON sections off the
     page. Measured at 1280px before the repair: the band computed `display:none`
     while `.delivery` inside it computed `flex`.

     A nested section's own `d-off` can only ever SUBTRACT from what its host
     shows, never add, so the wrapper has to be visible on a device when ANY of
     the three is on for it — bandClassFor() — and the hero's own switch then
     has to land on the hero's own content, which is the slider below. Neither
     half works without the other: the union alone would make the hero
     unhideable, the slider class alone would not bring the other two back.

     @unless follows the same rule: the band renders unless all three are off on
     both devices, which is what bandHidden() answers.

     BYTE-NEUTRAL for every shop with the three rows on — bandClassFor() returns
     exactly what classFor() returned, deviceClassFor() returns '', and the
     directives emit nothing. Proved by fetching and diffing, not by reasoning.

     $heroCarriesH1 is NOT changed, and the slider's condition is now that flag
     rather than a second copy of it: the flag decides whether the quiet <h1>
     above renders, so writing `count($banners) > 0` here again would let the
     two drift and give the page two <h1>s or none.

     AND THIS COMMENT OPENS WITH THE `HERO` MARKER RATHER THAN STANDING UNDER
     IT, which looks like a typo and is not. A Blade comment compiles to
     nothing and leaves the newline that followed it, so a SECOND comment block
     here adds a blank line to the rendered page of every shop on earth — one
     byte, no words, exactly the diff this file's other notes warn about.
     Measured: 89,288 bytes before, 89,289 after, one `>` at line 322. --}}
@unless ($sections->bandHidden('hero'))
<section class="sec {{ $sections->bandClassFor('hero') }}" style="padding-top:14px"><div class="wrap">
{{-- NO SLIDES, NO SLIDER — Lane FO.

     The band is still rendered when the list is empty, because the delivery
     strip and the promo ticker live inside it and are their own sections on
     Appearance → Homepage. The SLIDER is not: with nothing to rotate it drew
     an empty coloured box with a previous arrow, a next arrow and a row of
     dots, which is furniture rather than restraint — the rule the trust row's
     delivery card and the ticker's chips above already follow.

     It could not happen before this release: `home_banners` was read and
     written by nothing, so the list was always the three shipped slides.
     Deleting the last slide is a thing an owner can now do, and it has to
     leave a page rather than a frame.

     THE DIRECTIVE AND THE DIV SHARE A LINE, AND THE COMMENT ENDS ON IT, which
     is not how the rest of this file is laid out and is deliberate.
     StorefrontEnglishUnchangedTest compares this page against the same page at
     BASE_COMMIT byte for byte, and a wrapper written on its own lines moves the
     markup two spaces and a newline — a diff with no words in it, which that
     test cannot tell from a copy change and which would cost the next reader
     the time it takes to prove it is nothing. Written this way the rendered
     bytes are identical while there are slides, which is every shop that has
     not touched the new screen. --}}@if ($heroCarriesH1)  <div class="{{ $heroSliderClass }}" id="slider">
    <div class="slides" id="slides">
      @foreach ($banners as $b)
        <a class="sl" href="{{ Url::to($b['url']) }}" style="background:{{ $b['gradient'] }}">
          <div class="sl-t">
            <span class="k">{{ $b['kicker'] }}</span>
            @if ($loop->first)
{{-- ESCAPED, AND THAT IS THE CHANGE — Lane FO.

     This printed the headline with {!! !!} because the three
     shipped slides carry a <br>. That was safe for exactly as long
     as the value was a literal in HomeController, which is how it
     stayed for the life of this file: `home_banners` was read here
     and written by nothing in the tree, so nobody could put
     anything in it.

     Appearance → Homepage content is now a box an owner types into,
     which turns the same line into stored cross-site scripting on
     the front page of the shop. So the value is plain text with
     NEWLINES now and this escapes it and converts them.

     str_replace AND NOT nl2br(), which was the first version of this
     line. nl2br INSERTS a break and KEEPS the newline, so it renders
     "a<br>\nb" where the shipped literal was "a<br>b"; its default
     second argument emits the XHTML form on top of that. Neither
     reads any differently and both are a different page,
     byte for byte, from the one the server is serving today — which
     is the only definition StorefrontEnglishUnchangedTest has, and
     rightly, since it cannot tell a break that moved from a sentence
     that changed. This renders the three shipped headings as exactly
     the bytes they are now.

     The replacement is markup by construction and the value inside it
     has been through e(), so the {!! !!} that remains is over an
     escaped string and not over the setting. --}}              <h1>{!! str_replace("\n", '<br>', e($b['heading'])) !!}</h1>
            @else
              <h2>{!! str_replace("\n", '<br>', e($b['heading'])) !!}</h2>
            @endif
            <p>{{ $b['text'] }}</p>
{{-- The BUTTON is the one of the five that cannot simply be empty:
     .sl .b is a white pill with padding, so an empty one is a blank
     lozenge sitting on the banner rather than nothing at all. The
     eyebrow and the supporting line have no box of their own and
     collapse to nothing when they are empty, so they are left
     unconditional — which also keeps this page byte-identical to
     the one before it for every shop still on the shipped slides.

     Written as one echo rather than a wrapper, for the reason given
     at the top of this section: a directive on its own line here
     moves the markup and StorefrontEnglishUnchangedTest reports a
     diff with no words in it. --}}            {!! $b['button'] === '' ? '' : '<span class="b">' . e($b['button']) . '</span>' !!}
          </div>
          <div class="sl-i" style="background:{{ $b['panel'] }}"></div>
        </a>
      @endforeach
    </div>
    <button class="sarr prev" id="sprev" type="button" aria-label="{{ __('store.home.slider_previous') }}">‹</button>
    <button class="sarr next" id="snext" type="button" aria-label="{{ __('store.home.slider_next') }}">›</button>
    <div class="sdots" id="sdots"></div>
  </div>@endif

  {{-- RESOLVED ONCE FOR THE WHOLE HERO, because the band and the ticker below
       it make the SAME TWO CLAIMS and used to make them from four different
       sources. Hoisted above the band's own @unless so that hiding the band in
       Appearance → Sections cannot leave the ticker reading an undefined
       variable.

       Both are per-visitor and neither costs a query: DeliveryLine reads the
       settings snapshot the page has already taken, and the threshold is
       memoised on the Request by ShippingService::thresholdHere(), which the
       header has already asked for on this very page.

       BLOCK FORM, NOT @php(...) — for the reason this file already records
       thirty lines from the top: Blade pairs @php/@endphp with one non-greedy
       regex over the whole template, so an inline @php(...) sitting above the
       newsletter section's block pairs with THAT block's @endphp and swallows
       every @unless and @foreach in between. The page then 500s hundreds of
       lines below the actual mistake. Confirmed here the hard way: written
       inline, this exact line compiled to an unterminated `<?php (` and the
       home page died with "unexpected token class". --}}
  @php
    $homeDeliveryText = \App\Support\DeliveryLine::here();
    $homeFreeShip = app(\App\Services\ShippingService::class)->thresholdHere();
  @endphp

  @unless ($sections->hidden('delivery'))
  {{-- THE DELIVERY SENTENCE BELONGS TO THE VISITOR'S COUNTRY, NOT TO EVERYONE.

       This band used to print the stored default here unconditionally, to every
       visitor on earth, with no country check of any kind. That default is the
       owner's own wording about the United Arab Emirates, so a shopper in
       Riyadh was given a delivery promise about a country they are not in — on
       the first page of the shop, while the checkout had already been repaired
       to say nothing to them. One store, two answers, and the louder one wrong.

       App\Support\DeliveryLine is now the single reader of that rule and
       App\Support\ShopperCountry the single answer to where the shopper is, so
       this page and the checkout cannot disagree. Both are safe to call from
       here: neither issues a query of its own, and both answer for a request
       with no session and no geo signal at all.

       AN EMPTY ANSWER IS A REAL ANSWER and means say nothing. Nothing is
       invented to fill the gap — no delivery window has been measured for
       anywhere outside the UAE, and a plausible-looking guess printed here
       would be the same untruth in the other direction.

       THE THRESHOLD IS PER-COUNTRY TOO, which is where this band was left half
       repaired. The note that used to stand here said the free-delivery figure
       "keeps its place either way: it is true wherever the shopper is
       standing". That reasoning does not survive contact with this shop's own
       configuration. Production runs two zones with two different thresholds —
       199 for the UAE and 1,600 for the Gulf — and a shop on Extended Delivery
       carries a `free_from` PER COUNTRY. One figure cannot be true of both.

       And the figure printed here was not even the shop's. It came from the
       `free_shipping_threshold` SETTING, which has no admin screen and no
       writer anywhere in this application: the owner edits the real number on
       Store → Shipping, where it lives on the free-shipping method, and this
       band went on printing the stale default at every visitor including the
       UAE ones. A number nobody can edit, describing a country not everybody
       is in.

       Both halves now come from the one reader each — the sentence from
       DeliveryLine, the figure from ShippingService::thresholdHere() — and each
       is dropped when there is nothing true to say. When both are empty the
       band is not rendered at all: an icon with no words beside it reads as a
       broken page rather than as restraint.

       The separator belongs to the SECOND half and is only printed when there
       is a first half in front of it, so suppressing either one cannot leave a
       stray dot behind. --}}
  @if ($homeDeliveryText !== '' || $homeFreeShip !== null)
  <div class="delivery {{ $sections->classFor('delivery') }}">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 7h13v10H2z"/><path d="M15 10h4l3 3.5V17h-7z"/><circle cx="6" cy="19" r="1.6"/><circle cx="18" cy="19" r="1.6"/></svg>
    @if ($homeDeliveryText !== '')<b>{{ $homeDeliveryText }}</b>@endif
    @if ($homeFreeShip !== null)
      @if ($homeDeliveryText !== '')<span>·</span>@endif
      <span>{!! __('store.delivery.free_over', ['amount' => Money::format($homeFreeShip)]) !!}</span>
    @endif
  </div>
  @endif
  @endunless

  @unless ($sections->hidden('ticker'))
  {{-- THE TICKER MADE THE SAME TWO CLAIMS AS THE BAND ABOVE IT, AND MADE THEM UP.

       Two hard-coded spans, looped twice, shown to everyone. The delivery
       sentence named one country, the way the band above did before it was
       repaired — and the free-delivery figure was a literal typed into this
       template, so it did not read the shop's threshold at all. That second one
       is a plain bug with no country question attached: the owner raises the
       real threshold on Store → Shipping and this line goes on advertising the
       old one, to every visitor including the UAE ones it was written for.

       Both now come from the two values resolved at the top of this hero, which
       is where the band gets them, so the two strips of one page cannot
       disagree. Either is dropped when the shop has nothing true to say, which
       for the ticker costs nothing: the remaining chips simply scroll. --}}
  {{-- AND SO DID THE FIRST CHIP, WHICH OUTLIVED THE COMMENT ABOVE — Lane DL.

       Its default was an anniversary sale and a discount code, typed into this
       template and shown to everyone. UnbackedClaimsTest already pins that
       exact code, as the default of `checkout_coupon`, for being offered at the
       moment of payment by a shop that had not got it; this was the same
       literal one scroll higher, on the front page, where more people saw it.

       It was not a placeholder waiting for an owner either. NOTHING IN THIS
       APPLICATION WRITES `home_ticker`: it is in neither
       AdminController::SETTING_RULES nor EcommerceApiController's schema nor
       SettingsSeeder, and no ->set() names it. So the invented default was the
       shipped and only value, unchangeable and unremovable from any screen.

       Now it is a chip like the other two: shown when the owner has written
       one, dropped when he has not, with nothing invented in its place. --}}
  @php
    $tickerChips = [];

    /* ESCAPED, AND THE ONLY CHIP HERE THAT WAS NOT (Lane M3).

         The strip below prints with {!! !!}, because the free-delivery chip
         carries a <b> of its own that has to render, and the delivery chip
         beside it already calls e() for exactly that reason. This one did not:
         `home_ticker` is a SETTING, it went into the array raw, and a value of
         `<img src=x onerror=alert(1)>` was measured rendering unescaped on the
         storefront homepage. Rule 5 of the project notes states the rule this
         broke in one line -- anything printed unescaped is a constant, never a
         setting -- and the cast is no defence here: ModuleSchema's text arm
         trims and caps, it does not escape, so the value the Homepage content
         screen saves is the value that reached the page.

         e() and not strip_tags(): escaping is what makes a string safe to
         print, and it is applied where the printing happens rather than where
         the value is stored, so it holds for a row that never went through the
         screen. A chip of plain wording -- which is every chip any shop has,
         the shipped default being no chip at all -- is byte-identical. */
    if (($ownTicker = trim((string) $settings->get('home_ticker', ''))) !== '') {
        $tickerChips[] = '🎁 ' . e($ownTicker);
    }

    if ($homeFreeShip !== null) {
        $tickerChips[] = __('store.delivery.free_over', ['amount' => '<b>' . Money::format($homeFreeShip, 0) . '</b>']);
    }

    if ($homeDeliveryText !== '') {
        $tickerChips[] = e($homeDeliveryText);
    }
  @endphp
  {{-- No chips means no strip. An empty scrolling bar is not a smaller claim
       than a false one, it is just furniture — the same rule the trust row's
       delivery card follows when it has nothing to say. --}}
  @if ($tickerChips !== [])
  <div class="tick {{ $sections->classFor('ticker') }}"><div>
    @for ($i = 0; $i < 2; $i++)
      @foreach ($tickerChips as $chip)
        <span>{!! $chip !!}</span><span>·</span>
      @endforeach
    @endfor
  </div></div>
  @endif
  @endunless
</div></section>
@endunless

{{-- CATEGORIES --}}
@unless ($sections->hidden('categories'))
<section class="sec {{ $sections->classFor('categories') }}" style="padding-top:8px"><div class="wrap">
  <div class="cats">
    @foreach ($categories as $c)
      <a class="ct" href="{{ $c->url() }}">
        <div class="im" style="background:{{ Gradient::for($c->name) }}"></div>
        {{-- The tally only when there is one. A demo stand-in tile counts no
             real category and so carries 0; printing "0 products" under a tile
             that links to a full shop would be its own small untruth. --}}
        <b>{{ $c->t('name') }}</b>@if ((int) $c->products_count > 0)<span class="n">{{ trans_choice('store.home.category_product_count', (int) $c->products_count) }}</span>@endif
      </a>
    @endforeach
  </div>
</div></section>
@endunless

{{-- BUNDLES — a carousel with arrows, the heading centred and the All sets
     button redesigned (2.60.370): App\Support\HomeBundles holds the reasoning;
     Appearance → Homepage content → Big savings bundles holds the controls. --}}
@unless ($sections->hidden('bundles'))
@php
    $bndl = \App\Support\HomeBundles::config();
    $bndlUrl = str_starts_with($bndl['url'], '/') ? Url::to($bndl['url']) : $bndl['url'];
    $bndlLabel = $bndl['label'] !== '' ? $bndl['label'] : __('store.home.bundles_link');
    $bndlTitle = $bndl['title'] !== '' ? $bndl['title'] : __('store.home.bundles_heading');
    $bndlArrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
@endphp
<section class="sec {{ $bndl['classes'] }} {{ $sections->classFor('bundles') }}" style="{{ $bndl['style'] }}" data-ymal data-ymal-auto="{{ $bndl['auto'] }}" aria-labelledby="bndl-h"><div class="wrap">
  <div class="sh bndl-head"><div><h2 id="bndl-h">{{ $bndlTitle }}@if ($bndl['count']) <span class="cnt">{{ trans_choice('store.home.bundles_count', $rails['bundles']->count()) }}</span>@endif</h2>
    <p>{{ $bndl['sub'] !== '' ? $bndl['sub'] : __('store.home.bundles_subtitle') }}</p></div>
    <a class="bndl-all bndl-all-top" href="{{ $bndlUrl }}">{{ $bndlLabel }}<i>{!! $bndlArrow !!}</i></a></div>
  <div class="bndl-stage">
    <button type="button" class="bndl-arr bndl-prev" data-ymal-prev aria-controls="bndl-track" aria-label="{{ __('store.product.related_prev') }}" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 5.5 8 12l6.5 6.5"/></svg></button>
  @include('partials.home.grid', ['items' => $rails['bundles'], 'skin' => $sections->skinFor('bundles'), 'catLabel' => __('store.home.bundles_grid_label'), 'trackLabel' => $bndlTitle, 'aboveFold' => in_array('bundles', $homeFirst, true) ? $bndl['above'] : 0])
    <button type="button" class="bndl-arr bndl-next" data-ymal-next aria-controls="bndl-track" aria-label="{{ __('store.product.related_next') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9.5 5.5 6.5 6.5-6.5 6.5"/></svg></button>
  </div>
  <div class="bndl-foot"><a class="bndl-all bndl-all-bottom" href="{{ $bndlUrl }}">{{ $bndlLabel }}<i>{!! $bndlArrow !!}</i></a></div>
</div></section>
@endunless
{{-- BEST SELLERS, SECTION 2 OF ROW 55 (Lane HA). App\Support\HomeSections
     carries the reasoning; Appearance → Homepage content → Best Sellers the
     controls. A rail with no products draws nothing. --}}@unless ($sections->hidden('bestselling'))
@if ($home['bestselling']->isNotEmpty())
@include('partials.home.hs-rail', ['onTop' => in_array('bestselling', $homeFirst, true), 'key' => 'bestselling', 'cls' => $sections->classFor('bestselling'), 'r' => \App\Support\HomeSections::rail($homeSettings, 'bestselling'), 'items' => $home['bestselling']])
@endif
@endunless

{{-- RECOMMENDED --}}
@unless ($sections->hidden('recommended'))
<section class="sec {{ $sections->classFor('recommended') }}" style="padding-top:0"><div class="wrap">
  <div class="sh"><div><h2>{{ __('store.home.recommended_heading') }} <span class="cnt">{{ __('store.home.recommended_badge') }}</span></h2>
    <p>{{ __('store.home.recommended_subtitle') }}</p></div>
    <a class="lnk" href="{{ Url::to('/shop/') }}">{{ __('store.home.recommended_link') }}</a></div>
  @include('partials.home.grid', ['items' => $rails['recommended'], 'skin' => $sections->skinFor('recommended'), 'catLabel' => __('store.home.recommended_grid_label'), 'aboveFold' => in_array('recommended', $homeFirst, true) ? app(\App\Services\SiteLayout::class)->aboveFoldCards() : 0])
</div></section>
@endunless

{{-- ROUTINE --}}
@unless ($sections->hidden('routine'))
<section class="sec tinted {{ $sections->classFor('routine') }}" style="padding-top:0"><div class="wrap">
  <div class="sh"><div><h2>{{ __('store.home.routine_heading') }} <span class="cnt">{{ trans_choice('store.home.routine_steps', 6) }}</span></h2></div>
    <a class="lnk" href="{{ Url::to(\App\Support\UrlScheme::blogIndex()) }}">{{ __('store.home.routine_link') }}</a></div>
  <div class="rsteps">
    @foreach ($routine as $step)
      <a class="rstep" href="{{ Url::to($step['url']) }}">
        <span class="rn">{{ $step['n'] }}</span>
        <div class="rb"><b>{{ $step['title'] }}</b><span>{{ $step['note'] }}</span></div>
        @if ($step['pick'])
          <div class="rp"><i style="background:{{ Gradient::for($step['pick']->name) }}"></i>
            <span>{{ $step['pick']->brand?->t('name') }}<br><b>{!! Money::format($step['pick']->effectivePrice()) !!}</b></span></div>
        @endif
      </a>
    @endforeach
  </div>
  @if ($routineTotal > 0)
    <a class="rall" href="{{ Url::to('/shop/') }}">{!! __('store.home.routine_add_all', ['amount' => '<b>' . Money::format($routineTotal) . '</b>']) !!}</a>
  @endif
</div></section>
@endunless

{{-- SKIN QUIZ --}}
@unless ($sections->hidden('quiz'))
<section class="sec {{ $sections->classFor('quiz') }}" style="padding-top:0"><div class="wrap">
  <div class="quiz">
    <div class="q-left">
      <span class="q-k">{{ __('store.home.quiz_kicker') }}</span>
      <h2>{!! __('store.home.quiz_heading') !!}</h2>
      <p>{{ __('store.home.quiz_body') }}</p>
      <div class="q-why">
        <div><b>{{ number_format($catalogueCount) }}</b><span>{{ __('store.home.quiz_stat_matched') }}</span></div>
        <div><b>5</b><span>{{ __('store.home.quiz_stat_questions') }}</span></div>
        <div><b>0</b><span>{{ __('store.home.quiz_stat_cost') }}</span></div>
      </div>
    </div>
    <form class="q-card" method="get" action="{{ Url::to('/skin-quiz/') }}">
      <div class="q-top"><span class="q-step">{{ __('store.home.quiz_step', ['current' => 1, 'total' => 5]) }}</span><div class="q-bar"><i style="width:20%"></i></div></div>
      <h3 class="q-q">{{ __('store.home.quiz_question') }}</h3>
      <div class="q-opts">
        @foreach ([['oily', __('store.home.quiz_option_oily'), __('store.home.quiz_skin_oily')], ['dry', __('store.home.quiz_option_dry'), __('store.home.quiz_skin_dry')], ['combo', __('store.home.quiz_option_combo'), __('store.home.quiz_skin_combo')], ['sensitive', __('store.home.quiz_option_sensitive'), __('store.home.quiz_skin_sensitive')], ['normal', __('store.home.quiz_option_normal'), __('store.home.quiz_skin_normal')]] as [$v, $l, $t])
          <label class="q-o"><input type="radio" name="skin" value="{{ $v }}"><span class="d"></span><span class="l"><b>{{ $l }}</b><span>{{ $t }}</span></span></label>
        @endforeach
      </div>
      <div class="q-foot"><span class="q-hint">{{ __('store.home.quiz_hint') }}</span><button class="q-next" type="submit">{{ __('store.home.quiz_continue') }}</button></div>
    </form>
  </div>
</div></section>
@endunless

{{-- BRANDS, SECTION 3 OF ROW 55 (Lane HA) — design A on a laptop, logos on a
     phone, one list of links. The two gates are Lane EH's and unchanged: the
     homepage row (Appearance → Homepage) and the brands module (Store →
     Modules), which also decides whether /brands/ answers. --}}@unless ($sections->hidden('brands') || ! $settings->moduleEnabled('brands', true))
@if ($home['brands']->isNotEmpty())
@include('partials.home.hs-brands', ['cls' => $sections->classFor('brands'), 'b' => \App\Support\HomeSections::brands($homeSettings), 'brandRows' => $home['brands']])
@endif
@endunless

{{-- SPOTTED, SECTION 4 OF ROW 55. Lane HB builds partials/home/spotted (the
     Instagram carousel, design B); it is included HERE, at section 4, and it
     gates itself. Until it exists the old product-photo strip below keeps the
     slot, so the `spotted` row on Appearance → Homepage never points at
     nothing; once it exists the old strip stands down, so the page never
     draws two Spotted sections. (Lane HA) --}}@includeIf('partials.home.spotted')
@unless ($sections->hidden('spotted') || view()->exists('partials.home.spotted'))
<section class="sec {{ $sections->classFor('spotted') }}" style="padding-top:0"><div class="wrap">
  <div class="sh"><div><h2>{{ __('store.home.spotted_heading') }} <span class="cnt">{{ __('store.home.spotted_badge') }}</span></h2>
    <p>{{ __('store.home.spotted_subtitle') }}</p></div>
    <a class="lnk" href="{{ Url::to('/shop/') }}">{{ __('store.home.spotted_link') }}</a></div>
  <div class="ugc">
    @foreach ($rails['best1']->take(4) as $p)
      {{-- These tiles are product photographs, so they are the images on this
           page with the most to gain from being indexable. altFor() is the
           catalogue's own alt: the curated image_alts entry when the editor has
           written one, otherwise ProductTitle's derived "Brand Product" — which
           is why this does not simply repeat $p->name. --}}
      <a href="{{ $p->url() }}"><div class="im" style="background:{{ $p->image ? '#fff' : Gradient::for($p->name) }}">
        @if ($p->image)
          @php $ugcSrcset = \App\Support\ImageVariants::srcsetFor($p->image); @endphp
          {{-- The phone-sized copies, when the catalogue has been through
               Media Library -> Image Sizes. These four tiles were the only
               product photographs on the storefront still emitting no srcset:
               /shop has one through <x-product-card> and the product page
               through the gallery partial, and this strip was missed. A tile
               here is never wider than about 424 CSS pixels and was being
               handed the 1000x1000 original.

               '' when no variant is on disk, in which case no srcset and no
               sizes are emitted and the browser loads src exactly as before --
               ImageVariants::srcsetFor() is built from the filesystem for that
               reason, so a catalogue that has never run the batch renders the
               markup it renders today. --}}
          <img src="{{ $p->image }}" alt="{{ $p->altFor($p->image) }}" width="400" height="400" loading="lazy"
               @if ($ugcSrcset !== '') srcset="{{ $ugcSrcset }}" sizes="{{ \App\Support\ImageVariants::homeTileSizesAttribute() }}" @endif>
        @endif
        <span class="shop"><b>{{ $p->t('name') }}</b><span>{!! Money::format($p->effectivePrice()) !!}</span></span></div></a>
    @endforeach
  </div>
</div></section>
@endunless
{{--

     VIDEO RAIL — Lane IG.

     The owner: "Also make the Videos rail section on homepage and let us choose
     the section to show from the list or use shortcode."

     ── WHY THE <section> IS INSIDE THE @if AND NOT AROUND IT ─────────────────

     This is the whole of rule 1 for this feature, and getting it the other way
     round is the mistake that looks correct. A shop that has not configured this
     — which is every shop the day the package applies — must emit NOT ONE BYTE
     more than it does today, and StorefrontEnglishUnchangedTest compares this
     page against its pre-change self byte for byte. An empty <section> wrapper
     with a classFor() on it is still a changed page: a new element, a new class
     attribute and, because SectionDividers::classFor() runs for every key, a new
     divider rule above it.

     So the element is only reached when there is a rail to put in it. The
     @unless is kept as well, and it is not redundant: it is what makes the
     Desktop/Mobile switches on Appearance → Homepage → Video rail work, and it
     short-circuits before the settings read on a shop that has switched the row
     off for both.

     ── AND THE STRING IS BUILT IN PHP, NOT IN THE TEMPLATE ──────────────────

     UgcSettings::homeShortcode() returns '' unless the module is on AND a real
     handle is saved, and it is the thing that applies UgcSection::HANDLE_RE
     before the handle can become part of a shortcode's own syntax. Written here
     as [kbb_videos section="{{ $handle }}"] the quoting would be this template's
     problem, and `[^"]*` inside those quotes is exactly what HANDLE_RE exists to
     protect — see UgcSettings::homeSection().

     {!! !!} is correct and is the same call Shortcodes::render() already makes
     from a page body: what comes back is ugc/rail.blade.php's rendered output, in
     which every operator string went through {{ }} and every URL through UgcPath.
     Nothing from this template is interpolated into it.

     ── ▲ THE DIRECTIVE IS GLUED TO THE END OF THIS COMMENT, ON PURPOSE ──────

     It looks like a typo and it is the whole reason this block costs zero bytes.
     This file's own header records the mechanism twice: Blade compiles a
     directive to <?php ... ?> and PHP SWALLOWS ONE NEWLINE after a closing tag,
     so a line holding nothing but a directive contributes nothing at all — but a
     Blade COMMENT is replaced by the empty string and its trailing newline
     SURVIVES.

     Written the readable way, with this comment on its own line and a blank line
     above it, the block emitted exactly two newlines on every homepage on earth.
     StorefrontEnglishUnchangedTest reported it, correctly, as a changed page at
     byte 51624 — a diff with no words in it, which that file's header warns costs
     the next reader an hour to prove is nothing. With the @unless closing this
     line there is no newline left to survive.
     --}}@unless ($sections->hidden('videos'))
@php $videoRail = \App\Support\Shortcodes::render(app(\App\Services\UgcSettings::class)->homeShortcode()); @endphp
@if ($videoRail !== '')
<section class="sec {{ $sections->classFor('videos') }}"><div class="wrap">{!! $videoRail !!}</div></section>
@endif
@endunless
{{--

     INSTAGRAM PROFILE — Lane IG.

     The owner: "i want another function called Instagram Profile ... i want a nice
     grid type instagram section where all our recent posts/videos display".

     The `instagram` row went into HomepageSections::REGISTRY in the same change
     that added `videos`, AND THE PREVIOUS RUN OF THIS LANE NEVER DREW IT. That is
     a switch on Appearance → Homepage that moves a row on a screen and nothing on
     the shop — precisely the fault docs/FO-HOMEPAGE-INVENTORY.md was written to
     catalogue and HomepageSections::NESTED carries a paragraph about. Pinned now by
     HomepageInstagramSectionTest, which pairs every REGISTRY key against this file.

     ── THE SHAPE IS THE VIDEO RAIL'S, AND FOR THE SAME THREE REASONS ─────────

     Read the block above for the full argument; it applies here unchanged.

       · the <section> is INSIDE the @if, not around it, so a shop that has not
         connected an account emits not one byte more than it does today — an empty
         wrapper with a classFor() on it is still a changed page, and
         StorefrontEnglishUnchangedTest compares this file's output byte for byte.
       · the @unless is kept as well, because it is what makes the Desktop/Mobile
         switches on Appearance → Homepage → Instagram Profile work at all, and it
         short-circuits before any read on a shop that has switched the row off.
       · the directive is GLUED to the end of this comment. A Blade comment is
         replaced by the empty string and ITS TRAILING NEWLINE SURVIVES, where a
         line holding only a directive contributes nothing — the video block above
         cost two newlines on every homepage on earth written the readable way, and
         StorefrontEnglishUnchangedTest reported it at byte 51624.

     ── AND THE SHORTCODE IS THE GATE, NOT A SETTING ─────────────────────────

     Unlike the rail there is nothing to pick: there is one Instagram account, so
     `[kbb_instagram]` is the whole of it and no handle has to be validated into
     somebody else's syntax. Shortcodes::instagram() returns '' when the module is
     off, when nothing has been fetched, and when every fetched post's thumbnail
     failed to download — so this renders nothing until the owner has connected the
     account AND a fetch has stored a drawable post. The layout, the profile box and
     the counts all come from the saved settings, which is what makes the homepage
     row and the shortcode the same section rather than two.

     It is a CONSTANT STRING, so nothing from a setting reaches a shortcode's syntax
     here and there is no handle to escape — the one respect in which this block is
     simpler than the rail's, and the reason it needs no UgcSettings::homeShortcode()
     equivalent.
     --}}@unless ($sections->hidden('instagram'))
@php $igSection = \App\Support\Shortcodes::render('[kbb_instagram]'); @endphp
@if ($igSection !== '')
<section class="sec {{ $sections->classFor('instagram') }}"><div class="wrap">{!! $igSection !!}</div></section>
@endif
@endunless
{{-- TRENDING, SECTION 5 OF ROW 55 (Lane HA). What "trending" counts is
     GridSections::trendingScores(); the controls are Appearance → Homepage
     content → Trending. --}}@unless ($sections->hidden('trending'))
@if ($home['trending']->isNotEmpty())
@include('partials.home.hs-rail', ['onTop' => in_array('trending', $homeFirst, true), 'key' => 'trending', 'cls' => $sections->classFor('trending'), 'r' => \App\Support\HomeSections::rail($homeSettings, 'trending'), 'items' => $home['trending']])
@endif
@endunless

{{-- BEST SELLERS --}}
@unless ($sections->hidden('bestsellers'))
<section class="sec {{ $sections->classFor('bestsellers') }}" style="padding-top:0"><div class="wrap">
  <div class="sh"><div><h2>{{ __('store.home.bestsellers_heading') }} <span class="cnt">{{ __('store.home.bestsellers_badge') }}</span></h2>
    <p>{{ __('store.home.bestsellers_subtitle') }}</p></div>
    <a class="lnk" href="{{ Url::to('/shop/?orderby=popularity') }}">{{ __('store.home.bestsellers_link') }}</a></div>
  @include('partials.home.grid', ['items' => $rails['best1'], 'skin' => $sections->skinFor('bestsellers'), 'catLabel' => __('store.home.bestsellers_grid_label'), 'rank' => true, 'aboveFold' => in_array('bestsellers', $homeFirst, true) ? app(\App\Services\SiteLayout::class)->aboveFoldCards() : 0])
</div></section>
@endunless

{{-- FLASH --}}
@unless ($sections->hidden('flash'))
<section class="sec {{ $sections->classFor('flash') }}" style="padding-top:0"><div class="wrap">
  <div class="sh"><div><h2>{{ __('store.home.flash_heading') }} <span class="cnt">{{ __('store.home.flash_badge') }}</span></h2>
    <p>{{ __('store.home.flash_subtitle') }}</p></div>
    <a class="lnk" href="{{ Url::to('/shop/?on_sale=1') }}">{{ __('store.home.flash_link') }}</a></div>
  @include('partials.home.grid', ['items' => $rails['flash'], 'skin' => $sections->skinFor('flash'), 'catLabel' => __('store.home.flash_grid_label'), 'aboveFold' => in_array('flash', $homeFirst, true) ? app(\App\Services\SiteLayout::class)->aboveFoldCards() : 0])
</div></section>
@endunless

{{-- BLOG, SECTION 6 OF ROW 55 (Lane HA): three equal cards on a laptop, a
     column on a phone. Appearance → Homepage content → Blog. --}}@unless ($sections->hidden('blog'))
@php
    $hsBlog = \App\Support\HomeSections::blog($homeSettings);
    // "The latest three" is the journal row this page has always read
    // ($posts: cached, translated, demo-filled); a manual pick is HomeSections'.
    $hsPosts = $hsBlog['source'] === 'manual' && $home['posts']->isNotEmpty() ? $home['posts'] : $posts;
@endphp
@if ($hsPosts->isNotEmpty())
@include('partials.home.hs-blog', ['cls' => $sections->classFor('blog'), 'bl' => $hsBlog, 'postRows' => $hsPosts])
@endif
@endunless
{{-- UNDER AED 54, SECTION 7 OF ROW 55 (Lane HA). The ceiling is the price
     the shopper pays — GridSections::fetchPool() says how. --}}@unless ($sections->hidden('under54'))
@if ($home['under54']->isNotEmpty())
@include('partials.home.hs-rail', ['onTop' => in_array('under54', $homeFirst, true), 'key' => 'under54', 'cls' => $sections->classFor('under54'), 'r' => \App\Support\HomeSections::rail($homeSettings, 'under54'), 'items' => $home['under54']])
@endif
@endunless
{{-- THE TWO-COLUMN FEATURE, SECTION 8 OF ROW 55 (Lane HA). --}}@unless ($sections->hidden('feature'))
@include('partials.home.hs-feature', ['cls' => $sections->classFor('feature'), 'ft' => \App\Support\HomeSections::feature($homeSettings, $home['sunscreens'])])
@endunless
{{-- ABOUT US, SECTION 9 OF ROW 55 — LAST ON THE PAGE (Lane HA). The words
     are the owner's four paragraphs (HomeSections::ABOUT_DEFAULT), edited on
     Appearance → Homepage content → About us; cleared, the heading stands
     alone. The old band's counted figures and link are not part of his
     section and are gone with it. --}}@unless ($sections->hidden('about'))
@include('partials.home.hs-about', ['cls' => $sections->classFor('about'), 'ab' => \App\Support\HomeSections::about($homeSettings, $aboutText)])
@endunless

{{-- REVIEWS --}}
@unless ($sections->hidden('reviews'))
@if ($reviews['total'] > 0)
<section class="sec {{ $sections->classFor('reviews') }}" style="padding-top:0"><div class="wrap">
  <div class="sh"><div><h2>{{ __('store.home.reviews_heading') }} <span class="cnt">{{ __('store.home.reviews_average', ['rating' => number_format($reviews['average'], 1)]) }}</span></h2>
    <p>{{ trans_choice('store.home.reviews_subtitle', (int) $reviews['total'], ['formatted' => number_format($reviews['total'])]) }}</p></div>
    <a class="lnk" href="{{ Url::to('/reviews/') }}">{{ __('store.home.reviews_link') }}</a></div>

  <div class="rev-top">
    <div class="rev-score"><div class="big">{{ number_format($reviews['average'], 1) }}</div>
      <div class="stars">@for ($i = 1; $i <= 5; $i++)<span class="{{ $i <= round($reviews['average']) ? 'f' : '' }}">★</span>@endfor</div>
      <small>{{ trans_choice('store.home.reviews_count', (int) $reviews['total'], ['formatted' => number_format($reviews['total'])]) }}</small></div>
    <div class="rbars">
      @foreach ($reviews['bars'] as $star => $pct)
        <div class="rbar"><span class="t">{{ $star }}★</span><span class="track"><i style="width:{{ $pct }}%"></i></span><span class="n">{{ $pct }}%</span></div>
      @endforeach
    </div>
  </div>

  <div class="rfilters">
    @foreach ([['all', __('store.reviews.filter_all')], ['photos', __('store.reviews.filter_photos')], ['5', '5★'], ['4', '4★'], ['helpful', __('store.reviews.filter_helpful')]] as [$k, $l])
      <a class="rfilter{{ $k === 'all' ? ' on' : '' }}" href="{{ Url::to('/reviews/?rfilter=' . $k) }}">{{ $l }}</a>
    @endforeach
  </div>

  <div class="rgrid">
    @foreach ($reviews['items'] as $r)
      <div class="rcard">
        <div class="rh"><span class="av" style="background:{{ Gradient::for($r->author_name ?: '?') }}">{{ mb_substr($r->author_name ?: '?', 0, 1) }}</span>
          <div class="rwho"><div class="rline"><span class="nm">{{ $r->author_name }}</span>@if ($r->verified)<span class="vf">{{ __('store.reviews.verified_badge') }}</span>@endif</div>
            <span class="rstars">@for ($i = 1; $i <= 5; $i++)<span class="{{ $i <= (int) $r->rating ? 'f' : '' }}">★</span>@endfor</span></div></div>
        @if ($r->product)<div class="rprod">{{ $r->product->t('name') }}</div>@endif
        <div class="rtext">{{ \Illuminate\Support\Str::limit(strip_tags((string) $r->content), 150) }}</div>
        <div class="rf"><span>{{ $r->created_at?->diffForHumans() }}</span>@if ($r->helpful)<span class="cnt3">👍 {{ (int) $r->helpful }}</span>@endif</div>
      </div>
    @endforeach
  </div>
</div></section>
@endif
@endunless

{{-- TRUST --}}
@unless ($sections->hidden('trust'))
{{-- THE DELIVERY CARD SAID TWO THINGS AND RECORDED NEITHER.

     Its title named one country's shipping and its line under it carried both a
     transit time and a free-delivery figure, all four words of it typed into
     this array. The figure was the same stale literal the ticker carried, so
     the card advertised a threshold the owner cannot edit here, beside a
     delivery speed true of one destination, to every visitor of the shop.

     Rebuilt from the two things this application actually records: the
     owner's delivery sentence for wherever the shopper is (Store → Delivery &
     Shipping → Delivery lines) and the free-delivery threshold of their own
     country (Store → Shipping, or Extended Delivery's per-country `free_from`).
     Whichever of the two exists is shown; when neither does, the CARD ITSELF IS
     NOT RENDERED and the row closes up to three. A trust card is a promise, and
     a promise with nothing behind it is the one thing this row must not carry.

     The title is "Delivery" rather than "Fast delivery": "fast" is an
     unmeasured claim about a destination whose transit time this shop has not
     recorded, and the line underneath already says whatever IS known.

     Recomputed here rather than read from the hero's variables: Appearance →
     Sections can hide the hero, and a card that 500s when the owner turns off
     an unrelated strip is a worse defect than the one this fixes. It costs
     nothing — both values are memoised for the life of the request. --}}
@php
    $trustFreeShip = app(\App\Services\ShippingService::class)->thresholdHere();
    $trustDelivery = trim(implode(' · ', array_filter([
        \App\Support\DeliveryLine::here(),
        $trustFreeShip === null ? null : __('store.home.trust_free_over', ['amount' => Money::plain($trustFreeShip, 0)]),
    ])));

    $trustCards = [];

    if ($trustDelivery !== '') {
        $trustCards[] = [__('store.home.trust_delivery_title'), $trustDelivery, '<path d="M2 7h13v10H2z"/><path d="M15 10h4l3 3.5V17h-7z"/><circle cx="6" cy="19" r="1.6"/><circle cx="18" cy="19" r="1.6"/>'];
    }

    $trustCards[] = [__('store.home.trust_payments_title'), __('store.home.trust_payments_text'), '<rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>'];

    /* THE SOURCING AND SUPPORT CARDS ARE THE OWNER'S WORDS NOW — Lane DR.

       "100% original", "Direct from brands and trusted suppliers" and
       "24/7 support" were literals in this file: three statements about how the
       business buys and how many hours a day it answers, made to every visitor,
       on a host where changing a template needs a signed package. Nobody at the
       shop had ever approved them and nobody at the shop could take them down.

       They are settings now, with exactly this wording as the default, so
       nothing changes for a shop that leaves them alone. Clearing one in the
       admin DROPS THE WHOLE CARD — the same rule the delivery card above
       already follows, and the reason both are built into $trustCards rather
       than written into the markup: the row closes up instead of showing an
       icon with nothing beside it.

       App\Support\TrustClaims holds the keys and the defaults, and is the one
       place that decides an empty box means "do not say this". */
    $trustAuthTitle = \App\Support\TrustClaims::text($settings, 'trust_authentic_title');
    $trustAuthText  = \App\Support\TrustClaims::text($settings, 'trust_authentic_text');

    if ($trustAuthTitle !== null) {
        $trustCards[] = [$trustAuthTitle, $trustAuthText ?? '', '<path d="M12 2 4 5v6c0 5 3.5 8 8 11 4.5-3 8-6 8-11V5z"/><path d="m9 12 2 2 4-4"/>'];
    }

    /* A FOURTH COPY OF THE SHOP'S PHONE NUMBER — Lane DI. This card sits on the
       home page of every shop and carried the number as a literal, so an owner
       who changed it in the header still advertised the old one here. Same
       source as the header chip and the footer: App\Support\SupportContact.
       The TITLE is the claim — "24/7" is a promise about opening hours — so it
       goes through TrustClaims; the number underneath stays measured. */
    $trustSupportTitle = \App\Support\TrustClaims::text($settings, 'trust_support_title');

    if ($trustSupportTitle !== null) {
        $trustCards[] = [$trustSupportTitle, __('store.home.trust_support_text', ['phone' => \App\Support\SupportContact::phone()]), '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'];
    }
@endphp
<section class="sec {{ $sections->classFor('trust') }}" style="padding-top:0"><div class="wrap">
  <div class="trust"><div class="g">
    @foreach ($trustCards as [$t, $d, $icon])
      <div class="i"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">{!! $icon !!}</svg></span>
        <div><b>{{ $t }}</b><span>{{ $d }}</span></div></div>
    @endforeach
  </div></div>
</div></section>
@endunless

{{-- NEWSLETTER --}}
@unless ($sections->hidden('newsletter'))
@php
    // Appearance → Homepage still decides whether this shows; Growth & Marketing
    // → Newsletter decides what it says. Deliberately two screens, one switch.
    $nl = app(\App\Services\NewsletterSettings::class);
@endphp
<section class="sec {{ $sections->classFor('newsletter') }}" style="padding-top:0"><div class="wrap">
  <div class="nl" style="{{ $nl->cssVariables() }}">
    <div class="kick">{{ $nl->get('nl_eyebrow') }}</div>
    <h2>{{ $nl->get('nl_heading') }}</h2>
    <p class="lede" style="margin:0 auto">{{ $nl->get('nl_subheading') }}</p>
    <form class="f" method="post" action="{{ Url::to('/api/subscribe') }}" data-kbb-subscribe>@csrf
      <input type="email" name="email" placeholder="{{ $nl->get('nl_placeholder') }}" required>
      <button class="btn btn-p" type="submit">{{ $nl->get('nl_button') }}</button>
    </form>
    @if (session('kbb_subscribed'))
      <p class="nl-note" role="status">{{ session('kbb_subscribed') }}</p>
    @elseif (session('kbb_subscribe_error'))
      <p class="nl-note nl-note--bad" role="alert">{{ session('kbb_subscribe_error') }}</p>
    @endif
  </div>
</div></section>
@endunless
{{--

     THE OWNER'S REUSABLE PRODUCT GRID, EVERY INSTANCE HE HAS BUILT — Lane GS.

     "DO ONE thing. prepare a proper grid section with all controls and it can
      be use anywhere, and can be edit that specific grid section. so this case
      we can re-use this grid section anywhere multiple times with different
      products etc selection."

     ONE LOOP, NOT A SECTION. There is no fixed number of these, so there is no
     block to write per instance: `forHome()` answers a list and each row is one
     `<section>` drawn by the same partial. Adding another is a row in
     `grid_sections`, never a change to this file.

     IT SITS AT THE END, AND THAT IS WHAT MAKES THE CONSOLE HONEST.
     `HomepageSections::registry()` appends the instances after the seventeen,
     and this list IS the default order — a key whose position there disagreed
     with the position here would hand Appearance → Homepage a picture the shop
     does not draw, which is the fault `settle()` exists to stop one level
     along. The owner moves an instance up the page with the ↑ on that screen;
     the ordering rules `orderStyle()` emits reach these sections exactly as
     they reach the other fifteen, because each one is a direct child of
     `.kbb-home`.

     IT DRAWS NOTHING ON A SHOP THAT HAS BUILT NONE. `forHome()` returns `[]`
     before it reads anything at all when the table is empty, so the @if is
     false, no `<section>` is emitted, no style is pushed, and
     `HomepageSections::registry()` is the const byte for byte — which is rule
     1 on the most visible page in the shop. GridSectionShipsOffTest renders
     this page with instances in the table and asserts the bytes; the two rows
     the owner named are presets on his own screen, not defaults this ships.

     THE STYLE GOES THROUGH @push, WHICH REACHES THE HEAD BECAUSE BLADE
     EVALUATES THIS SECTION BEFORE THE LAYOUT. It is inline for the reason
     `orderStyle()` above already argues at length: the storefront serves BUILT
     css from a web root that is a different directory, `npx vite build` is a
     manual step nobody runs during an update, and a rule added to kbb.css
     therefore ships inert. Written on ONE line with nothing after the echo,
     because Blade doubles the whitespace following a raw echo and
     StorefrontEnglishUnchangedTest cannot tell a newline that moved from a
     sentence that changed — the identical trap this file records at its @vite
     line and in four other places.

     AND THE DEVICE SWITCHES ARE APPLIED HERE, ON THE LIST, rather than inside
     the partial. An instance switched off for BOTH devices is not rendered at
     all — the rule every one of the seventeen shipped sections follows — and
     doing it on the list is what keeps the @push below honest: gated inside
     the partial, a shop whose only instance was switched off would still put
     this feature's whole stylesheet in the head of its front page.

     --}}@php $gsRows = array_values(array_filter(app(\App\Services\GridSections::class)->forHome(), fn ($r) => ! $sections->hidden($r['section']->sectionKey()))); @endphp
@if ($gsRows !== [])
@push('styles'){!! \App\Services\GridSections::css() !!}@endpush
@foreach ($gsRows as $gsRow)
@include('partials.home.grid-section', ['section' => $gsRow['section'], 'items' => $gsRow['items'], 'sections' => $sections])
@endforeach
@endif

</div>
@endsection
