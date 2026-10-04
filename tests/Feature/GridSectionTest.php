<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\GridSection;
use App\Models\Product;
use App\Services\GridSections;
use App\Services\HomepageSections;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;

/**
 * The reusable homepage product-grid section — the mechanism. (Lane GS)
 *
 * The owner asked for ONE section type he can add as many times as he likes,
 * each instance with its own heading, product selection and layout. These cases
 * are about the TYPE: that an instance is a first-class homepage section, that
 * two instances are independent, and that each control actually reaches the
 * page. The bytes-unchanged argument is GridSectionShipsOffTest, the cost is
 * GridSectionQueryCostTest, the markup rules are GridSectionShapeTest and the
 * endpoints are GridSectionApiSurfaceTest.
 *
 * EVERY CASE BELOW HAS A MUTATION NOTE AND EVERY ONE OF THEM WAS RUN. A test
 * that asserts nothing while being counted as coverage is a fault this
 * repository has found four times.
 */
function gsReset(): void
{
    GridSection::query()->delete();
    GridSections::flush();
    Cache::forget('kbb.home.rails');
    SettingsService::forgetMemo();
}

function gsBrand(string $slug = 'gs-brand'): Brand
{
    return Brand::firstOrCreate(['slug' => $slug], ['name' => 'GS '.$slug]);
}

function gsCategory(string $slug, ?int $parentId = null): Category
{
    return Category::firstOrCreate(
        ['slug' => $slug],
        ['name' => 'GS '.$slug, 'parent_id' => $parentId, 'path' => $slug]
    );
}

function gsProduct(string $slug, array $extra = []): Product
{
    return Product::create(array_merge([
        'slug' => $slug,
        'name' => 'GS '.$slug,
        'status' => 'publish',
        'is_visible' => true,
        'brand_id' => gsBrand()->id,
        'price' => 5000,
        'stock_status' => 'instock',
        'type' => 'simple',
        'rating' => 0.0,
        'review_count' => 0,
        'total_sales' => 0,
    ], $extra));
}

/** An instance at the shipped defaults, plus whatever the case cares about. */
function gsSection(array $extra = []): GridSection
{
    $n = GridSection::query()->count() + 1;

    // `mobile_count` follows `count` unless the case names it. fetchCount() is
    // the LARGER of the two by design — one query serves both numbers — so a
    // helper that left mobile_count at its own default would quietly make every
    // "how many does this source return" case ask for a different number than
    // it typed.
    $extra += array_key_exists('count', $extra) ? ['mobile_count' => $extra['count']] : [];

    return GridSection::create(array_merge([
        'name' => 'Grid '.$n,
        'slug' => 'grid-'.$n,
        'status' => 'publish',
        'position' => $n,
        'show_heading' => true,
        'heading' => 'Grid '.$n,
        'subheading' => '',
        'source' => 'bestsellers',
        'source_brand_id' => null,
        'source_category_id' => null,
        'include_children' => false,
        'manual_ids' => null,
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

function gsHome(): string
{
    GridSections::flush();
    SettingsService::forgetMemo();

    return test()->get('/')->assertOk()->getContent();
}

/**
 * The homepage with its STYLE ELEMENTS removed.
 *
 * Almost every class this feature asserts on — `gs-car-d`, `gs-d-only`,
 * `gs-all`, `kbb-gsec` itself — is named twice on a page that draws an
 * instance: once in the markup and once in the constant stylesheet
 * GridSections::css() pushes. A raw str_contains over the document therefore
 * reports the STYLESHEET as the markup, which is the fault
 * CardsBannerSectionShapeTest's own header describes when it strips Blade
 * comments before scanning. So what is counted here is markup.
 */
function gsBody(): string
{
    return (string) preg_replace('#<style>.*?</style>#s', '', gsHome());
}

/**
 * One chunk per rendered instance, in page order.
 *
 * The homepage's own rails share this feature's markup vocabulary — the shipped
 * best-sellers rail draws `#1`…`#4` through the same card component — so a
 * count over the whole document answers a question about the SHOP and not about
 * this section. Cutting the document at each instance's `<section>` is what
 * makes "the ranking badge is the second instance's and not the first's" a
 * statement this file can actually make.
 *
 * @return list<string>
 */
function gsChunks(string $html): array
{
    return array_slice(explode('class="sec kbb-gsec', $html), 1);
}

beforeEach(function () {
    gsReset();

    foreach (range(1, 12) as $i) {
        // Far above anything the migration set seeds, so "best sellers" is a
        // ranking of THESE rows and the case is not hostage to the fixture.
        gsProduct('gs-p'.$i, ['total_sales' => 900000 - $i, 'name' => 'GS Product '.$i]);
    }
});

/* ═══════════ an instance IS a homepage section, through the one system ═════ */

it('puts a built instance into the homepage section registry, after the seventeen', function () {
    /*
     * THE WHOLE DESIGN IN ONE ASSERTION. The owner's brief is a section type he
     * can add repeatedly; the decision this lane took is that an instance is a
     * ROW in the existing registry rather than a second mechanism beside it, so
     * that ordering, the Desktop/Mobile switches and the dividers all come from
     * the code the seventeen shipped sections already go through.
     *
     * MUTATION: change HomepageSections::registry() to `return self::REGISTRY;`
     * and this goes red — the key is absent, and with it every switch and the
     * ordering. Run, red, put back.
     */
    $before = array_keys(HomepageSections::registry());

    $section = gsSection();
    GridSections::flush();

    $after = array_keys(HomepageSections::registry());

    expect($before)->toBe(array_keys(HomepageSections::REGISTRY))
        ->and($after)->toHaveCount(count($before) + 1)
        // Appended, not inserted: this list IS the default order and
        // store/home.blade.php draws the loop last.
        ->and(array_slice($after, 0, count($before)))->toBe($before)
        ->and(end($after))->toBe($section->sectionKey())
        ->and($section->sectionKey())->toBe('grid_'.$section->id);
});

it('keys an instance off its id, so renaming it cannot orphan its saved order', function () {
    /*
     * The key is what `homepage_sections` stores an instance's order and
     * switches against. Keyed off the SLUG, renaming an instance would silently
     * drop both and the section would jump to the end of the page with its
     * switches back on — a bug the owner would report as "it moved on its own".
     *
     * MUTATION: make GridSection::sectionKey() return 'grid_'.$this->slug and
     * this goes red on the second expectation. Run, red, put back.
     */
    $section = gsSection(['name' => 'Autumn picks', 'slug' => 'autumn-picks']);
    $key = $section->sectionKey();

    $section->update(['name' => 'Winter picks', 'slug' => 'winter-picks']);

    expect($section->fresh()->sectionKey())->toBe($key)
        ->and(GridSection::idFromKey($key))->toBe((int) $section->id);
});

it('refuses a section key that is not one of its own', function () {
    // `ctype_digit` and not `is_numeric`: `1e3`, ` 12` and `-4` are all numeric
    // and none of them is an id this shop ever issued.
    expect(GridSection::idFromKey('grid_7'))->toBe(7)
        ->and(GridSection::idFromKey('bundles'))->toBeNull()
        ->and(GridSection::idFromKey('grid_1e3'))->toBeNull()
        ->and(GridSection::idFromKey('grid_ 12'))->toBeNull()
        ->and(GridSection::idFromKey('grid_-4'))->toBeNull()
        ->and(GridSection::idFromKey('grid_0'))->toBeNull()
        ->and(GridSection::idFromKey('grid_'))->toBeNull();
});

/* ═════════════════════════ it reaches the page ════════════════════════════ */

it('draws a published instance on the homepage with its own heading', function () {
    gsSection(['heading' => 'Big savings bundles', 'subheading' => 'Below the sum of their parts.']);

    $html = gsBody();

    expect($html)->toContain('kbb-gsec')
        ->and($html)->toContain('Big savings bundles')
        ->and($html)->toContain('Below the sum of their parts.')
        // The grid is .kbb-pgrid, so it inherits the shop's card and its skin.
        ->and($html)->toContain('gs-grid');
});

it('draws nothing at all for a draft instance', function () {
    /*
     * A draft is an instance the owner is still building. It is LISTED on
     * Appearance → Homepage so he can see why it is not showing, and it draws
     * nothing.
     *
     * MUTATION: drop `->where('status', 'publish')` from
     * GridSections::forHome() and this goes red. Run, red, put back.
     */
    $bare = gsHome();

    gsSection(['status' => 'draft', 'heading' => 'Not ready yet']);

    expect(gsBody())->not->toContain('Not ready yet');

    // And it IS in the registry, which is the other half of the promise.
    expect(array_keys(HomepageSections::registry()))->toContain('grid_'.GridSection::query()->value('id'));

    // The bare page is only equal once nothing renders; the section's own
    // <section> is inside the loop's @if, so a draft-only shop is byte-identical.
    expect(gsHome())->toBe($bare);
});

it('honours the Desktop and Mobile switches an instance inherits from the section screen', function () {
    /*
     * These are NOT controls this lane built. They are the seventeen sections'
     * own switches, and an instance gets them because it is a registry row —
     * which is the argument for the whole design.
     *
     * MUTATION: change HomepageSections::registry() back to the const and this
     * goes red, because `hidden()` then knows nothing about the key and the
     * section renders on both. Run, red, put back.
     */
    $section = gsSection(['heading' => 'Switchable']);
    $key = $section->sectionKey();

    GridSections::flush();
    app(HomepageSections::class)->save([$key => ['desktop' => false, 'mobile' => true, 'order' => 99]]);

    expect(gsBody())->toContain('d-off');

    GridSections::flush();
    app(HomepageSections::class)->save([$key => ['desktop' => false, 'mobile' => false, 'order' => 99]]);

    // Off on both is not rendered at all, which also skips its queries.
    expect(app(HomepageSections::class)->hidden($key))->toBeTrue()
        ->and(gsBody())->not->toContain('Switchable');
});

/* ══════════════════════════ where the products come from ══════════════════ */

it('serves each of the seven sources from the selection the owner chose', function () {
    $other = Brand::create(['slug' => 'gs-other', 'name' => 'GS Other']);
    $cat = gsCategory('gs-parent');
    $child = gsCategory('gs-child', $cat->id);

    $branded = gsProduct('gs-branded', ['brand_id' => $other->id, 'name' => 'GS Branded One']);
    $inCat = gsProduct('gs-incat', ['name' => 'GS In Category']);
    $inCat->categories()->syncWithoutDetaching([$cat->id]);
    $inChild = gsProduct('gs-inchild', ['name' => 'GS In Child']);
    $inChild->categories()->syncWithoutDetaching([$child->id]);
    $sale = gsProduct('gs-sale', ['name' => 'GS On Sale', 'price' => 5000, 'sale_price' => 2500]);
    $feat = gsProduct('gs-feat', ['name' => 'GS Featured', 'featured' => true]);

    $svc = app(GridSections::class);

    $names = function (GridSection $s) use ($svc) {
        GridSections::flush();

        return collect($svc->forPreview($s)['items'])->pluck('name')->all();
    };

    expect($names(gsSection(['source' => 'brand', 'source_brand_id' => $other->id, 'count' => 10])))
        ->toBe(['GS Branded One']);

    expect($names(gsSection(['source' => 'category', 'source_category_id' => $cat->id, 'count' => 10])))
        ->toBe(['GS In Category']);

    /*
     * ── THE ONE THE OWNER NAMED AS A SUB-OPTION ─────────────────────────────
     *
     * "a category (with or without its children)". With children ON the child's
     * product joins the row; with it off it does not, which is the case above.
     *
     * MUTATION: make GridSections::categoryAndDescendants() return `[$id]` and
     * this expectation goes red — the child's product disappears. Run, red,
     * put back.
     */
    expect($names(gsSection([
        'source' => 'category', 'source_category_id' => $cat->id,
        'include_children' => true, 'count' => 10,
    ])))->toContain('GS In Child')->toContain('GS In Category');

    /*
     * `toContain` and not `toBe` for these two: the migration set seeds a
     * catalogue of its own and several of those rows are discounted. Asserting
     * an exact list would make the case hostage to a fixture this lane does not
     * own — which is a different test from "the on-sale source returns what is
     * on sale".
     */
    expect($names(gsSection(['source' => 'onsale', 'count' => 20])))->toContain('GS On Sale');
    expect($names(gsSection(['source' => 'featured', 'count' => 20])))->toContain('GS Featured');

    // And neither source returns what it is not asked for.
    expect($names(gsSection(['source' => 'onsale', 'count' => 20])))->not->toContain('GS Featured');

    // Best sellers is a ranking: GS Product 1 has the highest total_sales.
    expect($names(gsSection(['source' => 'bestsellers', 'count' => 3])))->toBe(
        ['GS Product 1', 'GS Product 2', 'GS Product 3']
    );

    // Newest is the reverse of creation, and `id` is what breaks the tie an
    // import creates by writing every row with the same timestamp.
    expect($names(gsSection(['source' => 'newest', 'count' => 1])))->toBe(['GS Featured']);
});

it('keeps a manual list in the owner’s own order and drops what is no longer visible', function () {
    /*
     * "a manual list he picks and orders himself". `whereIn` answers in whatever
     * order the engine likes, so the order is restored from the stored list.
     *
     * MUTATION: replace the map-over-ids in GridSections::fetchPool()'s `manual`
     * branch with a bare `->get()` and the first expectation goes red — the rows
     * come back in id order rather than the owner's. Run, red, put back.
     */
    $a = Product::query()->where('slug', 'gs-p3')->firstOrFail();
    $b = Product::query()->where('slug', 'gs-p1')->firstOrFail();
    $c = Product::query()->where('slug', 'gs-p7')->firstOrFail();

    $section = gsSection([
        'source' => 'manual',
        'manual_ids' => [$c->id, $a->id, $b->id],
        'count' => 10,
    ]);

    GridSections::flush();

    expect(collect(app(GridSections::class)->forPreview($section)['items'])->pluck('id')->all())
        ->toBe([$c->id, $a->id, $b->id]);

    /*
     * AND THE RE-CHECK. A product unpublished after it was picked drops out
     * silently rather than appearing on the front page because somebody picked
     * it in March.
     *
     * MUTATION: drop `->visible()` from the `$base` builder in fetchPool() and
     * this second half goes red. Run, red, put back.
     */
    $a->update(['status' => 'draft']);
    GridSections::flush();

    expect(collect(app(GridSections::class)->forPreview($section->fresh())['items'])->pluck('id')->all())
        ->toBe([$c->id, $b->id]);
});

/* ════════════════════════════ layout and counts ═══════════════════════════ */

it('writes the two column counts as custom properties and nothing else into CSS', function () {
    /*
     * Rule 5: "Anything printed unescaped is a constant, never a setting."
     * GridSections::css() has no interpolation in it at all — the per-instance
     * numbers arrive in the section's own style attribute, through {{ }}, as
     * integers clamped twice.
     *
     * MUTATION: put `.$section->heading.` anywhere inside css() and the second
     * expectation goes red on any heading with a `{`, `<` or `"` in it. Run,
     * red, put back. (Checked with a heading of `</style><script>x</script>`.)
     */
    // ▲ Lane HC: phone grid — a phone carousel reads Cards in view instead.
    gsSection(['desktop_cols' => 5, 'mobile_cols' => 3, 'mobile_layout' => 'grid', 'heading' => '</style><b>x']);

    $html = gsBody();

    expect($html)->toContain('--gs-d:5;--gs-m:3')
        ->and($html)->toContain('&lt;/style&gt;&lt;b&gt;x')
        ->and($html)->not->toContain('</style><b>x');
});

it('clamps a column count a hand-edited row put out of range', function () {
    // The cast clamps on the way in. This is the LAST point before the numbers
    // become CSS, and a row edited straight in the database has never been
    // through the cast at all.
    gsSection(['desktop_cols' => 99, 'mobile_cols' => 0, 'mobile_layout' => 'grid']);

    expect(gsBody())->toContain('--gs-d:6;--gs-m:1');
});

it('marks the surplus tiles rather than running a second query for the second count', function () {
    /*
     * "5 columns on desktop and in mobile 6 products" is two numbers over ONE
     * selection. The row is fetched at max(count, mobile_count) and the surplus
     * is hidden at the other width by a class.
     *
     * MUTATION: drop the `gs-d-only` / `gs-m-only` expressions from the cell's
     * class in partials/home/grid-section.blade.php and this goes red — the
     * phone then shows all ten. Run, red, put back.
     */
    gsSection(['count' => 10, 'mobile_count' => 6]);

    $html = gsBody();

    // Ten tiles fetched; four of them hidden on the phone and none on desktop.
    expect(substr_count($html, 'class="gs-cell'))->toBe(10)
        ->and(substr_count($html, 'gs-d-only'))->toBe(4)
        ->and(substr_count($html, 'gs-m-only'))->toBe(0);
});

it('turns the grid into a carousel on each side independently', function () {
    // "on mobile careousel" and "on desktop also give control to make it
    // carousel" — two controls, not one.
    gsSection(['desktop_layout' => 'grid', 'mobile_layout' => 'carousel']);

    $html = gsBody();
    expect($html)->toContain('gs-car-m')->and($html)->not->toContain('gs-car-d');

    GridSection::query()->update(['desktop_layout' => 'carousel', 'mobile_layout' => 'grid']);

    $html = gsBody();
    expect($html)->toContain('gs-car-d')->and($html)->not->toContain('gs-car-m');
});

it('picks an existing card skin and never invents one', function () {
    // The card and its 28 templates are Lane PG2's. This control PICKS one.
    gsSection(['skin' => 'luxe']);
    expect(gsBody())->toContain('data-skin="luxe"');

    // An unknown name falls back the way every other skin reader in the shop
    // falls back, rather than rendering an unstyled grid.
    //
    // ▲ ASSERTED AGAINST THE CONSTANT, not the literal 'classic'. Lane GS wrote
    //   this line while `classic` was the shop's default card; Lane PG2 landed
    //   the owner's showcase card in the same round and moved the default, and
    //   this was one of two tests the merge caught. A literal here would have to
    //   be edited every time he changes his mind about the card, which is a
    //   setting he is meant to change — and PG2's own DefaultCardStyleTest
    //   exists precisely because that default had five homes and a literal in
    //   three of them.
    GridSection::query()->update(['skin' => 'not-a-skin']);
    expect(gsBody())->toContain('data-skin="'.\App\Support\GridSkins::DEFAULT.'"');
});

/* ═══════════════════════════ the View all button ══════════════════════════ */

it('refuses a “View all” link whose scheme this shop will not follow', function () {
    /*
     * CLAUDE.md rule 5 names this case by itself. `{{ }}` escapes `&`, `<`,
     * `>`, `"` and `'` — and `javascript:alert(1)` contains none of them, so an
     * escaper passes it through byte for byte and the browser runs it.
     *
     * A refused link draws NO BUTTON rather than a button pointing at `#`,
     * which is SafeUrl's own distinction between a link and a picture: a button
     * that looks live and goes nowhere is worse than no button.
     *
     * MUTATION: change GridSections::viewAllHref() to return the raw column and
     * every refusal below goes red. Run, red, put back.
     */
    foreach ([
        'javascript:alert(1)',
        'jav&#x09;ascript:alert(1)',
        "java\nscript:alert(1)",
        '//evil.test/x',
        'data:text/html,<script>x</script>',
        'vbscript:msgbox(1)',
    ] as $bad) {
        gsReset();
        gsSection(['show_view_all' => true, 'view_all_label' => 'View all', 'view_all_url' => $bad]);

        $html = gsBody();

        expect($html)->not->toContain('gs-all')
            ->and($html)->not->toContain('javascript:')
            ->and($html)->not->toContain('vbscript:');
    }

    // And an address it WILL follow becomes a real link.
    gsReset();
    gsSection(['show_view_all' => true, 'view_all_label' => 'View all', 'view_all_url' => '/shop/?orderby=popularity']);

    expect(gsBody())->toContain('class="gs-all" href="/shop/?orderby=popularity"');
});

it('prints its own translated words when the owner leaves the button text empty', function () {
    // The heading and the label are the OWNER'S and are never keyed — a second
    // English source for a value he types is one of the two silently wrong. The
    // fallback is this section's own string and IS keyed.
    gsSection(['show_view_all' => true, 'view_all_label' => '', 'view_all_url' => '/shop/']);

    expect(gsBody())->toContain('>View all</a>');

    /*
     * THE SHOP'S EXISTING KEY, not a second one. `store.product_grid.view_all`
     * is what components/product-grid.blade.php and store/brands.blade.php
     * already print under a product grid, and this section prints the same
     * sentence in the same place.
     *
     * A new key was written first and ArabicInterfaceDraftsTest refused it
     * twice in one run — on the draft count, and on "it never labels two
     * controls on one screen with the same Arabic". Reusing it is not a
     * shortcut; it is one sentence with one translation.
     */
    expect(__('store.product_grid.view_all'))->toBe('View all');
});

it('draws no button at all when the owner switched it off, whatever the link says', function () {
    gsSection(['show_view_all' => false, 'view_all_url' => '/shop/', 'view_all_label' => 'View all']);

    expect(gsBody())->not->toContain('gs-all');
});

/* ════════════════════════ two instances are independent ═══════════════════ */

it('draws the owner’s two named instances side by side, each with its own everything', function () {
    /*
     * THE BRIEF, RENDERED. Not two bespoke sections that happen to look alike —
     * two rows of one type, differing only in their columns.
     *
     * MUTATION: make GridSections::specKey() return a constant and this goes
     * red on the headings, because both instances would then draw the same
     * pool under the same spec. Run, red, put back. (The constant spec is also
     * what GridSectionQueryCostTest's "two different brands are two queries"
     * catches from the cost side.)
     */
    $other = Brand::create(['slug' => 'gs-b2', 'name' => 'GS Brand Two']);
    gsProduct('gs-only-b2', ['brand_id' => $other->id, 'name' => 'GS Only On Brand Two']);

    gsSection([
        'name' => 'Big savings bundles', 'heading' => 'Big savings bundles',
        'source' => 'brand', 'source_brand_id' => $other->id,
        'count' => 4, 'mobile_count' => 4,
        'desktop_cols' => 4, 'desktop_layout' => 'grid', 'mobile_layout' => 'carousel',
        'show_view_all' => true, 'view_all_label' => 'View all', 'view_all_url' => '/shop/',
        'position' => 1,
    ]);

    gsSection([
        'name' => 'Best sellers', 'heading' => 'BEST SELLERS',
        'source' => 'bestsellers', 'count' => 10, 'mobile_count' => 6,
        'desktop_cols' => 5, 'desktop_layout' => 'grid', 'mobile_layout' => 'carousel',
        'show_rank' => true,
        'position' => 2,
    ]);

    /*
     * ONE request, read twice. Calling gsHome() a second time inside one test
     * does not give a second fresh document: Laravel's view factory is a
     * container singleton and this process keeps it between requests, so the
     * `styles` stack the layout prints still holds the first render's push and
     * the count comes back as 2. That is a property of the harness and not of
     * the page — a real visitor gets one process per request — so the document
     * is fetched ONCE and both questions are asked of it.
     */
    $full = gsHome();
    $html = (string) preg_replace('#<style>.*?</style>#s', '', $full);

    expect(substr_count($html, 'kbb-gsec'))->toBe(2)
        ->and($html)->toContain('Big savings bundles')
        ->and($html)->toContain('BEST SELLERS')
        ->and($html)->toContain('--gs-d:4;')
        ->and($html)->toContain('--gs-d:5;')
        // The first is one brand's single product; the second is ten best
        // sellers with four hidden on the phone.
        ->and($html)->toContain('<span class="kbb-card-nm">GS Only On Brand Two</span>')
        ->and(substr_count($html, 'gs-d-only'))->toBe(4)
        // One stylesheet for both, pushed once — counted on the FULL document,
        // because that is the thing being counted.
        ->and(substr_count($full, '.kbb-gsec .gs-cell{'))->toBe(1);

    // The ranking badge is the SECOND instance's and not the first's, asked of
    // each instance's own markup rather than of the page.
    $chunks = gsChunks($html);

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0])->not->toContain('kbb-badge-new">#1</span>')
        ->and(substr_count($chunks[1], 'kbb-badge-new">#'))->toBe(10);
});

it('pushes its stylesheet exactly once however many instances render', function () {
    /*
     * A style element per instance would be the same rules six times in the
     * head of the shop's most-hit page. It is pushed from the loop's @if, above
     * the loop.
     *
     * MUTATION: move the @push inside the @foreach and this goes red with 6.
     * Run, red, put back.
     */
    foreach (range(1, 6) as $i) {
        gsSection(['heading' => 'Row '.$i]);
    }

    expect(substr_count(gsHome(), '.kbb-gsec .gs-head{'))->toBe(1);
});
