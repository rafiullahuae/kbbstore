<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use App\Models\Setting;
use App\Services\SettingsService;

/**
 * `%%title%%` typed into a brand, category or article SEO box, and the three
 * <head>s that used to delete it.
 *
 * ── THE DEFECT, WHICH IS THE PRODUCT ONE IN THREE MORE PLACES ───────────────
 *
 * App\Support\Seo::titleOf() has a branch for a FINAL title — one the owner
 * typed, published verbatim with no site name appended — and that branch
 * resolves the string as a TEMPLATE through Services\Seo\TitleTemplate. Render
 * deletes every token it was not handed. The branch was handed `sep`,
 * `sitename` and `page`, and NOT `title`.
 *
 * On products that shipped: Yoast's own default product template is
 * `%%title%% %%sep%% %%sitename%%`, an import wrote it into `products.seo.title`
 * for all 671 rows, and every product page published `<title>K-Beauty Bliss`
 * — the same six words on every page in the catalogue, while the import
 * reported every row imported. Fixed by having the caller supply the row the
 * token stands for, under `title_token`.
 *
 * The same branch serves three more pages, and they were left alone at the
 * time because NOTHING IMPORTS a Yoast template into `brands.seo`,
 * `categories.seo` or `posts.seo` — the columns exist, but no importer writes
 * them. That is the whole reason this matters rather than a reason to skip it:
 * the only way a template reaches those columns is an operator TYPING one into
 * the SEO box on the screen, and a box that silently deletes what you type into
 * it is worse than a box that refuses it. Yoast's chips are the syntax this
 * shop's own product editor hands out (resources/views/admin/app.blade.php
 * offers %%title%%, %%page%%, %%sep%% and %%sitename%%), so an operator who has
 * used that screen has every reason to type the same thing here.
 *
 *   Catalog → Brands → SEO → Page title
 *   Store → Catalog → Categories → SEO → Page title
 *   Content → Journal → (article) → SEO → Page title
 *
 * ── WHAT WENT RED, AND WHAT THE MUTATION IS ─────────────────────────────────
 *
 * Each of the three cases below fails without its one-line caller change:
 *
 *   Store\BrandController::seoCtx()    $ctx['title_token'] = $brand->t('name')
 *   Store\ShopController::index()      'title_token' => $category?->t('name')
 *   Store\PageController::post()       'title_token' => $post->t('title')
 *
 * MUTATION NOTE: delete any one of those three lines and that page's case goes
 * red with the token GONE rather than resolved — the brand case reads
 * "| K-Beauty Bliss" collapsed to "K-Beauty Bliss", which is the exact bytes
 * the 671 product pages published. The other two stay green, which is what
 * makes these three separate cases rather than one.
 *
 * ── AND THE HALF THAT MUST NOT MOVE ─────────────────────────────────────────
 *
 * `title_token` is read by Seo::titleOf() ONLY inside the `title_is_final`
 * branch, and the brand and category callers set it only alongside
 * `title_is_final`. So a brand, category or article with an empty SEO title
 * publishes the title it published before, through `seo_title_template`, with
 * the site name appended. The last three cases pin that, because "the fix moved
 * nothing else" is the claim this repository actually checks.
 */

/**
 * The site name these cases are written against.
 *
 * SET RATHER THAN READ. `%%sitename%%` resolves to `seo_site_name`, then
 * `store_name`, then APP_NAME — so a test that hard-coded the third would be
 * pinning phpunit.xml, and one that computed the winner would be reimplementing
 * SeoSettings::firstFilled() in order to check it. Writing the first candidate
 * makes the expected string knowable and says so out loud.
 */
const TYT_SITE = 'KBB Test Shop';

beforeEach(function () {
    Setting::query()->updateOrCreate(
        ['key' => 'seo_site_name'],
        ['value' => TYT_SITE, 'autoload' => true]
    );

    // Setting::map() memoises in a process-level static as well as in the
    // cache, which CLAUDE.md records as a trap in exactly this position.
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
});

/** The <title> this page publishes, decoded. */
function tytTitle(string $uri): string
{
    $html = test()->get($uri)->assertOk()->getContent();

    expect($html)->toMatch('#<title>.*</title>#s');

    preg_match('#<title>(.*?)</title>#s', $html, $m);

    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}

/** A brand with one live product, so the landing page has something to list. */
function tytBrand(?array $seo): Brand
{
    $brand = Brand::firstOrCreate(['slug' => 'tyt-roundlab'], ['name' => 'Round Lab']);
    $brand->forceFill(['seo' => $seo])->save();

    Product::firstOrCreate(
        ['slug' => 'tyt-dokdo-toner'],
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

function tytCategory(?array $seo): Category
{
    $category = Category::firstOrCreate(
        ['slug' => 'tyt-serums'],
        ['name' => 'Serums', 'path' => 'tyt-serums']
    );
    $category->forceFill(['seo' => $seo])->save();

    return $category;
}

function tytPost(?array $seo): Post
{
    return Post::create([
        'slug' => 'tyt-heartleaf-extract',
        'title' => 'Heartleaf extract, transforming K-beauty skincare',
        'excerpt' => 'One ingredient has quietly become a staple.',
        'body' => '<p>Heartleaf is a calming botanical.</p>',
        'tag' => 'Ingredients',
        'status' => 'published',
        'published_at' => now()->subDays(3),
        'seo' => $seo,
    ]);
}

/* ──────────────────────────── the token resolves ─────────────────────────── */

it('resolves %%title%% in a brand SEO title instead of deleting it', function () {
    /*
     * THE ASSERTION IS AGAINST THE PAGE'S OWN DEFAULT, NOT AGAINST A LITERAL.
     *
     * SeoSettings ships `seo_title_template` as `{title} {sep} {sitename}`, and
     * Yoast's shipped product template is the same three tokens in the same
     * order spelt `%%title%% %%sep%% %%sitename%%` — TitleTemplate::render()
     * accepts both syntaxes deliberately. So typing Yoast's default into the
     * box must produce EXACTLY the title the empty box produces, and that is a
     * comparison this test can make without knowing what the site is called.
     * Hard-coding the site name would pin the test environment's APP_NAME
     * instead of the behaviour.
     */
    $expected = tytTitle('/korean-skincare-brands/' . tytBrand(null)->slug . '/');

    $brand = tytBrand(['title' => '%%title%% %%sep%% %%sitename%%']);

    $title = tytTitle('/korean-skincare-brands/' . $brand->slug . '/');

    // The brand's own name is what %%title%% means on a brand archive.
    expect($title)->toBe($expected)
        // The failure this exists for, said the other way round as well: with
        // the token deleted the title collapses to the SITE NAME ALONE, tidy()
        // having eaten the separator left dangling in front of it. That is the
        // exact string 671 product pages published.
        ->and($title)->toStartWith('Round Lab');
});

it('resolves %%title%% in a category SEO title instead of deleting it', function () {
    $category = tytCategory(['title' => 'Buy %%title%% %%sep%% %%sitename%%']);

    $title = tytTitle('/product-category/' . $category->path . '/');

    // Every token resolved: the category's own name for %%title%%, the stored
    // separator for %%sep%%, the site name for %%sitename%%. Without the fix
    // the first of the three is deleted and tidy() closes the gap.
    expect($title)->toBe('Buy Serums | ' . TYT_SITE);
});

it('resolves %%title%% in an article SEO title instead of deleting it', function () {
    $post = tytPost(['title' => '%%title%% %%sep%% %%sitename%%']);

    $title = tytTitle('/' . $post->slug . '/');

    $post->forceFill(['seo' => null])->save();

    // Same comparison as the brand case: Yoast's default and this store's
    // default are the same three tokens, so they must render the same bytes.
    expect($title)->toBe(tytTitle('/' . $post->slug . '/'))
        ->and($title)->toStartWith('Heartleaf extract, transforming K-beauty skincare');
});

it('still deletes a token nothing knows about', function () {
    // The token cleanup is not weakened by the fix: a misspelling still goes,
    // rather than reaching the browser tab as literal percent signs.
    $brand = tytBrand(['title' => '%%title%% %%sitnam%%']);

    expect(tytTitle('/korean-skincare-brands/' . $brand->slug . '/'))->toBe('Round Lab');
});

/* ─────────────────────── and nothing else may move ───────────────────────── */

it('leaves a brand with no SEO title on the template it always used', function () {
    $brand = tytBrand(null);

    $title = tytTitle('/korean-skincare-brands/' . $brand->slug . '/');

    // Through `seo_title_template`, site name appended — NOT the verbatim name.
    expect($title)->toStartWith('Round Lab ')
        ->and($title)->not->toBe('Round Lab');
});

it('leaves a category with no SEO title on the template it always used', function () {
    $category = tytCategory(null);

    $title = tytTitle('/product-category/' . $category->path . '/');

    expect($title)->toStartWith('Serums ')
        ->and($title)->not->toBe('Serums');
});

it('leaves an article with no SEO title on the template it always used', function () {
    $post = tytPost(null);

    $title = tytTitle('/' . $post->slug . '/');

    expect($title)->toStartWith('Heartleaf extract, transforming K-beauty skincare ')
        ->and($title)->not->toBe('Heartleaf extract, transforming K-beauty skincare');
});

it('leaves a plain typed SEO title verbatim, with no site name appended', function () {
    // The `title_is_final` promise, unchanged: a title with no tokens in it is
    // the whole title. Adding `title_token` must not start appending anything.
    $brand = tytBrand(['title' => 'Round Lab UAE — Dokdo cleansing line']);

    expect(tytTitle('/korean-skincare-brands/' . $brand->slug . '/'))
        ->toBe('Round Lab UAE — Dokdo cleansing line');
});
