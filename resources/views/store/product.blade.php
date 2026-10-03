{{--
    Product page — ported from the finalized kbb-product.html.

    Differences from the earlier port, all present in the finalized design:
    the rating capsule (.sr-capbar), the "12k+ sold" line, bundle options with
    image swatches and savings tags, the dispatch cutoff, the review filters and
    load-more, and the details tabs.
--}}
@extends('layouts.store')
@php
    use App\Support\CssUrl;
    use App\Support\Gradient;
    use App\Support\Money;
    use App\Support\ProductTitle;
    use App\Support\Url;

    /* t(), not the column — see App\Support\HasTranslations. On English these
       ARE the column, byte for byte, because t() returns early for the default
       locale. On /ar they are the Arabic the owner typed, per field: a product
       with an Arabic name and no Arabic short description shows the Arabic name
       and the English blurb, never a blank where either should be.

       Where a translated field is printed under an @if, the @if keeps asking
       the ENGLISH column and only the printed value is t(): whether this
       product has a blurb at all is a fact about the product, not about the
       language it is being read in. See .bb-desc below. */
    $brand   = $product->brand?->t('name') ?? '';
    $name    = $product->t('name');
    /* The gradient's seed stays ENGLISH so a product is the same colour on
       /product/... and /ar/product/..., and nothing reads it. */
    $seed    = ($product->brand?->name ?? '') . $product->name;
    /* THE REVIEWS TABLE IS THE ANSWER, WITH NO FALLBACK.
       These read `?: $product->rating` and `?: $product->review_count`, so a
       product with no approved reviews fell back to the denormalised columns —
       which DemoCatalogueSeeder had filled with mt_rand(4, 1400). The page
       therefore advertised "4.9 · 3,204 reviews" on products nobody had ever
       reviewed, while the admin correctly reported no approved review existed.

       Those columns are not wrong in principle: ProductRating::refresh() keeps
       them in step with approved reviews and the shop cards read them. They
       were wrong in fact, which the accompanying migration corrects. But the
       fallback has to go regardless — it is what let a stale or seeded column
       speak over the live count, and "show the number even when we have none"
       is never the behaviour anyone wanted. */
    $rating  = (float) $summary['average'];
    $rcount  = (int) $summary['total'];
    $onSale  = $product->isOnSale();
    $price   = $product->effectivePrice();

    /* THE HEADLINE PRICE OF A VARIABLE PRODUCT, WHICH READ AED 0.

       $price above is Product::effectivePrice(), and a variable parent carries
       no price of its own -- WooCommerce keeps the figures on the variations,
       `products.price` is NULL, and effectivePrice() ends `return (int)
       $this->price`, which casts that to zero. So .bb-price and the sticky bar
       both rendered `AED 0` on every variable product page in the shop.

       AND IT IS NOT A FLICKER. pdp.js writes the real figure into `.now` from
       a CLICK listener only -- see its `.variant` branch -- so nothing
       overwrites this on load. An option is already highlighted server-side
       ($buyable, below), and the price beside it still said AED 0 until the
       shopper tapped something. On a page that quotes a price, a rating and a
       delivery line, that is the one number they came for.

       THE SAME ANSWER THE TILES PRINT, from the same class, rather than a
       second computation: App\Services\VariantPricing mirrors
       ProductVariant::effectivePrice() in SQL, parent sale window included, and
       two implementations of "what does this cost" are how this codebase has
       twice ended up with two different answers.

       $price ITSELF IS NOT TOUCHED. It still feeds Money::decimalsToDistinguish
       below and the sale comparison, and pdp.js still replaces what is drawn
       the moment an option is chosen. Only what the page SAYS before that
       changes. Fixing effectivePrice() is a backfill of `products.price` or a
       change to the importer, and that decision is the owner's -- see
       VariantPricing's header and the test that pins the zero deliberately. */
    $kbbRange = app(\App\Services\VariantPricing::class)->range($product);
    $kbbHeadline = null;

    if ($kbbRange !== null) {
        // Both ends at ONE precision, and the precision that separates them --
        // the same rule as the struck/live pair below.
        $kbbRangeDp = Money::decimalsToDistinguish($kbbRange[0], $kbbRange[1]);

        /* store.product_card.price_range, REUSED rather than re-keyed. It is
           the same phrase in the same words, and a second key would be a second
           Arabic translation of one sentence -- the thing InterfaceStrings'
           header warns about. Renaming it is worse still: a rename orphans
           every translation already typed against the old key. */
        $kbbHeadline = \App\Services\VariantPricing::isSpread($kbbRange)
            ? __('store.product_card.price_range', [
                'low' => Money::format($kbbRange[0], $kbbRangeDp),
                'high' => Money::format($kbbRange[1], $kbbRangeDp),
            ])
            : Money::format($kbbRange[0]);
    }
    $out     = $product->stock_status !== 'instock';
    $off     = $onSale ? $product->discountPercent() : 0;
    // $out is finished below, once the variants are in hand: a variable product
    // whose every option is sold out is sold out, whatever the parent row says.

    $grad    = Gradient::for($seed);
    // Gallery entries are now labelled shots, not bare URLs; the partial
    // renders the frame, so this is only kept for anything else referencing it.
    $mainCss = CssUrl::value(\App\Support\ImageVariants::variantUrl((string) ($gallery[0]['image'] ?? ''), 400));
    $mainBg  = $mainCss !== ''
        ? "#fff url('" . e($mainCss) . "') center/contain no-repeat"
        : $grad;

    $variants = $product->variants;
    $isVar    = $variants->isNotEmpty();

    /* THE OPTION THE PAGE ARMS THE BUTTON WITH HAS TO BE ONE THAT CAN BE BOUGHT.

       The hidden variation_id below was `$variants->first()?->id` regardless of
       stock, and only option 0 was ever given the `on` class, and only when it
       happened to be in stock. So a product whose first size is sold out
       rendered with NO option highlighted, an enabled Add to cart, and a hidden
       field pointing at the sold-out size — and pdp.js declines a click on an
       `.oos` row outright, so tapping it does nothing at all. The only way the
       shopper learned was to press Add and be refused by the server.

       `$buyable` is the first option actually on the shelf; when every option
       is gone it is null and $out below becomes true, which is the same fact a
       simple product's `outofstock` already states. */
    $buyable  = $isVar ? $variants->first(fn ($v) => $v->inStock()) : null;

    /* Nothing left to choose is the same thing as nothing left to sell. Without
       this the stock line said "In stock · ready to ship" over a list where
       every row was tagged Sold out, and the button stayed live. */
    $out      = $out || ($isVar && $buyable === null);

    // Bundles are variants carrying a savings tag, exactly as the design does.
    $hasBundle = $variants->contains(fn ($v) => (bool) $v->tag);
    $optNote   = $hasBundle ? 'Save more with bundles' : $variants->count() . ' options';

    // Capsule only by default: showing the capsule and the inline line puts
    // two rating badges above the price, which reads as a duplicate. Both is
    // still available in Store → Ecommerce → Product page → Review badges.
    $capStyle = (string) $settings->get('review_capsule_style', 'capsule');
    $showCap  = in_array($capStyle, ['capsule', 'both'], true);
    $showRate = in_array($capStyle, ['inline', 'both'], true);

    /* ═══════════════════════════════════════════════════════════════════════
       WHERE THE SHORT DESCRIPTION SITS. (Lane PP)

         "and then a short description should come after the list."

       On a SET the blurb now prints BELOW "What is in this set", not above it.
       The reason it is the right way round is the reason he noticed: the list
       is the set's specification -- three named products, with quantities --
       and the blurb is the sentence that sums them up. A summary before the
       thing it summarises is a caption with nothing above it.

       ▲ AND ONLY ON A SET. An ordinary product has no list, so "after the
         list" names no position on its page; the only candidates would be
         after the bundle strip or after Add to cart, and BOTH move the blurb
         below the buying decision on the 99% of this catalogue that is not a
         set. He asked for one page to change and the screenshot he marked up
         was a set's. So the ordinary product's blurb is where it was, to the
         byte, and $kbbShortBelow is false for every product in the shop but
         the sets. CLAUDE.md rule 1.

       ▲ AND ONLY WHEN THE LIST IS ACTUALLY DRAWN. A `type='set'` row whose
         membership is empty renders no panel at all, and moving the blurb
         "after" a block that does not exist would move it past the option
         slot for no reason a shopper could see. So the question asked here is
         the same one the panel asks itself -- does SetContents have members --
         and it is asked through SetContents::fromProduct(), which is the one
         description of a set's contents this application has.

       ▲ AND IT COSTS NO QUERY. Store\ProductController::show() has already run
         SetEagerLoad::on([$product]), so `setItems` and their members and
         brands are in memory; this is a second loop over an array, not a
         second trip to the database. StorefrontQueryBudgetTest measures it:
         a set's page is the same count at 3 members and at 12, before and
         after this change. */
    $kbbShortBelow = (bool) $product->short_description
        && \App\Support\SetContents::fromProduct($product)['members'] !== [];

    /* ═══════════════════════════════════════════════════════════════════════
       THE BLURB IS HTML, AND IT USED TO BE PRINTED AS TEXT. (Lane PI-A)

       An imported WooCommerce short description is post_excerpt, which is
       HTML, and `{{ }}` escaped it -- so the owner's product page read
       "…fine lines and dryness. <div>This set is ideal for", the tag printed
       as letters, and an "&" in the excerpt read "&amp;".

       RichText::forDisplay() is the same render pipeline the Description tab
       uses: the old shop's paragraph and <br> rules for bare newlines, then
       the allowlist, last, because the result is printed with {!! !!}.

       ▲ <p> UNLESS THE BLURB HAS BLOCKS IN IT. A <p> cannot contain a <div>,
         a <p> or a <ul>: the parser closes it at the first one, and the rest
         of the blurb lands OUTSIDE .bb-desc -- outside the three-line cap and
         its fade, under "Read more" rather than behind it. So the element is
         a <div> for a blurb that carries blocks, and stays the <p> it always
         was for one that does not: two literal elements behind an @if, never
         a tag name built from a variable.

       ▲ forStorefront(), NOT forDisplay(). (Lane PJ-B) The same pipeline plus
         the old shop's `[rey_global_section id=N]` drawn as its block -- a
         blurb naming no section comes back byte for byte as forDisplay()
         returned it. A blurb that does name one carries a <div>, so
         hasBlocks() below picks the <div> wrapper for it.

       ▲ forShortDescription(), NOT forStorefront(). (Lane PV) The same HTML
         with the empty lines above and below the copy taken off. An imported
         excerpt that opened with `<p>&nbsp;</p><p>&nbsp;</p>` filled the
         three-line cap with nothing, so the page read title, blank band,
         "Read more" -- RichText::forShortDescription() has the measurement.
         A blurb with nothing to trim comes back byte for byte. And a blurb
         that was NOTHING BUT empty lines now prints no box and no "Read more"
         at all ($kbbBlurb === '' in both @ifs below). */
    $kbbBlurb = \App\Support\RichText::forShortDescription($product->t('short_description'));
    $kbbBlurbBlocks = \App\Support\RichText::hasBlocks($kbbBlurb);

    /* ═══════════════════════════════════════════════════════════════════════
       THE PHONE PAGE AS SECTIONS. (Lane QA)

         "i want things need to work as sections. [...] and give functionality
          to drag an drop the positioning changing / sorting, and ON/OFF
          anything. THIS message changes is only for MOBILE."

       One rendered page serves both widths, so the order is CSS: each section
       below is ONE element (a `.pm-sec` wrapper, or the root of the partial
       that draws it), and at the page's own phone breakpoint (880px) the
       intermediate boxes -- .pdp, .buybox, the cart form -- become
       `display:contents`, so every section is a flex item of `.pdp-page` and
       takes `order` from the custom property App\Services\ProductMobileSections
       writes onto that wrapper. On a laptop the wrappers are plain blocks with
       no padding or border, so margins collapse through them and the page is
       drawn as before. The DOM order is the LAPTOP order -- title, price row,
       short description -- which is the second of his two desktop changes.

       ▲ NOTHING FROM A SETTING REACHES CSS BUT INTEGERS. wrapperStyle() is
         `--pm-…:<int>` built from literal keys; wrapperClass() is literal class
         names. Both go through {{ }} regardless. */
    $kbbMsec = app(\App\Services\ProductMobileSections::class);

    /* THE LAPTOP ORDER OF THE FOUR BLOCKS UNDER THE TWO COLUMNS. (Lane RF)
       App\Services\ProductDesktopSections. While the order is the default it
       adds NOTHING to the wrapper below -- not a class, not a property, not a
       byte -- and `$kbbDsecDrawn` is not even worked out. Once he moves a
       block it appends ` pds-on …` to the class and `;--pds-o-<key>:<int>` to
       the style, read only inside the laptop media query. */
    $kbbDsec = app(\App\Services\ProductDesktopSections::class);
    $kbbDsecDrawn = $kbbDsec->isDefault() ? [] : \App\Services\ProductDesktopSections::drawn($modules, $buyTogether ?? null, $alsoLike ?? null);
    /* (Lane RG) The buy column's three blocks that depend on the product, for
       ProductDesktopSections::firstBuy() -- worked out only once he has moved
       a buy-column block, from values this template already holds. */
    $kbbDsecBuyDrawn = $kbbDsec->isBuyDefault() ? [] : [
        'short' => (bool) $product->short_description && $kbbBlurb !== '' && ! $kbbShortBelow,
        'paylater' => $kbbMsec->payLater() !== [],
        'bundles' => $isVar || ! empty($bundles) || $product->type === 'set',
        // (Lane RI) only when he has dragged it in: drawn() asks no query.
        'buytogether' => $kbbDsec->buyTogetherRight() && \App\Services\ProductDesktopSections::drawn($modules, $buyTogether ?? null, $alsoLike ?? null)['buytogether'],
    ];
    /* (Lane RI) Buy these together in the buy column: the ONE `.kbb-fbt`
       element is drawn at the end of `.buybox` instead of after `.pdp`. */
    $kbbDsecBtRight = $kbbDsec->buyTogetherRight();

    /* ═══════════════════════════════════════════════════════════════════════
       THE THREE PROPOSED LAYOUTS ARE GONE. (Lane PP2)

       Lane PP put a three-entry map here -- focus / editorial / compact --
       keyed off `?layout=` in the query string, so the owner could look at
       three drawings of this page on the real catalogue and pick one. He
       looked, and answered that they were not three choices: read side by
       side they differed by a card border against two hairline rules, one type
       step, and the capitalisation of one button.

       So there is no layout parameter on this shop any more. `.pdp` carries no
       layout class, `?layout=` is an unread query string like any other, and
       the ~240 lines of `.pp-lay*` rules behind it are deleted from
       resources/css/kbb/kbb-product.css rather than left as dead selectors
       every later lane has to reason about. The measurements that produced
       them are kept in docs/PP-PRODUCT-PAGE-PROPOSALS.md, which is where a
       proposal that was not taken belongs.

       THE MAIN IMAGE IS SQUARE, IN ANY CASE -- his words. `.gmain` is back to
       the `aspect-ratio:1` it has always carried, with no override anywhere. */
@endphp

{{-- Brand once, not twice. This concatenated brand and name unconditionally,
     but a great many names in this catalogue were imported already carrying
     their brand, so the tab read "Anua Anua Heartleaf 77% Soothing Toner".
     Prepending only when the name does not already lead with the brand is the
     whole fix; ProductTitle explains why that test compares words rather than
     characters, and why stripping a leading brand instead would be wrong. --}}
@section('title', ProductTitle::head($brand, $name))

@push('head')
    {{--
        SUPERSEDED, AND KEPT ONLY BECAUSE IT IS PINNED ELSEWHERE.

        This preload was added while the gallery painted the main shot as a CSS
        background-image, which the preload scanner cannot see. Its own note
        said so: "a mitigation, not the fix -- the real repair is an <img> with
        width, height and alt ... which the product page layout lane owns."

        That repair has landed. partials/product-gallery.blade.php now renders
        the main shot as a real <img> carrying loading="eager" and
        fetchpriority="high", in the initial HTML, so the scanner finds it on
        its first pass with no hint required. This <link> now resolves to the
        same URL as that <img> and buys nothing.

        It is left in place only because two tests in ProductSeoTest.php assert
        it, and that file belongs to another lane. Removing the link and those
        two assertions together is a one-line follow-up for whoever owns it.

        AND IT STOPPED BUYING NOTHING THE MOMENT THE <img> GAINED A srcset.

        "Resolves to the same URL" was true only while the <img> had exactly
        one candidate. Now that the gallery offers phone-sized copies, the
        browser may well choose the 400w or 800w one -- while this hint names
        the full-size original unconditionally. A preload the page then does
        not use is not a wasted hint, it is a whole EXTRA download of the
        largest file on the page, on every product view, which is the precise
        cost the copies exist to remove. The hint would have paid for the
        optimisation and then some.

        imagesrcset/imagesizes are how a preload is told to make the same
        choice as the <img>: given identical lists, the browser resolves both
        to one candidate and fetches it once. They are emitted only when the
        gallery is really emitting a srcset, so the plain single-URL form is
        still exactly what a product with no copies on disk gets -- which is
        the form ProductSeoTest asserts, byte for byte, and why that file did
        not have to be touched.
    --}}
    @php
        /* The same question the gallery partial asks, asked again rather than
           passed along: this is a @push into <head> and the partial is
           @included much further down the template, so there is no variable
           either could hand the other. Two is_file() calls and one image
           header, against a duplicated download of the page's biggest asset. */
        $preloadSrcset = empty($gallery[0]['image'])
            ? ''
            : \App\Support\ImageVariants::detailSrcsetFor($gallery[0]['image']);
    @endphp
    @if (! empty($gallery[0]['image']))
        @if ($preloadSrcset === '')
            <link rel="preload" as="image" href="{{ $gallery[0]['image'] }}" fetchpriority="high">
        @else
            <link rel="preload" as="image" href="{{ $gallery[0]['image'] }}" fetchpriority="high" imagesrcset="{{ $preloadSrcset }}" imagesizes="{{ \App\Support\ImageVariants::detailSizesAttribute() }}">
        @endif
    @endif
@endpush

{{-- ▲ THE @include BELOW SITS HARD AGAINST THE MARGIN, AND THAT IS MEASURED.

     partials/product-layout-css.blade.php emits NOTHING while every value on
     Appearance → Product page → Layout is at the number this page already
     draws — which is what makes applying this package a change of nothing. So
     the directive may add no bytes of its own either, and BOTH obvious
     placements do:

       indented on its own line   the four leading spaces are literal output and
                                  are pushed into <head> whatever the partial
                                  emits.
       on the end of the </style> line   worse, and this is the one that was
                                  written first: Blade compiles @include to
                                  `<?php echo … ?>` and PHP swallows ONE newline
                                  after a closing tag — so the include ate the
                                  newline that used to end the <style> line.
                                  StorefrontEnglishUnchangedTest caught it at
                                  byte 19916 of the product page, a single ⏎,
                                  which is exactly the size of accident that
                                  test exists to refuse.

     Its own line, no indentation: the newline the directive swallows is its
     own, the <style> line keeps the one it had, and the rendered <head> is byte
     for byte what it was. --}}
@push('styles')
    @vite('resources/css/kbb/kbb-product.css')
    <style>{!! $reviewsCss !!}</style>
@include('partials.product-layout-css')
@endpush

@section('content')
<div class="wrap pdp-page {{ $kbbMsec->wrapperClass() }}{{ $kbbDsec->wrapperClass($kbbDsecDrawn, $kbbDsecBuyDrawn) }}" style="{{ $kbbMsec->wrapperStyle() }}{{ $kbbDsec->wrapperStyle() }}">
  <div class="crumb"><a href="{{ Url::to('/') }}">{{ __('store.breadcrumb.home') }}</a> / <a href="{{ $product->categories->first()?->url() ?? Url::to('/shop/') }}">{{ $product->categories->first()?->t('name') ?? __('store.breadcrumb.shop') }}</a> / {{ $name }}</div>
  <div class="pdp">
    <!-- gallery -->
    @include('partials.product-gallery')

  <!-- buy box -->
    <div class="buybox">
{{-- THE BRAND NAME IS A LINK TO THE BRAND'S OWN PAGE.
     The owner pointed at it: it was a bare <div>, so the one word on this page
     that names a brand went nowhere, while `/brands/{slug}/` -- a real page with
     that brand's copy, logo and product grid, and in the sitemap -- sat unlinked
     from every product it sells.

     `Brand::url()` is the helper for it and already existed; one of the design
     preview templates was already calling it, so the live page was the odd one
     out rather than the URL being new.

     (That sentence originally named the preview directory, and
     PdpPreviewTest's sweep went red on it -- the sweep reads this file for the
     directory's name and cannot tell a comment from a reference. It was right
     to: a shipped template must not reach into those previews, and a sweep that
     forgave prose would forgive the real thing written inside a comment block
     too.)

     ▲ THE ELEMENT STAYS A <div> AND THE <a> GOES INSIDE IT. `.bb-brand` carries
       the eyebrow's size, letter-spacing, colour and the spacing above the
       name, and `#bbBrand` is read elsewhere; swapping the tag would move the
       line and break both. The anchor inherits, so nothing shifts.

     ▲ AND IT IS RENDERED ONLY WHEN THERE IS SOMEWHERE TO GO. A brand with no
       slug yields no url, and a link to nowhere is worse than plain text --
       so the name still prints, unlinked.

     ▲ THIS COMMENT'S CLOSING MARKER IS GLUED TO THE DIRECTIVE BELOW, WITH NO
       NEWLINE BETWEEN THEM. A Blade comment is removed but the newline that
       followed it is not, so writing this block on its own lines added ONE
       BLANK LINE to the buy box of every product page in the shop.
       StorefrontEnglishUnchangedTest reported it at byte 40177, alongside the
       change that was actually meant. CLAUDE.md carries the same warning for
       directives; it is just as true of comments.

     ▲ AND THAT MARKER IS DESCRIBED HERE IN WORDS, NEVER TYPED. Spelling it out
       inside a Blade comment CLOSES THE COMMENT AT THAT POINT: the first draft
       of this note did exactly that, so every line after it compiled as
       template code and the product page died with `syntax error, unexpected
       token ":"`. Same family as writing the end-of-PHP-block directive inside
       a PHP comment, which CLAUDE.md already records. --}}<div class="pm-sec pm-title">      @if ($brand)<div class="bb-brand" id="bbBrand">@if ($product->brand?->url())<a href="{{ $product->brand->url() }}">{{ $brand }}</a>@else{{ $brand }}@endif</div>@endif
      @php
          /* ═══════════════════════════════════════════════════════════════
             LEDGER — THE PRICE JOINS THE NAME'S ROW, SO THIS BLOCK MOVED UP.
             (Lane PDP2)

               "then product name, and right side cut price and actual price
                beautifully present."

             `$kbbWas` and `$kbbSaleDp` were computed inside `.bb-price`, three
             elements lower down the column. The price is now the second track
             of the title's row, so the computation travels with it. Both of its
             OTHER readers -- the bundle loop's `max($bdp, $kbbSaleDp)` and the
             sticky bar's `.now` -- are further down this file still, so nothing
             else moves.

             ▲ $kbbWas, NOT `(int) $product->price`. A variable product's
               markdown lives on its VARIATIONS and `products.price` is NULL on
               the parent, so the moment Product::isOnSale() started telling the
               truth about one of them, the <s> below would have printed `AED 0`
               beside a real from-price. Product::compareAtPrice() is what this
               page advertised the day before the sale opened -- the lowest
               REGULAR price across the options -- and it is the same figure the
               tile's badge and the "On sale" facet compare against.

             ▲ BOTH FIGURES AT ONE PRECISION, and the precision that separates
               them. Money::decimalsToDistinguish() answers 0 -- no change at
               all -- for every markdown whole dirhams can already tell apart,
               and 2 for the one that cannot: without it a markdown from
               AED 100.00 to AED 99.80 prints `AED 100` inside the <s> and
               `AED 100` inside `.now` beside it, striking a price through and
               quoting the identical number next to it.

             ▲ ONE @php REGION AND NOT THREE. A directive on a line of its own
               contributes its indentation and its newline to every page that
               renders it, and StorefrontEnglishUnchangedTest compares BYTES --
               the same trap this file already records twice. */
          $kbbWas = (int) $product->compareAtPrice();
          $kbbSaleDp = $onSale ? Money::decimalsToDistinguish($kbbWas, $price) : null;

          $badgeHeart  = (bool) $settings->get('review_badge_heart', true);
          $badgeAvg    = (bool) $settings->get('review_badge_avg', true);
          $badgeCount  = (bool) $settings->get('review_badge_count', true);
          $badgeSold   = (bool) $settings->get('review_badge_sold', true);
          $badgeLabel  = str_replace('{n}', number_format($rcount), (string) $settings->get('review_badge_label', __('store.product.review_badge_label')));
          $badgeColour = (string) $settings->get('review_badge_colour', '#E8A33D');

          /* THE RATING HAIRLINE'S FILL IS ARITHMETIC ON A NUMBER THE SERVER
             ALREADY HAS -- `$rating / 5` as a percent, written into the style
             attribute. Nothing in the browser measures anything (CLAUDE.md rule
             4), and it is `inline-size`, so the bar fills from the inline-start
             edge and therefore from the RIGHT on /ar with no [dir] rule.

             CLAMPED, because a rating outside 0..5 is a data fault and a bar
             140% wide would be a layout fault stacked on top of it. It is only
             ever drawn under `@if ($rcount)` / `$showCap && $rcount`, so an
             unreviewed product draws no bar rather than an empty one -- the
             same refusal the shipped page already makes about the number. */
          $kbbRateFill = max(0, min(100, (int) round(($rating / 5) * 100)));
      @endphp
      {{-- TWO TRACKS, AND THE PRICE TRACK IS SIZED TO ITS CONTENT.
           `minmax(0,1fr)` on the title track is what keeps a 118-character
           imported name from pushing the price off the inline edge: without the
           `0` minimum a grid track refuses to shrink below its content and the
           row overflows, which at 390px is a horizontal scrollbar on the whole
           document. Measured on pdp-very-long-name-ampoule, at both widths.

           THE LIVE PRICE IS ALWAYS INSIDE `.now`, ON SALE OR NOT, and that is
           carried over from the block this replaced rather than rediscovered:
           without the span an ordinary price rendered bare and then JUMPED IN
           SIZE the moment a bundle was picked, because pdp.js' setPrice()
           creates the span when it cannot find one. See its `// No .now span`
           branch.

           THE STRUCK FIGURE IS FIRST IN THE MARKUP BECAUSE IT IS FIRST ON THE
           PAGE. Ledger stacks it ABOVE the live figure rather than beside it --
           beside it is where the pair wraps badly on a 390px phone with a
           forty-character K-beauty name, at which point the two prices have
           wrapped rather than been designed. pdp.js finds `.now` by selector and
           never by position (see setPrice()), so the order costs nothing. --}}
      {{-- ── THE TITLE ROW HOLDS THE NAME AND THE SHARE ICON; THE PRICE HAS ITS
           OWN ROW UNDER IT. (Lane QA)

             "the share icon will also desktop beside the title on right side.
              and the pricing row will come downside and on right side of the
              pricing row rating without count (can be shown in seperate row if
              i like to do so) from backend."

           `.bb-head` keeps its two tracks -- `minmax(0,1fr)` for the name, so a
           118-character imported name still wraps instead of pushing the page
           sideways -- and the second track is now the share button (Lane QB
           draws the sheet it opens). The price moved into `.bb-pricerow`
           below, with the rating rows beside it; pdp.js finds `#bbPrice` and
           `.now` by id and selector, never by position, so the tier switch is
           untouched. --}}
      <div class="bb-head">
        <h1 class="bb-title" id="bbTitle">{{ $name }}</h1>
        @include('partials.product.share-button')
      </div></div><!--/pm-->
      <div class="pm-sec pm-price"><div class="bb-pricerow" id="bbPriceRow">
        <div class="bb-price" id="bbPrice">@if ($onSale)<s>{!! Money::format($kbbWas, $kbbSaleDp) !!}</s>@endif<span class="now">@if ($kbbHeadline !== null){!! $kbbHeadline !!}@else{!! Money::format($price, $kbbSaleDp) !!}@endif</span>@if ($onSale && $off)<span class="off">{{ \App\Support\Bidi::number('-' . $off . '%') }}</span>@endif</div>
      <div class="cap-area" id="capArea">
          @if ($showCap && $rcount)
              <a class="{{ $modules->classFor('capsule') }} sr-capbar" href="#sr">
                  @if ($badgeHeart)<span class="sr-cap-heart">&#10084;</span>@endif
                  <span class="sr-cap-stars" style="color:{{ $badgeColour }}">★★★★★</span>
                  @if ($badgeAvg)<span class="sr-cap-avg">{{ number_format($rating, 1) }}</span>@endif
                  {{-- The hairline, in BOTH rating rows and not just this one.
                       Store → Ecommerce → Product page → Review badges picks
                       which of the two is drawn (capsule / inline / both), and a
                       shopper who has chosen `inline` is looking at .bb-rate --
                       so a bar added to only one of them is a bar that
                       disappears when the owner moves a control he already had.
                       On `both` there are two rating rows and therefore two
                       bars, which is correct: each row states its own rating. --}}
                  <span class="bb-ratebar"><i style="inline-size:{{ $kbbRateFill }}%"></i></span>
                  {{-- "rating (4.9 and bar, remove count)" -- so the count is drawn
                       only when Mobile sections → "Show the review count" is on
                       (off as shipped), AND the badge switch still allows it. --}}
                  @if ($badgeCount && $kbbMsec->on('rate_count'))<span class="sr-cap-count">{{ $badgeLabel }}</span>@endif
              </a>
          @endif
      </div>
      @if ($rcount)
      <div class="{{ $modules->classFor('rating') }} bb-rate" id="bbRate" @unless ($showRate) style="display:none" @endunless><span class="stars" id="bbStars" style="color:{{ $badgeColour }}">@for ($i = 1; $i <= 5; $i++){!! $i <= round($rating) ? '<span class="f">★</span>' : '<span>★</span>' !!}@endfor</span> @if ($badgeAvg)<span>{{ number_format($rating, 1) }}</span> @endif<span class="bb-ratebar"><i style="inline-size:{{ $kbbRateFill }}%"></i></span>@if ($badgeCount && $kbbMsec->on('rate_count'))· <a href="#sr">{{ $badgeLabel }}</a>@endif @if ($badgeSold && $product->total_sales > 999) · <span style="color:var(--green);font-weight:600">{{ __('store.product.sold_thousands', ['count' => round($product->total_sales / 1000)]) }}</span>@endif</div>
      @endif
      </div>
      @if ($vatLine)<div class="{{ $modules->classFor('vat') }} bb-vat">{{ $vatLine }}</div>@endif</div><!--/pm-->
      {{-- "2-3 lines short description with fade read more."

           THREE LINE-BOXES, THEN A FADE, THEN ONE TAP TO THE REST, AND NO
           JAVASCRIPT. The cap is `max-block-size: calc(3 * <line-height>)` and
           the bottom of it is dissolved with `mask-image`; the toggle is a
           CHECKBOX, so `.bb-morebox:checked ~ .bb-desc` lifts the cap and
           removes the mask in one rule and it works with JavaScript off.

           ▲ WHY NOT `-webkit-line-clamp`. Clamp ends the third line with an
             ELLIPSIS, and an ellipsis is a truncation mark, not a fade. He drew
             a fade -- the last line going to nothing.

           ▲ AND WHY NOTHING MEASURES IT. "Is this text actually longer than
             three lines" is the canonical reason a page reaches for
             scrollHeight, and CLAUDE.md rule 4 forbids it. It is not asked: the
             cap and the mask are declared in CSS and paint identically whether
             the blurb runs to two lines or to nine, and a short blurb simply
             never reaches the mask, so it fades nothing.

           ▲ ONE ID PER PAGE, AND THE TWO POSITIONS ARE MUTUALLY EXCLUSIVE.
             `$kbbShortBelow` is true only on a set whose contents panel really
             draws, and this @if carries `! $kbbShortBelow` while the one inside
             the form carries `$kbbShortBelow` -- so exactly one `#bbMore` is
             ever in the document and the label cannot point at the wrong one.

           ▲ OFF-CANVAS, NOT `hidden`. A `hidden` checkbox is `display:none`,
             and a display:none control cannot be reached by keyboard -- which
             would make the only way to read the rest of the blurb a mouse. It is
             parked at 1x1px with opacity 0 instead, which is the same reasoning
             parts/tabs.blade.php gives for its radio group. It carries no
             `name`, so the copy of it that lands inside the cart form on a set
             is not serialised with the basket.

           ▲ NO CHEVRON BESIDE THE ARROW. `store.product.read_more` is already
             "Read more ↓" in English and "اقرأ المزيد ↓" in Arabic; the drawing
             put an SVG chevron after it as well, which is two arrows saying one
             thing on the page he has just asked to make quieter.

           ▲ ON ONE SOURCE LINE, and that is not tidiness. Blade contributes a
             directive's own indentation and newline to the rendered page even
             when the directive prints nothing, and this file already records
             what that costs. --}}
      @if ($product->short_description && $kbbBlurb !== '' && ! $kbbShortBelow)<div class="pm-sec pm-short"><input class="bb-morebox" type="checkbox" id="bbMore">@if ($kbbBlurbBlocks)<div class="{{ $modules->classFor('short') }} bb-desc">{!! $kbbBlurb !!}</div>@else<p class="{{ $modules->classFor('short') }} bb-desc">{!! $kbbBlurb !!}</p>@endif<label class="bb-more" for="bbMore">{{ __('store.product.read_more') }}</label></div><!--/pm-->@endif
@include('partials.product.paylater')

      <form class="cart kbb-cart-form" data-product_id="{{ $product->id }}" method="post">
        @csrf
        <div class="pm-sec pm-bundles">@if ($isVar)
        <div class="opt-label">{{ __('store.product.choose_option') }} <span id="optNote">{{ $optNote }}</span></div>
        <div class="{{ $modules->classFor('options') }} variants" id="variants">
          @foreach ($variants as $n => $v)
            @php
              $vsale = $v->effectivePrice();
              $vreg  = (int) ($v->price ?: $vsale);
              $oos   = ! $v->inStock();
              $voff  = ($vreg > 0 && $vsale < $vreg) ? (int) round((1 - $vsale / $vreg) * 100) : 0;
              // Same collision as the main price block, one level down: two
              // options priced 10000 and 9980 fils both printed "AED 100", so
              // the row struck a price through and quoted the identical number
              // next to it, sometimes under a "Save 0%" — no, under nothing,
              // because $voff guards that — which left the strike unexplained.
              $vdp   = ($vsale < $vreg) ? Money::decimalsToDistinguish($vreg, $vsale) : null;
              /*
               * The 22px option swatch, at 22px rather than at 1000x1000 -- the
               * worst ratio on the shop before Lane IM. background-image takes
               * exactly ONE url and image-set() selects on pixel ratio rather
               * than width, so a srcset cannot say "this box is 22px";
               * variantUrl() is the single-URL form, and it hands the original
               * straight back when no copy has been cut.
               *
               * RESOLVED HERE AND NOT IN THE @if BELOW, which is where it went
               * first. Three levels of nested parentheses on one directive line
               * defeats StorefrontStringsAreKeyedTest's scanner: it stops
               * matching the condition partway and reads the tail as prose a
               * shopper is meant to read, then reports the expression itself as
               * an unkeyed English string. The scanner is not wrong to give up
               * -- a condition that deep is unreadable to a person too. Inside
               * this block, which already exists, so no newline is added and
               * StorefrontEnglishUnchangedTest does not move.
               */
              $vImg  = \App\Support\ImageVariants::variantUrl((string) $v->image, 400);

              /* THE SAME PAIR, FOR A REAL VARIATION. (Lane PDP2 round 3)
                 A variation carries its own regular price, so there is no
                 1-unit exception here: a row with `$vsale < $vreg` means its own
                 markdown and a row without one means no strike and no badge at
                 all. `$voff` is already computed above for the row's own tag. */
              $vWas  = ($vsale < $vreg) ? Money::plain($vreg, $vdp) : '';
              $vOff  = $voff > 0 ? \App\Support\Bidi::number('-' . $voff . '%') : '';
            @endphp
            {{-- Selected by identity, not by index: the highlighted row is the
                 first one that can be bought, which is the same row the hidden
                 field below is set to. `0 === $n` selected nothing at all when
                 option 0 was sold out. --}}
            <div class="variant{{ $buyable && $v->is($buyable) ? ' on' : '' }}{{ $oos ? ' oos' : '' }}" data-i="{{ $n }}" data-vid="{{ $v->id }}" data-qty="1" data-price="{{ Money::plain($vsale, $vdp) }}" data-was="{{ $vWas }}" data-off="{{ $vOff }}">
              @if (($vImgCss = CssUrl::value($vImg)) !== '')<span class="vsw" style="background-image:url('{{ $vImgCss }}')"></span>@else<span class="vr"></span>@endif<span class="vn">{{ $v->label() ?: __('store.product.option_fallback', ['number' => $n + 1]) }}</span><span class="vp">@if ($vsale < $vreg)<s>{!! Money::format($vreg, $vdp) !!}</s>@endif{!! Money::format($vsale, $vdp) !!}</span>@if ($oos)<span class="vtag sold">{{ __('store.product.sold_out_tag') }}</span>@elseif ($v->tag)<span class="vtag">{{ $v->tag }}</span>@elseif ($voff)<span class="vtag">{{ __('store.product.save_percent', ['percent' => $voff]) }}</span>@endif
            </div>
          @endforeach
        </div>
        {{-- The option Add to cart posts when the shopper touches nothing. It
             has to be one the shop can actually sell, or the first press of the
             button is always refused. --}}
        <input type="hidden" name="variation_id" id="kbbVarId" value="{{ ($buyable ?? $variants->first())?->id }}">
        @elseif ($bundles)
        {{-- Quantity bundles: the same product at a better rate for buying more.
             Generated from the tier table, so every product has them without
             per-product setup. --}}
        <div class="opt-label">{{ __('store.product.choose_option') }} <span id="optNote">{{ __('store.product.bundles_note') }}</span></div>
        <div class="variants" id="variants">
          @foreach ($bundles as $n => $b)
            {{-- TWO REASONS THIS ROW MAY HAVE TO WIDEN, and the second was
                 missed on the first pass because it is not about this row at
                 all.

                 1. The row's own pair. A bundle whose `was` and `total` round
                    to the same string states a saving it does not show.

                 2. THE PRICE BLOCK ABOVE IT. A single-figure row has no pair to
                    collide with, so rule 1 leaves it at the store's whole-dirham
                    display — and the 1-unit row IS the headline price. On a
                    product marked down from AED 100.00 to AED 99.80 the block at
                    the top of the page correctly read `AED 99.80` while the
                    row directly beneath it read `AED 100`, for the same unit, in
                    the same eyeful. Two renderings of one number on one document
                    must not be quoted at two widths — the same argument the
                    sticky bar below already makes, applied upward.

                 So the row takes the WIDER of its own requirement and the price
                 block's. max() and not a replacement, because a bundle whose own
                 pair needs more precision than the headline still needs it. --}}
            {{-- ── AND THE PAIR THE PRICE BLOCK HAS TO SHOW WHEN THIS ROW IS
                      PICKED, WHICH IS THE ROW'S OWN — EXCEPT ON THE FIRST.
                                                            (Lane PDP2 round 3)

                 THE DEFECT, READ OUT OF A BROWSER RATHER THAN OUT OF THIS FILE:
                 press the 2-pack and the block read `AED 99 struck / AED 140 /
                 -25%` while this row read `AED 149 / AED 140 / Save 6%`. The
                 strike was untidy. THE BADGE WAS A FALSE CLAIM ABOUT MONEY --
                 "-25%" on a tier discounted 6%, on the page where the shopper
                 decides -- and it is the reason this is a defect and not a
                 tidy-up.

                 ▲ WHY THE FIRST ROW CANNOT USE ITS OWN PAIR, which is the whole
                   reason the obvious fix is wrong. BundleService computes
                   `was = qty * effectivePrice` -- the SALE price -- so the
                   1-unit tier has `was === total`, `saved` of 0 and no struck
                   figure at all. Copy this row's pair literally and the view the
                   page OPENS ON loses "AED 99 / -25%", which is the product's
                   own markdown and the one number the page is really about.

                   The two `was` figures are different things and both are true:
                   the PRODUCT's ($kbbWas, AED 99 -- what it cost before the
                   sale) and the TIER's ($b['was'], AED 149 -- what two cost
                   without the bundle discount). A row with a saving of its own
                   means the second; a row without one means the first.

                 ▲ PLAIN, NOT Money::format(), AND AT THE ROW'S OWN PRECISION.
                   `data-price` beside it is already Money::plain() for the same
                   reason: it crosses into JavaScript, where markup would have to
                   be trusted. pdp.js escapes it on the way out. $bdp is the
                   width this row prints at, so the block cannot end up quoting
                   one number at two widths -- the rule the block above and the
                   sticky bar below both already follow. The FALLBACK pair is at
                   $kbbSaleDp and not $bdp for the same rule read the other way:
                   it is the figure the server already rendered into .bb-price,
                   so pressing 1 unit after a bundle has to restore that render
                   exactly rather than a wider spelling of it.

                 ▲ THE BADGE TEXT IS COMPOSED HERE, NOT IN THE BROWSER, because
                   App\Support\Bidi::number() wraps it in isolates on /ar and a
                   string built as '-' + n + '%' in JavaScript would not have
                   them. The server already knows the number; it may as well say
                   the whole word. --}}
            @php
                $bdp = $b['saved'] > 0
                    ? Money::decimalsToDistinguish((int) $b['was'], (int) $b['total'])
                    : null;
                $bdp = max($bdp ?? Money::displayDecimals(), $kbbSaleDp ?? Money::displayDecimals());

                $bHasOwn = $b['saved'] > 0;
                $bWas = $bHasOwn ? Money::plain((int) $b['was'], $bdp) : ($onSale ? Money::plain($kbbWas, $kbbSaleDp) : '');
                $bPct = $bHasOwn ? (int) $b['percent'] : ($onSale ? (int) $off : 0);
                $bOff = $bPct > 0 ? \App\Support\Bidi::number('-' . $bPct . '%') : '';
            @endphp
            <div class="variant{{ 0 === $n ? ' on' : '' }}" data-i="{{ $n }}" data-qty="{{ $b['qty'] }}" data-price="{{ Money::plain($b['total'], $bdp) }}" data-was="{{ $bWas }}" data-off="{{ $bOff }}">
              <span class="vr"></span><span class="vn">{{ $b['label'] }}</span><span class="vp">@if ($b['saved'] > 0)<s>{!! Money::format($b['was'], $bdp) !!}</s>@endif{!! Money::format($b['total'], $bdp) !!}</span>@if ($b['tag'])<span class="vtag">{{ $b['tag'] }}</span>@endif
            </div>
          @endforeach
        </div>
        @endif

        {{-- ═══════════════════════════════════════════════════════════════
             WHAT IS IN THIS SET — IN THE SLOT THE BULK STRIP USED TO FILL.
             (Lane SF)

             "the Set product will not have bundle purchase, instead of that
              section, bring the What's inside there, and make it nice list,
              not grid!"  — with an arrow drawn from the contents section far
             down the page UP into this exact space.

             So it is HERE: inside the buy column, after the variant/bundle
             block above and before the stock line and Add to cart below. On a
             phone the buy column stacks under the gallery, so the list lands
             in the main flow at the same point in the reading order.

             ▲ THE @if ABOVE AND THIS ARE MUTUALLY EXCLUSIVE BY CONSTRUCTION,
               NOT BY LUCK. A set is never `$isVar` (a set has no variations)
               and BundleService::forProduct() now answers an empty array for
               one, so `$bundles` is falsy and neither branch above draws
               anything. The panel itself renders NOTHING for a product that is
               not a set. There is no product for which both appear, and no
               product for which the old placement and this one both do —
               SetBuyColumnTest pins all three.

             ▲ AND IT IS INCLUDED EXACTLY ONCE ON THIS PAGE. The section-level
               @include near the foot of the file was REMOVED in the same edit
               that added this one; two includes would print the box's contents
               twice and its saving twice.

             ── AND THE SHORT DESCRIPTION FOLLOWS IT, ON A SET. (Lane PP) ────

             "and then a short description should come after the list."

             The second @if on the line below, and nowhere else on this page:
             the @if above the buy form carries `! $kbbShortBelow`, so exactly
             ONE of the two prints for any product. Same element, same classes,
             same module class from $modules->classFor('short') -- a shopper who
             has turned the short description off in Appearance has it off in
             both places, and the CSS that styles it does not need to know which
             of the two positions it is in.

             ▲ ON THE SAME SOURCE LINE AS THE @include, AND THAT IS NOT
               TIDINESS. StorefrontEnglishUnchangedTest compares BYTES. A
               directive on a line of its own contributes its indentation and
               its newline to every page that renders it, INCLUDING the 99% of
               this catalogue for which the @if is false and prints nothing --
               so written on its own line this block changed the English output
               of every ordinary product page in the shop by two whitespace
               runs, for a feature none of them have. Written here it changes
               nothing at all: the walk is green rather than pinned forward,
               and the integrator has one less diff to read.

               Same trap the .bb-price block above records for computing the
               range beside the markup instead of up in the php block at the top. --}}
        @include('partials.set-contents-panel')</div><!--/pm-->@if ($kbbShortBelow && $kbbBlurb !== '')<div class="pm-sec pm-short pm-short-set"><input class="bb-morebox" type="checkbox" id="bbMore">@if ($kbbBlurbBlocks)<div class="{{ $modules->classFor('short') }} bb-desc">{!! $kbbBlurb !!}</div>@else<p class="{{ $modules->classFor('short') }} bb-desc">{!! $kbbBlurb !!}</p>@endif<label class="bb-more" for="bbMore">{{ __('store.product.read_more') }}</label></div><!--/pm-->@endif

        @php
            // Scarcity note, from the configured threshold. Only shown when the
            // count is genuinely known and genuinely low — an invented urgency
            // message is the fastest way to lose a customer's trust.
            $lowAt = (int) $settings->get('low_stock_at', 5);
            /* `stock`, not `stock_quantity`.
             *
             * There is no `stock_quantity` column on `products` and no accessor
             * of that name on the model — ProductImporter reads the WooCommerce
             * field `stock_quantity` and writes it to `stock`, which is what the
             * schema calls it. Eloquent answers null for an attribute it does
             * not have, so `is_numeric($left)` was false for every product in
             * the catalogue and "Only N left · order soon" had never rendered
             * once, for anybody. The owner's `low_stock_at` setting drove
             * nothing at all.
             *
             * The guards around it are unchanged, and they are the point: the
             * count has to be genuinely known and genuinely low. An invented
             * urgency message is the fastest way to lose a customer's trust. */
            $left  = $product->manage_stock ? $product->stock : null;
            $low   = ! $out && $lowAt > 0 && is_numeric($left) && $left > 0 && $left <= $lowAt;
        @endphp
        {{-- Each directive needs a non-word character before its @, or Blade
             treats it as literal text and every branch prints at once. --}}
        <div class="pm-sec pm-ready"><div class="{{ $modules->classFor('stockline') }} stockline{{ $out ? ' out' : '' }}"><span class="dot"></span>
            @if ($out)
                {{ __('store.product.stock_sold_out') }}
            @elseif ($low)
                {{ trans_choice('store.product.stock_low', (int) $left) }}
            @else
                {{ __('store.product.stock_in') }}
            @endif
        </div>
        {{-- THE ARRIVAL DATE IS ONLY OFFERED WHERE ARRIVAL IS KNOWN.

             This said "for delivery by ..." to everybody. The date is the
             dispatch date plus `dispatch_days`, one global number describing
             the shop's own country, so a shopper in Riyadh was handed a
             transit time nobody has measured — the same wrong promise removed
             from the checkout, the dispatch email and the home page before it.

             ProductController::cutoff() now answers with a null `date` outside
             the shop's own country and the sentence keeps the half that is
             still true: when the parcel LEAVES is a fact about the warehouse's
             working week, not about the destination. Nothing is invented to
             replace the half that went. --}}
        {{-- A DIRECTIVE NEEDS A NON-WORD CHARACTER AFTER IT AS WELL AS BEFORE,
             which is the other half of the trap this file already records
             twenty lines below. Written closed-up as `@else` followed
             immediately by the word "Order", Blade reads the whole thing as a
             directive named `elseOrder`, compiles nothing for it and drops the
             branch — the page still renders, still returns 200, and simply
             says less than it should. Hence the line breaks. --}}
        @if ($cutoff)
        <div class="{{ $modules->classFor('cutoff') }} deliver">
            @if ($cutoff['date'] !== null)
                {!! __('store.product.cutoff_delivery', ['remaining' => '<b id="cutoff">' . e($cutoff['remaining']) . '</b>', 'date' => '<b>' . e($cutoff['date']) . '</b>']) !!}
            @else
                {!! __('store.product.cutoff_dispatch', ['remaining' => '<b id="cutoff">' . e($cutoff['remaining']) . '</b>', 'ship' => '<b>' . e($cutoff['ship']) . '</b>']) !!}
            @endif
        </div>
        @endif
</div><!--/pm-->
@include('partials.product.delivery-box')
        <div class="pm-sec pm-cart"><div class="buyrow">
          <div class="{{ $modules->classFor('quantity') }} qty"><button type="button" data-q="-1">−</button><span id="qtyVal">1</span><button type="button" data-q="1">+</button><input type="hidden" name="quantity" id="qtyInput" value="1"></div>
          <button class="addcart" id="mainAdd" type="submit" @disabled($out)><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/></svg> {{ $out ? __('store.product.sold_out_tag') : __('store.product_card.add_to_cart') }}</button>
        </div>
        @unless ($modules->hidden('buynow'))
<button class="buynow" type="submit" data-buynow="1" @disabled($out)>{{ __('store.product.buy_now') }}</button>
@endunless
</div><!--/pm-->@include('partials.product.trust-share-stack')
      </form>

      {{-- "Tell me when this is back" (Lane EN).

           OUTSIDE the form above, and that is structural rather than
           stylistic: a form inside a form is invalid HTML and browsers
           resolve it by throwing the inner one away, so nested here the
           notify button would submit the cart.

           Renders nothing at all unless the product is sold out AND the
           back_in_stock module is on AND the owner has written the line of
           prose that goes above it — both of the last two ship as they ship,
           off and unwritten, so applying the package changes this page by
           exactly nothing. partials/notify-me.blade.php argues the placement
           and carries the rest of the reasoning. --}}
      @include('partials.notify-me', ['notifyLabel' => app(\App\Services\StockAlerts::class)->formLabel()])

      {{-- TWO OF THESE FOUR WERE PROMISES NOTHING RECORDED.
           "Fast UAE delivery" and "Easy 14-day returns" were literals here.
           No returns window exists anywhere in this application — not a
           setting, not a page, not a policy row — and the delivery one was
           shown to a Gulf shopper as readily as to a UAE one, which is the
           exact claim Lane CF removed from the checkout and
           App\Mail\OrderStatusChanged removed from the dispatch email.
           Nothing is invented in their place: each is now a line the owner
           writes in Store → Ecommerce, and until they do, it is not shown.
           The other two stay — authenticity is what this shop is, and the
           pay-later methods are the gateways it actually offers.

           AND THE DELIVERY ONE HAD NO COUNTRY CHECK EITHER.

           `trust_delivery_text` replaced the literal with the owner's own
           words, which fixed the "nothing records it" half and left the
           "wrong country" half exactly where it was: one global string, shown
           to every visitor on earth, with the admin screen suggesting a UAE
           sentence to type into it. Blank by default, so nothing false was on
           the page yet — the defect was armed rather than firing.

           A SINGLE GLOBAL STRING CANNOT BE MADE COUNTRY-AWARE. It can only
           ever be true of one country and the shop has no way of knowing
           which, so the chip is not gated, it is re-sourced. It reads
           App\Support\DeliveryLine, which is already the only reader of the
           per-country wording for the home page and the checkout, through
           App\Support\ShopperCountry, which is already the only answer to
           where the shopper is standing. Neither issues a query and both
           answer for a request with no session and no geo signal at all.

           ONE SCREEN WRITES THE SENTENCE — Store → Delivery & Shipping →
           Delivery lines — so this page cannot contradict the other two, and
           `trust_delivery_text` is gone rather than left inert beside it. Two
           screens both claiming to set "the delivery line" is the duplication
           this project has had to merge twice already.

           AN EMPTY ANSWER IS A REAL ANSWER and means show no chip. Nothing is
           invented to fill the gap: no delivery window outside the shop's own
           country has been measured, and the owner types one when it has. --}}
      {{-- "100% authentic" WAS A LITERAL HERE — Lane DT.

           2.60.193 made the shop's other six claims the owner's own words
           (App\Support\TrustClaims). This one was left behind because another
           lane held this file, which left the product page as the last place in
           the storefront making an unverified claim that only a signed package
           could retract — on shared hosting with no shell, that is a claim the
           owner cannot withdraw at all.

           IT HAS ITS OWN KEY rather than sharing the checkout's, whose default
           is the same string. See TrustClaims::CLAIMS: a shared box would make
           clearing this chip silently remove the one beside Place order too.

           EMPTY REMOVES THE WHOLE CHIP, icon included. `.trust` is a two-column
           grid, so the remaining chips simply reflow — the same thing the
           delivery and returns chips beside it have always done, and the reason
           they are written as conditionals rather than as empty strings. --}}
      @php
          $trustAuthentic = \App\Support\TrustClaims::text($settings, 'product_authentic_text');
          $trustDelivery = \App\Support\DeliveryLine::here();
          $trustReturns  = trim((string) $settings->get('trust_returns_text', ''));
      @endphp
      <div class="{{ $modules->classFor('trust') }} trust pm-sec pm-trust">
        @if ($trustAuthentic !== null)<div class="ti"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2 4 5v6c0 5 3.5 8 8 11 4.5-3 8-6 8-11V5z"/><path d="m9 12 2 2 4-4"/></svg> {{ $trustAuthentic }}</div>@endif
        @if ($trustDelivery !== '')<div class="ti"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7h13v10H3z"/><path d="M16 10h4l1 3v4h-5z"/><circle cx="7" cy="18" r="1.6"/><circle cx="18" cy="18" r="1.6"/></svg> {{ $trustDelivery }}</div>@endif
        @if ($trustReturns !== '')<div class="ti"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9"/><path d="M3 5v4h4"/></svg> {{ $trustReturns }}</div>@endif
        <div class="ti"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/></svg> {{ __('store.product.trust_pay_later') }}</div>
      </div>
      {{-- Asked, not typed. App\Support\PaymentChips gates Apple Pay and Google
           Pay on App\Services\Payments\Wallets, which is the one place that knows
           whether this shop can take either. The section switch above
           (Catalog → Product page → Sections) still decides whether the row is
           drawn at all; this decides what it may say when it is. --}}<div class="{{ $modules->classFor('paychips') }} paychips pm-sec pm-paychips">@foreach (\App\Support\PaymentChips::row('product') as $kbbChip)<span>{{ $kbbChip }}</span>@endforeach<span>{{ __('store.footer.pay_cod') }}</span></div>
@if ($kbbDsecBtRight)
{{-- (Lane RI) Placed in the buy column (Desktop sections): the same partial,
     drawn here INSTEAD of below — one element, one id, one fbt.js binding.
     Outside the cart form on purpose: its checkboxes must not submit with Add
     to cart. On a phone `.buybox` is display:contents, so it is still an item
     of `.pdp-page` in Mobile sections' order. --}}
@unless ($modules->hidden('fbt'))
@include('partials.fbt')
@endunless
@endif
    </div>
  </div>

@unless ($kbbDsecBtRight)
  @unless ($modules->hidden('fbt'))
@include('partials.fbt')
@endunless
@endunless

  <!-- details tabs -->
  <section class="sec pm-sec pm-details">
    <div class="eyebrow">{{ __('store.product.details_eyebrow') }}</div>
    <h2>{{ __('store.product.details_heading') }}</h2>
    @unless ($modules->hidden('tabs'))
@include('partials.product-tabs')
@endunless
  </section>

  <!-- reviews -->
  @unless ($modules->hidden('reviews'))
@include('partials.reviews')
@endunless

  <!-- related -->
@include('partials.you-may-also-like')
</div>

{{-- Sticky add-to-cart. Off unless switched on in Appearance → Product styles →
     Sticky Add to Cart, because the bar was missing from this page for several
     releases and turning it on for everyone at once is a change nobody asked for.

     Values are read flat from SettingsService: ProductStyles writes each key at
     the top level, which is the same place the grid components read from.

     The markup shape is fixed by two things that already exist — kbb-product.css
     styles .stickybar and pdp.js observes #stickybar and writes into
     #stickyPrice's .now span on variant change. Neither was changed here. --}}
@php
    $sticky = (bool) $settings->get('sticky_show', false);
@endphp
@if ($sticky)
@php
    $stDev   = (string) $settings->get('sticky_devices', 'phone');
    $stTrig  = (string) $settings->get('sticky_trigger', 'button');
    $stClass = 'stickybar sb-' . ($stDev === 'all' ? 'all' : ($stDev === 'phone_tablet' ? 'pt' : 'ph'));
    $stVars  = '--sb-bg:' . $settings->get('sticky_bg', '#FFFFFF')
             . ';--sb-btn-bg:' . $settings->get('sticky_btn_bg', '#2A2228')
             . ';--sb-btn-fg:' . $settings->get('sticky_btn_fg', '#FFFFFF')
             . ';--sb-radius:' . (int) $settings->get('sticky_radius', 99) . 'px';
@endphp
<div class="{{ $stClass }}" id="stickybar" style="{{ $stVars }}"
     data-trigger="{{ $stTrig }}" data-offset="{{ (int) $settings->get('sticky_offset', 200) }}">
  <div class="in">
    @if ($settings->get('sticky_thumb', true) || $settings->get('sticky_name', true))
    <div class="si">
        @if ($settings->get('sticky_thumb', true))
        <div class="sth" style="background:{{ $mainBg }}">{{ $gallery ? '' : Gradient::initials($brand) }}</div>
        @endif
        @if ($settings->get('sticky_name', true))
        <div style="min-width:0"><div class="snm">{{ $name }}</div></div>
        @endif
    </div>
    @endif
    @if ($settings->get('sticky_price', true))
    {{-- $kbbSaleDp, not the default: this is the SAME fils the .now span at
         the top of the page prints, and two renderings of one number on one
         document must not be quoted at two widths. Without it a page whose
         price block widened to AED 99.80 carried a sticky bar still reading
         AED 100. --}}
    <span class="sp" id="stickyPrice"><span class="now">@if ($kbbHeadline !== null){!! $kbbHeadline !!}@else{!! Money::format($price, $kbbSaleDp) !!}@endif</span></span>
    @endif
    <button class="addcart" type="button" onclick="document.querySelector('.kbb-cart-form .addcart')?.click()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/></svg> {{ $settings->get('sticky_label', __('store.product_card.add_to_cart')) }}</button>
  </div>
</div>
@endif

{{-- The share sheet the title row's button opens -- Lane QB's partial.
     @includeIf, so this page renders whether or not that file has landed.
     The comment is glued to the directive: a Blade comment's own newline is
     not swallowed, and this page is compared byte for byte. --}}@includeIf('partials.product.share-sheet')
@push('scripts')
{!! app(\App\Services\MarketingPixels::class)->viewContent($product) !!}
@endpush
@endsection
