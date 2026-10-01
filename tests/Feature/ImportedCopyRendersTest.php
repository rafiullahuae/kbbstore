<?php

declare(strict_types=1);

/*
 * =============================================================================
 * IMPORTED WOOCOMMERCE COPY, AS THE OWNER SAW IT AFTER HIS IMPORT  (Lane PI-A)
 * =============================================================================
 *
 * Two reports from his first look at the shop with his real catalogue in it.
 *
 * ── ITEM 1: "POPULAR RIGHT NOW" PRINTED PRICE MARKUP AS LETTERS ─────────────
 *
 * Focus the search field and every product row read
 *
 *     Anua · <span class="woocommerce-Price-amount amount" dir="ltr"><span
 *     class="woocommerce-Price-currencySymbol" dir="auto">AED</span>80</span>
 *
 * Store\SearchController::starter() put Money::format() -- the HTML Blade
 * prints with {!! !!} -- into JSON, and search.js escapes every JSON field
 * before it reaches the DOM, as it must. The suggestion and result rows in the
 * same controller already used Money::plain(). The fix is the server's; the JS
 * escape stays.
 *
 * ── ITEM 3: DESCRIPTIONS AS ONE RUN-ON SLAB, AND A LITERAL <div> ────────────
 *
 * WooCommerce keeps classic-editor post_content with BARE NEWLINES and no <p>,
 * and WordPress builds the paragraphs at display time with wpautop(). The
 * exporter ships post_content raw (class-kbb-export-stage-products.php), the
 * importer runs the allowlist over it and stores it, and the product page
 * printed it -- so a browser turned every newline into a space and the
 * Description tab read "…other functions. Medicube – PDRN Pink Collagen Capsule
 * Cream This elasticity…". RichText::forDisplay() now does what wpautop() did,
 * at render time, so every product already imported is fixed without a
 * re-import, and then runs the allowlist over the result, last.
 *
 * The short description is post_excerpt, which is HTML, and the page printed it
 * with {{ }}: "…fine lines and dryness. <div>This set is ideal for" and, for an
 * "&", the letters "&amp;". The same entity leak reached the meta description,
 * the JSON-LD and the quick-view modal through strip_tags(), which removes tags
 * and leaves entities encoded for the next escape to double.
 *
 * Every test below was run against the tree WITHOUT its fix and went red; each
 * mutation note says what was reverted and what the failure read.
 */

use App\Models\Product;
use App\Support\Money;
use App\Support\RichText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The shape WooCommerce stores for the owner's PDRN Glow Booster Set. */
const PIA_WOO_DESCRIPTION = "<strong>WHAT'S INCLUDED IN THE SET :</strong>\r\n\r\n"
    . "<strong>1. MEDICUBE AGE-R BOOSTER PRO X2 PINK</strong>\r\n"
    . "The Age-R Booster Pro is a 6-in-1 device that combines booster, microcurrent and other functions.\r\n\r\n"
    . "<strong>2. Medicube – PDRN Pink Collagen Capsule Cream</strong>\r\n"
    . "This elasticity cream delivers PDRN and collagen in capsules.\r\n\r\n"
    . "<strong>Benefits:</strong>\r\n"
    . "<ul>\r\n \t<li>Boosts absorption</li>\r\n \t<li>Improves elasticity</li>\r\n \t<li>Brightens dull skin</li>\r\n</ul>\r\n"
    . "Use the booster on clean skin.\r\nFinish with sunscreen.\r\n\r\n"
    . "<h3>About brand: medicube</h3>\r\n"
    . 'Medicube is a Korean dermatology-led brand.';

function piaProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'name' => 'PIA Glow Booster Set',
        'slug' => 'pia-' . Str::lower(Str::random(8)),
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 39900,
        'stock_status' => 'instock',
    ], $overrides));
}

/** The desktop Description panel's body, as served. */
function piaDescriptionPanel(string $html): string
{
    preg_match('#<div class="dcontent clamp">(.*?)</div>\s*<button class="readmore"#s', $html, $m);

    return $m[1] ?? '';
}

/** Every element carrying the .bb-desc class, with its tag. */
function piaBlurbs(string $html): array
{
    preg_match_all('#<(p|div) class="[^"]*\bbb-desc">(.*?)</\1><label class="bb-more"#s', $html, $m, PREG_SET_ORDER);

    return $m;
}

// ── Item 1 ──────────────────────────────────────────────────────────────────

it('gives "Popular right now" a price in text, not the WooCommerce markup', function () {
    /*
     * ON THE SHOP: `Anua · <span class="woocommerce-Price-amount amount" ...`
     * printed as letters under every product in the panel.
     *
     * MUTATION, RUN: SearchController::starter() back to Money::format() --
     * red, "price" was '<span class="woocommerce-Price-amount amount"
     * dir="ltr">…'.
     */
    Cache::forget('kbb.search.starter.products');
    $p = piaProduct(['price' => 9900, 'sale_price' => 8000, 'total_sales' => 999999]);

    $row = collect($this->getJson('/api/search/starter')->assertOk()->json('popular'))
        ->first(fn ($r) => str_contains((string) $r['url'], $p->slug));

    expect($row)->not->toBeNull()
        ->and($row['price'])->not->toContain('<')
        ->and($row['price'])->not->toContain('&')
        // The figure the shop charges -- the sale price -- exactly as the
        // typed suggestion rows beside it say it.
        ->and($row['price'])->toBe(Money::plain($p->fresh()->effectivePrice()))
        ->and($row['price'])->toContain('80');
});

it('returns only the five fields the panel draws for a popular product', function () {
    /*
     * /api/* IS UNAUTHENTICATED (CLAUDE.md). The starter list is an explicit
     * allowlist; a product row carries wc_id, sku and total_sales, and none
     * of them may ride along. Pinned here because this lane touched the map.
     */
    Cache::forget('kbb.search.starter.products');
    piaProduct(['sku' => 'SECRET-SKU', 'wc_id' => 777001, 'total_sales' => 999999]);

    $json = $this->getJson('/api/search/starter')->assertOk();

    foreach ($json->json('popular') as $row) {
        expect(array_keys($row))->toEqualCanonicalizing(['name', 'brand', 'url', 'image', 'price']);
    }

    expect($json->getContent())->not->toContain('SECRET-SKU')->not->toContain('777001');
});

// ── Item 3a: the long description ──────────────────────────────────────────

it('lays an imported description out the way WordPress did', function () {
    /*
     * ON THE SHOP: "…other functions. Medicube – PDRN Pink Collagen Capsule
     * Cream This elasticity…" -- one block, headings and paragraphs welded.
     *
     * MUTATION, RUN: ProductTabs::builtins() back to 'body' => $body -- red,
     * the panel held 0 <p> and the bare "\r\n\r\n" the importer stored.
     */
    $p = piaProduct(['description' => RichText::clean(PIA_WOO_DESCRIPTION)]);

    $panel = piaDescriptionPanel($this->get('/product/' . $p->slug . '/')->assertOk()->getContent());

    expect($panel)
        ->toContain("<p><strong>WHAT'S INCLUDED IN THE SET :</strong></p>")
        ->toContain("<p><strong>1. MEDICUBE AGE-R BOOSTER PRO X2 PINK</strong><br>\nThe Age-R Booster Pro")
        ->toContain('<p><strong>Benefits:</strong></p>')
        ->toContain("<ul>\n<li>Boosts absorption</li>")
        ->toContain("<p>Use the booster on clean skin.<br>\nFinish with sunscreen.</p>")
        ->toContain('<h3>About brand: medicube</h3>')
        // Never a <p> round a block, never a <br> beside one.
        ->not->toContain('<p><ul>')->not->toContain('<p><h3>')->not->toContain('</ul><br>')
        ->and(substr_count($panel, '<p>'))->toBe(6)
        ->and(substr_count($panel, '<li>'))->toBe(3);
});

it('turns "- " lines and single newlines into <br>, as wpautop did, without inventing a list', function () {
    $html = RichText::forDisplay("Benefits:\n- Boosts absorption\n- Improves elasticity\n\nAbout brand: medicube");

    expect($html)->toBe("<p>Benefits:<br>\n- Boosts absorption<br>\n- Improves elasticity</p>\n<p>About brand: medicube</p>");
});

it('leaves a description the admin editor wrote exactly as it was', function () {
    /*
     * RULE 1. Editor HTML carries its own <p>s; the only newlines in it sit
     * between blocks, where they are layout. forDisplay() must not touch it,
     * or every hand-written description on the shop moves for an import fix.
     *
     * MUTATION, RUN: forDisplay() calling autop() unconditionally -- red, the
     * editor's one-line `<ul><li>One</li><li>Two</li></ul>` came back split
     * over four lines, a newline round every <li>.
     */
    $editor = "<p>The long English description.</p>\n<ul><li>One</li><li>Two</li></ul>\n<p>Use <strong>daily</strong>.</p>";

    expect(RichText::needsAutop($editor))->toBeFalse()
        ->and(RichText::forDisplay($editor))->toBe($editor);

    $p = piaProduct(['description' => $editor]);
    expect(piaDescriptionPanel($this->get('/product/' . $p->slug . '/')->getContent()))->toBe($editor);
});

it('keeps <pre> newlines as newlines', function () {
    $html = RichText::forDisplay("Intro line\nsecond\n<pre>a\nb</pre>");

    expect($html)->toContain("<pre>a\nb</pre>")->toContain("Intro line<br>\nsecond");
});

// ── Item 3b: the short description ─────────────────────────────────────────

it('renders the short description as HTML, not as escaped tags', function () {
    /*
     * ON THE SHOP: "…fine lines and dryness. <div>This set is ideal for" --
     * the tag printed as letters, in both blurb positions.
     *
     * MUTATION, RUN: product.blade.php back to {{ $product->t('short_description') }}
     * -- red, the page contained "&lt;div&gt;This set is ideal for".
     */
    $p = piaProduct([
        'short_description' => RichText::clean('This set targets fine lines and dryness. <div>This set is ideal for gifting.</div>'),
    ]);

    $html = $this->get('/product/' . $p->slug . '/')->assertOk()->getContent();
    $blurbs = piaBlurbs($html);

    expect($html)->not->toContain('&lt;div&gt;')
        ->and($blurbs)->toHaveCount(1)
        // A <p> cannot hold a <div>: the parser would close it early and drop
        // the rest of the blurb outside the three-line cap. So: a <div>.
        ->and($blurbs[0][1])->toBe('div')
        ->and($blurbs[0][2])->toBe('This set targets fine lines and dryness. <div>This set is ideal for gifting.</div>');
});

it('keeps a one-line blurb in the <p> it always had', function () {
    $p = piaProduct(['short_description' => 'A gentle daily serum for tired skin.']);

    $blurbs = piaBlurbs($this->get('/product/' . $p->slug . '/')->getContent());

    expect($blurbs)->toHaveCount(1)
        ->and($blurbs[0][1])->toBe('p')
        ->and($blurbs[0][2])->toBe('A gentle daily serum for tired skin.');
});

it('decodes an "&" in an imported excerpt once everywhere it is shown', function () {
    /*
     * ON THE SHOP: the importer's sanitiser stores "&" as "&amp;", and four
     * places doubled it: the blurb read "Lift &amp; glow", and so did the
     * quick-view modal, the meta description ("&amp;amp;" in the source) and
     * the JSON-LD ("Lift &amp; glow").
     *
     * MUTATIONS, RUN, one at a time:
     *   product.blade.php back to {{ }}          red on the blurb
     *   quick-view back to strip_tags()          red on the modal
     *   ProductSeo::rawDescription() back to t() red on meta and JSON-LD
     */
    $p = piaProduct(['short_description' => RichText::clean("Lift & glow in two steps.\nSecond line.")]);
    expect($p->short_description)->toContain('&amp;');

    $html = $this->get('/product/' . $p->slug . '/')->assertOk()->getContent();

    expect(piaBlurbs($html)[0][2])->toBe("<p>Lift &amp; glow in two steps.<br>\nSecond line.</p>")
        ->and($html)->toContain('<meta name="description" content="Lift &amp; glow in two steps. Second line.">')
        ->and($html)->not->toContain('&amp;amp;');

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    $product = collect($m[1])->map(fn ($j) => json_decode($j, true))->firstWhere('@type', 'Product');
    expect($product['description'])->toBe('Lift & glow in two steps. Second line.');

    $qv = $this->getJson('/quick-view/' . $p->id)->assertOk()->json('html');
    expect($qv)->toContain('<p class="qv-blurb">Lift &amp; glow in two steps. Second line.</p>');
});

// ── Sanitising: the render path trusts nothing in the column ───────────────

it('prints nothing executable from a row that never went through the sanitiser', function () {
    /*
     * AdminController's quick edit stores `description` as sent, so a column
     * can hold markup the importer would have stripped. Before this lane the
     * Description tab printed it raw: in Chromium the preview's onerror ran.
     * Now every byte printed from these columns has just been through the
     * allowlist.
     *
     * MUTATION, RUN: forDisplay() returning autop()'s output without clean()
     * -- red, this case and six others in this file.
     */
    $p = piaProduct();
    DB::table('products')->where('id', $p->id)->update([
        'description' => "First paragraph.\n\nSecond <b onmouseover=\"x()\">bold</b>.\n"
            . '<script>window.pwned = 1</script>'
            . '<img src="/a.jpg" onerror="window.pwned = 2">'
            . '<a href="jav&#x09;ascript:alert(1)">x</a>'
            . '<p style="position:fixed;inset:0;z-index:99999">overlay</p>'
            . "<img src=/b.jpg\nonerror=alert(3)>",
        'short_description' => 'Blurb <img src="/a.jpg" onerror="alert(4)"><script>alert(5)</script><svg onload="alert(6)"></svg>',
    ]);

    $html = $this->get('/product/' . $p->slug . '/')->assertOk()->getContent();
    $printed = piaDescriptionPanel($html) . implode('', array_column(piaBlurbs($html), 2));

    expect($printed)->toContain('<p>First paragraph.</p>')
        ->not->toContain('<script')
        ->not->toMatch('/\son[a-z]+\s*=/i')
        ->not->toContain('javascript')
        ->not->toContain('style=')
        ->not->toContain('<svg')
        ->not->toContain('pwned')
        ->not->toContain('alert(');
});

it('cannot be talked into a live attribute by a newline inside a tag', function () {
    // autop() edits text with regexes; a newline between attributes is the
    // one place it could split a tag. The allowlist runs after it regardless.
    foreach ([
        "<img src=/x.jpg\nonerror=alert(1)>",
        "a\n<a href=\"/ok\"\nonclick=\"alert(1)\">b</a>\nc",
        "<p>a\n<scr\nipt>alert(1)</script></p>",
        "x\n\n<iframe src=\"//evil\"></iframe>\n\ny",
    ] as $payload) {
        $out = RichText::forDisplay($payload);

        expect($out)->not->toMatch('/\son[a-z]+\s*=/i')
            ->not->toContain('<script')
            ->not->toContain('<iframe');
    }
});

it('keeps the Arabic description on /ar laid out the same way', function () {
    \App\Models\Setting::query()->updateOrCreate(['key' => \App\Support\Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    \App\Models\Setting::query()->updateOrCreate(['key' => \App\Support\Locale::SETTING_RTL], ['value' => '1', 'autoload' => true]);
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();
    app(\App\Services\SettingsService::class)->flush();
    \App\Services\Translation\TranslationStore::flush();

    $p = piaProduct(['description' => '<p>English.</p>']);
    \App\Services\Translation\TranslationStore::put(
        'ar', 'products', $p->id, 'description', "سطر أول\nسطر ثان\n\nفقرة ثانية",
        \App\Models\Translation::STATUS_PUBLISHED, \App\Models\Translation::SOURCE_MANUAL,
    );

    $panel = piaDescriptionPanel($this->get('/ar/product/' . $p->slug . '/')->assertOk()->getContent());

    expect($panel)->toBe("<p>سطر أول<br>\nسطر ثان</p>\n<p>فقرة ثانية</p>");
});

it('ships the paragraph spacing in the stylesheet the product page loads', function () {
    /*
     * The sheet resets every margin to zero, so <p>s with no rule of their own
     * sit flush and the description still reads as one slab -- structure in
     * the markup, none on the page. Measured in Chromium at 1280: 10px between
     * the first two paragraphs with the rule. Read from the BUILT file the
     * manifest names, because that is what the server serves.
     *
     * MUTATION, RUN: the committed build put back to the one before this lane
     * -- red, the rule is not in it.
     */
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $css = (string) file_get_contents(public_path('build/' . $manifest['resources/css/kbb/kbb-product.css']['file']));

    expect($css)->toContain('.details .dcontent p{margin-block-end:.75em}')
        ->toContain('.details .dcontent>:last-child{margin-block-end:0}')
        ->toContain('.pdp .bb-desc :is(p,div,ul,ol){margin-block-end:.6em}');
});
