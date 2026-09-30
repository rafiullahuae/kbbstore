<?php

declare(strict_types=1);

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Locale;
use Illuminate\Support\Str;

/**
 * =============================================================================
 * THE PRICE BLOCK MUST AGREE WITH THE ROW THE SHOPPER JUST PRESSED
 * =============================================================================
 *
 * ── THE DEFECT, AS IT LOOKED ON THE SHOP ────────────────────────────────────
 *
 * Measured in Chromium at 390px on /product/pdp-heartleaf-toner/ before this
 * change, pressing the 2-pack row:
 *
 *     the row said      AED 149   AED 140   Save 6%
 *     the block said    AED 99    AED 140   -25%
 *                       ▲ stale             ▲ stale
 *
 * pdp.js wrote the tier's total into `.now` and left the `<s>` and the `.off`
 * badge exactly as the server had rendered them — the PRODUCT's own sale pair.
 * The strike was untidy. The badge was a FALSE CLAIM ABOUT MONEY: -25% printed
 * beside a tier discounted 6%, on the page where the shopper decides.
 *
 * ── WHY THE OBVIOUS FIX IS WRONG, WHICH IS WHAT THIS FILE IS REALLY FOR ─────
 *
 * The obvious fix is "copy the row's own struck figure". BundleService computes
 * `was = qty * effectivePrice` — the SALE price — so the 1-UNIT tier has
 * `was === total`, `saved` of 0 and no struck figure at all. Copy that row
 * literally and the view the page OPENS ON loses "AED 99 / -25%", which is the
 * product's own markdown and the one number the page is really about.
 *
 * So the two `was` figures are different things and both are true: the
 * PRODUCT's (what it cost before the sale) and the TIER's (what N cost without
 * the bundle discount). A row with a saving of its own means the second; a row
 * without one falls back to the first. `falls the 1-unit row back to the
 * product own markdown` below is the case that goes red under the naive fix,
 * and it is the only one that does.
 *
 * ── THE TWO THINGS THE COORDINATOR ASKED BE SETTLED, NOT ASSUMED ────────────
 *
 *  1. THE STICKY BAR carries a `.now` and nothing else, by design. Measured
 *     before the change: its figure already followed the tier (AED 74 ->
 *     AED 140) and it has neither strike nor badge, so it had nothing to go
 *     stale. The risk was the reverse — that a fix would CREATE the problem
 *     there by growing the two spans. `does not give the sticky bar a strike it
 *     never had` pins that it does not.
 *
 *  2. THE SCHEMA.ORG OFFER must never be fed a price the shopper picked.
 *     It is built server-side by App\Support\Seo::render() into <head>, long
 *     before the buy column exists, and the script writes only into #bbPrice
 *     and #stickyPrice. Measured: after pressing the 2-pack, offers, og:price
 *     and the JSON-LD byte length were identical (5424 bytes both times).
 *     `keeps the schema offer out of reach of the selected price` pins the
 *     structure that makes that true rather than the observation that it was.
 *
 * ── AND ONE THING THE ENGLISH PAGE CANNOT SHOW ──────────────────────────────
 *
 * The badge TEXT is composed on the server so that App\Support\Bidi::number()
 * can isolate it. In the default locale that method returns the token
 * unchanged, so the whole difference is invisible here and visible only on
 * `/ar` — which is why the last case renders the mirror.
 */
function tierBrand(): Brand
{
    return Brand::create(['name' => 'Anua', 'slug' => 'anua-'.Str::random(5)]);
}

/** A simple product on sale at AED 99 -> AED 74.25, which is what the shop sells. */
function tierProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'tier-'.Str::random(8),
        'name' => 'Heartleaf 77% Soothing Toner 250ml',
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'sale_price' => 7425,
        'stock_status' => 'instock',
        'image' => '/img/heartleaf.jpg',
        'short_description' => 'A gentle daily toner built around 77% heartleaf extract.',
        'description' => '<p>Anua built this around a single idea.</p>',
    ], $overrides));
}

function tierPage(Product $product): string
{
    return test()->get('/product/'.$product->slug.'/')->assertOk()->getContent();
}

/** `str_contains`, never `toContain($needle, $why)` — Pest's is variadic. */
function tierHas(string $html, string $needle, string $why): void
{
    expect(str_contains($html, $needle))->toBeTrue($why.' — missing: '.$needle);
}

function tierLacks(string $html, string $needle, string $why): void
{
    expect(str_contains($html, $needle))->toBeFalse($why.' — still present: '.$needle);
}

/**
 * Every `<div class="variant …">` opening tag on the page, in document order.
 *
 * The optional group is not decoration: `variant[^"]*` also matches the
 * CONTAINER, `<div class="variants" id="variants">`, which made row 0 the strip
 * itself and every attribute read come back absent.
 */
function tierRows(string $html): array
{
    preg_match_all('#<div class="variant(?: [^"]*)?"[^>]*>#', $html, $m);

    return $m[0];
}

/** The rendered text of the Nth variant row, tags stripped. */
function tierRowText(string $html, int $n): string
{
    preg_match_all('#<div class="variant(?: [^"]*)?"[^>]*>(.*?)</div>#s', $html, $m);

    expect($m[1])->toHaveCount(3, 'the three default bundle tiers');

    return trim(preg_replace('/\s+/', ' ', strip_tags($m[1][$n], '<s>')));
}

function tierAttr(string $tag, string $name): string
{
    preg_match('#\s'.preg_quote($name, '#').'="([^"]*)"#', $tag, $m);

    return $m[1] ?? '__absent__';
}

function tierJs(): string
{
    return (string) file_get_contents(base_path('resources/js/kbb/pdp.js'));
}

/* ═══════════ 1. every row hands the block a pair, and it is the row's ═══════ */

it('gives every bundle row the struck figure and the badge it prints', function () {
    $html = tierPage(tierProduct(['brand_id' => tierBrand()->id]));
    $rows = tierRows($html);

    expect($rows)->toHaveCount(3, 'three default tiers');

    /* Tier 2 and tier 3 have savings of their own, so the pair each hands over
       is the TIER's, not the product's: the 2-pack is 2 x the SALE price,
       AED 148.50, down to AED 140.85 at the 5% tier rate — which the row prints
       as AED 149 / AED 140 and the badge rounds to 6%. The figures are not
       hard-coded below; each is compared against what its own row printed in
       the same render, so a tier table edited in the admin cannot make this
       case wrong about the shop. */
    foreach ([1, 2] as $n) {
        $was = tierAttr($rows[$n], 'data-was');
        $off = tierAttr($rows[$n], 'data-off');
        $text = tierRowText($html, $n);

        expect($was)->not->toBe('', "row $n has a saving of its own, so it carries its own struck figure");
        tierHas($text, $was, "row $n's data-was is the figure the row prints");

        expect($off)->toMatch('/^\D*-?\d+%\D*$/u', "row $n's badge is a percentage");

        // The percent in the attribute is the percent BundleService computed
        // for this tier, which is also what the row's tag says when it has one.
        preg_match('/(\d+)/', $off, $pm);
        expect((int) $pm[1])->toBeGreaterThan(0, "row $n is discounted, so the badge says so");
    }
});

/* ═══════════ 2. THE CASE THE NAIVE FIX FAILS ═══════════════════════════════ */

it('falls the 1-unit row back to the product own markdown', function () {
    /*
     * MUTATION: make the template copy the row's own pair literally —
     *     $bWas = $bHasOwn ? Money::plain((int) $b['was'], $bdp) : '';
     * — and this case goes red with data-was="" on row 0, because
     * BundleService gives the 1-unit tier `was === total` and `saved` of 0.
     * Nothing else in this file moves. That is the whole reason the fix is
     * three lines rather than one.
     */
    $html = tierPage(tierProduct(['brand_id' => tierBrand()->id]));
    $rows = tierRows($html);

    $was = tierAttr($rows[0], 'data-was');
    $off = tierAttr($rows[0], 'data-off');

    expect($was)->not->toBe('', 'the 1-unit row must still carry the product\'s own was-price');
    expect($off)->not->toBe('', 'and its own discount badge');

    // AED 99 struck, 25% off — the product's sale, not the tier's (which is 0).
    expect($was)->toContain('99');
    preg_match('/(\d+)/', $off, $pm);
    expect((int) $pm[1])->toBe(25, 'the product is 25% off; the 1-unit tier is 0% off');

    // And the row itself prints no struck figure, which is exactly why the
    // attribute cannot be copied from it.
    expect(tierRowText($html, 0))->not->toContain('<s>');
});

it('leaves the pair empty on a product that is not on sale', function () {
    // No sale and no tier saving on row 0 means there is genuinely nothing to
    // strike — so the block must be told to hide its spans, not to invent one.
    $html = tierPage(tierProduct(['sale_price' => null, 'brand_id' => tierBrand()->id]));
    $rows = tierRows($html);

    expect(tierAttr($rows[0], 'data-was'))->toBe('', 'nothing was ever struck at 1 unit');
    expect(tierAttr($rows[0], 'data-off'))->toBe('', 'and nothing is discounted');
    expect(tierAttr($rows[1], 'data-was'))->not->toBe('', 'but the 2-pack still saves 5%');
});

/* ═══════════ 3. variations carry their own pair too ════════════════════════ */

it('gives every variation row its own struck figure', function () {
    $parent = tierProduct([
        'type' => 'variable',
        // NULL on the parent, exactly as WooCommerce leaves it: the figures
        // live on the variations, so isOnSale() is false on the product and
        // the server strikes nothing. That is what makes `mayCreate` matter.
        'price' => null,
        'sale_price' => null,
        'brand_id' => tierBrand()->id,
    ]);

    $size = Attribute::updateOrCreate(['slug' => 'size'], [
        'name' => 'Size', 'is_variation_axis' => true, 'is_filterable' => true, 'position' => 10,
    ]);

    /*
     * THE 30ml IS NOT MARKED DOWN, AND IT IS LOAD-BEARING. VariantPricing reads
     * the whole set: with every variation on sale the parent's own headline is a
     * discounted RANGE and the server strikes a figure after all. The shop's
     * fixture has one full-price size, so the parent is not on sale and the
     * block opens with a bare `.now` -- which is the shape `mayCreate` is for.
     */
    foreach ([['30ml', 6900, null], ['50ml', 9900, 7920], ['100ml', 16900, 12675]] as $n => [$label, $regular, $sale]) {
        $value = AttributeValue::updateOrCreate(
            ['attribute_id' => $size->id, 'slug' => Str::slug($label)],
            ['name' => $label, 'position' => $n]
        );

        ProductVariant::create([
            'product_id' => $parent->id,
            'price' => $regular,
            'sale_price' => $sale,
            'stock_status' => 'instock',
            'position' => $n,
        ])->attributeValues()->sync([$value->id]);
    }

    $html = tierPage($parent->fresh());

    preg_match_all('#<div class="variant(?: [^"]*)?"[^>]*data-vid="[^"]*"[^>]*>#', $html, $m);
    expect($m[0])->toHaveCount(3, 'three variations');

    // The 30ml is full price, so it carries no pair at all.
    expect(tierAttr($m[0][0], 'data-was'))->toBe('', 'a full-price variation strikes nothing');
    expect(tierAttr($m[0][0], 'data-off'))->toBe('', 'and claims no discount');

    foreach ([$m[0][1], $m[0][2]] as $tag) {
        expect(tierAttr($tag, 'data-was'))->not->toBe('', 'a marked-down variation carries its regular price');
        preg_match('/(\d+)/', tierAttr($tag, 'data-off'), $pm);
        expect((int) ($pm[1] ?? 0))->toBeGreaterThan(0, 'and the percent it is down by');
    }

    /*
     * AND THE BLOCK HAS TO BE ALLOWED TO GROW THE SPANS HERE. Measured before
     * `mayCreate`: picking the 100ml gave a flat AED 127 in the block while the
     * row directly beneath it read AED 169 / AED 127. The server cannot have
     * rendered an `<s>` for it to update, because the parent is not on sale.
     */
    tierLacks(
        substr($html, (int) strpos($html, '<div class="bb-price"'), 300),
        '<s>',
        'the parent has no sale of its own, so the server strikes nothing'
    );
});

/* ═══════════ 4. what the script does with the pair ═════════════════════════ */

it('escapes both figures on the way out of the data attribute', function () {
    /*
     * MUTATION: drop either escapeHtml() and this goes red. The attributes
     * carry `currency_symbol`, which the admin stores as FREE TEXT, and the
     * HTML parser decodes entities while building the attribute — so what
     * `dataset.was` hands back is live characters, not Blade's escape.
     * CLAUDE.md rule 5.
     */
    $js = tierJs();

    tierHas($js, "escapeHtml(variant.dataset.was || '')", 'the struck figure is escaped');
    tierHas($js, "escapeHtml(variant.dataset.off || '')", 'the badge is escaped');

    // Neither may be read raw anywhere else.
    expect(substr_count($js, 'dataset.was'))->toBe(1, 'one reader for the struck figure');
    // Qualified with `variant.` deliberately: a bare `dataset.off` also counts
    // the sticky bar's `dataset.offset`, which is a scroll distance in pixels
    // and not a price at all.
    expect(substr_count($js, 'variant.dataset.off'))->toBe(1, 'one reader for the badge');
});

it('writes the pair into the two spans the stylesheet already lays out', function () {
    $js = tierJs();

    // Ledger's order: <s> above .now, badge after it. An element created the
    // other way round would be styled correctly and read backwards.
    tierHas($js, "live.insertAdjacentHTML('beforebegin', '<s></s>')", 'the strike goes above the live figure');
    tierHas($js, "live.insertAdjacentHTML('afterend', '<span class=\"off\"></span>')", 'the badge goes after it');

    /*
     * MUTATION: swap these two for the `hidden` attribute and both spans stay
     * on screen — kbb-product.css gives each a `display` through a class
     * selector, which outranks the User-Agent rule behind [hidden].
     */
    tierHas($js, "struck.style.display = was ? '' : 'none'", 'an empty figure hides its span');
    tierHas($js, "badge.style.display = off ? '' : 'none'", 'an empty badge hides its span');
});

/* ═══════════ 5. the sticky bar — settled, not assumed ══════════════════════ */

it('does not give the sticky bar a strike it never had', function () {
    /*
     * Measured before the change: the bar's `.now` ALREADY followed the tier
     * (AED 74 -> AED 140) and it carries neither strike nor badge, so it had
     * nothing to go stale. It is passed `false` so that fixing the block does
     * not hand the bar the problem.
     *
     * MUTATION: pass `true` on the #stickyPrice line and this goes red.
     */
    $js = tierJs();

    tierHas($js, "setPrice(document.getElementById('bbPrice'), price, was, off, true)", 'the block may grow the spans');
    tierHas($js, "setPrice(document.getElementById('stickyPrice'), price, was, off, false)", 'the bar may not');

    // And the server does not render them there either, so `false` is the
    // truth about the bar rather than a restriction on it.
    $settings = app(SettingsService::class);
    $settings->set('sticky_show', true);
    $settings->set('sticky_price', true);

    $html = tierPage(tierProduct(['brand_id' => tierBrand()->id]));
    $bar = substr($html, (int) strpos($html, 'id="stickybar"'));

    tierHas($bar, 'id="stickyPrice"', 'the bar prints a price');
    tierLacks(substr($bar, 0, (int) strpos($bar, '</span>') + 7), '<s>', 'and only one figure');
});

/* ═══════════ 6. the crawler's price is out of the script's reach ═══════════ */

it('keeps the schema offer out of reach of the selected price', function () {
    /*
     * A crawler must not read a price the shopper picked. Measured: after
     * pressing the 2-pack, the offers block, og:price and the JSON-LD byte
     * length were byte-identical (5424 both times). This case pins the reason
     * rather than the observation.
     */
    $settings = app(SettingsService::class);
    $settings->set('sticky_show', true);

    $product = tierProduct(['brand_id' => tierBrand()->id]);
    $html = tierPage($product);

    $ld = (int) strpos($html, 'application/ld+json');
    $block = (int) strpos($html, 'id="bbPrice"');

    expect($ld)->toBeGreaterThan(0, 'the page carries structured data');
    expect($ld)->toBeLessThan($block, 'it is built in <head>, before the buy column exists');

    /*
     * EVERY setPrice() CALL IN THE FILE, AND THERE ARE TWO. This is the
     * assertion that would go red if a later lane pointed the writer at the
     * structured data, or at anything else: it does not merely check that
     * `schema` is absent from the id, it enumerates the targets.
     */
    $js = tierJs();
    preg_match_all("#setPrice\(document\.getElementById\('([A-Za-z]+)'\)#", $js, $m);

    expect($m[1])->toBe(['bbPrice', 'stickyPrice'], 'the script writes into these two elements and no others');
    tierLacks($js, 'ld+json', 'and never looks at the structured data');
    tierLacks($js, 'itemprop', 'nor at a microdata attribute');

    $head = substr($html, 0, $block);
    tierLacks($head, 'id="bbPrice"', 'the head carries no copy of the block');
    tierLacks($head, 'id="stickyPrice"', 'nor of the bar');

    /*
     * The structured data quotes the product's own unit price and no tier
     * total. 2 x AED 74.25 at the 5% tier rate is AED 140.85, which is the
     * figure the 2-pack row prints — a crawler must never be shown it, because
     * it is a price conditional on buying two.
     */
    $json = substr($html, $ld, 3000);
    tierHas($json, '74.25', 'the crawler is given the unit price');
    tierLacks($json, '140.85', 'and never a bundle total');
});

/* ═══════════ 7. the badge is composed on the server, for /ar's sake ════════ */

it('wraps the badge in isolates on the Arabic mirror', function () {
    /*
     * WHY THE BADGE TEXT IS BUILT IN THE TEMPLATE AND NOT IN THE BROWSER.
     *
     * App\Support\Bidi::number() wraps a signed number in LRI … PDI so that
     * `-6%` does not repaint as `6%-` inside a right-to-left paragraph. A
     * string assembled in JavaScript as `'-' + n + '%'` would carry no
     * isolates, and the defect would be invisible to anybody reading the
     * English page. So the server composes the whole word and the script only
     * moves it.
     *
     * MUTATION: build the badge in pdp.js instead — or drop Bidi::number() from
     * the two @php blocks — and this goes red on /ar while every other case in
     * this file stays green. It is also why `data-off` holds `-6%` and not `6`.
     */
    $s = app(SettingsService::class);
    $s->set(Locale::SETTING_ENABLED, '1');
    $s->set(Locale::SETTING_RTL, '1');
    $s->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    $product = tierProduct(['brand_id' => tierBrand()->id]);
    $html = test()->get('/ar/product/'.$product->slug.'/')->assertOk()->getContent();
    $rows = tierRows($html);

    expect($rows)->toHaveCount(3, 'the Arabic page draws the same three tiers');

    foreach ($rows as $n => $tag) {
        $off = tierAttr($tag, 'data-off');

        expect($off)->not->toBe('', "row $n still claims its discount on /ar");
        expect($off)->toStartWith("\u{2066}", "row $n's badge opens with LRI");
        expect($off)->toEndWith("\u{2069}", "row $n's badge closes with PDI");
    }

    // And English is left alone: Bidi::number() returns the token unchanged in
    // the default locale, so no English byte moves for a rendered difference
    // that does not exist there.
    $s->set(Locale::SETTING_ENABLED, '0');
    $s->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    $plain = tierAttr(tierRows(tierPage($product))[1], 'data-off');
    expect($plain)->toMatch('/^-\d+%$/', 'the English attribute is the bare token');
    // `tierLacks`, not `->not->toContain($needle, $why)`: that form can never
    // fail, and ExpectationsThatCannotFailTest sweeps the whole suite for it.
    tierLacks($plain, "\u{2066}", 'the English attribute carries no isolate');
});
