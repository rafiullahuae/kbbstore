{{--
    Product page — ported from the finalized kbb-product.html.

    Differences from the earlier port, all present in the finalized design:
    the rating capsule (.sr-capbar), the "12k+ sold" line, bundle options with
    image swatches and savings tags, the dispatch cutoff, the review filters and
    load-more, and the details tabs.
--}}
@extends('layouts.store')
@php
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
    $mainBg  = ! empty($gallery[0]['image'])
        ? "#fff url('" . e($gallery[0]['image']) . "') center/contain no-repeat"
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

@push('styles')
    @vite('resources/css/kbb/kbb-product.css')
    <style>{!! $reviewsCss !!}</style>
@endpush

@section('content')
<div class="wrap">
  <div class="crumb"><a href="{{ Url::to('/') }}">{{ __('store.breadcrumb.home') }}</a> / <a href="{{ $product->categories->first()?->url() ?? Url::to('/shop/') }}">{{ $product->categories->first()?->t('name') ?? __('store.breadcrumb.shop') }}</a> / {{ $name }}</div>
  <div class="pdp">
    <!-- gallery -->
    @include('partials.product-gallery')

  <!-- buy box -->
    <div class="buybox">
      @if ($brand)<div class="bb-brand" id="bbBrand">{{ $brand }}</div>@endif
      <h1 class="bb-title" id="bbTitle">{{ $name }}</h1>
      @php
          $badgeHeart  = (bool) $settings->get('review_badge_heart', true);
          $badgeAvg    = (bool) $settings->get('review_badge_avg', true);
          $badgeCount  = (bool) $settings->get('review_badge_count', true);
          $badgeSold   = (bool) $settings->get('review_badge_sold', true);
          $badgeLabel  = str_replace('{n}', number_format($rcount), (string) $settings->get('review_badge_label', __('store.product.review_badge_label')));
          $badgeColour = (string) $settings->get('review_badge_colour', '#E8A33D');
      @endphp
      <div class="cap-area" id="capArea">
          @if ($showCap && $rcount)
              <a class="{{ $modules->classFor('capsule') }} sr-capbar" href="#sr">
                  @if ($badgeHeart)<span class="sr-cap-heart">&#10084;</span>@endif
                  <span class="sr-cap-stars" style="color:{{ $badgeColour }}">★★★★★</span>
                  @if ($badgeAvg)<span class="sr-cap-avg">{{ number_format($rating, 1) }}</span>@endif
                  @if ($badgeCount)<span class="sr-cap-count">{{ $badgeLabel }}</span>@endif
              </a>
          @endif
      </div>
      @if ($rcount)
      <div class="{{ $modules->classFor('rating') }} bb-rate" id="bbRate" @unless ($showRate) style="display:none" @endunless><span class="stars" id="bbStars" style="color:{{ $badgeColour }}">@for ($i = 1; $i <= 5; $i++){!! $i <= round($rating) ? '<span class="f">★</span>' : '<span>★</span>' !!}@endfor</span> @if ($badgeAvg)<span>{{ number_format($rating, 1) }}</span> @endif @if ($badgeCount)· <a href="#sr">{{ $badgeLabel }}</a>@endif @if ($badgeSold && $product->total_sales > 999) · <span style="color:var(--green);font-weight:600">{{ __('store.product.sold_thousands', ['count' => round($product->total_sales / 1000)]) }}</span>@endif</div>
      @endif
      <div class="bb-price" id="bbPrice">{{-- The current price is always inside .now, on sale or not.
        Without it an ordinary price rendered bare and then jumped in size the
        moment a bundle was selected, because the update adds the span. --}}
        {{-- The struck price and the live price are quoted at ONE precision,
             chosen so the two are actually different numbers. Whole dirhams is
             this store's display setting, and at whole dirhams a markdown from
             AED 100.00 to AED 99.80 printed `AED 100` inside .now and `AED 100`
             inside the <s> beside it. See Money::decimalsToDistinguish(): it
             answers 0 — no change at all — for every markdown the rounded form
             can already tell apart. --}}
        @php
        /* $kbbWas, NOT `(int) $product->price`. A variable product's markdown
           lives on its VARIATIONS and `products.price` is NULL on the parent,
           so the moment Product::isOnSale() started telling the truth about one
           of them, the <s> below would have printed `AED 0` beside a real
           from-price. Product::compareAtPrice() is what this page advertised
           the day before the sale opened -- the lowest REGULAR price across the
           options -- and it is the same figure the tile's badge and the "On
           sale" facet compare against. For every product that carries a price
           of its own it IS `(int) $product->price`, unchanged.

           BOTH STATEMENTS IN THE ONE @php REGION, and that is not tidiness. A
           second `@php` line of its own contributes its indentation to the
           rendered page and StorefrontEnglishUnchangedTest compares BYTES --
           the same trap components/product-card.blade.php records for computing
           the range beside the markup instead of up here. */
        $kbbWas = (int) $product->compareAtPrice();
        $kbbSaleDp = $onSale ? Money::decimalsToDistinguish($kbbWas, $price) : null;
        @endphp
        <span class="now">@if ($kbbHeadline !== null){!! $kbbHeadline !!}@else{!! Money::format($price, $kbbSaleDp) !!}@endif</span>
        @if ($onSale)
            <s>{!! Money::format($kbbWas, $kbbSaleDp) !!}</s>
            @if ($off)<span class="off">{{ \App\Support\Bidi::number('-' . $off . '%') }}</span>@endif
        @endif
      </div>
      @if ($vatLine)<div class="{{ $modules->classFor('vat') }} bb-vat">{{ $vatLine }}</div>@endif
      @if ($product->short_description)<p class="{{ $modules->classFor('short') }} bb-desc">{{ $product->t('short_description') }}</p>@endif

      <form class="cart kbb-cart-form" data-product_id="{{ $product->id }}" method="post">
        @csrf
        @if ($isVar)
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
            @endphp
            {{-- Selected by identity, not by index: the highlighted row is the
                 first one that can be bought, which is the same row the hidden
                 field below is set to. `0 === $n` selected nothing at all when
                 option 0 was sold out. --}}
            <div class="variant{{ $buyable && $v->is($buyable) ? ' on' : '' }}{{ $oos ? ' oos' : '' }}" data-i="{{ $n }}" data-vid="{{ $v->id }}" data-qty="1" data-price="{{ Money::plain($vsale, $vdp) }}">
              @if ($v->image)<span class="vsw" style="background-image:url('{{ $v->image }}')"></span>@else<span class="vr"></span>@endif<span class="vn">{{ $v->label() ?: __('store.product.option_fallback', ['number' => $n + 1]) }}</span><span class="vp">@if ($vsale < $vreg)<s>{!! Money::format($vreg, $vdp) !!}</s>@endif{!! Money::format($vsale, $vdp) !!}</span>@if ($oos)<span class="vtag sold">{{ __('store.product.sold_out_tag') }}</span>@elseif ($v->tag)<span class="vtag">{{ $v->tag }}</span>@elseif ($voff)<span class="vtag">{{ __('store.product.save_percent', ['percent' => $voff]) }}</span>@endif
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
            @php
                $bdp = $b['saved'] > 0
                    ? Money::decimalsToDistinguish((int) $b['was'], (int) $b['total'])
                    : null;
                $bdp = max($bdp ?? Money::displayDecimals(), $kbbSaleDp ?? Money::displayDecimals());
            @endphp
            <div class="variant{{ 0 === $n ? ' on' : '' }}" data-i="{{ $n }}" data-qty="{{ $b['qty'] }}" data-price="{{ Money::plain($b['total'], $bdp) }}">
              <span class="vr"></span><span class="vn">{{ $b['label'] }}</span><span class="vp">@if ($b['saved'] > 0)<s>{!! Money::format($b['was'], $bdp) !!}</s>@endif{!! Money::format($b['total'], $bdp) !!}</span>@if ($b['tag'])<span class="vtag">{{ $b['tag'] }}</span>@endif
            </div>
          @endforeach
        </div>
        @endif

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
        <div class="{{ $modules->classFor('stockline') }} stockline{{ $out ? ' out' : '' }}"><span class="dot"></span>
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

        <div class="buyrow">
          <div class="{{ $modules->classFor('quantity') }} qty"><button type="button" data-q="-1">−</button><span id="qtyVal">1</span><button type="button" data-q="1">+</button><input type="hidden" name="quantity" id="qtyInput" value="1"></div>
          <button class="addcart" id="mainAdd" type="submit" @disabled($out)><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/></svg> {{ $out ? __('store.product.sold_out_tag') : __('store.product_card.add_to_cart') }}</button>
        </div>
        @unless ($modules->hidden('buynow'))
<button class="buynow" type="submit" data-buynow="1" @disabled($out)>{{ __('store.product.buy_now') }}</button>
@endunless
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
      <div class="{{ $modules->classFor('trust') }} trust">
        @if ($trustAuthentic !== null)<div class="ti"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2 4 5v6c0 5 3.5 8 8 11 4.5-3 8-6 8-11V5z"/><path d="m9 12 2 2 4-4"/></svg> {{ $trustAuthentic }}</div>@endif
        @if ($trustDelivery !== '')<div class="ti"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7h13v10H3z"/><path d="M16 10h4l1 3v4h-5z"/><circle cx="7" cy="18" r="1.6"/><circle cx="18" cy="18" r="1.6"/></svg> {{ $trustDelivery }}</div>@endif
        @if ($trustReturns !== '')<div class="ti"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9"/><path d="M3 5v4h4"/></svg> {{ $trustReturns }}</div>@endif
        <div class="ti"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/></svg> {{ __('store.product.trust_pay_later') }}</div>
      </div>
      <div class="{{ $modules->classFor('paychips') }} paychips"><span>Tabby</span><span>Tamara</span><span>Visa</span><span>Mastercard</span><span>Apple Pay</span><span>{{ __('store.footer.pay_cod') }}</span></div>
    </div>
  </div>

  @unless ($modules->hidden('fbt'))
@include('partials.fbt')
@endunless

  <!-- details tabs -->
  <section class="sec">
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
  @if ($related->isNotEmpty())
  <section class="sec">
    <div class="eyebrow">{{ __('store.product.related_eyebrow') }}</div>
    <h2>{{ __('store.product.related_heading') }}</h2>
    <div class="{{ $modules->classFor('related') }} rel" id="related">@foreach ($related as $item)<x-product-card :product="$item" />@endforeach</div>
  </section>
  @endif
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

@push('scripts')
{!! app(\App\Services\MarketingPixels::class)->viewContent($product) !!}
@endpush
@endsection
