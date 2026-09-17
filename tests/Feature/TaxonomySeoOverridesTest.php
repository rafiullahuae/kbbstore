<?php

/**
 * `brands.seo` and `categories.seo` — two columns nothing read.
 *
 * ── THE DEFECT
 *
 * 2026_10_05_add_category_seo_and_redirects added a `json` column called `seo`
 * to both tables, and its own header says the shape is "deliberately identical
 * to `products.seo` so there is one shape in this app for the SEO overrides of
 * a thing". Store\ProductController::show() has read that shape on products for
 * months. Nothing read either of the other two.
 *
 * So Catalog → Brands and Store → Catalog → Categories collected an SEO title
 * and description, validated them, saved them into the column, and reported
 * success — and every brand landing page went on publishing the store-wide
 * default meta description, while every category archive went on taking its
 * <title> from the bare category name. Dead schema on one side, a missing
 * feature on the other. Reproduced against a running preview before the fix by
 * saving a brand SEO title and fetching /korean-skincare-brands/round-lab/.
 *
 * ── THE KEY-NAME TRAP, WHICH IS WHY normalise() IS ON THE READ
 *
 * The admin screens for brands and categories write `seo.description`. The
 * published vocabulary — ProductSeo::PUBLISHED_KEYS, what the storefront reads
 * — spells that key `desc`. Wiring the column up by reading the raw array would
 * have found no `desc` and published nothing, which is the SECOND of the two
 * independent bugs ProductSeo's header records against products. Running the
 * stored bag through ProductSeo::normalise() applies the RENAME that already
 * exists for exactly this, so the taxonomy pages use the product mechanism
 * rather than a parallel one.
 *
 * `it('publishes a description saved under the admin's own key name')` is that
 * case, and it is the one that fails if somebody later "simplifies" the read
 * to `$brand->seo['desc']`.
 *
 * ── noindex IS THE DANGEROUS ONE
 *
 * Setting it has to reach three documents, not one: the page's robots tag, the
 * sitemap (Google reports "page says noindex, sitemap submits it" as an error
 * against the property rather than honouring the page), and the hreflang set
 * (an alternates cluster containing a noindexed member is dropped whole, which
 * costs the OTHER language its alternate too). All three are asserted here.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Locale;
use App\Support\Url;

/** A brand with a live product, so the sitemap is willing to list it at all. */
function tsoBrand(?array $seo = null): Brand
{
    $brand = Brand::firstOrCreate(['slug' => 'tso-roundlab'], ['name' => 'Round Lab']);
    $brand->forceFill(['seo' => $seo])->save();

    Product::firstOrCreate(
        ['slug' => 'tso-dokdo-toner'],
        [
            'name' => 'Dokdo Toner',
            'brand_id' => $brand->id,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 8500,
            'stock_status' => 'instock',
            'type' => 'simple',
        ]
    );

    return $brand;
}

function tsoCategory(?array $seo = null): Category
{
    $category = Category::firstOrCreate(
        ['slug' => 'tso-serums'],
        ['name' => 'Serums', 'path' => 'tso-serums']
    );
    $category->forceFill(['seo' => $seo])->save();

    return $category;
}

/** The <head> tags these tests care about, as [tag => content]. */
function tsoHead(string $html): array
{
    $out = [];

    if (preg_match('#<title>(.*?)</title>#s', $html, $m)) {
        $out['title'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    foreach (['description', 'robots'] as $name) {
        if (preg_match('#<meta name="' . $name . '" content="(.*?)">#s', $html, $m)) {
            $out[$name] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
    }

    if (preg_match('#<link rel="canonical" href="(.*?)">#s', $html, $m)) {
        $out['canonical'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    if (preg_match('#<meta property="og:image" content="(.*?)">#s', $html, $m)) {
        $out['og:image'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    preg_match_all('#<link rel="alternate" hreflang="([^"]+)" href="([^"]+)">#', $html, $alts, PREG_SET_ORDER);
    $out['alternates'] = array_column($alts, 1);

    return $out;
}

function tsoBrandHead(Brand $brand): array
{
    return tsoHead(test()->get('/korean-skincare-brands/' . $brand->slug . '/')->assertOk()->getContent());
}

function tsoCategoryHead(Category $category, string $query = ''): array
{
    return tsoHead(test()->get('/product-category/' . $category->path . '/' . $query)->assertOk()->getContent());
}

/** Turn Arabic on the way the Translation settings screen does. */
function tsoArabicOn(): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

/* ──────────────────────────────── brands ─────────────────────────────────── */

it('publishes a brand title the owner typed, as the whole title', function () {
    $brand = tsoBrand(['title' => 'Round Lab UAE — Dokdo cleansing line']);

    $head = tsoBrandHead($brand);

    // Verbatim, with no site name appended: `title_is_final`, exactly as a
    // per-product SEO title behaves. The default path templates the name and
    // appends " | <site>", which is what the next assertion pins.
    expect($head['title'])->toBe('Round Lab UAE — Dokdo cleansing line');
});

it("publishes a brand description saved under the admin's own key name", function () {
    // `description`, which is what Admin\BrandsApiController writes — NOT
    // `desc`, which is what the storefront reads. ProductSeo::RENAME is the
    // bridge; a read that skips it publishes nothing and this test is the one
    // that says so.
    $brand = tsoBrand(['description' => 'Round Lab Dokdo toner and sunscreen, shipped across the UAE.']);

    expect(tsoBrandHead($brand)['description'])
        ->toBe('Round Lab Dokdo toner and sunscreen, shipped across the UAE.');
});

it('leaves a brand with no overrides exactly as it was', function () {
    $brand = tsoBrand(null);

    $head = tsoBrandHead($brand);

    // The brand name through the title template, the store default description,
    // the computed canonical. Nothing about an unconfigured brand may move.
    expect($head['title'])->toContain('Round Lab');
    expect($head['title'])->not->toBe('Round Lab');
    expect($head['robots'])->toBe('index, follow');
    expect($head['canonical'])->toContain('/korean-skincare-brands/tso-roundlab/');
});

it('lets a brand canonical override replace the computed one, absolutely', function () {
    $brand = tsoBrand(['canonical' => 'https://kbeautybliss.com/brands/round-lab/']);

    // A full URL passes through Url::absolute() untouched. Url::to() would have
    // prefixed it or re-localised it; absolute() is the method whose whole
    // purpose is a path that already carries what it means.
    expect(tsoBrandHead($brand)['canonical'])->toBe('https://kbeautybliss.com/brands/round-lab/');
});

it('honours the base path in a root-relative brand canonical override', function () {
    // The override is the shape an owner types into a box: a site path, not a
    // URL. It has to come out carrying KBB_BASE_PATH, or the canonical 404s on
    // the live host — the class of bug a test on the default base path cannot
    // see, which is why this one sets the base path.
    config(['kbb.base_path' => '/kbb-upgrade']);
    Url::forgetBase();

    $brand = tsoBrand(['canonical' => '/brands/round-lab/']);

    expect(tsoBrandHead($brand)['canonical'])->toContain('/kbb-upgrade/brands/round-lab/');

    config(['kbb.base_path' => '']);
    Url::forgetBase();
});

it('publishes a brand og:image override over the page hero', function () {
    $brand = tsoBrand(['og_image' => '/wp-content/uploads/roundlab-share.jpg']);

    expect(tsoBrandHead($brand)['og:image'])->toContain('/wp-content/uploads/roundlab-share.jpg');
});

it('noindexes a brand on the page AND drops it from the sitemap', function () {
    $brand = tsoBrand(['noindex' => true]);

    expect(tsoBrandHead($brand)['robots'])->toBe('noindex, nofollow');

    $sitemap = test()->get('/sitemap.xml')->assertOk()->getContent();

    // The page saying noindex while the sitemap submits the same URL is what
    // Search Console reports as "Submitted URL marked noindex" — an error
    // against the property, not a quietly honoured instruction.
    expect($sitemap)->not->toContain('/korean-skincare-brands/' . $brand->slug . '/');
});

it('still lists an ordinary brand in the sitemap', function () {
    // The guard on the skip: it is a filter, not an amputation.
    $brand = tsoBrand(null);

    expect(test()->get('/sitemap.xml')->assertOk()->getContent())
        ->toContain('/korean-skincare-brands/' . $brand->slug . '/');
});

/* ────────────────────────────── categories ───────────────────────────────── */

it('publishes a category title and description the owner typed', function () {
    $category = tsoCategory([
        'title' => 'Korean Serums in the UAE',
        'description' => 'Every Korean serum K-Beauty Bliss carries, in stock in Dubai.',
    ]);

    $head = tsoCategoryHead($category);

    expect($head['title'])->toBe('Korean Serums in the UAE');
    expect($head['description'])->toBe('Every Korean serum K-Beauty Bliss carries, in stock in Dubai.');
});

it('leaves a category with no overrides exactly as it was', function () {
    $category = tsoCategory(null);

    $head = tsoCategoryHead($category);

    expect($head['robots'])->toBe('index, follow');
    // ShopController::seoDescription()'s sentence, unchanged.
    expect($head['description'])->toContain('Shop Serums at K-Beauty Bliss');
    expect($head['canonical'])->toContain('/product-category/tso-serums/');
});

it('keeps pagination self-canonical under a category canonical override', function () {
    // Enough rows for a second page to exist. /shop 404s a ?paged beyond the
    // last page on purpose (an out-of-range page used to answer 200 with the
    // page-one grid and self-canonicalise), so the canonical on page two can
    // only be asserted on a category that really has one.
    $filled = tsoCategory(null);

    for ($i = 0; $i < 30; $i++) {
        $p = Product::create([
            'slug' => 'tso-page-' . $i,
            'name' => 'Paged Product ' . $i,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 5000 + $i,
            'stock_status' => 'instock',
            'type' => 'simple',
        ]);
        $p->categories()->syncWithoutDetaching([$filled->id]);
    }

    // THE TRAP. Substituting the owner's URL for the WHOLE canonical would make
    // every page of the category, and every filtered view of it, claim to be
    // one document — which is the defect Facets::canonicalUrl() exists to
    // avoid, and which costs Google everything only reachable from page two.
    // The override replaces the clean BASE the method builds from, so paging
    // behaves as it does on every other listing.
    $category = tsoCategory(['canonical' => '/korean-serums/']);

    expect(tsoCategoryHead($category)['canonical'])->toContain('/korean-serums/');
    expect(tsoCategoryHead($category)['canonical'])->not->toContain('paged');

    expect(tsoCategoryHead($category, '?paged=2')['canonical'])->toContain('/korean-serums/?paged=2');
});

it('noindexes a category on the page AND drops it from the sitemap', function () {
    // '1' rather than true: the flag is written by hand, by the importer and by
    // the admin screen, and ProductSeo::normalise() is what turns any of those
    // into a real bool before `!empty()` reads it.
    $category = tsoCategory(['noindex' => '1']);

    expect(tsoCategoryHead($category)['robots'])->toBe('noindex, nofollow');

    expect(test()->get('/sitemap.xml')->assertOk()->getContent())
        ->not->toContain('/product-category/' . $category->path . '/');
});

it('still lists an ordinary category in the sitemap', function () {
    $category = tsoCategory(null);

    expect(test()->get('/sitemap.xml')->assertOk()->getContent())
        ->toContain('/product-category/' . $category->path . '/');
});

/* ───────────────────────────── hreflang, once ────────────────────────────── */

it('publishes no hreflang alternate for a page that says noindex', function () {
    tsoArabicOn();

    // Indexable: both languages and x-default, which is the behaviour the
    // bilingual lane shipped and which must not be lost.
    $ordinary = tsoBrand(null);
    expect(tsoBrandHead($ordinary)['alternates'])->not->toBeEmpty();

    // Noindexed: none. An alternates cluster whose members include a noindexed
    // page is dropped whole by Google, so leaving these on costs the ARABIC
    // page its alternate as well as saying two things at once.
    $hidden = tsoBrand(['noindex' => true]);
    expect(tsoBrandHead($hidden)['robots'])->toBe('noindex, nofollow');
    expect(tsoBrandHead($hidden)['alternates'])->toBe([]);
});
