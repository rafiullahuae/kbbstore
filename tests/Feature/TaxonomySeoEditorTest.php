<?php

/**
 * The brand and category SEO forms, and the keys they used to destroy.
 *
 * ── THE DEFECT
 *
 * `brands.seo` and `categories.seo` were wired to the storefront this round.
 * Both are read through App\Support\ProductSeo::normalise(), so both act on the
 * FULL published vocabulary — title, desc, canonical, og_image, noindex.
 * Store\BrandController::seoCtx() reads all five; Store\ShopController reads all
 * five; Store\SeoFilesController::isNoindex() reads `noindex` out of both
 * columns to decide whether the sitemap may advertise the URL.
 *
 * The two admin screens collect TWO of them. That alone would only be a missing
 * feature. What made it a defect is how the two write paths ended:
 *
 *     $seo = array_filter([
 *         'title'       => trim((string) ($data['seo']['title'] ?? '')),
 *         'description' => trim((string) ($data['seo']['description'] ?? '')),
 *     ], fn ($v) => $v !== '');
 *
 *     $data['seo'] = $seo === [] ? null : $seo;
 *
 * The column is REBUILT from the two boxes the screen draws. Every other key is
 * dropped on the floor — not left alone, deleted. So a `noindex` set by the
 * Yoast importer, by a migration or by hand survived exactly until the next
 * time anybody opened that brand in the admin and pressed Save, at which point
 * the page quietly went back to being indexable and the sitemap quietly went
 * back to advertising it. Nothing on any screen said so, because the screen
 * never knew the key existed.
 *
 * That is the same shape as the bug ProductSeo's own header was written for —
 * a write path and a read path with different vocabularies — and it is the one
 * CLAUDE.md records this project having already paid for once.
 *
 * ── WHAT IS PINNED HERE
 *
 * 1. A key the screen does not draw SURVIVES a save. This is the data-loss
 *    case and it is the reason the lane exists.
 * 2. The screen can SET all five, so `noindex` has a writer at all — it had
 *    three readers and none.
 * 3. Clearing a box the screen DOES draw still deletes that key. Preservation
 *    must not become "nothing can ever be removed".
 * 4. A body with no `seo` at all leaves the column alone rather than wiping it.
 * 5. The round trip reaches the three documents that have to agree: the page's
 *    robots tag, the sitemap, and the hreflang cluster.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\AdminUser;
use Tests\Support\BrandAdminRoutes;
use Tests\Support\CatalogAdminRoutes;

function tseAdmin(): AdminUser
{
    return AdminUser::firstOrCreate(
        ['email' => 't-tse-admin@example.test'],
        ['name' => 'T TSE Admin', 'password' => 'password-long-enough', 'role' => 'owner']
    );
}

function tseBrand(?array $seo = null): Brand
{
    $brand = Brand::firstOrCreate(['slug' => 't-tse-brand'], ['name' => 'T TSE Brand']);
    $brand->forceFill(['seo' => $seo])->save();

    Product::firstOrCreate(['slug' => 't-tse-prod'], [
        'name' => 'T TSE Prod', 'brand_id' => $brand->id, 'status' => 'publish',
        'is_visible' => true, 'price' => 5500, 'stock_status' => 'instock', 'type' => 'simple',
    ]);

    return $brand->fresh();
}

function tseCategory(?array $seo = null): Category
{
    $category = Category::firstOrCreate(
        ['slug' => 't-tse-cat'],
        ['name' => 'T TSE Cat', 'path' => 't-tse-cat']
    );
    $category->forceFill(['seo' => $seo])->save();

    return $category->fresh();
}

/** The body the brand screen sends: every box it draws, always. */
function tseBrandBody(array $seo = []): array
{
    return [
        'name' => 'T TSE Brand',
        'slug' => 't-tse-brand',
        'description' => 'A brand.',
        'position' => 0,
        'seo' => $seo,
    ];
}

function tseCategoryBody(array $seo = []): array
{
    return [
        'name' => 'T TSE Cat',
        'slug' => 't-tse-cat',
        'seo' => $seo,
    ];
}

describe('the brand SEO form', function () {
    beforeEach(function () {
        BrandAdminRoutes::wire($this->app);
        $this->actingAs(tseAdmin(), 'admin');
    });

    /* (1) THE DATA-LOSS CASE. */
    it('keeps a noindex the screen does not draw when the screen saves', function () {
        $brand = tseBrand(['noindex' => true, 'canonical' => 'https://example.test/x/']);

        $this->putJson('/admin-api/brands/' . $brand->id, tseBrandBody([
            'title' => 'T Brand SEO title',
            'description' => 'T Brand SEO description',
        ]))->assertOk();

        $seo = $brand->fresh()->seo;

        expect($seo['noindex'] ?? null)->toBeTrue()
            ->and($seo['canonical'] ?? null)->toBe('https://example.test/x/');
    });

    /* (2) noindex HAS A WRITER NOW. */
    it('can set noindex, canonical and og_image from the screen', function () {
        $brand = tseBrand();

        $this->putJson('/admin-api/brands/' . $brand->id, tseBrandBody([
            'title' => 'T Brand SEO title',
            'description' => 'T Brand SEO description',
            'canonical' => 'https://example.test/canonical/',
            'og_image' => '/uploads/brand-card.jpg',
            'noindex' => true,
        ]))->assertOk();

        $seo = $brand->fresh()->seo;

        expect($seo['noindex'] ?? null)->toBeTrue()
            ->and($seo['canonical'] ?? null)->toBe('https://example.test/canonical/')
            ->and($seo['og_image'] ?? null)->toBe('/uploads/brand-card.jpg');
    });

    /* (3) PRESERVATION IS NOT "NOTHING CAN BE REMOVED". */
    it('deletes a key when the operator clears the box that draws it', function () {
        $brand = tseBrand(['title' => 'T Old title', 'noindex' => true]);

        $this->putJson('/admin-api/brands/' . $brand->id, tseBrandBody([
            'title' => '',
            'description' => '',
            'noindex' => false,
        ]))->assertOk();

        $seo = $brand->fresh()->seo;

        expect($seo['title'] ?? null)->toBeNull()
            ->and($seo['noindex'] ?? null)->toBeNull();
    });

    /* (4) A BODY WITH NO seo AT ALL. */
    it('leaves the column alone when the body carries no seo key', function () {
        $brand = tseBrand(['title' => 'T Kept', 'noindex' => true]);

        $body = tseBrandBody();
        unset($body['seo']);

        $this->putJson('/admin-api/brands/' . $brand->id, $body)->assertOk();

        $seo = $brand->fresh()->seo;

        expect($seo['title'] ?? null)->toBe('T Kept')
            ->and($seo['noindex'] ?? null)->toBeTrue();
    });
});

describe('the category SEO form', function () {
    beforeEach(function () {
        CatalogAdminRoutes::wire($this->app);
        $this->actingAs(tseAdmin(), 'admin');
    });

    it('keeps a noindex the screen does not draw when the screen saves', function () {
        $category = tseCategory(['noindex' => true, 'og_image' => '/uploads/cat.jpg']);

        $this->putJson('/admin-api/categories/' . $category->id, tseCategoryBody([
            'title' => 'T Cat SEO title',
            'description' => 'T Cat SEO description',
        ]))->assertOk();

        $seo = $category->fresh()->seo;

        expect($seo['noindex'] ?? null)->toBeTrue()
            ->and($seo['og_image'] ?? null)->toBe('/uploads/cat.jpg');
    });

    it('can set noindex, canonical and og_image from the screen', function () {
        $category = tseCategory();

        $this->putJson('/admin-api/categories/' . $category->id, tseCategoryBody([
            'title' => 'T Cat SEO title',
            'canonical' => 'https://example.test/cat/',
            'og_image' => '/uploads/cat-card.jpg',
            'noindex' => true,
        ]))->assertOk();

        $seo = $category->fresh()->seo;

        expect($seo['noindex'] ?? null)->toBeTrue()
            ->and($seo['canonical'] ?? null)->toBe('https://example.test/cat/')
            ->and($seo['og_image'] ?? null)->toBe('/uploads/cat-card.jpg');
    });
});

/* ────────────────────────────── the round trip ────────────────────────────
   The write path meeting the three documents that have to agree about it.
   TaxonomySeoOverridesTest already pins those three against a noindex placed
   DIRECTLY in the column; this pins the seam between that and the admin — the
   screen really can produce the state that test describes, which is the half
   that did not exist. */

it('a noindex set through the brand screen reaches the page and leaves the sitemap', function () {
    BrandAdminRoutes::wire($this->app);
    $this->actingAs(tseAdmin(), 'admin');

    $brand = tseBrand();

    // Before: listed, and indexable.
    expect($this->get('/sitemap.xml')->getContent())->toContain('/korean-skincare-brands/t-tse-brand/');

    $this->putJson('/admin-api/brands/' . $brand->id, tseBrandBody([
        'title' => '', 'description' => '', 'noindex' => true,
    ]))->assertOk();

    $html = $this->get('/korean-skincare-brands/t-tse-brand/')->assertOk()->getContent();

    preg_match('#<meta name="robots" content="([^"]+)">#', $html, $m);

    expect($m[1] ?? '')->toContain('noindex')
        // And the sitemap stops advertising it in the same move. Google reports
        // "page says noindex, sitemap submits it" as an error against the
        // property rather than quietly honouring the page.
        ->and($this->get('/sitemap.xml')->getContent())
        ->not->toContain('/korean-skincare-brands/t-tse-brand/');
});

/*
 * THE SAVE THE SCREEN ACTUALLY MAKES MOST OFTEN: every box empty.
 *
 * Both screens now post all five keys on every save, so a brand nobody has
 * written SEO for posts canonical:'' — and `seo.canonical` carries a `url`
 * rule. `nullable` exempts null, NOT the empty string, so whether this is a
 * 200 or a 422 depends on ConvertEmptyStringsToNull being in the global
 * middleware stack. That is a framework default this application could remove
 * tomorrow without anyone connecting it to a brand dialog that stopped saving,
 * which is exactly why it is pinned here rather than reasoned about.
 */
it('accepts the all-blank SEO body the screen posts for a brand with no overrides', function () {
    BrandAdminRoutes::wire($this->app);
    $this->actingAs(tseAdmin(), 'admin');

    $brand = tseBrand();

    $this->putJson('/admin-api/brands/' . $brand->id, tseBrandBody([
        'title' => '', 'description' => '', 'canonical' => '', 'og_image' => '', 'noindex' => false,
    ]))->assertOk();

    // Nothing survives, and the column is null rather than an empty array —
    // `is_array($brand->seo)` is the test every reader uses.
    expect($brand->fresh()->seo)->toBeNull();
});

it('accepts the all-blank SEO body the screen posts for a category with no overrides', function () {
    CatalogAdminRoutes::wire($this->app);
    $this->actingAs(tseAdmin(), 'admin');

    $category = tseCategory();

    $this->putJson('/admin-api/categories/' . $category->id, tseCategoryBody([
        'title' => '', 'description' => '', 'canonical' => '', 'og_image' => '', 'noindex' => false,
    ]))->assertOk();

    expect($category->fresh()->seo)->toBeNull();
});

/* And a canonical that is NOT a URL is still refused, so the rule above is
   doing work rather than being satisfied by everything. */
it('refuses a canonical that is not a full address', function () {
    BrandAdminRoutes::wire($this->app);
    $this->actingAs(tseAdmin(), 'admin');

    $brand = tseBrand();

    $this->putJson('/admin-api/brands/' . $brand->id, tseBrandBody([
        'canonical' => '/korean-skincare-brands/t-tse-brand/',
    ]))->assertStatus(422)->assertJsonValidationErrors('seo.canonical');
});
