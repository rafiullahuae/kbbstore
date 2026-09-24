<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Setting;
use App\Services\Translation\InterfaceStrings;
use App\Support\ConcernCollections;
use App\Support\RoutineConcerns;
use Illuminate\Support\Facades\Route;

/*
 * Concern-led collections — Lane S, round 2.
 *
 * ── WHAT WAS MISSING ON THE SHOP ────────────────────────────────────────────
 *
 * docs/SEO-BUILD-PLAN.md ranks this first of everything in the SEO plan, and
 * docs/SEO-COMPETITIVE.md records /collections/acne as the single most
 * instructive thing observed on the competitor's site. This shop sorted its
 * catalogue only by what a product IS — Cleansers, Toners, Serums — and had no
 * page anywhere addressed to what a shopper WANTS. "korean skincare for acne"
 * is a query with buying intent; "new in" is not.
 *
 * ── AND THE DEFECT THE DESIGN EXISTS TO AVOID ───────────────────────────────
 *
 * The plan's own warning about this feature: "a concern collection with four
 * products and no copy is worse than no page." A near-empty listing that
 * publishes itself as its own canonical and enters the sitemap teaches Google
 * that this site has thin pages, and that judgement is not confined to the page
 * that earned it. So most of this file is about the page NOT existing.
 *
 * MUTATION NOTE: drop the `abort_unless(ConcernCollections::isLive(...))` line
 * from CollectionController::concern() and five tests here go red. Set
 * MIN_PRODUCTS to 1 and "does not exist until enough products are tagged" goes
 * red. Add a second slug to ENABLED without writing its copy and "every
 * enabled concern has copy" goes red.
 */

function ccMount(): void
{
    Route::middleware('web')->group(base_path('routes/concern-collections.php'));
}

/** A live, in-stock product tagged for the given concerns. */
function ccProduct(array $concerns, array $attrs = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'slug' => 'cc-product-' . $n,
        'name' => 'CC Product ' . $n,
        'status' => 'publish',
        'is_visible' => true,
        'stock_status' => 'instock',
        'price' => 10000,
        'short_description' => 'A real description for concern product number ' . $n . '.',
        'image' => '/media/cc-' . $n . '.jpg',
        'sku' => 'CC-' . $n,
        // Exactly as Admin\RoutinesApiController::tag() writes it.
        'routine_concerns' => $concerns === [] ? null : json_encode($concerns),
    ], $attrs));
}

function ccSettings(): void
{
    Setting::updateOrCreate(['key' => 'site_url'], ['value' => 'https://kbeautybliss.test']);
    Setting::flushMap();
}

/* ------------------------------------------------- the page does not exist yet */

it('404s a concern nobody has tagged any products for', function () {
    ccMount();

    // The shipped state on the day the package lands. Not an empty grid, not a
    // "coming soon" — a coming-soon page is the thin page with extra steps.
    test()->get('/concern/acne/')->assertNotFound();
});

it('does not exist until enough products are tagged', function () {
    ccMount();

    // One short of the floor.
    foreach (range(1, ConcernCollections::MIN_PRODUCTS - 1) as $i) {
        ccProduct(['acne']);
    }

    test()->get('/concern/acne/')->assertNotFound();

    // And the one that makes it a collection rather than a list.
    ccProduct(['acne']);

    test()->get('/concern/acne/')->assertOk();
});

it('404s a concern that has products but no copy written for it', function () {
    ccMount();

    // `hydration` is a perfectly valid RoutineConcerns slug and the routine
    // builder uses it. It has no page, because nobody has written its
    // sentences — a search result with nothing to say is not worth having.
    foreach (range(1, ConcernCollections::MIN_PRODUCTS + 2) as $i) {
        ccProduct(['hydration']);
    }

    expect(RoutineConcerns::exists('hydration'))->toBeTrue()
        ->and(ConcernCollections::isEnabled('hydration'))->toBeFalse();

    test()->get('/concern/hydration/')->assertNotFound();
});

it('404s a slug that is not a concern at all', function () {
    ccMount();

    test()->get('/concern/not-a-concern/')->assertNotFound();
    test()->get('/concern/acne-x/')->assertNotFound();
});

/* ------------------------------------------------------- once it does exist */

it('serves the concern page with its own copy, not a slug turned into a heading', function () {
    ccMount();
    ccSettings();

    foreach (range(1, ConcernCollections::MIN_PRODUCTS) as $i) {
        ccProduct(['acne']);
    }

    $html = test()->get('/concern/acne/')->assertOk()->getContent();

    // The heading is written for a shopper arriving from a search, not the
    // back-office label an operator ticks ("Acne & blemishes").
    expect($html)->toContain('Korean skincare for acne-prone skin')
        ->and($html)->not->toContain('Acne &amp; blemishes');
});

it('lists only the products tagged for that concern, and only live ones', function () {
    ccMount();
    ccSettings();

    $wanted = [];

    foreach (range(1, ConcernCollections::MIN_PRODUCTS) as $i) {
        $wanted[] = ccProduct(['acne'], ['name' => 'CC Acne Wanted ' . $i]);
    }

    // Tagged for something else.
    ccProduct(['hydration'], ['name' => 'CC Hydration Only']);
    // Tagged for nothing.
    ccProduct([], ['name' => 'CC Untagged']);
    // Tagged for acne but out of stock: the routine engine will not show it and
    // neither will this, or the page sells what it cannot deliver.
    ccProduct(['acne'], ['name' => 'CC Acne Out Of Stock', 'stock_status' => 'outofstock']);
    // Tagged for acne but not published.
    ccProduct(['acne'], ['name' => 'CC Acne Draft', 'status' => 'draft']);

    $html = test()->get('/concern/acne/')->assertOk()->getContent();

    foreach ($wanted as $product) {
        expect($html)->toContain($product->name);
    }

    expect($html)->not->toContain('CC Hydration Only')
        ->not->toContain('CC Untagged')
        ->not->toContain('CC Acne Out Of Stock')
        ->not->toContain('CC Acne Draft');
});

it('matches a concern exactly and never as a substring of the stored json', function () {
    /*
     * The `LIKE '%"acne"%'` match is safe because every slug in the stored
     * json_encode output is wrapped in quotes. This pins that rather than
     * assuming it: a product tagged only for `sun` must not appear on a page
     * whose slug happens to share letters with it, and a product whose NAME
     * contains the word must not either.
     */
    ccMount();
    ccSettings();

    foreach (range(1, ConcernCollections::MIN_PRODUCTS) as $i) {
        ccProduct(['acne']);
    }

    ccProduct(['sun', 'hydration'], ['name' => 'CC Sun And Hydration']);

    expect(ConcernCollections::count('acne'))->toBe(ConcernCollections::MIN_PRODUCTS);
});

it('publishes the page as a CollectionPage with its own canonical', function () {
    ccMount();
    ccSettings();

    foreach (range(1, ConcernCollections::MIN_PRODUCTS) as $i) {
        ccProduct(['acne']);
    }

    $html = test()->get('/concern/acne/')->assertOk()->getContent();

    // The same seoCtx the four curated listings get: a self-referencing
    // canonical, a breadcrumb and a CollectionPage + ItemList.
    expect($html)->toContain('rel="canonical" href="https://kbeautybliss.test/concern/acne/"')
        ->and($html)->toContain('"@type":"CollectionPage"')
        ->and($html)->toContain('"@type":"BreadcrumbList"');
});

/* ----------------------------------------------------------- the sitemap */

it('keeps a concern page out of the sitemap until it exists', function () {
    ccSettings();

    // MUTATION NOTE: make SeoFilesController list ConcernCollections::slugs()
    // instead of ::live() and this is red. A sitemap entry that 404s is a
    // Search Console error.
    expect(test()->get('/sitemap.xml')->getContent())->not->toContain('/concern/acne/');

    foreach (range(1, ConcernCollections::MIN_PRODUCTS) as $i) {
        ccProduct(['acne']);
    }

    expect(test()->get('/sitemap.xml')->getContent())
        ->toContain('https://kbeautybliss.test/concern/acne/');
});

it('asks the same class the router asks, so the two cannot disagree', function () {
    ccMount();
    ccSettings();

    foreach (ConcernCollections::slugs() as $slug) {
        $live = ConcernCollections::isLive($slug);
        $status = test()->get('/concern/' . $slug . '/')->status();

        expect($status)->toBe($live ? 200 : 404);
        expect(in_array($slug, ConcernCollections::live(), true))->toBe($live);
    }
});

/* ---------------------------------------------- copy, and the drift guard */

it('has copy for every concern it has enabled', function () {
    /*
     * The standing guard. ENABLED is what makes a page possible and the copy is
     * what makes it worth having; a slug added to one without the other ships a
     * page whose heading is a missing translation key. Two English sources fail
     * silently and in one direction, which is the argument
     * CollectionPhpLabelsAreKeyedTest makes for the four curated listings.
     */
    $english = InterfaceStrings::all()['store'] ?? [];

    foreach (ConcernCollections::ENABLED as $slug) {
        $key = str_replace('-', '_', $slug);

        expect($english)->toHaveKey('concern.title_' . $key)
            ->and($english)->toHaveKey('concern.intro_' . $key);

        expect(trim((string) $english['concern.title_' . $key]))->not->toBe('');
        expect(trim((string) $english['concern.intro_' . $key]))->not->toBe('');
    }
});

it('only enables slugs that are really concerns this shop knows about', function () {
    // A second vocabulary of concerns is the defect RoutineConcerns' header
    // warns about at length: the day anything joins the two, the join fails
    // silently on the spellings that do not match.
    foreach (ConcernCollections::ENABLED as $slug) {
        expect(RoutineConcerns::exists($slug))->toBeTrue();
    }
});

it('ships exactly one concern live, because six thin pages cost more than one good one', function () {
    // SEO-BUILD-PLAN: "Ship one, with real copy, and measure it before shipping
    // six." This is that instruction as a test, so the next lane to add a slug
    // has to read it.
    expect(ConcernCollections::ENABLED)->toBe(['acne']);
});
