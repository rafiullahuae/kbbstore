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

it('404s a concern this shop has not enabled, however many products carry it', function () {
    /*
     * ── WHAT THIS TEST USED TO SAY, AND WHY IT CHANGED — Lane S8 ───────────
     *
     * It used to tag five products for `hydration` and assert the page 404s
     * because nobody had written hydration's sentences. **Every one of the eight
     * concerns has its sentences now** and all eight are in ENABLED, at the
     * owner's instruction, so there is no longer a concern in this shop that has
     * products and no copy. The old assertion could only be made green again by
     * un-writing a concern's copy, which is the wrong way round.
     *
     * The GATE it was protecting is still there and still worth pinning, so this
     * drives it against a slug ENABLED does not contain. `isEnabled()` is the
     * first half of `isLive()`, and without it `/concern/<anything>/` would run
     * a LIKE against the products table for an arbitrary URL segment.
     */
    ccMount();

    // Tagged for a real concern, so the products are not the reason for the 404.
    foreach (range(1, ConcernCollections::MIN_PRODUCTS + 2) as $i) {
        ccProduct(['hydration']);
    }

    // `dryness` is the concern SEO-GAP.md's own examples keep naming and this
    // shop does not have: not in RoutineConcerns, so not in ENABLED, so no page
    // — and nothing can be tagged for it either, because clean() drops it.
    expect(RoutineConcerns::exists('dryness'))->toBeFalse();
    expect(ConcernCollections::isEnabled('dryness'))->toBeFalse();
    expect(ConcernCollections::count('dryness'))->toBe(0);

    test()->get('/concern/dryness/')->assertNotFound();

    // And the gate is a real gate rather than a coincidence of the two lists
    // being equal today: every slug the class will serve is a concern this shop
    // knows about, and nothing else can get through.
    expect(ConcernCollections::slugs())->toBe(array_values(array_intersect(
        RoutineConcerns::slugs(),
        ConcernCollections::ENABLED
    )));
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

    // The HEADING is written for a shopper arriving from a search, rather than
    // being the concern's short label with a slug turned into title case.
    expect($html)->toContain('Korean skincare for acne-prone skin')
        ->and($html)->not->toContain('<h1>Acne &amp; blemishes</h1>')
        ->and($html)->not->toContain('>acne<');
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

it('enables all eight concerns and still publishes none of them', function () {
    /*
     * ══════════════════════════════════════════════════════════════════════
     * THE RULE-1 MEASUREMENT FOR LANE S8, AND THE TEST THAT REPLACED A PIN
     * ══════════════════════════════════════════════════════════════════════
     *
     * This test used to read `expect(ENABLED)->toBe(['acne'])`, carrying
     * SEO-BUILD-PLAN's "ship one and measure it before shipping six". The owner
     * has overruled that in as many words -- "i don't want to miss or skip
     * anything" -- so all eight are enabled.
     *
     * What matters is that the instruction's REAL content survives, and it does,
     * because it was never about the length of ENABLED. Six thin pages are
     * prevented by MIN_PRODUCTS, and MIN_PRODUCTS does not care how many slugs
     * have copy. This test is that claim, measured on the shop as it ships:
     *
     *   - all eight concerns have copy and are enabled;
     *   - NOT ONE of them has a page, because nothing is tagged;
     *   - the sitemap carries no /concern/ entry at all;
     *   - so applying the package that enables them moves nothing.
     *
     * MUTATION NOTE: set MIN_PRODUCTS to 0 and every expectation below the first
     * goes red at once -- eight addresses that 404 today would answer 200 with
     * an empty grid and eight of them would enter the sitemap. That is the
     * defect the floor exists for, and it is now the defect this test catches.
     * Removing a slug from ENABLED goes red on the first expectation instead.
     */
    ccMount();
    ccSettings();

    expect(ConcernCollections::ENABLED)->toBe(RoutineConcerns::slugs())
        ->and(count(ConcernCollections::ENABLED))->toBe(8);

    // Nothing tagged: the shipped state on the day the package lands.
    expect(ConcernCollections::live())->toBe([]);

    foreach (RoutineConcerns::slugs() as $slug) {
        test()->get(ConcernCollections::path($slug))->assertNotFound();
    }

    expect(str_contains(test()->get('/sitemap.xml')->getContent(), '/concern/'))->toBeFalse();
});

it('gives every one of the eight its own written heading and intro', function () {
    /*
     * Not "a key exists" -- the 'has copy for every concern it has enabled' test
     * above already does that. This is about the copy being WRITTEN rather than
     * generated, which is the thing that makes the page worth ranking:
     *
     *   - no heading is the back-office label ("Pores & oil") or the slug in
     *     title case, which is what an assembled heading looks like;
     *   - no two concerns share a heading or an intro, which is what a
     *     copy-paste looks like;
     *   - no intro carries a [SQUARE BRACKET] placeholder from
     *     docs/SEO-CONCERN-COPY.md's drafts. A placeholder in a translation
     *     value is PRINTED: the shopper reads "[A BARRIER CREAM]". Nothing in
     *     this application resolves a bracket.
     *
     * MUTATION NOTE: paste one concern's intro over another's and the
     * uniqueness expectation is red; put a bracket back in and the last one is.
     */
    $english = InterfaceStrings::all()['store'] ?? [];

    $titles = [];
    $intros = [];

    foreach (ConcernCollections::ENABLED as $slug) {
        $key = str_replace('-', '_', $slug);
        $title = (string) ($english['concern.title_'.$key] ?? '');
        $intro = (string) ($english['concern.intro_'.$key] ?? '');

        expect($title)->not->toBe(RoutineConcerns::adminLabel($slug));
        expect($title)->not->toBe(ucfirst($slug));
        expect(str_contains($intro, '['))->toBeFalse();
        expect(str_contains($intro, ']'))->toBeFalse();

        // Long enough to be prose rather than a label. Deliberately a floor and
        // not a band: docs/SEO-CONCERN-COPY.md is explicit that the 150-300 word
        // convention is practitioner convention and must not be a test.
        expect(str_word_count($intro))->toBeGreaterThan(40);

        $titles[] = $title;
        $intros[] = $intro;
    }

    expect(count(array_unique($titles)))->toBe(count($titles));
    expect(count(array_unique($intros)))->toBe(count($intros));
});

it('gives the product cards a short label without changing the four curated listings', function () {
    /*
     * The card eyebrow is the page title for the four curated listings, whose
     * titles are two words and read well there. A concern title is a SENTENCE
     * aimed at a search result, and it wrapped to two lines on every card and
     * repeated the heading twenty-four times down the page.
     *
     * The short label is RoutineConcerns' own shopper-facing string, not a
     * third wording for the same concept.
     *
     * MUTATION NOTE: change `$cardLabel ?? $title` back to `$title` in
     * store/collection.blade.php and the first expectation goes red; pass a
     * cardLabel from show() as well and the second goes red.
     */
    ccMount();
    ccSettings();

    foreach (range(1, ConcernCollections::MIN_PRODUCTS) as $i) {
        ccProduct(['acne']);
    }

    $concern = test()->get('/concern/acne/')->assertOk()->getContent();

    expect($concern)->toContain('kbb-card-cat">' . e(__(RoutineConcerns::labelKey('acne'))))
        // The <h1> is still the sentence a search result wants.
        ->and($concern)->toContain('Korean skincare for acne-prone skin');

    // And the curated listing still prints its own title on its cards.
    ccProduct([], ['name' => 'CC For New In']);

    expect(test()->get('/new-in/')->assertOk()->getContent())
        ->toContain('kbb-card-cat">' . e(__('store.collection.title_new_in')));
});
