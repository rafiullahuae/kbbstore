@extends('layouts.store')
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
     tokenises, so the statements pass never sees them. --}}    @vite('resources/css/kbb/kbb-grid-skins.css'){!! $sections->orderStyle() !!}@endpush

@section('content')
<div class="kbb-home">

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
  $heroCarriesH1 = ! $sections->hidden('hero') && count($banners) > 0;

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

    if (($ownTicker = trim((string) $settings->get('home_ticker', ''))) !== '') {
        $tickerChips[] = '🎁 ' . $ownTicker;
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

{{-- BUNDLES --}}
@unless ($sections->hidden('bundles'))
<section class="sec {{ $sections->classFor('bundles') }}" style="padding-top:8px"><div class="wrap">
  <div class="sh"><div><h2>{{ __('store.home.bundles_heading') }} <span class="cnt">{{ trans_choice('store.home.bundles_count', $rails['bundles']->count()) }}</span></h2>
    <p>{{ __('store.home.bundles_subtitle') }}</p></div>
    <a class="lnk" href="{{ Url::to('/shop/?cat=skincare-sets') }}">{{ __('store.home.bundles_link') }}</a></div>
  @include('partials.home.grid', ['items' => $rails['bundles'], 'skin' => $sections->skinFor('bundles'), 'catLabel' => __('store.home.bundles_grid_label')])
</div></section>
@endunless

{{-- RECOMMENDED --}}
@unless ($sections->hidden('recommended'))
<section class="sec {{ $sections->classFor('recommended') }}" style="padding-top:0"><div class="wrap">
  <div class="sh"><div><h2>{{ __('store.home.recommended_heading') }} <span class="cnt">{{ __('store.home.recommended_badge') }}</span></h2>
    <p>{{ __('store.home.recommended_subtitle') }}</p></div>
    <a class="lnk" href="{{ Url::to('/shop/') }}">{{ __('store.home.recommended_link') }}</a></div>
  @include('partials.home.grid', ['items' => $rails['recommended'], 'skin' => $sections->skinFor('recommended'), 'catLabel' => __('store.home.recommended_grid_label')])
</div></section>
@endunless

{{-- ROUTINE --}}
@unless ($sections->hidden('routine'))
<section class="sec tinted {{ $sections->classFor('routine') }}" style="padding-top:0"><div class="wrap">
  <div class="sh"><div><h2>{{ __('store.home.routine_heading') }} <span class="cnt">{{ trans_choice('store.home.routine_steps', 6) }}</span></h2></div>
    <a class="lnk" href="{{ Url::to('/skincare-guide/') }}">{{ __('store.home.routine_link') }}</a></div>
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

{{-- BRANDS --}}
{{-- TWO GATES, AND THEY ASK DIFFERENT QUESTIONS — Lane EH.

     `$sections->hidden('brands')` is the HomepageSections entry: "do I want
     this strip on my home page", edited on Appearance → Homepage, and it has
     governed this section all along.

     `moduleEnabled('brands')` is the module on Store → Modules: "does this shop
     have brands at all", which also decides whether the directory at
     /korean-skincare-brands/ and the per-brand landing pages answer. A module
     switched off has to leave NO trace on the storefront, and a brand strip
     still sitting on the home page — every tile linking to a page that now
     404s — is the loudest trace there is.

     Not folded into one: the homepage section must stay independently
     removable, or turning the strip off would be the only way to keep the
     brand pages and would take them with it. --}}
@unless ($sections->hidden('brands') || ! $settings->moduleEnabled('brands', true))
<section class="sec tinted {{ $sections->classFor('brands') }}" style="padding-top:0"><div class="wrap">
  {{-- The COUNT is counted and the CLAIM is the owner's — Lane DR.

       "{n} brands" is read off the catalogue and stays that way; "Korean
       brands, all sourced direct" is a statement about how the shop buys, it
       was a literal in this file, and it is a setting now with this wording as
       its default. Cleared, the line disappears and the heading stands alone. --}}
  {{-- BLOCK FORM, NOT @php(...) — this file's own headers, thirty and
       seventy lines from the top, record why: Blade pairs @php/@endphp
       with one non-greedy regex over the whole template, so an inline
       @php(...) above the newsletter section's block borrows THAT
       block's @endphp and swallows every directive in between. Written
       inline, this exact line killed the home page with "unexpected
       token class" two hundred lines below itself. --}}
  @php
    $brandsNote = \App\Support\TrustClaims::text($settings, 'home_brands_note');
  @endphp
  <div class="sh"><div><h2>{{ __('store.home.brands_heading') }} <span class="cnt">{{ trans_choice('store.home.brands_count', (int) $brandTotal) }}</span></h2>
    @if ($brandsNote !== null)<p>{{ $brandsNote }}</p>@endif</div>
    <a class="lnk" href="{{ Url::to('/brands/') }}">{{ __('store.home.brands_link') }}</a></div>
  <div class="brands">
    @foreach ($brands as $b)
      {{-- As with the category tiles above: no tally for a stand-in brand. --}}
      <a class="bd" href="{{ $b->url() }}"><div class="bname"><span class="bn">{{ $b->t('name') }}</span>@if ((int) $b->products_count > 0)<span class="bc">{{ $b->products_count }}</span>@endif</div></a>
    @endforeach
  </div>
</div></section>
@endunless

{{-- SPOTTED --}}
@unless ($sections->hidden('spotted'))
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

{{-- BEST SELLERS --}}
@unless ($sections->hidden('bestsellers'))
<section class="sec {{ $sections->classFor('bestsellers') }}" style="padding-top:0"><div class="wrap">
  <div class="sh"><div><h2>{{ __('store.home.bestsellers_heading') }} <span class="cnt">{{ __('store.home.bestsellers_badge') }}</span></h2>
    <p>{{ __('store.home.bestsellers_subtitle') }}</p></div>
    <a class="lnk" href="{{ Url::to('/shop/?orderby=popularity') }}">{{ __('store.home.bestsellers_link') }}</a></div>
  @include('partials.home.grid', ['items' => $rails['best1'], 'skin' => $sections->skinFor('bestsellers'), 'catLabel' => __('store.home.bestsellers_grid_label'), 'rank' => true])
</div></section>
@endunless

{{-- FLASH --}}
@unless ($sections->hidden('flash'))
<section class="sec {{ $sections->classFor('flash') }}" style="padding-top:0"><div class="wrap">
  <div class="sh"><div><h2>{{ __('store.home.flash_heading') }} <span class="cnt">{{ __('store.home.flash_badge') }}</span></h2>
    <p>{{ __('store.home.flash_subtitle') }}</p></div>
    <a class="lnk" href="{{ Url::to('/shop/?on_sale=1') }}">{{ __('store.home.flash_link') }}</a></div>
  @include('partials.home.grid', ['items' => $rails['flash'], 'skin' => $sections->skinFor('flash'), 'catLabel' => __('store.home.flash_grid_label')])
</div></section>
@endunless

{{-- BLOG --}}
@unless ($sections->hidden('blog'))
@if ($posts->isNotEmpty())
<section class="sec tinted {{ $sections->classFor('blog') }}" style="padding-top:0"><div class="wrap">
  <div class="sh"><div><h2>{{ __('store.home.journal_heading') }} <span class="cnt">{{ __('store.home.journal_badge') }}</span></h2><p>{{ __('store.home.journal_subtitle') }}</p></div>
    <a class="lnk" href="{{ Url::to('/skincare-guide/') }}">{{ __('store.home.journal_link') }}</a></div>
  <div class="blog">
    @foreach ($posts as $post)
      <a class="bl" href="{{ Url::to('/' . $post->slug . '/') }}">
        {{-- `cover`, not `image`. There is no posts.image column — see the
             Post::saved hook in AppServiceProvider, which says so for the same
             reason — and Eloquent returns null for a missing attribute instead
             of failing, so this rail has been drawing the gradient placeholder
             for every article no matter what photograph the post carried.
             Converting the background to an <img> without correcting the column
             would have shipped an <img> that is never emitted. --}}
        @php
          $postCover = \App\Support\CoverImage::src($post->cover);
        @endphp
        <div class="im" style="background:{{ $postCover ? '#fff' : Gradient::for($post->title) }}">
          @if ($postCover)
            <img src="{{ $postCover }}" alt="{{ $post->t('title') }}" width="640" height="400" loading="lazy">
          @endif
          {{-- `tag`, `body` and readMinutes(): the same correction as `cover`
               above, for the three fields that were missed when it was made.
               There is no posts.category, no posts.content and no
               posts.read_minutes, so the chip never drew, an article with no
               excerpt printed an empty paragraph, and `?? 5` was not a
               fallback but the only branch -- every article on this page
               claimed five minutes whatever its length. --}}
          @if ($post->tag)<span class="chip">{{ $post->tag }}</span>@endif</div>
        <h3>{{ $post->t('title') }}</h3>
        <p>{{ \Illuminate\Support\Str::limit(strip_tags((string) ($post->t('excerpt') ?: $post->t('body'))), 110) }}</p>
        <span class="meta">{{ trans_choice('store.home.read_minutes', $post->readMinutes()) }} · {{ $post->published_at?->format('j M') }}</span>
      </a>
    @endforeach
  </div>
</div></section>
@endif
@endunless

{{-- ABOUT --}}
@unless ($sections->hidden('about'))
<section class="sec {{ $sections->classFor('about') }}" style="padding-top:0"><div class="wrap">
  <div class="about">
    <div class="im"></div>
    <div>
      <h2>{{ __('store.home.about_heading') }}</h2>
      {{-- CLEARED MEANS SAY NOTHING — Lane FO, the rule TrustClaims already
           applies three sections down. `about_text` was read here and written
           by nothing in this application, so the shipped paragraph was the only
           paragraph any shop could have. It has a box now (Appearance →
           Homepage content), and a box the owner can fill is a box the owner
           can empty: emptied, the paragraph is dropped rather than printed
           blank, and the heading, the three counted figures and the link stay.
           The value arrives from HomeController already resolved, so this
           template needs no raw-PHP block of its own. That is not tidiness:
           this file's own headers, thirty and seventy lines from the top,
           record that Blade compiles STATEMENTS BEFORE COMMENTS, so naming a
           directive in prose here is the same as writing one. --}}
      @if ($aboutText !== '')<p>{{ $aboutText }}</p>@endif
      <div class="astats">
        <div><b>{{ number_format($catalogueCount) }}</b><span>{{ __('store.home.about_stat_products') }}</span></div>
        <div><b>{{ $brandTotal }}</b><span>{{ __('store.home.about_stat_brands') }}</span></div>
        <div><b>{{ $reviews['total'] > 999 ? __('store.home.count_thousands_plus', ['count' => round($reviews['total'] / 1000, 1)]) : $reviews['total'] }}</b><span>{{ __('store.home.about_stat_reviews') }}</span></div>
        {{-- A DELIVERY WINDOW WAS THE FOURTH STAT HERE, AND IT IS GONE.

             The other three are counted from the database — products stocked,
             brands carried, reviews approved. This one was two digits typed
             into a template: a transit time, presented in the same row and the
             same weight as three measured figures, to every visitor on earth.
             It named no country, which made it worse rather than better, since
             the number it quoted describes exactly one.

             It is REMOVED rather than made per-country, because there is
             nothing to make it out of. A stat tile wants a NUMBER, and the only
             record this shop keeps of delivery anywhere is a SENTENCE the owner
             writes on Store → Delivery & Shipping → Delivery lines. Deriving
             "1–3" from a row that reads "Delivered across Saudi Arabia" would
             be inventing the very figure this refuses to invent — the
             alternative ProductPagePromisesTest already pins by name for the
             product page's arrival date.

             The delivery promise still has three places to appear, all of them
             fed by that one sentence. It does not need a fourth wearing a
             number's clothes. --}}
      </div>
      <a class="lnk" style="display:inline-block;margin-top:18px" href="{{ Url::to('/about/') }}">{{ __('store.home.about_link') }}</a>
    </div>
  </div>
</div></section>
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

</div>
@endsection
