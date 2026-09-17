<?php

declare(strict_types=1);

/**
 * Lane EI — the product editor's search-snippet preview, and the page it claims
 * to preview.
 *
 * ── WHY THIS FILE EXISTS WHEN SnippetPreviewTruthTest ALREADY DOES ──────────
 *
 * It fixed the wrong screen, through no fault of its own.
 *
 * Lane DZ found the admin drawing a Google preview out of an invented sentence
 * and corrected the panel it could see: `ySnippet()` in
 * resources/views/admin/app.blade.php, fed by `seo_fallback_description` off
 * GET /admin-api/products/{id}. That work is correct and stays.
 *
 * But that panel is `yoastPanel()`, reached from `openProduct()` — and Lane AT
 * had already retired that editor. app.blade.php says so itself at the include
 * of the replacement: "that editor is a mock whose controls all call
 * toast('… (preview)')". The screen the owner actually edits products on is
 * resources/views/admin/partials/product-editor-screen.blade.php, on
 * routes/product-editor-admin.php, and ProductEditorRetirementTest asserts it
 * is now the ONLY one. Its preview was still inventing:
 *
 *     d.textContent = clip(seoD || stripTags(model.short_description)
 *         || 'Add a description so Google shows the right words here.', 155);
 *
 * — a sentence no page emits, in front of a chain that on this store really
 * does end in "no description tag at all" often enough to matter.
 *
 * AND THE TITLE WAS WRONG TOO, which Lane DZ never covered because the
 * description was the reported defect. The preview drew the operator's box, or
 * the bare product name when that box was empty. The page emits neither:
 *
 *     box EMPTY  -> ProductTitle::head(brand, name), which is the product name
 *                   with " · K-Beauty Bliss" ALREADY ON IT — the suffix used to
 *                   be a literal in store/product.blade.php and moved into that
 *                   method so the description's {title} token could resolve to
 *                   the same words. So the tag is ~17 characters longer than
 *                   the name the preview was showing.
 *     box FILLED -> the typed string and nothing else. Store\ProductController
 *                   sets `title_is_final`, so no template is applied and no
 *                   site name is appended.
 *
 * The two cases are therefore not the same length from the same typing, the
 * preview showed a third thing, and the "50 / 60" counter under it measured a
 * fourth — the box. An operator filling a 55-character title was told "55 / 60,
 * good"; the same operator clearing it was told "0 / 60" under a 49-character
 * tag. Both counters now measure the tag.
 *
 * A SECOND FINDING CAME OUT OF WRITING THIS, and is pinned below rather than
 * fixed: `seo_title_template` cannot change a product title at all, because
 * ProductTitle::head() has already produced the final string and Seo::tokens()
 * then blanks {sitename}. That is a sitewide control with no reader on the
 * page type that matters most. Changing it would move every product's tab and
 * search result, so it is reported to the owner, not quietly altered.
 *
 * ── WHAT IS PINNED ─────────────────────────────────────────────────────────
 *
 * That the four strings the endpoint publishes are the page's own bytes, read
 * off the rendered response and not out of App\Support\Seo — an assertion made
 * against that class would pass while the page emitted nothing, which is how
 * this SEO engine once sat fully built and entirely disconnected.
 *
 * The title is resolved by App\Support\Seo::titleFor(), which is an EXTRACTION
 * of the block render() has always used rather than a second copy of it. Same
 * arrangement as describe(), and for the same reason: two copies of the
 * "already carries the brand" rule is how a rename half-happens.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Setting;
use App\Support\ProductTitle;
use Illuminate\Support\Str;
use Tests\Support\ProductEditorRoutes;

function peiSettings(array $values): void
{
    foreach ($values as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    Setting::flushMap();
}

beforeEach(function () {
    ProductEditorRoutes::wire(app());

    peiSettings([
        'site_url' => 'https://kbeautybliss.test',
        'seo_site_name' => 'K-Beauty Bliss',
        'seo_separator' => '|',
        'seo_title_template' => '{title} {sep} {sitename}',
        'seo_default_description' => 'Korean skincare and K-beauty, shipped across the Gulf.',
    ]);
});

function peiProduct(array $attributes = []): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'pei-anua'], ['name' => 'Anua']);

    return Product::create(array_merge([
        'slug' => 'pei-' . Str::random(10),
        'name' => 'Heartleaf Quercetinol Toner',
        'brand_id' => $brand->id,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
    ], $attributes));
}

/** The <title> the crawler is actually served. */
function peiStorefrontTitle(Product $product): string
{
    $html = test()->get('/product/' . $product->slug)->assertOk()->getContent();
    $m = [];

    expect(preg_match('#<title>(.*?)</title>#s', (string) $html, $m))->toBe(1);

    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}

/** The description tag actually served, or null when the page publishes none. */
function peiStorefrontDescription(Product $product): ?string
{
    $html = test()->get('/product/' . $product->slug)->assertOk()->getContent();
    $m = [];

    return preg_match('/<meta name="description" content="(.*?)">/s', (string) $html, $m) === 1
        ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')
        : null;
}

/** What the product EDITOR — the surviving one — is handed when it opens this product. */
function peiEditorPayload(Product $product): array
{
    $admin = AdminUser::create([
        'name' => 'Editor Owner',
        'email' => 'pei-' . uniqid() . '@example.test',
        'password' => bcrypt('secret-secret'),
        'role' => 'owner',
    ]);

    $body = (array) test()->actingAs($admin, 'admin')
        ->getJson('/admin-api/product-editor-load/' . $product->id)
        ->assertOk()
        ->json();

    return (array) $body['product'];
}

/*
|------------------------------------------------------------------------------
| 1. The title the endpoint publishes is the title the page emits
|------------------------------------------------------------------------------
*/

it('hands the editor the title a product with no SEO title of its own publishes', function () {
    $product = peiProduct();

    $published = peiStorefrontTitle($product);

    // ProductTitle::head() is the page's raw title and it ALREADY carries the
    // site name -- the suffix used to be a literal in store/product.blade.php.
    // That is the size of the bug: the preview drew the operator's empty box,
    // or the bare product name, under a tag this long.
    expect($published)->toBe("Anua Heartleaf Quercetinol Toner \u{b7} K-Beauty Bliss")
        ->and($published)->toBe(ProductTitle::head('Anua', 'Heartleaf Quercetinol Toner'));

    expect(peiEditorPayload($product)['seo_title'])->toBe($published);
});

it('prints the site name once, not twice', function () {
    /*
     * The rule most likely to be lost in a reimplementation, and the reason
     * titleFor() is an extraction of render()'s block rather than a second copy
     * of it: Seo::tokens() blanks {sitename} when the raw title already
     * contains it, so `{title} {sep} {sitename}` does not append a SECOND
     * site name -- and TitleTemplate::tidy() then drops the separator left
     * dangling by the blanked token.
     *
     * A preview that re-templated the title itself would show the doubled form.
     */
    $published = peiStorefrontTitle(peiProduct());

    expect(substr_count($published, 'K-Beauty Bliss'))->toBe(1);
});

it('does not append the site name to a title the operator typed', function () {
    /*
     * The asymmetry the preview has to respect. A per-product SEO title is
     * final: Store\ProductController::show() sets `title_is_final`, so the
     * template is skipped entirely and the typed string IS the tag.
     */
    $product = peiProduct(['seo' => ['title' => 'Anua Heartleaf Toner, the calm one']]);

    $published = peiStorefrontTitle($product);

    expect($published)->toBe('Anua Heartleaf Toner, the calm one')
        ->and($published)->not->toContain('K-Beauty Bliss');

    expect(peiEditorPayload($product)['seo_title'])->toBe($published);
});

it('resolves a token chip in a typed title the same way the page does', function () {
    // The per-product panel inserts Yoast-style %%...%% chips and they reach
    // the storefront verbatim in `seo.title`. A preview that left them as
    // literals would disagree with the page on exactly the products that used
    // the feature.
    $product = peiProduct(['seo' => ['title' => '%%title%% %%sep%% Toner']]);

    $published = peiStorefrontTitle($product);

    expect($published)->not->toContain('%%');
    expect(peiEditorPayload($product)['seo_title'])->toBe($published);
});

it('answers what the title would be if the SEO title box were cleared', function () {
    /*
     * The preview's own logic is `typed || fallback`, so the endpoint's job is
     * the second operand: what appears the moment the box is emptied. With a
     * title saved, the page prints that title and the fallback is still the
     * untyped form -- which is what the operator needs to see before clearing
     * it.
     */
    $product = peiProduct(['seo' => ['title' => 'Anua Heartleaf Toner, the calm one']]);

    $fallback = peiEditorPayload($product)['seo_fallback_title'];

    expect($fallback)->toBe("Anua Heartleaf Quercetinol Toner \u{b7} K-Beauty Bliss");

    // And it really is what the page falls back to: clear the override and the
    // page prints exactly that string.
    $product->update(['seo' => null]);

    expect(peiStorefrontTitle($product->fresh()))->toBe($fallback);
});

it('agrees with the page even when the sitewide title template is changed', function () {
    /*
     * A FINDING, PINNED AS IT STANDS RATHER THAN CHANGED.
     *
     * `seo_title_template` on the SEO & Meta screen cannot alter a PRODUCT
     * title on this store, and nothing says so. ProductTitle::head() hands the
     * engine a raw title that already ends in the site name; Seo::tokens()
     * therefore blanks {sitename}; TitleTemplate::tidy() then drops the
     * separator that token was attached to. Whatever the operator arranges
     * around {title}, what survives is {title} -- which already is the whole
     * thing.
     *
     * Changing that would move every product page's tab and every product's
     * search result on a shop that has not asked for it, and ProductTitle::head
     * says in as many words that it moved the string without changing what any
     * page prints. So this lane reports it and pins the behaviour instead.
     *
     * What matters for the preview either way is the invariant below: whatever
     * the template does or does not do, the editor is handed the page's bytes.
     */
    peiSettings(['seo_title_template' => '{sitename} {sep} {title}']);

    $product = peiProduct();

    expect(peiEditorPayload($product)['seo_title'])->toBe(peiStorefrontTitle($product));

    peiSettings(['seo_title_template' => 'Buy {title} today']);

    expect(peiEditorPayload($product)['seo_title'])->toBe(peiStorefrontTitle($product));
});

/*
|------------------------------------------------------------------------------
| 2. The description, on the editor that is actually used
|------------------------------------------------------------------------------
*/

it('hands this editor the same description bytes the page publishes', function () {
    $product = peiProduct(['short_description' => 'A daily toner for calm, even-looking skin.']);

    $published = peiStorefrontDescription($product);

    expect($published)->toBe('A daily toner for calm, even-looking skin.');
    expect(peiEditorPayload($product)['seo_description'])->toBe($published);
});

it('admits this page publishes no description where it publishes none', function () {
    /*
     * The reported-not-patched defect: the editor writes '' when the operator
     * clears the short-description box, `??` falls through on null only, so the
     * sitewide default is never reached and NO description tag is emitted. The
     * preview's job is to stop hiding that, which is what the invented sentence
     * did.
     */
    $product = peiProduct(['short_description' => '']);

    expect(peiStorefrontDescription($product))->toBeNull();

    $payload = peiEditorPayload($product);

    expect($payload['seo_description'])->toBe('')
        ->and($payload['seo_fallback_description'])->toBe('');
});

it('falls back to the sitewide description, not to a sentence of its own', function () {
    $product = peiProduct();

    $published = peiStorefrontDescription($product);

    expect($published)->toBe('Korean skincare and K-beauty, shipped across the Gulf.');
    expect(peiEditorPayload($product)['seo_fallback_description'])->toBe($published);
});

it('offers no delivery promise in anything this endpoint calls a description', function () {
    // A meta description is one string per URL and cannot be the per-country
    // line App\Support\DeliveryLine resolves, so it must promise no speed at all.
    $payload = peiEditorPayload(peiProduct());

    foreach (['seo_description', 'seo_fallback_description'] as $key) {
        $value = mb_strtolower((string) $payload[$key]);

        foreach (['uae delivery', 'fast delivery', 'next day', 'next-day', 'guaranteed'] as $promise) {
            expect(str_contains($value, $promise))
                ->toBeFalse($key . ' promises: ' . $promise);
        }
    }
});

/*
|------------------------------------------------------------------------------
| 3. The screen consumes it
|------------------------------------------------------------------------------
|
| A payload nothing reads is the defect this project has spent twenty packages
| removing, so the endpoint's four keys are pinned against the file that draws
| the preview. Needles are assembled at run time: a source guard that carried
| the invented sentence as a literal would match ITSELF.
*/

function peiEditorSource(): string
{
    return (string) file_get_contents(
        resource_path('views/admin/partials/product-editor-screen.blade.php')
    );
}

it('no longer carries the invented fallback sentence', function () {
    $invented = 'Add a description so Google' . ' shows the right words here.';

    expect(str_contains(peiEditorSource(), $invented))->toBeFalse(
        'the product editor still falls back to a sentence the storefront never emits'
    );
});

it('draws the snippet and the counters from the endpoint\'s strings', function () {
    $source = peiEditorSource();

    foreach (['seo_fallback' . '_title', 'seo_fallback' . '_description'] as $key) {
        expect(str_contains($source, $key))->toBeTrue(
            'the editor never reads ' . $key . ', so the preview cannot be showing the real tag'
        );
    }
});

it('publishes every key the screen reads', function () {
    // The other half of the same pin: the screen's names and the endpoint's
    // names have to be the same names. This project has already shipped a save
    // path writing `seo_json` while every reader read `seo`.
    $payload = peiEditorPayload(peiProduct());

    foreach (['seo_title', 'seo_description', 'seo_fallback_title', 'seo_fallback_description'] as $key) {
        expect($payload)->toHaveKey($key);
    }
});
