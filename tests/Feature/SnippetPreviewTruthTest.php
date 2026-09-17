<?php

declare(strict_types=1);

/**
 * Lane DZ — the admin's search-snippet preview and the page it claims to preview.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * resources/views/admin/app.blade.php, `ySnippet()`, draws the Google-style
 * preview under a product's SEO tab. When the operator has typed no meta
 * description it falls back to, verbatim:
 *
 *     Shop {name} by {brand} at {site} — authentic Korean skincare, fast UAE
 *     delivery.
 *
 * TWO THINGS ARE WRONG WITH THAT.
 *
 * FIRST, THE STOREFRONT HAS NEVER EMITTED IT. The real chain, read out of
 * Store\ProductController::show() and App\Support\Seo::render(), is the
 * product's own `short_description`, then the sitewide
 * `seo_default_description`, then no description tag at all. So the owner was
 * shown a preview of a search result his site does not produce, on the one
 * screen whose entire purpose is to show him what search engines will see.
 *
 * SECOND, IT PROMISES "fast UAE delivery" FOR A GULF-WIDE SHOP. This shop
 * serves Saudi Arabia, Kuwait, Qatar, Bahrain and Oman on different terms, and
 * App\Support\DeliveryLine exists precisely because one delivery promise
 * printed to everybody was wrong for everybody outside one country. A meta
 * description is ONE string per URL — it cannot be per-visitor — so the only
 * safe description is one that makes no delivery promise, which is exactly what
 * the shipped `seo_default_description` already is (MachineFacingClaimsTest
 * pins that it makes no claim at all).
 *
 * ── WHAT THIS FILE PINS ─────────────────────────────────────────────────────
 *
 * The preview lives in a file this lane may not edit, so the work splits:
 *
 *   OURS      GET /admin-api/products/{id} now carries
 *             `seo_fallback_description` — the exact `<meta name="description">`
 *             the storefront will publish for that product if the meta box is
 *             left empty. Not a description of it, not a reconstruction: the
 *             string, built by App\Support\Seo::describe(), the same method the
 *             page itself uses.
 *
 *   PINNED    that the endpoint's answer and the rendered page's tag are the
 *             same bytes, across the whole fallback chain, including the token
 *             substitution and the empty case.
 *
 *   PENDING   the integrator's two-line edit in the shell, below, guarded so
 *             that the guard turns itself on the moment the edit lands.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Setting;
use App\Support\ProductTitle;
use Illuminate\Support\Str;

/**
 * THE INTEGRATOR'S EDIT, AND WHERE IT GOES.
 *
 * File: resources/views/admin/app.blade.php
 *
 * 1. In `ySnippet()` (~line 13135), REPLACE the whole line:
 *
 *      if(d) d.textContent = (peSeo.meta_description||'').trim() || ('Shop '+(peCtx.n||'this product')+(peCtx.b?(' by '+peCtx.b):'')+' at '+ySite()+' — authentic Korean skincare, fast UAE delivery.');
 *
 *    WITH:
 *
 *      if(d) d.textContent = (peSeo.meta_description||'').trim() || (peCtx.fallbackDesc||'');
 *
 * 2. In the product loader inside `window.openProduct` (~line 13337), REPLACE:
 *
 *      if(full.seo && typeof full.seo==='object'){ peSeo=full.seo; if(typeof yoastTab!=='undefined' && yoastTab==='seo') window.renderYoastBody(); }
 *
 *    WITH:
 *
 *      peCtx.fallbackDesc = full.seo_fallback_description || '';
 *      if(full.seo && typeof full.seo==='object'){ peSeo=full.seo; }
 *      if(typeof yoastTab!=='undefined' && yoastTab==='seo') window.renderYoastBody();
 *
 *    THE UNCONDITIONAL RE-RENDER IS PART OF THE FIX, not tidying. The re-render
 *    was inside the `full.seo` branch, so a product that has never had a per-
 *    product SEO blob — which is most of them, and every product whose preview
 *    is falling back in the first place — never repainted after the fetch. Move
 *    the fallback in without moving that line out and the preview goes on
 *    showing whatever it drew before the response arrived.
 *
 * THEN FLIP sdpSnippetEditPending() TO false. The guard below turns itself on
 * at that point, and a stale `true` is a hole rather than a note.
 */
function sdpSnippetEditPending(): bool
{
    // Wired by the integrator in the same package that merged this lane: the
    // preview reads seo_fallback_description from the product endpoint, and
    // the panel repaints after the fetch whether or not the product carries a
    // per-product SEO blob.
    return false;
}

/** The invented sentence's tail, assembled so this file is not a copy of it. */
function sdpInventedTail(): string
{
    return 'authentic Korean skincare,' . ' fast UAE delivery.';
}

function sdpShell(): string
{
    return (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

function sdpSettings(array $values): void
{
    foreach ($values as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    Setting::flushMap();
}

beforeEach(function () {
    sdpSettings([
        'site_url' => 'https://kbeautybliss.test',
        'seo_site_name' => 'K-Beauty Bliss',
        'seo_separator' => '|',
        'seo_title_template' => '{title} {sep} {sitename}',
        'seo_default_description' => 'Korean skincare and K-beauty, shipped across the Gulf.',
    ]);
});

function sdpProduct(array $attributes = []): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'sdp-anua'], ['name' => 'Anua']);

    return Product::create(array_merge([
        'slug' => 'sdp-' . Str::random(10),
        'name' => 'Heartleaf Quercetinol Toner',
        'brand_id' => $brand->id,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
    ], $attributes));
}

/**
 * The description tag the crawler is actually served, or null when the page
 * publishes none.
 *
 * Read off the response bytes rather than out of App\Support\Seo: an assertion
 * made against the array inside that class would pass while the page emitted
 * nothing, which is how this SEO engine once sat fully built and entirely
 * disconnected (see ProductSeoTest's header).
 */
function sdpStorefrontDescription(Product $product): ?string
{
    $html = test()->get('/product/' . $product->slug)->assertOk()->getContent();
    $m = [];

    return preg_match('/<meta name="description" content="(.*?)">/s', $html, $m) === 1
        ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')
        : null;
}

/** What the product editor is handed when it opens this product. */
function sdpEditorPayload(Product $product): array
{
    $admin = AdminUser::create([
        'name' => 'Snippet Owner',
        'email' => 'snippet-' . uniqid() . '@example.test',
        'password' => bcrypt('secret-secret'),
        'role' => 'owner',
    ]);

    return (array) test()->actingAs($admin, 'admin')
        ->getJson('/admin-api/products/' . $product->id)
        ->assertOk()
        ->json();
}

/*
|------------------------------------------------------------------------------
| 1. The endpoint answers with the page's own bytes
|------------------------------------------------------------------------------
*/

it('hands the editor the sitewide description a product with no description of its own publishes', function () {
    $product = sdpProduct();

    $published = sdpStorefrontDescription($product);

    expect($published)->toBe('Korean skincare and K-beauty, shipped across the Gulf.');
    expect(sdpEditorPayload($product)['seo_fallback_description'])->toBe($published);
});

it('hands the editor the product\'s own short description where it has one', function () {
    // The step of the chain the invented sentence skipped entirely: the preview
    // never showed a short description, even on products that have one.
    $product = sdpProduct(['short_description' => 'A daily toner for calm, even-looking skin.']);

    $published = sdpStorefrontDescription($product);

    expect($published)->toBe('A daily toner for calm, even-looking skin.');
    expect(sdpEditorPayload($product)['seo_fallback_description'])->toBe($published);
});

it('substitutes the same placeholders the page substitutes', function () {
    /*
     * `seo_default_description` accepts the same tokens the title template
     * does. `{title}` is the one that matters here: it resolves to the product
     * page's OWN head title, which is why App\Support\ProductTitle::head()
     * exists rather than that suffix staying a literal in the Blade file. A
     * preview that resolved it differently would agree on every shop except one
     * that used the feature.
     *
     * `{sitename}` is the trap this case caught. App\Support\Seo drops that
     * token when the page title already carries the site name — a rule written
     * for the TITLE, applied by mutating the array the description is rendered
     * with too. So on a product page "{sitename}" resolves to nothing, and a
     * preview that substituted it would have disagreed with the page on any
     * shop that used the placeholder. Asserted, not worked around: Seo::tokens()
     * is now the one place that rule lives.
     */
    sdpSettings(['seo_default_description' => 'Buy {title} at {sitename} {sep} shipped across the Gulf.']);

    $product = sdpProduct();
    $published = sdpStorefrontDescription($product);

    expect($published)->toContain(ProductTitle::head('Anua', 'Heartleaf Quercetinol Toner'));
    expect(sdpEditorPayload($product)['seo_fallback_description'])->toBe($published);
});

it('clamps a long description exactly where the page clamps it', function () {
    sdpSettings(['seo_default_description' => str_repeat('Korean skincare. ', 40)]);

    $product = sdpProduct();
    $published = sdpStorefrontDescription($product);

    // 297 characters plus the ellipsis App\Support\Seo appends.
    expect(mb_strlen((string) $published))->toBe(298);
    expect($published)->toEndWith('…');
    expect(sdpEditorPayload($product)['seo_fallback_description'])->toBe($published);
});

it('admits the page publishes no description at all where it publishes none', function () {
    /*
     * A REPORTED DEFECT, PINNED RATHER THAN PATCHED.
     *
     * `??` falls through on null only. The product editor writes the EMPTY
     * STRING when the operator clears the short-description box
     * (AdminController::updateProduct assigns whatever arrives), and an empty
     * string is a value — so the sitewide default is never reached and
     * App\Support\Seo emits no description tag whatsoever. The product silently
     * loses its search snippet, and nothing anywhere says so.
     *
     * Changing that is a decision about what every such product's search result
     * says, and it belongs with the editor's write path rather than in a lane
     * passing through the read path. What this lane can do is make sure the
     * preview STOPS HIDING IT: the endpoint answers '' and the snippet shows an
     * empty description, which is precisely what Google would show.
     */
    $product = sdpProduct(['short_description' => '']);

    expect(sdpStorefrontDescription($product))->toBeNull();
    expect(sdpEditorPayload($product)['seo_fallback_description'])->toBe('');
});

it('answers what would show if the meta box were cleared, not what the box holds', function () {
    /*
     * The preview's own logic is `(typed meta || fallback)`, so the endpoint's
     * job is only the second operand. With a per-product description saved, the
     * page prints that description and the FALLBACK is still the sitewide one —
     * which is what the operator needs to see the moment they empty the box.
     */
    $product = sdpProduct(['seo' => ['desc' => 'A per-product description, typed by hand.']]);

    expect(sdpStorefrontDescription($product))->toBe('A per-product description, typed by hand.');

    $fallback = sdpEditorPayload($product)['seo_fallback_description'];

    expect($fallback)->toBe('Korean skincare and K-beauty, shipped across the Gulf.');

    // And it really is what the page falls back to: clear the override and the
    // page prints exactly that string.
    $product->update(['seo' => null]);

    expect(sdpStorefrontDescription($product->fresh()))->toBe($fallback);
});

it('promises no delivery speed in anything the endpoint offers as a description', function () {
    // The half of the invented sentence that was a business claim rather than a
    // wrong fallback. Whatever the chain produces, it must not carry a delivery
    // promise attached to one country — a meta description is one string per URL
    // and cannot be the per-country line App\Support\DeliveryLine resolves.
    $fallback = mb_strtolower(sdpEditorPayload(sdpProduct())['seo_fallback_description']);

    foreach (['uae delivery', 'fast delivery', 'next day', 'next-day', '1-3 days', '1–3 days'] as $promise) {
        expect(str_contains($fallback, $promise))->toBeFalse('the offered description promises: ' . $promise);
    }
});

/*
|------------------------------------------------------------------------------
| 2. The shell, and the edit it is waiting for
|------------------------------------------------------------------------------
*/

it('shows the operator the description the storefront will publish', function () {
    $shell = sdpShell();

    if (sdpSnippetEditPending()) {
        /*
         * Waiting on the integrator — the instruction is in this file's
         * sdpSnippetEditPending() docblock, with both anchors spelled out.
         *
         * While it waits, two things are asserted so the note cannot rot: the
         * anchor the instruction names is still present verbatim (a moved
         * anchor makes the instruction unapplicable), and the endpoint already
         * carries the key the replacement reads — because a paste that lands on
         * a key nothing sends would blank every preview instead of fixing it,
         * which is strictly worse than the invented sentence.
         */
        expect(str_contains($shell, sdpInventedTail()))
            ->toBeTrue('the snippet preview no longer carries the invented sentence — flip sdpSnippetEditPending() to false.');

        expect(str_contains($shell, "if(d) d.textContent = (peSeo.meta_description||'').trim() ||"))
            ->toBeTrue('the anchor line the integrator instruction names has moved; rewrite the instruction.');

        expect(sdpEditorPayload(sdpProduct()))
            ->toHaveKey('seo_fallback_description');

        return;
    }

    // The edit has landed: the preview reads the endpoint and invents nothing.
    expect(str_contains($shell, 'peCtx.fallbackDesc'))
        ->toBeTrue('the snippet preview must read the storefront\'s own fallback.');

    expect(str_contains($shell, 'full.seo_fallback_description'))
        ->toBeTrue('the loader must put the endpoint\'s fallback where the snippet can read it.');

    expect(str_contains($shell, sdpInventedTail()))
        ->toBeFalse('the invented description is still in the shell.');
});

it('leaves nothing pending that the shell has already done', function () {
    // The mirror of the guard above, so an exemption cannot outlive its reason.
    // Stated unconditionally first: a loop that never runs is a test that
    // asserts nothing.
    expect(sdpSnippetEditPending())->toBeBool();

    if (! sdpSnippetEditPending()) {
        return;
    }

    expect(str_contains(sdpShell(), 'peCtx.fallbackDesc'))
        ->toBeFalse('the shell already reads the real fallback, so sdpSnippetEditPending() must be false.');
});
