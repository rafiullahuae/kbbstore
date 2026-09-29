<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\GridSection;
use App\Models\Product;
use App\Services\GridSections;
use App\Support\AdminCapabilities;
use App\Support\GridSkins;
use Tests\Support\GridSectionAdminRoutes;

/**
 * Appearance → Grid sections: the endpoints, and what they will and will not
 * answer. (Lane GS)
 *
 * The routes ship in `routes/grid-sections-admin.php` because CLAUDE.md forbids
 * this lane from editing `routes/web.php`, so they are MOUNTED HERE the way the
 * integrator is told to mount them — `Tests\Support\GridSectionAdminRoutes`,
 * which is the shape CLAUDE.md names for a lane that needs its routes before
 * they are wired ("register the group in the test"), and not an assertion that
 * the require is absent. That assertion goes red the moment the integrator does
 * the one thing the lane asked for; three lanes have lost a round to it.
 *
 * ── WHAT THIS FILE IS ACTUALLY FOR ──────────────────────────────────────────
 *
 * Rule 5, four ways:
 *
 *   1. AN ALLOWLIST, NEVER THE MODEL. The manual picker returns products.
 *      CLAUDE.md names what a bare `->get()` on that table hands out —
 *      `wc_id`, `sku`, `total_sales` — and says each one leaked in production.
 *      The picker's five columns are asserted by NAME and by absence.
 *   2. A SELECT STORES ONE OF ITS OWN OPTIONS OR THE DEFAULT. Posted rubbish
 *      for a source, a layout or a column count lands on the default rather
 *      than in the column.
 *   3. THE MANUAL LIST IS IDS VALIDATED AGAINST ROWS THAT EXIST.
 *   4. EVERY NEW ADMIN ENDPOINT GETS ITS OWN CAPABILITY AND FAILS CLOSED.
 *
 * And one thing that is not rule 5 and matters as much: the option set the
 * PICKER is handed and the option set the CAST checks against are the same
 * sets, so the screen cannot offer a value the save refuses.
 */
function gsaWire(): void
{
    GridSectionAdminRoutes::wire(app());
}

/**
 * An admin, built the way AdminCapabilityMapTest builds one.
 *
 * `User::factory()` is not available here — this repository ships no Faker — so
 * the row is written directly, which is what every other capability test in the
 * suite does.
 */
function gsaAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'GSA '.$role,
        'email' => 'gsa-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

function gsaSection(array $extra = []): GridSection
{
    return GridSection::create(array_merge([
        'name' => 'GSA', 'slug' => 'gsa-'.uniqid(), 'status' => 'draft', 'position' => 1,
        'show_heading' => true, 'heading' => 'GSA', 'subheading' => '',
        'source' => 'bestsellers', 'include_children' => false,
        'count' => 4, 'mobile_count' => 4,
        'desktop_layout' => 'grid', 'desktop_cols' => 4,
        'mobile_layout' => 'carousel', 'mobile_cols' => 2,
        'skin' => '', 'card_label' => '', 'show_rank' => false,
        'show_view_all' => false, 'view_all_label' => '', 'view_all_url' => '',
    ], $extra));
}

function gsaProduct(string $slug): Product
{
    return Product::firstOrCreate(['slug' => $slug], [
        'name' => 'GSA '.$slug, 'status' => 'publish', 'is_visible' => true,
        'price' => 5000, 'stock_status' => 'instock', 'type' => 'simple',
        'rating' => 0.0, 'review_count' => 0, 'total_sales' => 600000,
        // Both columns CLAUDE.md names as having leaked, filled, so the
        // allowlist case is asserting an absence that would otherwise be one by
        // accident. `wc_id` is unique, hence the crc rather than a constant.
        'sku' => 'SKU-'.$slug, 'wc_id' => crc32($slug) % 1000000,
    ]);
}

beforeEach(function () {
    gsaWire();
    GridSection::query()->delete();
    GridSections::flush();
});

/* ══════════════════════════ the capability, closed ════════════════════════ */

it('maps every one of its routes to a capability, writes above reads', function () {
    /*
     * `AdminCapabilities::for()` returns null for a route it does not
     * recognise, and EnforceAdminCapability turns a null into a 403 for
     * everyone who is not an owner. A path added here tomorrow and never mapped
     * is owner-only, not open — so this case is about the ORDER, which is the
     * half that fails silently.
     *
     * MUTATION: move the two `GET admin-api/grid-sections…` rules ABOVE the
     * five write rules in AdminCapabilities::RULES and this goes red — DELETE
     * then resolves to `gridsections.view` and a read capability is enough to
     * delete an instance. Run, red, put back.
     */
    $unmapped = [];
    $byRoute = [];

    foreach (GridSectionAdminRoutes::registered() as $route) {
        $cap = AdminCapabilities::for($route);

        if ($cap === null) {
            $unmapped[] = implode('|', $route->methods()).' '.$route->uri();
        }

        foreach ($route->methods() as $method) {
            if ($method !== 'HEAD') {
                $byRoute[$method.' '.$route->uri()] = $cap;
            }
        }
    }

    expect($unmapped)->toBe([], 'unmapped: '.implode(', ', $unmapped));

    // Every write is on the manage half; every read on the view half.
    expect($byRoute['DELETE admin-api/grid-sections/{grid}'])->toBe('gridsections.manage')
        ->and($byRoute['PUT admin-api/grid-sections/{grid}'])->toBe('gridsections.manage')
        ->and($byRoute['POST admin-api/grid-sections/{grid}/preview'])->toBe('gridsections.manage')
        ->and($byRoute['POST admin-api/grid-sections'])->toBe('gridsections.manage')
        ->and($byRoute['GET admin-api/grid-sections'])->toBe('gridsections.view')
        ->and($byRoute['GET admin-api/grid-sections/{grid}/preview'])->toBe('gridsections.view')
        ->and($byRoute['GET admin-api/grid-sections/products'])->toBe('gridsections.view');
});

it('refuses a guest on every one of its routes', function () {
    foreach (GridSectionAdminRoutes::registered() as $route) {
        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD'], true)) {
                continue;
            }

            $uri = '/'.str_replace(['{grid}'], ['1'], $route->uri());

            $response = test()->call($method, $uri);

            // 404 (Lane SEC, round 2): an admin-guarded address that does not
            // carry the secret admin path is hidden rather than redirected, so the
            // Location header can no longer name it. Still a refusal.
            expect(in_array($response->status(), [401, 403, 302, 404], true))
                ->toBeTrue($method.' '.$uri.' answered '.$response->status().' to a guest');
        }
    }
});

/* ═══════════════════════ the picker's allowlist ═══════════════════════════ */

it('returns five columns from the catalogue and not the product row', function () {
    /*
     * MUTATION: change the picker's `->select([...])` to a bare query and its
     * mapping to `$p->toArray()`, and this goes red on `sku` and `wc_id`. Run,
     * red, put back.
     *
     * It is behind auth:admin and its own capability and it STILL names its
     * columns, because CLAUDE.md's list of what leaked in production is a list
     * of endpoints somebody was sure was private.
     */
    gsaProduct('gsa-p1');

    $body = test()->actingAs(gsaAdmin(), 'admin')
        ->getJson('/admin-api/grid-sections/products?q=gsa')
        ->assertOk()
        ->json();

    expect($body['products'])->not->toBeEmpty();

    foreach ($body['products'] as $row) {
        expect(array_keys($row))->toBe(['id', 'name', 'slug', 'image', 'price']);
    }

    $raw = json_encode($body);

    foreach (['sku', 'wc_id', 'total_sales', 'cost_price'] as $forbidden) {
        expect($raw)->not->toContain($forbidden);
    }
});

it('offers only products a shopper can already see', function () {
    // A picker that offered a draft product would produce a manual list with a
    // hole in it: fetchPool() re-applies visible() on the way out, so the
    // product would silently vanish from the grid and the owner would never
    // learn why.
    gsaProduct('gsa-live');
    gsaProduct('gsa-hidden')->update(['status' => 'draft']);

    $names = collect(test()->actingAs(gsaAdmin(), 'admin')
        ->getJson('/admin-api/grid-sections/products?q=gsa-')
        ->assertOk()
        ->json('products'))->pluck('slug')->all();

    expect($names)->toContain('gsa-live')->not->toContain('gsa-hidden');
});

/* ═══════════════════ a select stores one of its own options ═══════════════ */

it('lands a posted value it will not store on the shipped default', function () {
    /*
     * `invalid => default` is the policy, and it is the same choice
     * Banners::POLICY makes: a hand-rolled POST of `source=<script>`, or the id
     * of a brand deleted a minute ago, stores the DEFAULT and the grid draws
     * best sellers, rather than coming back in a `rejected` list a screen then
     * has to explain.
     *
     * MUTATION: drop `self::POLICY` from the `ModuleSchema::normalise()` call
     * in GridSections::fields() and this goes red — DEFAULT_POLICY is
     * `invalid => reject`, cast() answers null, apply() skips, and the posted
     * rubbish leaves the OLD value in place instead of the default. Run, red,
     * put back.
     */
    $section = gsaSection(['source' => 'newest', 'desktop_cols' => 5, 'desktop_layout' => 'carousel']);

    test()->actingAs(gsaAdmin(), 'admin')
        ->putJson('/admin-api/grid-sections/'.$section->id, ['values' => [
            'source' => '<script>alert(1)</script>',
            'desktop_cols' => '999',
            'mobile_cols' => 'nine',
            'desktop_layout' => 'spiral',
            'skin' => 'not-a-skin',
            'status' => 'live',
        ]])
        ->assertOk();

    $fresh = $section->fresh();

    expect($fresh->source)->toBe('bestsellers')
        ->and($fresh->desktop_cols)->toBe(4)
        ->and($fresh->mobile_cols)->toBe(2)
        ->and($fresh->desktop_layout)->toBe('grid')
        ->and($fresh->skin)->toBe('')
        ->and($fresh->status)->toBe('draft')
        // And every stored value really is one of the options.
        ->and(GridSection::SOURCES)->toHaveKey($fresh->source)
        ->and(GridSection::LAYOUTS)->toHaveKey($fresh->desktop_layout)
        ->and(GridSection::STATUSES)->toHaveKey($fresh->status);
});

it('hands the picker the SAME option sets the cast checks against', function () {
    /*
     * A picker built from one list and a cast built from another is how a
     * screen comes to offer a value the save refuses — the arrangement
     * docs/M-PHASE3-SETTINGS-SCHEMA.md §1 measured. The three sets that live in
     * another registry travel as their own top-level keys because
     * ModuleSchema::fields() emits DECLARED options rather than overrides; this
     * asserts the two are the same sets anyway.
     *
     * MUTATION: make optionSets() return `['skins' => ['classic' => 'Classic']]`
     * and this goes red. Run, red, put back.
     */
    $brand = Brand::firstOrCreate(['slug' => 'gsa-brand'], ['name' => 'GSA Brand']);
    $section = gsaSection();

    $body = test()->actingAs(gsaAdmin(), 'admin')
        ->getJson('/admin-api/grid-sections/'.$section->id)
        ->assertOk()
        ->json();

    $overrides = GridSections::overrides();

    expect($body['brands'])->toBe($overrides['source_brand_id']['options'])
        ->and($body['categories'])->toBe($overrides['source_category_id']['options'])
        ->and($body['skins'])->toBe($overrides['skin']['options'])
        // The brand that exists is offered.
        ->and($body['brands'])->toHaveKey((string) $brand->id)
        // And every one of the 28 shipped card templates is offered, plus the
        // "use the shop's own" option that is this control's default.
        ->and(array_keys($body['skins']))->toBe(array_merge([''], array_keys(GridSkins::ALL)));
});

/* ═════════════════════ the manual list is validated ═══════════════════════ */

it('stores only ids that name a row, de-duplicated, in the owner’s order', function () {
    /*
     * "A manual product list is ids validated against rows that exist" —
     * CLAUDE.md rule 5, in as many words. An int is not a product: without the
     * check a POST of ten thousand ids would be stored verbatim and carried
     * into a `whereIn` of ten thousand bound parameters on the homepage's
     * critical path, by somebody with an editor account.
     *
     * MUTATION: delete the `whereIn(...)->pluck('id')` filter from
     * cleanManualIds() and this goes red on the 999999. Run, red, put back.
     */
    $a = gsaProduct('gsa-m1');
    $b = gsaProduct('gsa-m2');
    $section = gsaSection(['source' => 'manual']);

    test()->actingAs(gsaAdmin(), 'admin')
        ->putJson('/admin-api/grid-sections/'.$section->id, [
            'values' => ['source' => 'manual'],
            'manual_ids' => [$b->id, 999999, $a->id, $b->id, 0, -3],
        ])
        ->assertOk();

    expect($section->fresh()->manual_ids)->toBe([$b->id, $a->id]);
});

it('never stores more manual ids than the grid could ever draw', function () {
    // The cap is fetchCount()'s own ceiling. Storing more than the row can draw
    // is storing something nothing will read — and it is the size of the
    // `whereIn` the homepage would run.
    $ids = [];

    foreach (range(1, 60) as $i) {
        $ids[] = gsaProduct('gsa-cap-'.$i)->id;
    }

    $section = gsaSection(['source' => 'manual']);

    test()->actingAs(gsaAdmin(), 'admin')
        ->putJson('/admin-api/grid-sections/'.$section->id, [
            'values' => [], 'manual_ids' => $ids,
        ])
        ->assertOk();

    expect($section->fresh()->manual_ids)->toHaveCount(48);
});

/* ════════════════════════ create, preview, delete ═════════════════════════ */

it('creates the owner’s two named rows from presets, and always as a draft', function () {
    /*
     * ▲ ALWAYS A DRAFT, whatever the preset says. Pressing "Add" must not put a
     * new band on the live front page before the owner has looked at it.
     *
     * MUTATION: remove the `$section->status = 'draft';` line after the preset
     * is applied in store() and add a `'status' => 'publish'` to the bundles
     * preset — this goes red. Run, red, put back.
     */
    $admin = gsaAdmin();

    foreach (['bundles', 'bestsellers'] as $preset) {
        test()->actingAs($admin, 'admin')
            ->postJson('/admin-api/grid-sections', ['preset' => $preset])
            ->assertCreated();
    }

    $rows = GridSection::query()->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->name)->toBe('Big savings bundles')
        ->and($rows[0]->desktop_cols)->toBe(4)
        ->and($rows[0]->mobile_layout)->toBe('carousel')
        ->and($rows[0]->status)->toBe('draft')
        ->and($rows[1]->heading)->toBe('BEST SELLERS')
        ->and($rows[1]->desktop_cols)->toBe(5)
        ->and($rows[1]->mobile_count)->toBe(6)
        ->and($rows[1]->status)->toBe('draft')
        // Two slugs, not one.
        ->and($rows[0]->slug)->not->toBe($rows[1]->slug);
});

it('previews from the unsaved buffer and writes nothing', function () {
    /*
     * The whole point of a buffered editor is to see a COMBINATION before
     * committing it. A preview that read the database would show the owner the
     * row he is trying to get away from — and one that WROTE would be a preview
     * that publishes, which is the one failure this must not have.
     *
     * MUTATION: add `$grid->save();` to previewDraft() and this goes red on the
     * stored heading. Run, red, put back.
     */
    gsaProduct('gsa-pv');
    $section = gsaSection(['heading' => 'Stored heading', 'status' => 'draft']);

    $body = test()->actingAs(gsaAdmin(), 'admin')
        ->postJson('/admin-api/grid-sections/'.$section->id.'/preview', [
            'values' => ['heading' => 'Typed but not saved', 'desktop_cols' => '6'],
        ])
        ->assertOk()
        ->json();

    expect($body['empty'])->toBeFalse()
        ->and($body['html'])->toContain('Typed but not saved')
        ->and($body['html'])->toContain('--gs-d:6')
        // A DRAFT is drawn, because a draft is exactly what he is looking at
        // while he builds it.
        ->and($body['html'])->toContain('kbb-gsec')
        // And the row is untouched.
        ->and($section->fresh()->heading)->toBe('Stored heading')
        ->and($section->fresh()->status)->toBe('draft');
});

it('leaves the homepage’s cached answer stale for nobody after a write', function () {
    /*
     * `registryRows()` memoises in a process-level static AS WELL as in the
     * cache — the `Setting::map()` shape CLAUDE.md names, which within one
     * long-lived process will not see writes made after the first call. Every
     * mutating endpoint ends in `GridSections::flush()`; miss it on one and the
     * console saves an instance, reloads its own list from the memo, and shows
     * the owner the row he just changed in its old shape.
     *
     * MUTATION: delete the `GridSections::flush()` call from update() and this
     * goes red — the registry still carries the old name. Run, red, put back.
     */
    $section = gsaSection(['name' => 'Before']);

    // Prime both layers.
    expect(GridSections::registryRows()[$section->sectionKey()][0])->toBe('Before');

    test()->actingAs(gsaAdmin(), 'admin')
        ->putJson('/admin-api/grid-sections/'.$section->id, ['values' => ['name' => 'After']])
        ->assertOk();

    expect(GridSections::registryRows()[$section->sectionKey()][0])->toBe('After');
});

it('deletes an instance and takes it out of the homepage registry', function () {
    $section = gsaSection();
    $key = $section->sectionKey();

    expect(GridSections::registryRows())->toHaveKey($key);

    test()->actingAs(gsaAdmin(), 'admin')
        ->deleteJson('/admin-api/grid-sections/'.$section->id)
        ->assertOk();

    expect(GridSections::registryRows())->not->toHaveKey($key)
        ->and(\App\Services\HomepageSections::registry())->not->toHaveKey($key);
});

it('gives every control on the screen a writer behind it', function () {
    /*
     * ── THE SHAPE AdminConsoleWriteTokenTest WAS WRITTEN AFTER ──────────────
     *
     * `site_title` had a box on Appearance → Homepage and no writer behind it
     * in either console block: the owner typed into it and nothing happened.
     * This screen cannot grow that defect by forgetting a key — it renders from
     * the payload's `tabs` rather than from a list of its own — but it CAN grow
     * it the other way, by a field being added to GridSections::SCHEMA and
     * declared in no TABS group, which drops it off the screen while leaving it
     * in the cast.
     *
     * So both directions are asserted, over the real endpoints:
     *
     *   every SCHEMA key is in a TABS group, so it reaches the screen;
     *   every SCHEMA key round-trips through PUT, so the box that draws it
     *     writes something.
     *
     * MUTATION: delete `'card_label'` from the `layout` group in
     * GridSections::TABS and the first half goes red; delete the
     * `$section->{$key} = $cast;` line at the end of apply() and the second
     * half goes red on eighteen keys at once. Run, red, put back.
     */
    $section = gsaSection();
    $admin = gsaAdmin();

    $tabKeys = [];

    foreach (GridSections::TABS as [, , $keys]) {
        foreach ($keys as $k) {
            $tabKeys[] = $k;
        }
    }

    expect(array_keys(GridSections::SCHEMA))->toEqualCanonicalizing($tabKeys);

    // And the payload really carries them all.
    $body = test()->actingAs($admin, 'admin')
        ->getJson('/admin-api/grid-sections/'.$section->id)->assertOk()->json();

    $drawn = [];

    foreach ($body['tabs'] as $tab) {
        foreach ($tab['fields'] as $field) {
            $drawn[] = $field['key'];
        }
    }

    expect($drawn)->toEqualCanonicalizing(array_keys(GridSections::SCHEMA));

    /*
     * Each key moved to a value that is NOT its default and NOT what the row
     * already holds, so a writer that silently did nothing cannot pass by
     * coincidence.
     */
    $moved = [
        'name' => 'Renamed', 'status' => 'publish',
        'show_heading' => false, 'heading' => 'A heading', 'subheading' => 'A sub',
        'source' => 'newest', 'include_children' => true,
        'count' => 12, 'mobile_count' => 3,
        'desktop_layout' => 'carousel', 'desktop_cols' => '6',
        'mobile_layout' => 'grid', 'mobile_cols' => '3',
        'skin' => 'luxe', 'card_label' => 'An eyebrow', 'show_rank' => true,
        'show_view_all' => true, 'view_all_label' => 'See them all', 'view_all_url' => '/shop/',
    ];

    test()->actingAs($admin, 'admin')
        ->putJson('/admin-api/grid-sections/'.$section->id, ['values' => $moved])
        ->assertOk();

    $stored = GridSections::valuesOf($section->fresh());

    foreach ($moved as $key => $want) {
        expect((string) $stored[$key])->toBe((string) $want, "the control for '{$key}' has no writer behind it");
    }

    // The two id selects are the exception this loop cannot cover — they store
    // null for '' — so they are asserted on their own.
    test()->actingAs($admin, 'admin')
        ->putJson('/admin-api/grid-sections/'.$section->id, ['values' => [
            'source_brand_id' => (string) Brand::firstOrCreate(['slug' => 'gsa-w'], ['name' => 'GSA W'])->id,
        ]])->assertOk();

    expect($section->fresh()->source_brand_id)->toBeGreaterThan(0);
});

it('gives a blank grid a name, so it is not an unnamed row on two screens', function () {
    /*
     * ── THE ORDER OF TWO LINES, AND WHAT IT COST ────────────────────────────
     *
     * store() wrote `$section->name` and THEN laid every schema default over
     * the row — and `name`'s shipped default is `''`. So "Add a blank grid"
     * created a row with no name at all: blank on this screen's list, and blank
     * on Appearance → Homepage, which is the one place the owner orders it
     * against the shop's other sections. Two screens with an unnamed row he
     * cannot tell from the next one.
     *
     * It was invisible from the preset side, because both presets carry a
     * `name` that is applied after the defaults — which is why this case
     * creates a BLANK one and the preset case above did not catch it.
     *
     * MUTATION: move the `$section->name = $name;` block back above the two
     * apply() calls in store() and this goes red with an empty name. Run, red,
     * put back.
     */
    $body = test()->actingAs(gsaAdmin(), 'admin')
        ->postJson('/admin-api/grid-sections', [])
        ->assertCreated()
        ->json();

    expect($body['section']['name'])->not->toBe('')
        ->and($body['section']['slug'])->not->toBe('')
        ->and($body['section']['status'])->toBe('draft');

    $row = GridSection::query()->findOrFail($body['section']['id']);

    expect($row->name)->toBe('Product grid')
        // And the rest of the row is the shipped defaults rather than nulls,
        // which is the other half of what the defaults pass is for.
        ->and($row->source)->toBe('bestsellers')
        ->and($row->desktop_cols)->toBe(4)
        ->and($row->mobile_layout)->toBe('carousel');

    // And a name the owner typed is kept.
    $named = test()->actingAs(gsaAdmin(), 'admin')
        ->postJson('/admin-api/grid-sections', ['name' => 'Autumn picks'])
        ->assertCreated()
        ->json();

    expect($named['section']['name'])->toBe('Autumn picks')
        ->and($named['section']['slug'])->toBe('autumn-picks');
});
