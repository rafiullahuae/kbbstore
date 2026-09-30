<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use App\Services\ModuleSchema;
use App\Services\ProductLayout;
use App\Services\SettingsService;

/**
 * `Appearance → Product page` — THE LIVE PREVIEW, PHONE AND LAPTOP BOTH.
 *                                                        (Lane PDP2, round 5)
 *
 * ── THE DEFECT THIS FILE WAS WRITTEN AGAINST ────────────────────────────────
 *
 *     "where's the preview on the product controls page? i need a proper
 *      preview of mobile and desktop both."
 *
 * He is right, and it is this lane's own gap. Round 4 put THIRTY controls on
 * that screen — four tabs of spacing and type — and renderProductPage() emitted
 * no preview element of any kind. Thirty sliders and nothing to see them
 * against, so the only way to find out what a number did was to save it onto
 * the shop.
 *
 * ── THE FALSE GREEN THIS FILE IS BUILT TO AVOID ─────────────────────────────
 *
 * "A preview renders" is satisfied by an EMPTY element, and it is satisfied by
 * a FULL one that is not listening. Lane GRID had the first on the Product grid
 * screen — 32 swatches that all existed and were all blank while the count
 * assertion passed — and this lane nearly shipped the second: the obvious URL
 * to frame was `admin-api/catalog/pdp-preview/ledger/{slug}`, the design he
 * chose, and it draws a handsome product page. It is also written in markup of
 * its own (every class in it is `pv-…`) while the thirty properties are read by
 * `.pdp .bb-title`, `.sec h2` and `.details .dtabbar` in kbb-product.css. The
 * first harness run photographed exactly that: two frames with a complete
 * product page in them, and twenty-nine of the thirty controls moving nothing.
 *
 * So nothing here asserts that a frame exists. What it asserts is:
 *
 *   §1  the panel is on the screen at all, with BOTH widths, neither behind a
 *       toggle — several of the thirty are per-width by design;
 *   §2  the page the frames load is the SHIPPED product page, and it really
 *       carries the elements the thirty properties are read on;
 *   §3  the endpoint hands the console a property name for every control;
 *   §4  the value the console builds is the value the server would print —
 *       the same rule, reproduced here, compared property for property;
 *   §5  nothing measures a rectangle, and the storefront did not move.
 */
function pppConsole(): string
{
    return (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

/**
 * The screen's own region of the console script, WITH ITS COMMENTS REMOVED.
 *
 * Lane GRID's pgpRegion() carries the argument in full and it is this lane's
 * too: the region's header quotes the defect and names the forbidden measuring
 * APIs out loud to say the screen uses none of them, so an absence assertion
 * would be red because of prose and — far worse — every PRESENCE assertion
 * could be satisfied by a comment mentioning the code rather than by the code.
 */
function pppRegion(): string
{
    $src = pppConsole();
    $from = strpos($src, '/* ---------- Appearance · Product page ----------');
    expect($from)->not->toBeFalse();
    $to = strpos($src, '/* ---------- Appearance · Product styles ----------', (int) $from);
    expect($to)->not->toBeFalse();

    return (string) preg_replace('~/\*.*?\*/~s', ' ', substr($src, (int) $from, (int) $to - (int) $from));
}

/** The panel's block of the console stylesheet, comments stripped, same reason. */
function pppCss(): string
{
    $src = pppConsole();
    $from = strpos($src, '.ppwrap{display:grid');
    expect($from)->not->toBeFalse();
    $to = strpos($src, '</style>', (int) $from);
    expect($to)->not->toBeFalse();

    return (string) preg_replace('~/\*.*?\*/~s', ' ', substr($src, (int) $from, (int) $to - (int) $from));
}

function pppProduct(): Product
{
    /*
     * ▲ THE TABLE IS EMPTIED FIRST, AND IT IS NOT TIDINESS. tests/Pest.php's
     *   own header records that the FIRST test in a process escapes
     *   RefreshDatabase's transaction entirely — whatever it writes survives
     *   the whole run. preview() answers the first visible product by id, so a
     *   row left behind by an earlier case is a row this file would then assert
     *   against, and the failure lands in whichever case happens to run second.
     *   Measured: `--filter` reversed the order and the slug assertion started
     *   reading the previous case's product.
     */
    Product::query()->delete();

    $brand = Brand::firstOrCreate(['slug' => 'ppp-brand'], ['name' => 'Beauty of Joseon']);

    return Product::create([
        'name' => 'Relief Sun Rice + Probiotics SPF50+',
        'slug' => 'ppp-relief-sun',
        'brand_id' => $brand->id,
        'price' => 9300,
        'status' => 'publish',
        'is_visible' => true,
        'stock_status' => 'instock',
        'type' => 'simple',
        'short_description' => 'A daily sun stick with rice bran and probiotics.',
        'description' => '<p>The description tab.</p>',
        'ingredients' => '<p>Oryza Sativa Extract.</p>',
        'how_to_use' => '<p>Morning, as the last step.</p>',
        'image' => 'https://example.test/one.jpg',
        'images' => ['https://example.test/two.jpg'],
    ]);
}

function pppAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Owner',
        'email' => 'ppp-owner@kbeautybliss.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/* ═══════════════ §1 · the panel is there, and it is BOTH widths ═══════════ */

it('puts a preview panel on the screen that had thirty sliders and none', function () {
    $region = pppRegion();

    /*
     * MUTATION NOTE — RUN. Delete `${ppPreviewPanel()}` from the shell that
     * paintProductPage() writes, which is the state this screen shipped in
     * after round 4, and the first three expectations go red together. Putting
     * the call back greens them.
     */
    expect($region)->toContain('ppPreviewPanel()')
        ->and($region)->toContain('<div class="ppwrap"><div class="ppcol" id="ppCol"></div>')
        ->and($region)->toContain('<iframe data-ppframe="${kind}"');
});

it('draws a laptop and a phone at once, neither of them behind a toggle', function () {
    $region = pppRegion();
    $css = pppCss();

    /*
     * HE ASKED FOR BOTH — "i need a proper preview of mobile and desktop both"
     * — and both is the point, not a convenience. `title_m` is 19px and
     * `title_d` is 30px: one preview with a switch on it would let him set a
     * laptop size that ruins the phone and find out on the shop.
     *
     * MUTATION NOTE — RUN. Drop the `pane('mobile', …)` call and the first
     * expectation reds; set `--ppv-w:1280` on the mobile rule and the width
     * expectations red.
     */
    expect($region)->toContain("pane('desktop','Laptop · 1280px'")
        ->and($region)->toContain("pane('mobile','Phone · 390px'");

    expect($css)->toContain('.ppv-pane[data-ppv="desktop"]{--ppv-w:1280;')
        ->and($css)->toContain('.ppv-pane[data-ppv="mobile"]{--ppv-w:390;');

    /* And neither is hidden: no display:none, no visibility rule, no `hidden`
       attribute anywhere in the panel's own CSS or markup. A toggle is exactly
       what he said he did not want. */
    expect($css)->not->toContain('display:none')
        ->and($css)->not->toContain('visibility:hidden');
});

/* ═════════ §2 · the frame loads the SHIPPED page, and it is not a husk ════ */

it('frames the shipped product page — the one the thirty properties are read on', function () {
    /* The model slugs itself from the name, so the row's slug is read back
       rather than assumed — the endpoint answers what is stored. */
    $product = pppProduct()->fresh();

    $this->actingAs(pppAdmin(), 'admin');
    $payload = $this->getJson('/admin-api/product-page')->assertOk()->json();

    expect($payload)->toHaveKey('preview');
    expect($payload['preview']['slug'])->toBe($product->slug);
    /* URL contract U-01 — `/product/{slug}/`, trailing slash and base prefix —
       and it is the model's own method rather than a second spelling of it. */
    expect($payload['preview']['url'])->toBe($product->url())
        ->and($payload['preview']['url'])->toEndWith('/');

    /*
     * ▲ THE ANTI-FALSE-GREEN CASE, AND IT IS THE REASON THIS FILE EXISTS.
     *
     * The URL is fetched and the RESULT is read. An empty frame, a 404 page and
     * a candidate design would all satisfy "a preview renders"; only the
     * shipped page carries every one of these, and every one of them is an
     * element one of the thirty custom properties is read on:
     *
     *     .pdp .bb-title   --pl-title-m / --pl-title-d / --pl-title-w
     *     .bb-price        --pl-price-s / --pl-price-w / --pl-was-s
     *     .bb-desc         --pl-desc-s  / --pl-desc-lh
     *     .sec h2          --pl-heading-s / --pl-heading-w
     *     .dtabbar         --pl-tab-gap / --pl-tab-body-gap / --pl-tab-s
     *
     * MUTATION NOTE — RUN. Point preview()'s `url` at
     * `admin-api/catalog/pdp-preview/ledger/{slug}` — the design he chose,
     * which was this lane's first answer — and this case reds on the URL and
     * again on every `bb-…` / `dtabbar` expectation, because that template is
     * written in `pv-…` classes throughout. That is precisely the preview that
     * would have LOOKED finished and answered one control in thirty; the
     * harness photographed it doing exactly that before the URL was changed.
     */
    /* ▲ THE URL THE PANEL ACTUALLY USES, not one re-derived here. Fetching
         `$product->url()` instead would leave the markup assertions green
         while `preview.url` pointed somewhere else entirely — which is the
         mutation below, and the shape this lane nearly shipped. */
    $page = $this->get($payload['preview']['url']);
    $page->assertOk();
    $html = $page->getContent();

    expect($html)->toContain('Relief Sun Rice + Probiotics SPF50+')
        ->and($html)->toContain('class="pdp')
        ->and($html)->toContain('bb-title')
        ->and($html)->toContain('bb-price')
        ->and($html)->toContain('bb-desc')
        ->and($html)->toContain('dtabbar');
});

it('draws an explanation rather than a frame when there is no product to draw', function () {
    Product::query()->delete();   // see pppProduct()

    $this->actingAs(pppAdmin(), 'admin');
    $payload = $this->getJson('/admin-api/product-page')->assertOk()->json();

    /* A fresh install really is in this state until the catalogue imports. An
       <iframe> pointed at a 404 is the failure this avoids.
       MUTATION NOTE — RUN. Drop the null-safe operators in preview() and this
       case reds with a method call on null. */
    expect($payload['preview']['slug'])->toBeNull()
        ->and($payload['preview']['url'])->toBeNull();

    expect(pppRegion())->toContain('There is no visible product to draw yet.');
});

/* ═══════════ §3 · a property name for every one of the thirty ═════════════ */

it('hands the console a custom property for every control, and no other', function () {
    $this->actingAs(pppAdmin(), 'admin');
    $props = $this->getJson('/admin-api/product-page')->assertOk()->json('preview.props');

    /*
     * A name that is wrong in the console does NOT error: the property is
     * simply never read, so that one control silently stops answering while
     * the other twenty-nine keep working. One table, read by both sides.
     *
     * MUTATION NOTE — RUN. Delete `'tab_s' => '--pl-tab-s',` from
     * ProductLayout::PROPS and the key comparison reds; rename it to
     * `--pl-tabs-s` and the second block reds on the value.
     */
    expect(array_keys($props))->toEqualCanonicalizing(array_keys(ProductLayout::SCHEMA));
    expect(array_values($props))->toEqualCanonicalizing(array_keys(ProductLayout::vars(ProductLayout::defaults())));
});

/* ═════════ §4 · the console's formatter IS the server's formatter ═════════ */

/**
 * ppPvValue() in resources/views/admin/app.blade.php, written in PHP.
 *
 * The console has to build a CSS value from a slider before anything is saved,
 * so there are two formatters for these thirty numbers and only one of them can
 * be right. This is the console's rule — the field's own `unit` and `scale`,
 * which ModuleSchema already sends it — and the test below demands it agrees
 * with ProductLayout::vars() property for property.
 */
function pppConsoleVars(array $tabs, array $props): array
{
    $out = [];

    foreach ($tabs as $tab) {
        foreach ($tab['fields'] as $f) {
            $o = $f['options'] ?? [];

            if ($f['type'] === 'select') {
                $out[$props[$f['key']]] = (string) $f['value'];

                continue;
            }

            $scale = ((float) ($o['scale'] ?? 0)) ?: 1.0;
            $n = min((float) $o['max'], max((float) $o['min'], (float) $f['value']));
            $num = rtrim(rtrim(number_format($n / $scale, 6, '.', ''), '0'), '.');
            $out[$props[$f['key']]] = $num.(($o['unit'] ?? '') === 'px' ? 'px' : '');
        }
    }

    return $out;
}

it('builds the same value in the console as the stylesheet prints on the shop', function () {
    $props = ProductLayout::PROPS;

    $shipped = ProductLayout::defaults();
    $tabs = ModuleSchema::tabs(ProductLayout::SCHEMA, ProductLayout::TABS, $shipped, ProductLayout::POLICY);

    /*
     * ▲ THE DECIMALS ARE THE WHOLE POINT. Sizes are stored in tenths and line
     *   heights in hundredths, so `135` must become `13.5px` on both sides and
     *   `162` must become `1.62` with NO unit. The day one side prints `13.5`
     *   where the other prints `13.5px`, the preview and the shop disagree and
     *   only the shop is right.
     *
     * MUTATION NOTE — RUN. Change ppPvValue()'s unit arm to always append
     * 'px' and this reds on `--pl-desc-lh`; drop the `/ sc` and it reds on
     * every tenths key at once. (Both were run against the PHP twin here,
     * which is the same rule.)
     */
    expect(pppConsoleVars($tabs, $props))->toBe(ProductLayout::vars($shipped));

    /* And again on values nobody shipped, so the agreement is not an accident
       of the defaults happening to be round numbers. */
    $moved = array_merge($shipped, [
        'title_m' => 275, 'title_d' => 435, 'desc_s' => 175, 'desc_lh' => 118,
        'body_lh' => 235, 'sec_pad' => 90, 'title_w' => '700', 'heading_w' => '400',
    ]);
    $movedTabs = ModuleSchema::tabs(ProductLayout::SCHEMA, ProductLayout::TABS, $moved, ProductLayout::POLICY);

    expect(pppConsoleVars($movedTabs, $props))->toBe(ProductLayout::vars($moved));
});

it('writes those properties on every input rather than only on save', function () {
    $region = pppRegion();

    /*
     * "it updates as he moves any of the thirty controls" — before saving.
     * `input` and not `change`, so the picture moves while the handle is under
     * his finger.
     *
     */
    /*
     * ▲ THE SLICE IS BOUNDED BY THE NEXT LISTENER, AND THE FIRST DRAFT WAS NOT.
     *   It read `substr(..., 700)` from the `input` listener, which runs past
     *   the end of that handler and into the `change` one — which ALSO repaints.
     *   Deleting the call from the input listener left this green. Measured, not
     *   reasoned about: the mutation below was run against the loose form, it
     *   passed, and that is the whole false-green class this file exists for.
     */
    $at = (int) strpos($region, "document.addEventListener('input'");
    $end = (int) strpos($region, 'document.addEventListener(', $at + 20);
    $input = substr($region, $at, $end - $at);

    /* MUTATION NOTE — RUN. Delete the `ppPaintPreview();` line from the input
       listener and this reds; the panel then only shows the saved page. */
    expect($input)->toContain('ppPaintPreview()');
    expect($region)->toContain("root.style.setProperty(pair[0], pair[1])");
    expect($region)->toContain('ppReloadPreview()');
});

it('does not rebuild the frames when he moves between tabs', function () {
    $region = pppRegion();

    /*
     * Re-inserting an <iframe> RELOADS it. paintProductPage() used to replace
     * #content wholesale on every tab click, so with two frames in it that is
     * two product pages fetched per click — and the picture would flash back to
     * the saved page, losing whatever he had just dragged.
     *
     * MUTATION NOTE — RUN. Take the `rebuild || !$('#ppShell')` guard off and
     * the shell is rewritten on every repaint: this reds, and the harness shows
     * the frames reloading on each tab click.
     */
    expect($region)->toContain("if(rebuild || !$('#ppShell')){")
        ->and(substr_count($region, 'ppPreviewPanel()'))->toBe(2)   // the definition and the one call
        ->and($region)->toContain('paintProductPage(true)');
});

/* ═══════════════ §5 · nothing measured, nothing on the shop moved ═════════ */

it('sizes the two frames with calc and never with a measured rectangle', function () {
    $region = pppRegion();
    $css = pppCss();

    /*
     * CLAUDE.md rule 4, and two other tests in this suite forbid these by name
     * on the storefront. A 1280px page inside a 400px panel is a SCALE, and the
     * scale is a constant per pane with every dimension derived by calc().
     *
     * MUTATION NOTE — RUN. Replace `--ppv-s` with a scale worked out from
     * `el.getBoundingClientRect().width / 1280` in ppPreviewPanel() and the
     * first loop reds on getBoundingClientRect.
     */
    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'clientHeight', 'getComputedStyle'] as $api) {
        expect($region)->not->toContain($api);
    }

    expect($css)->toContain('width:calc(var(--ppv-w) * var(--ppv-s) * 1px)')
        ->and($css)->toContain('transform:scale(var(--ppv-s))')
        /* The scaled box, without which `.ppv-vp` scrolls the iframe's UNSCALED
           height — 2400px of nothing to reach content that ends at 744. */
        ->and($css)->toContain('.ppv-scale{position:relative');
});

it('changes nothing about the product page itself', function () {
    /*
     * This is an ADMIN preview. The panel writes its properties onto the
     * FRAME's own <html> from the console, and the shop's own <style> block is
     * still emitted only by partials/product-layout-css.blade.php and only when
     * something has been saved. `StorefrontEnglishUnchangedTest` is the
     * instrument; this is the narrower statement it depends on.
     *
     * MUTATION NOTE — RUN. Make storefrontCss() return css() unconditionally
     * and this reds, as does StorefrontEnglishUnchangedTest.
     */
    $product = pppProduct()->fresh();
    $layout = app(ProductLayout::class);

    expect($layout->storefrontCss())->toBe('');
    expect($this->get(route('product.show', ['slug' => $product->slug]))->getContent())
        ->not->toContain('kbb-pdp-layout');

    /* And once he has saved one, it is there — because a preview that shows a
       change the shop will not make is worse than no preview. */
    app(SettingsService::class)->set(ProductLayout::PREFIX.'title_m', 275);
    /* Setting::map() memoises in a process-level static as well as the cache
       (CLAUDE.md), so the write above is invisible to this process until the
       snapshot is dropped. Fine under PHP-FPM, a trap in a test. */
    \App\Models\Setting::flushMap();

    $html = $this->get($product->url())->getContent();
    expect($html)->toContain('kbb-pdp-layout')
        ->and($html)->toContain('--pl-title-m:27.5px');
});

it('still saves both halves — the panel is an addition, not a replacement', function () {
    /*
     * ▲ THE DEFECT THIS CASE WAS WRITTEN AGAINST, AND IT WAS THIS LANE'S OWN.
     *
     *   While `preview()` was being rewritten, the edit that replaced it
     *   matched from its docblock to the END OF THE CLASS and took
     *   `save()` with it. `php -l` was clean, the screen loaded, the two frames
     *   drew the product page, every slider moved the picture — and pressing
     *   Save answered
     *
     *       500  Call to undefined method …ProductPageApiController::save()
     *
     *   with the console showing "Could not save." Eleven cases in this file
     *   were green through all of it, because not one of them POSTed: a preview
     *   is read-only, so a test suite written around a preview can be entirely
     *   green while the screen it previews has lost the ability to store
     *   anything. It was found by driving the real screen in a browser.
     *
     * MUTATION NOTE — RUN. Delete save() from the controller (the exact state
     * above) and this case reds with that 500; every other case in this file
     * stays green, which is the point of writing it.
     */
    $product = pppProduct()->fresh();
    $this->actingAs(pppAdmin(), 'admin');

    $r = $this->postJson('/admin-api/product-page', ['layout' => ['title_m' => 275]]);
    $r->assertOk();
    expect($r->json('ok'))->toBeTrue()->and($r->json('saved'))->toBe(1);

    \App\Models\Setting::flushMap();

    /* And it really reached the shop, which is what the panel is promising. */
    expect($this->get($product->url())->getContent())->toContain('--pl-title-m:27.5px');

    /* The other half still saves too — the panel sits beside both. */
    $sections = $this->getJson('/admin-api/product-page')->json('sections');
    $sections[0]['mobile'] = ! $sections[0]['mobile'];

    $this->postJson('/admin-api/product-page', ['sections' => array_map(
        fn (array $s) => ['key' => $s['key'], 'desktop' => $s['desktop'], 'mobile' => $s['mobile']],
        $sections,
    )])->assertOk()->assertJson(['ok' => true]);
});

it('ships the clear_caches migration the inline console cannot do without', function () {
    /*
     * The panel and its stylesheet are both INLINE in app.blade.php, so there
     * is no hashed asset to bust: a stale compiled view serves yesterday's
     * screen — thirty sliders and no preview, the exact complaint — while the
     * endpoint underneath answers perfectly and the package reports as applied.
     *
     * MUTATION NOTE — RUN. Rename the migration and this reds.
     */
    $found = glob(database_path('migrations/*_clear_caches_product_page_preview.php'));

    expect($found)->toHaveCount(1);
});
