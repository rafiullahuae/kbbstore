{{--
    Shoppable video — the rail. Phase 20, Lane V3.

    ── R3, AND NOTHING BUT R3 ───────────────────────────────────────────────

    The owner chose "R3 — Card overlaid on the poster is final" and then said
    "make sure you get 100% design the proposed design, i need exactly to match
    including every single thing." So this markup is R3's markup from
    docs/UGC-VIDEO-PREVIEWS.html, element for element, with its classes renamed
    to the ugcr- prefix this lane owns:

        .s-rail      → .ugcr-rail        .ov-card        → .ugcr-card
        .s-rail>*    → .ugcr-cell        .ov-card .bd    → .ugcr-bd
        .vt          → .ugcr-t           .ov-card .nm    → .ugcr-nm
        .vt .over    → .ugcr-over        .ov-card .row2  → .ugcr-row2
        .vt .handle  → .ugcr-handle      .pr             → .ugcr-pr
        .vt .cap     → .ugcr-cap         .pr .now/was/off→ .ugcr-now/was/off
        .vt .pbtn    → .ugcr-play        .addb           → .ugcr-add

    Every number behind them — 158/206px tiles, the 12px gap, the 16px radius,
    the 56%→72% scrim, the 10px overlay padding, the card's 6px inset and
    7px/8px padding, the 42px play disc, every font size and weight — is copied
    from that file rather than re-chosen, and resources/views/ugc/assets.blade.php
    has the side-by-side comparison that proves it.

    ── THE ONE DELIBERATE CHANGE ────────────────────────────────────────────

    The rating moves OFF the poster and INTO the product box, as a thin
    type-only bar on the brand line. He marked it up on a screenshot: "inside the
    product box, i need a thin minimal type rating bar as marked attached". So
    .ugcr-over no longer carries a rating bar at all, and .ugcr-bd is a flex row
    carrying the brand and, at its inline end, the bar. docs/UGC-RAIL-R3.md has
    the measurements; the short version is that the brand line is the only row in
    that card with spare inline space, so the bar costs the card ZERO pixels of
    height — which was a requirement, not a preference.

    ── W1 OWNS EVERY SHARED VIEW, SO NOTHING HERE TOUCHES ONE ───────────────

    resources/views/store/**, components/**, layouts/** and partials/** belong to
    another lane this round. This file and assets.blade.php are the whole of the
    storefront markup and the whole of the CSS, they are reached only from
    App\Support\Shortcodes::videos(), and they carry their own <style> rather than
    adding an entry to the vite bundle in layouts/store.blade.php. That also means
    the cost lands only on pages that actually carry a rail.

    ── EVERYTHING PRINTED IS ESCAPED, AND EVERY URL WAS CHECKED UPSTREAM ────

    Rule 5. Every operator string goes through {{ }}. Every path and every href in
    $tiles has already been through App\Services\UgcPath::stored() / ::link() in
    App\Services\Ugc\Tile — decoded and stripped BEFORE the scheme was read — and
    a value that failed is null, which draws no element. Nothing here is printed
    unescaped except App\Support\Money::format(), whose markup is a constant.
--}}
@php
    use App\Support\Money;

    /** @var array{heading: string, subheading: string, columns: string|null, tiles: array} $rail */
    /** @var array<string, mixed> $conf */

    $tiles = $rail['tiles'];
    $heading = $headingOverride ?? $rail['heading'];
    $subheading = $rail['subheading'];

    // The section may override the column choice; otherwise the setting stands.
    $vars = \App\Services\UgcSettings::cssVariables($conf, $columnsOverride ?? $rail['columns']);
    $cols = $columnsOverride ?? $rail['columns'] ?? (string) ($conf['cols'] ?? 'peek');

    $likesOn = (bool) ($conf['likes_on'] ?? false);

    /*
     * Everything the OPENED PLAYER needs, formatted once here rather than a
     * second time in JavaScript. The player is markup built in the browser, so
     * the money has to arrive as the markup App\Support\Money produces — it
     * carries a dir="ltr" span that an Arabic page needs, and reimplementing that
     * in the script would be a second currency formatter to keep in step.
     */
    $playerData = function (array $tile) use ($conf) {
        return [
            'handle' => $tile['handle'],
            'source_url' => $tile['source_url'],
            'products' => array_map(fn (array $p) => [
                'id' => $p['id'],
                'name' => $p['name'],
                'brand' => $p['brand'],
                'url' => $p['url'],
                'image' => $p['image'],
                'now_html' => \App\Support\Money::format($p['now']),
                'was_html' => (($conf['strike'] ?? true) && $p['was'] !== null) ? \App\Support\Money::format($p['was']) : '',
                'off' => \App\Support\Bidi::number('-'.$p['off'].'%'),
                'price_plain' => number_format($p['now'] / 100, 2, '.', ''),
                'rating' => number_format($p['rating'], 1),
                // 0 here is what makes the player card draw NO BAR — the same
                // rule the tile follows, applied in the one other place a rating
                // appears.
                'reviews' => ($conf['rating'] ?? true) ? $p['reviews'] : 0,
                'reviews_label' => number_format($p['reviews']),
                'rating_aria' => __('store.ugc.rating_aria', [
                    'rating' => number_format($p['rating'], 1),
                    'count' => number_format($p['reviews']),
                ]),
                'add_label' => __('store.ugc.add'),
            ], $tile['products']),
        ];
    };
@endphp
@if ($tiles !== [])
@if ($withAssets ?? true)@include('ugc.assets')@endif
<section class="kbb-ugc ugcr-sec{{ $cols === 'grid_two' ? ' is-grid' : '' }}"
         style="{{ $vars }}"
         data-ugcr-teaser="{{ ($conf['teaser'] ?? true) ? '1' : '0' }}"
         data-ugcr-max="{{ (int) ($conf['max_playing'] ?? 4) }}"
         data-ugcr-teaser-ms="{{ (int) ($conf['teaser_ms'] ?? 2500) }}"
         data-ugcr-autoplay="{{ ($conf['autoplay_open'] ?? true) ? '1' : '0' }}"
         data-ugcr-controls="{{ ($conf['controls'] ?? true) ? '1' : '0' }}"
         data-ugcr-sound="{{ ($conf['sound_on_open'] ?? false) ? '1' : '0' }}"
         data-ugcr-like="{{ $likesOn ? ($likeUrl ?? '') : '' }}">
  @if ($heading !== '' || $subheading !== '')
    <header class="ugcr-head">
      @if ($heading !== '')<h2 class="ugcr-h2">{{ $heading }}</h2>@endif
      @if ($subheading !== '')<p class="ugcr-sub">{{ $subheading }}</p>@endif
    </header>
  @endif

  <div class="ugcr-rail" role="list">
    @foreach ($tiles as $tile)
      @php
        $p = $tile['products'][0] ?? null;
        /*
         * The tile's own box, from the columns. NEVER MEASURED: two tests in this
         * repo forbid the element-measuring APIs by name, and the reserved ratio
         * is what keeps layout shift at zero before any media has loaded.
         *
         * REDUCED BY THE GCD, and that is not cosmetic. 360/640 and 9/16 are the
         * same ratio and the browser lays them out identically, but
         * getComputedStyle reports the string it was given — so the
         * element-by-element run against R3 reported `aspectRatio: 9 / 16` vs
         * `360 / 640` as a delta on every tile. Reducing makes the computed value
         * literally identical for the ordinary 9:16 clip, and still reserves the
         * true box for a clip that is not 9:16.
         */
        $w = (int) $tile['width'];
        $h = (int) $tile['height'];
        $g = ($w > 0 && $h > 0) ? \App\Services\Ugc\Tile::gcd($w, $h) : 0;
        $ratio = $g > 0 ? ($w / $g).' / '.($h / $g) : '9 / 16';
      @endphp
      <div class="ugcr-cell" role="listitem">
        <div class="ugcr-t{{ $p ? ' has-card' : '' }}"
             style="aspect-ratio:{{ $ratio }}"
             data-ugcr-tile
             data-ugcr-slug="{{ $tile['slug'] }}"
             @if ($tile['teaser'])data-ugcr-teaser-src="{{ $tile['teaser'] }}"@endif
             @if ($tile['src'])data-ugcr-src="{{ $tile['src'] }}"@endif
             data-ugcr-poster="{{ $tile['poster'] }}"
             role="button" tabindex="0"
             aria-label="{{ __('store.ugc.play') }}: {{ $tile['title'] !== '' ? $tile['title'] : $tile['caption'] }}">

          {{-- The poster, and the only thing fetched before anybody interacts.

               NO width/height ATTRIBUTES, and that is deliberate rather than an
               omission. They are the right habit on an ordinary flowed image, and
               here they are inert: this element is position:absolute, inset:0,
               width/height 100%, object-fit:cover, so its box comes entirely from
               the tile's own aspect-ratio and its intrinsic ratio has no layout
               effect at all. What they DID do was report `aspect-ratio: auto 360 /
               640` where R3 reports `auto`, on every tile of the
               element-by-element run — a delta bought for nothing.

               The reserved box is the tile's `aspect-ratio`, set from the stored
               width and height a line above, which is what actually keeps layout
               shift at zero before any media has loaded. --}}
          <img class="ugcr-poster" src="{{ $tile['poster'] }}" alt=""
               decoding="async" loading="lazy">

          @if (($conf['badge'] ?? false) && $tile['count'] > 0)
            <span class="ugcr-badge"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6h15l-1.5 9h-12z"/></svg>{{ $tile['count'] }}</span>
          @endif

          @if ($likesOn)
            @include('ugc.likes', ['tile' => $tile])
          @endif

          <div class="ugcr-over">
            @if (($conf['handle'] ?? true) && $tile['handle'] !== '')
              <div class="ugcr-handle"><i class="ugcr-av" aria-hidden="true"></i><bdi>{{ $tile['handle'] }}</bdi></div>
            @endif
            @if (($conf['caption'] ?? true) && $tile['caption'] !== '')
              <div class="ugcr-cap">{{ $tile['caption'] }}</div>
            @endif
          </div>

          @if ($p)
            {{-- R3: the card sits ON the poster, so the rail keeps the poster's
                 height and this is the shortest variant that still sells
                 anything. One line of name over a single price/Add row, and no
                 thumbnail — the poster behind it already is the picture. --}}
            <div class="ugcr-card">
              <div class="ugcr-bd">
                <span class="ugcr-bdn">{{ $p['brand'] }}</span>
                @if (($conf['rating'] ?? true) && $p['reviews'] > 0)
                  {{-- THE ONE DELIBERATE CHANGE TO R3.

                       A product with NO REVIEWS gets NO BAR — not an empty star
                       row, which reads as "rated badly", and not a zero, which
                       reads as "rated zero". A product can be three years old
                       and simply unreviewed, and the shop already draws this
                       line: partials/home/grid.blade.php gates its New badge on
                       `! $p->review_count`.

                       role="img" with ONE label, and the glyph aria-hidden: a
                       star character read aloud on its own is noise, and the
                       label carries the count even on the narrow tile where the
                       container query hides it. --}}
                  <span class="ugcr-rate" role="img"
                        aria-label="{{ __('store.ugc.rating_aria', ['rating' => number_format($p['rating'], 1), 'count' => number_format($p['reviews'])]) }}">
                    <b class="ugcr-sc"><bdi>{{ number_format($p['rating'], 1) }}</bdi></b>
                    <i class="ugcr-star" aria-hidden="true">&#9733;</i>
                    <span class="ugcr-rc"><bdi>({{ number_format($p['reviews']) }})</bdi></span>
                  </span>
                @endif
              </div>
              <a class="ugcr-nm" href="{{ $p['url'] }}">{{ $p['name'] }}</a>
              <div class="ugcr-row2">
                <span class="ugcr-pr">
                  <span class="ugcr-now">{!! Money::format($p['now']) !!}</span>
                  @if (($conf['strike'] ?? true) && $p['was'] !== null)
                    <span class="ugcr-was">{!! Money::format($p['was']) !!}</span>
                    <span class="ugcr-off"><bdi>{{ \App\Support\Bidi::number('-'.$p['off'].'%') }}</bdi></span>
                  @endif
                </span>
                {{-- data-kbb-add is the storefront's own add-to-cart hook,
                     delegated on document in resources/js/kbb/cart.js. Reusing it
                     rather than writing a second cart call is what keeps this rail
                     out of the cart's business entirely. --}}
                <button class="ugcr-add" type="button"
                        data-kbb-add="{{ $p['id'] }}"
                        data-price="{{ number_format($p['now'] / 100, 2, '.', '') }}"
                        data-name="{{ $p['name'] }}">{{ __('store.ugc.add') }}</button>
              </div>
            </div>
          @endif

          <button class="ugcr-play" type="button" aria-label="{{ __('store.ugc.play') }}" data-ugcr-play><span></span></button>
        </div>
        {{-- What the OPENED PLAYER needs, and nothing else: every product on this
             clip, already formatted. Inline JSON rather than a second request,
             because the alternative is an /api call per tap on a page that has
             already paid for this data once.

             JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT is not
             decoration: without JSON_HEX_TAG a product named with a literal
             "</script>" would close this block early and the rest of the payload
             would be parsed as HTML. With it, `<` can never appear as itself
             inside the element at all, so no product name, brand or price markup
             can terminate it. The player then escapes every one of these values
             again on the way into the DOM. --}}
        <script type="application/json" data-ugcr-data>@json($playerData($tile), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)</script>
      </div>
    @endforeach
  </div>
</section>
@endif
