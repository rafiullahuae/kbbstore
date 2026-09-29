<?php

declare(strict_types=1);

use App\Models\GridSection;
use App\Models\Product;
use App\Services\GridSections;
use App\Services\HomepageSections;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;

/**
 * RULE 1, ON THE MOST VISIBLE PAGE IN THE SHOP. (Lane GS)
 *
 * "Nothing that already works may change. A lane fixes or adds the thing it was
 * given and leaves the rest of the shop byte-identical."
 *
 * `StorefrontEnglishUnchangedTest` is the instrument for the whole storefront
 * and it stays the instrument. This file is the ARGUMENT underneath it, and it
 * is narrower and harsher in one respect that matters: that test compares the
 * shop against its old self on a database with no grid sections in it, so it
 * would be equally green if this feature drew nothing because there was nothing
 * to draw. This one FILLS the table — drafted instances, published instances,
 * instances switched off — and asserts the homepage is still the same bytes
 * wherever it should be.
 *
 * ── WHY THE OWNER'S TWO ROWS ARE NOT SEEDED ─────────────────────────────────
 *
 * He named a 4-up bundles row and a 5-up BEST SELLERS row. Both are PRESETS on
 * Appearance → Product grids — one button each — and neither is created by a
 * migration, because seeding them would put two new bands on a live front page
 * the moment the package applied, which is the one thing rule 1 forbids. The
 * last case below asserts the table is empty after the migration set has run.
 *
 * ── WHAT THE DEFECT WOULD LOOK LIKE ON THE SHOP ─────────────────────────────
 *
 * Three shapes, and every one of them has shipped in this repository before:
 *
 *   1. THE EMPTY WRAPPER — the `<section>` written AROUND the gate instead of
 *      inside it. Every homepage on earth gains an element, a class attribute
 *      and a divider rule above it: a diff with no words in it, which
 *      StorefrontEnglishUnchangedTest reports and nobody can tell from a copy
 *      change. store/home.blade.php records paying for this at byte 51624.
 *
 *   2. THE STRAY NEWLINE — a Blade comment replaced by the empty string with
 *      ITS TRAILING NEWLINE surviving. The video rail cost two newlines on
 *      every homepage on earth, written the readable way.
 *
 *   3. THE STYLESHEET THAT IS ALWAYS THERE. `GridSections::css()` pushed
 *      unconditionally rather than from inside the gate would put 1.4 KB of
 *      rules for a feature nobody uses into the head of the most-hit URL on the
 *      site.
 *
 * MUTATION NOTES, and all four were run.
 *
 *   1. Move the `@push('styles')` line in store/home.blade.php above its
 *      `@if ($gsRows !== [])`. "draws not one byte with nothing built" goes RED
 *      on the stylesheet.
 *   2. Move the `<section …>` line in partials/home/grid-section.blade.php
 *      outside its `@unless ($sections->hidden($gsKey))`. "an instance switched
 *      off on both devices leaves no wrapper behind" goes red.
 *   3. Drop `->where('status', 'publish')` from GridSections::forHome(). "a
 *      table full of drafts is still the same page" goes red.
 *   4. Un-glue the `@php` from the end of the Blade comment above the loop —
 *      put it on its own line. Nothing in THIS file moves, because a newline is
 *      added to both sides of its comparisons; StorefrontEnglishUnchangedTest
 *      reports the homepage as changed, which is the whole reason that file
 *      exists and why this one does not pretend to do its job.
 */
function gsoReset(): void
{
    GridSection::query()->delete();
    GridSections::flush();
    Cache::forget('kbb.home.rails');
    SettingsService::forgetMemo();
}

function gsoHome(): string
{
    GridSections::flush();
    SettingsService::forgetMemo();

    return test()->get('/')->assertOk()->getContent();
}

function gsoProduct(string $slug): Product
{
    return Product::firstOrCreate(['slug' => $slug], [
        'name' => 'GSO '.$slug,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 5000,
        'stock_status' => 'instock',
        'type' => 'simple',
        'rating' => 0.0,
        'review_count' => 0,
        'total_sales' => 700000,
    ]);
}

function gsoSection(array $extra = []): GridSection
{
    $n = GridSection::query()->count() + 1;

    return GridSection::create(array_merge([
        'name' => 'GSO '.$n,
        'slug' => 'gso-'.$n.'-'.uniqid(),
        'status' => 'publish',
        'position' => $n,
        'show_heading' => true,
        'heading' => 'GSO heading '.$n,
        'subheading' => '',
        'source' => 'bestsellers',
        'include_children' => false,
        'count' => 4,
        'mobile_count' => 4,
        'desktop_layout' => 'grid',
        'desktop_cols' => 4,
        'mobile_layout' => 'carousel',
        'mobile_cols' => 2,
        'skin' => '',
        'card_label' => '',
        'show_rank' => false,
        'show_view_all' => false,
        'view_all_label' => '',
        'view_all_url' => '',
    ], $extra));
}

beforeEach(function () {
    gsoReset();
    gsoProduct('gso-p1');
    gsoProduct('gso-p2');
});

it('ships with an empty table, so the owner’s two rows are content and not defaults', function () {
    /*
     * The migration set has run by the time this executes. A seeded row here
     * would be two new bands on a live front page the morning the package
     * applied.
     */
    expect(GridSection::query()->count())->toBe(0)
        // And the presets that WOULD create them exist, so the owner is one
        // button from each rather than being told to build them by hand.
        ->and(array_keys(GridSections::PRESETS))->toBe(['bundles', 'bestsellers']);
});

it('draws not one byte with nothing built', function () {
    /*
     * The baseline this file compares everything against, and the only case
     * that needs no "before" at all: the registry is the const, so
     * `orderIsDefault()` is unchanged and `orderStyle()` still returns ''.
     */
    $html = gsoHome();

    expect(HomepageSections::registry())->toBe(HomepageSections::REGISTRY)
        ->and($html)->not->toContain('kbb-gsec')
        ->and($html)->not->toContain('gs-grid')
        // The stylesheet is pushed from inside the gate, so it is not there
        // either. 1.4 KB in the head of the most-hit URL for a feature nobody
        // uses is the third shape in this file's header.
        ->and($html)->not->toContain('.kbb-gsec');
});

it('is still the same page when the table is full of drafts', function () {
    $bare = gsoHome();

    gsoSection(['status' => 'draft', 'heading' => 'Draft one']);
    gsoSection(['status' => 'draft', 'heading' => 'Draft two']);
    gsoSection(['status' => 'draft', 'heading' => 'Draft three']);

    expect(gsoHome())->toBe($bare);
});

it('leaves no wrapper behind for an instance switched off on both devices', function () {
    $bare = gsoHome();

    $section = gsoSection(['heading' => 'Switched right off']);

    GridSections::flush();
    app(HomepageSections::class)->save([
        $section->sectionKey() => ['desktop' => false, 'mobile' => false, 'order' => 99],
    ]);

    $html = gsoHome();

    expect($html)->not->toContain('kbb-gsec')
        ->and($html)->not->toContain('Switched right off')
        // No empty content wrapper — the shape the header's case 1 describes.
        ->and(substr_count($html, '<div class="wrap"></div>'))->toBe(0);

    /*
     * NOT byte-identical to $bare, and this is the honest half of the case.
     * Saving that payload writes `homepage_sections`, which makes
     * `orderIsDefault()` false for the WHOLE page — so the shop gains the
     * ordering style element and every movable section gains an order class.
     * That is the existing ordering mechanism behaving exactly as it does when
     * the owner reorders any of the seventeen shipped sections, and it is not
     * this feature's doing. What this feature must not do is leave its own
     * markup behind, which is what the three expectations above check.
     */
    expect(strlen($html))->toBeGreaterThan(0);
});

it('draws not one byte of THIS feature when its instance has no products to show', function () {
    /*
     * A heading with no grid under it is a gap on the front page. `forHome()`
     * drops an instance whose selection came back empty, so an owner who
     * points a grid at a brand he has not stocked yet gets nothing rather than
     * a titled hole.
     *
     * MUTATION: delete the `if ($items->isEmpty()) continue;` in
     * GridSections::forHome() and this goes red. Run, red, put back.
     */
    $bare = gsoHome();

    /*
     * An id no brand holds, rather than a new empty brand — CREATING one would
     * change the page all by itself (the brand strip lists it and the About
     * band counts it), and this case is about the grid and not about the brand
     * strip. It is also the realistic shape: a brand chosen and then deleted.
     */
    gsoSection(['source' => 'brand', 'source_brand_id' => 999999, 'heading' => 'Nothing in here']);

    expect(gsoHome())->toBe($bare);
});

it('keeps the homepage section list in the order the template draws it', function () {
    /*
     * This list IS the default order, and store/home.blade.php draws the loop
     * LAST for that reason. A key whose position in the registry disagreed with
     * the template would hand Appearance → Homepage a picture the shop does not
     * draw — the fault `HomepageSections::settle()` exists to stop one level
     * along.
     *
     * MUTATION: change HomepageSections::registry() to
     * `GridSections::registryRows() + self::REGISTRY` and this goes red: the
     * instances would be listed FIRST and the screen would say they are at the
     * top of a page that draws them at the bottom. Run, red, put back.
     */
    gsoSection(['heading' => 'One']);
    gsoSection(['heading' => 'Two']);

    GridSections::flush();

    $keys = array_keys(HomepageSections::registry());
    $shipped = array_keys(HomepageSections::REGISTRY);

    expect(array_slice($keys, 0, count($shipped)))->toBe($shipped);

    // And the shop draws them after the last shipped section.
    $html = gsoHome();

    expect(strpos($html, 'One'))->not->toBeFalse()
        ->and(strpos($html, 'Two'))->not->toBeFalse()
        // Both after the last shipped section's own words, and in position order.
        ->and((int) strpos($html, '>One<'))->toBeGreaterThan((int) strpos($html, 'kbb-gsec'))
        ->and((int) strpos($html, '>One<'))->toBeLessThan((int) strpos($html, '>Two<'));
});
