<?php

/**
 * How far the "Title template" box on SEO & Meta actually reaches — Lane EM.
 *
 * ── THE REPORT, AND WHAT MEASURING IT FOUND ─────────────────────────────────
 *
 * The finding handed to this lane was "`seo_title_template` has no effect on
 * product pages". Rendered against real pages, that is NOT what happens, and
 * the difference decides which fix is the right one:
 *
 *   TEMPLATE "Buy {title} today"
 *     product -> "Buy Anua Heartleaf Quercetinol Toner · K-Beauty Bliss today"
 *
 * The template drives product titles perfectly well. What it cannot do THERE is
 * move the site name, and the cause is not the template engine — it is that
 * store/product.blade.php ends its own title with the site name (via
 * ProductTitle::head()), as do shop, collection, cart, checkout, wishlist,
 * orders, tracking and CMS pages. App\Support\Seo::tokens() then blanks
 * {sitename} so the brand is not printed twice, and TitleTemplate::tidy() drops
 * the separator it was attached to. The pages that do NOT bake in that suffix —
 * the brand directory, the account area, the newsletter pages — honour both
 * tokens normally. The home page ignores the template outright.
 *
 * So the control is not dead; it is INCONSISTENT, and nothing on the screen
 * says which page is which. That is what this file pins.
 *
 * ── WHY THE BEHAVIOUR IS PINNED AND NOT CHANGED ─────────────────────────────
 *
 * Making {sitename} live on product pages means removing the suffix from
 * ProductTitle::head(), which moves every product's browser tab and every
 * product's Google result at once on a shop that has not asked for it. That is
 * the owner's call. The behaviour is therefore left exactly as it is, stated
 * honestly by AdminController::titleTemplateBasis(), and locked here so that
 * neither the behaviour nor the sentence describing it can move alone.
 *
 * ── READ OFF THE RESPONSE, NOT OUT OF App\Support\Seo ───────────────────────
 *
 * Every title below is scraped from the bytes the page serves. An assertion
 * made against that class would pass while the pages emitted something else,
 * which is how this SEO engine once sat fully built and entirely disconnected.
 */

use App\Http\Controllers\Admin\AdminController;
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Str;

function ttrSettings(array $values): void
{
    foreach ($values as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    Setting::flushMap();
}

beforeEach(function () {
    ttrSettings([
        'site_url' => 'https://kbeautybliss.test',
        'seo_site_name' => 'K-Beauty Bliss',
        'seo_separator' => '|',
        'seo_title_template' => '{title} {sep} {sitename}',
        'seo_default_description' => 'Korean skincare and K-beauty, shipped across the Gulf.',
    ]);
});

function ttrProduct(): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'ttr-anua'], ['name' => 'Anua']);

    return Product::create([
        'slug' => 'ttr-' . Str::random(10),
        'name' => 'Heartleaf Quercetinol Toner',
        'brand_id' => $brand->id,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
    ]);
}

/** The <title> the crawler is actually served for this URL. */
function ttrTitle(string $url): string
{
    $html = (string) test()->get($url)->assertOk()->getContent();
    $m = [];

    expect(preg_match('#<title>(.*?)</title>#s', $html, $m))->toBe(1);

    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}

/*
|------------------------------------------------------------------------------
| 1. The template DOES reach product pages
|------------------------------------------------------------------------------
*/

it('puts template text into a product title, contrary to the report', function () {
    $product = ttrProduct();

    ttrSettings(['seo_title_template' => 'Buy {title} today']);

    expect(ttrTitle('/product/' . $product->slug))
        ->toBe('Buy Anua Heartleaf Quercetinol Toner · K-Beauty Bliss today');

    ttrSettings(['seo_title_template' => '{title} - CLEARANCE']);

    expect(ttrTitle('/product/' . $product->slug))
        ->toBe('Anua Heartleaf Quercetinol Toner · K-Beauty Bliss - CLEARANCE');
});

it('reaches the other pages that build their own suffixed title', function () {
    ttrSettings(['seo_title_template' => 'Buy {title} today']);

    expect(ttrTitle('/shop/'))->toStartWith('Buy ');
    expect(ttrTitle('/shop/'))->toEndWith(' today');
    expect(ttrTitle('/cart/'))->toBe('Buy Cart · K-Beauty Bliss today');
});

/*
|------------------------------------------------------------------------------
| 2. …but {sitename} and {sep} are inert on exactly those pages
|------------------------------------------------------------------------------
*/

it('cannot move the site name on a page that already ends in it', function () {
    /*
     * THE REAL DEFECT, AND THE ONE THE SCREEN HAS TO ADMIT TO. Reversing the
     * template changes nothing here, because {sitename} resolves to '' (the
     * raw title already contains the site name) and tidy() then eats the
     * dangling separator. The operator sees a box that works on one page and
     * not on the next with nothing explaining the difference.
     */
    $product = ttrProduct();
    $url = '/product/' . $product->slug;

    ttrSettings(['seo_title_template' => '{title} {sep} {sitename}']);
    $forwards = ttrTitle($url);

    ttrSettings(['seo_title_template' => '{sitename} {sep} {title}']);
    $backwards = ttrTitle($url);

    expect($forwards)->toBe('Anua Heartleaf Quercetinol Toner · K-Beauty Bliss');
    expect($backwards)->toBe($forwards);

    // And the same on the cart, so this is a property of the suffixed family
    // rather than something peculiar to products.
    ttrSettings(['seo_title_template' => '{title} {sep} {sitename}']);
    expect(ttrTitle('/cart/'))->toBe('Cart · K-Beauty Bliss');

    ttrSettings(['seo_title_template' => '{sitename} {sep} {title}']);
    expect(ttrTitle('/cart/'))->toBe('Cart · K-Beauty Bliss');
});

it('does move the site name on a page that does not', function () {
    /*
     * The other half, and the reason "the template does nothing" is the wrong
     * summary: on the brand directory and the account pages both tokens behave
     * exactly as the box implies.
     */
    ttrSettings(['seo_title_template' => '{title} {sep} {sitename}']);

    expect(ttrTitle('/korean-skincare-brands/'))->toBe('All brands | K-Beauty Bliss');
    expect(ttrTitle('/my-account/'))->toBe('Sign in | K-Beauty Bliss');

    ttrSettings(['seo_title_template' => '{sitename} {sep} {title}']);

    expect(ttrTitle('/korean-skincare-brands/'))->toBe('K-Beauty Bliss | All brands');
    expect(ttrTitle('/my-account/'))->toBe('K-Beauty Bliss | Sign in');
});

/*
|------------------------------------------------------------------------------
| 3. The home page ignores the template entirely
|------------------------------------------------------------------------------
*/

it('leaves the home page to its own Home title box', function () {
    $before = ttrTitle('/');

    foreach (['Buy {title} today', '{sitename} {sep} {title}', '{title} - CLEARANCE'] as $template) {
        ttrSettings(['seo_title_template' => $template]);

        expect(ttrTitle('/'))->toBe($before);
    }
});

/*
|------------------------------------------------------------------------------
| 4. The screen's explanation has to keep matching all of the above
|------------------------------------------------------------------------------
*/

it('states the exception on the screen rather than only in a test', function () {
    /*
     * The pattern AdminController::revenueBasis() already establishes: the
     * caption the admin renders is resolved from the code that implements the
     * behaviour, so the two cannot drift. Asserted here because a note nobody
     * checks is how this repo's recurring defect — a screen stating something
     * the code does not do — gets written in the first place.
     */
    $basis = AdminController::titleTemplateBasis();

    expect($basis)->toHaveKeys(['label', 'note', 'tokens_note']);

    // It must NOT claim to cover every page: the home page is excluded, and the
    // suffixed family is named.
    expect($basis['note'])->toContain('except the home page');
    expect($basis['tokens_note'])->toContain('{sitename}');
    expect($basis['tokens_note'])->toContain('Product');

    // And the endpoint the screen reads actually carries it.
    $owner = AdminUser::create([
        'name' => 'Title Owner',
        'email' => 'title-' . uniqid() . '@example.test',
        'password' => bcrypt('secret-secret'),
        'role' => 'owner',
    ]);

    $payload = (array) test()
        ->actingAs($owner, 'admin')
        ->getJson('/admin-api/settings')
        ->assertOk()
        ->json();

    expect($payload)->toHaveKey('title_template_basis');
    expect($payload['title_template_basis']['note'])->toBe($basis['note']);
});
