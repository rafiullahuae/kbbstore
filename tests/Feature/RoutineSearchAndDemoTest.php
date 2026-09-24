<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use App\Services\BuildMyRoutine;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\ConcernCollections;
use App\Support\DemoSeed;
use App\Support\RoutineConcerns;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\BuildMyRoutineRoutes;

/**
 * Lane Q round 3 — the owner's three, and the first is a bug he hit live.
 *
 * "for the routines okay, but also give option to import demo data, and turn on
 * off routine section completely. also the product search on build routine page
 * was not working and was not showing any results upon search, make sure
 * everything must be working in quick real time."
 *
 * ── THE SEARCH HAD TWO INDEPENDENT CAUSES, AND ROUND 2 FIXED NEITHER ───────
 *
 * Round 2 found that a search narrowed to the open step returns nothing on an
 * empty step. That was real, but it CANNOT be what he hit: on the screen he
 * actually has, 2.60.268, there were no step tabs and the role filter defaulted
 * to "Every product", so a search carried no role at all. Reproduced in
 * Chromium against that exact file rather than reasoned about.
 *
 *   1. THE TRIGGER. The box was bound to `onchange`, which on a text input
 *      fires on BLUR or ENTER and nothing else. Typing a word fired ZERO
 *      requests and left the previous rows sitting there. Measured: typing
 *      "centella" on 2.60.268 produced no request and all 24 rows stayed. It
 *      was still true on the tabs screen, where it reads worse, because a step
 *      tab starts empty. Pinned by the first case below.
 *
 *   2. THE COLUMN. Round 1 added `ingredients` to the search's WHERE, and
 *      `products.ingredients` is added by a migration that guards every column
 *      with hasColumn. On a server where it has not run the column is absent
 *      and EVERY SEARCH IS A 500 while browsing without a term works perfectly.
 *      CLAUDE.md records five packages whose migrations reached the live server
 *      and never ran. Measured: two 500s and "Could not load the product list."
 *      Pinned by the third and fourth cases.
 *
 * Which of the two he hit cannot be settled from here, so both are fixed.
 */
beforeEach(function () {
    BuildMyRoutineRoutes::wire(app());
    Product::query()->forceDelete();
});

function rsdAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Q3 Owner',
        'email' => 'q3-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

function rsdProduct(array $overrides = []): Product
{
    return Product::create(array_replace([
        'slug' => 'rsd-' . Str::random(8),
        'name' => 'RSD ' . Str::random(4),
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
    ], $overrides));
}

function rsdScreen(): string
{
    return (string) file_get_contents(
        resource_path('views/admin/partials/routines-screen.blade.php')
    );
}

/* ─────────────────────── 1. the trigger: typing must search ─────────────── */

it('runs the search as he types rather than only when he leaves the box', function () {
    /*
     * THE DEFECT, VERBATIM FROM THE SHIPPED FILE:
     *
     *     q.onchange = function(){ query = q.value.trim(); … loadProducts(); };
     *
     * `change` on a text input fires on blur or Enter. He typed a word, nothing
     * happened at all — no request, no spinner, no change to the list — and
     * reported the search as not working. Measured in Chromium against
     * 2.60.268: typing "centella" fired ZERO requests and left all 24 rows.
     *
     * MUTATION NOTE: swap `q.oninput` back to `q.onchange` and this is red on
     * the first two expectations. Re-measured after the fix: typing the same
     * word fires exactly one request and paints 16 rows.
     */
    $src = rsdScreen();

    expect($src)->toContain('q.oninput = function()')
        ->and($src)->not->toContain('q.onchange = function()');

    // Debounced, so a word is one request and not one per letter, and the
    // number is written down rather than folded into a magic literal.
    expect($src)->toContain('}, 250);');

    /*
     * ENTER STILL SEARCHES AND NO LONGER FIRES TWICE. On the shipped screen
     * `onchange` AND `onkeydown` both answered Enter, so it sent the same
     * request twice — measured. With `change` gone there is one handler left.
     */
    expect($src)->toContain("if (ev.key !== 'Enter') return;")
        ->and($src)->toContain('clearTimeout(typeTimer);');
});

it('keeps the caret in the box while the results repaint', function () {
    /*
     * THE DEFECT THE FIX WOULD OTHERWISE HAVE CREATED, and it would have been
     * worse than the bug: render() replaces the whole panel, so a repaint
     * between keystrokes destroys the search input and takes focus with it. A
     * box you can type exactly one character into is not an improvement on a
     * box that ignores you.
     *
     * MUTATION NOTE: delete the keepId/restored block at the end of render()
     * and this is red; in Chromium, the second keystroke goes nowhere.
     */
    $src = rsdScreen();

    expect($src)->toContain('var keepId = active && active.id')
        ->and($src)->toContain('restored.setSelectionRange(keepAt, keepAt)');
});

/* ───────────────── 2. the column: a search must never 500 ────────────────── */

it('still searches when the server has no ingredients column', function () {
    /*
     * THE DEFECT, AND THIS LANE SHIPPED IT. Round 1 added
     * `orWhereRaw('ingredients LIKE ?')` unconditionally. The column is added
     * by 2026_10_05_000000_add_product_editor_columns.php inside a
     * Schema::hasColumn guard, so a server that never ran it has no such
     * column and every search is a SQL error — a 500 — while browsing the same
     * table without a term works perfectly. That is exactly the shape of the
     * owner's report, and it is invisible to a test suite that always migrates.
     *
     * MUTATION NOTE: drop the `if ($hasIngredients)` guard in
     * RoutinesApiController::products() and this case goes red with a 500 —
     * measured in Chromium too, where it printed "Could not load the product
     * list." over two failed requests.
     */
    rsdProduct(['name' => 'Centella Ampoule']);
    rsdProduct(['name' => 'Plain Cream']);

    Schema::table('products', function ($t) {
        $t->dropColumn('ingredients');
    });

    expect(Schema::hasColumn('products', 'ingredients'))->toBeFalse();

    $body = test()->actingAs(rsdAdmin(), 'admin')
        ->getJson('/admin-api/routine-products?q=centella')
        ->assertOk()
        ->json();

    // It degrades to the name and SKU search it had before round 1 — it does
    // not fail, and it does not silently return nothing either.
    expect($body['total'])->toBe(1)
        ->and($body['products'][0]['name'])->toBe('Centella Ampoule')
        ->and($body['searched'])->toBe(['name', 'sku']);
});

it('says which columns it searched, so a narrowed search is not a silent one', function () {
    /*
     * THE DEFECT A BARE FALLBACK WOULD LEAVE: the ingredient terms on
     * docs/SEO-CONCERN-MAPPING.md §3 quietly finding nothing, with the owner
     * concluding the worksheet is wrong rather than that his server is behind.
     * The screen prints this.
     */
    rsdProduct(['name' => 'Plain Cream', 'ingredients' => 'Water, Centella Asiatica Extract.']);

    $hit = test()->actingAs(rsdAdmin(), 'admin')
        ->getJson('/admin-api/routine-products?q=centella')->assertOk()->json();

    expect($hit['searched'])->toBe(['name', 'sku', 'ingredients'])
        ->and($hit['total'])->toBe(1);

    // Nothing typed, nothing claimed.
    $all = test()->actingAs(rsdAdmin(), 'admin')
        ->getJson('/admin-api/routine-products')->assertOk()->json();

    expect($all['searched'])->toBeNull();
});

/* ───────────── 3. the race: a stale reply must never repaint ─────────────── */

it('drops a reply that is no longer the one being waited for', function () {
    /*
     * THE CLASSIC SEARCH RACE. Type "ceramide" and several requests are in
     * flight; the reply to "cer" landing after the reply to "ceramide"
     * repaints the older, wider result set over the newer one. It looks
     * exactly like a broken search, and because it depends on the network it
     * is intermittent, which is worse than always wrong.
     *
     * MUTATION NOTE — RUN, NOT ASSERTED. Both guards were removed and the race
     * was arranged in Chromium by holding the reply to `q=cer` for two seconds:
     * with the guards, the box read "ceramide" and the list showed 166 rows;
     * without them, the box read "ceramide" and the list showed 339 — the
     * stale answer — under a label that said "339 products match ceramide".
     * Removing only the `products = body` guard is NOT enough to show it,
     * because the `finally` guard also suppresses the repaint; the note says so
     * because a later lane deleting one of the two would otherwise find this
     * file green.
     */
    $src = rsdScreen();
    $fn = Str::betweenFirst($src, 'async function loadProducts(){', 'function stepView');

    // The ticket is taken before the request and checked after it — twice, and
    // both matter.
    expect($fn)->toContain('var mine = ++pseq;')
        ->and(substr_count($fn, 'if (mine !== pseq) return;'))->toBe(2)
        ->and($fn)->toContain('if (mine === pseq) { searching = false; render(); }');
});

it('distinguishes nothing-matched from nothing-loaded', function () {
    /*
     * THE DEFECT: one word, "Loading…", drawn for "the first request is out",
     * "your search is running" and "the request failed". A control that cannot
     * tell you which of those happened is a control you stop trusting — and
     * part of his report may simply be that this box never acknowledged a
     * keystroke.
     */
    $src = rsdScreen();

    expect($src)->toContain("status = 'Searching…';")
        ->and($src)->toContain("' match “' + esc(query) + '”'")
        ->and($src)->toContain('Nothing loaded.')
        ->and($src)->toContain("'No product matches “' + esc(query) + '”.'");
});

/* ──────────────────────── 4. demo data for routines ──────────────────────── */

it('imports five demo products, one for every step', function () {
    /*
     * THE GAP: DemoContentController::TYPES had no routines type, so he could
     * not see a filled routine without first doing the two-to-three hour
     * tagging job — which is the job the demo exists to help him decide about.
     *
     * MUTATION NOTE: remove 'routines' from TYPES and this is red with a 422.
     */
    $out = test()->actingAs(rsdAdmin(), 'admin')
        ->postJson('/admin-api/demo-content/routines/import')->assertOk()->json();

    expect($out['ok'])->toBeTrue()->and($out['count'])->toBe(5);

    $demo = Product::query()->where('sku', 'like', 'DEMO-RTN-%')->get();

    expect($demo)->toHaveCount(5)
        ->and($demo->pluck('routine_role')->sort()->values()->all())
        ->toBe(['cleanse', 'moisturise', 'protect', 'tone', 'treat']);

    // Every one is live, or it fills nothing.
    foreach ($demo as $p) {
        expect($p->status)->toBe('publish')
            ->and((bool) $p->is_visible)->toBeTrue()
            ->and($p->stock_status)->toBe('instock')
            ->and($p->name)->toStartWith('Demo —');
    }

    // And every routine can now draw every step.
    $c = app(BuildMyRoutine::class)->coverage();

    expect($c['by_role'])->toBe(['cleanse' => 1, 'tone' => 1, 'treat' => 1, 'moisturise' => 1, 'protect' => 1])
        ->and(array_sum($c['by_role_short']))->toBe(0);
});

it('cannot publish a concern page or move the countdown, by construction', function () {
    /*
     * THE DEFECT THIS DESIGN EXISTS AGAINST, and it is the one worth the most
     * care. A demo product tagged for a concern would count towards
     * ConcernCollections::MIN_PRODUCTS, take /concern/acne/ from 404 to 200,
     * put it in the sitemap, make the quiz hand-off offer it — and then 404 it
     * again the day he pressed Remove, which is how a shop teaches Google it
     * has dead pages. It would also inflate the countdown that tells him how
     * close he is to a real landing page, which is the one number on this
     * screen that has to be trustworthy.
     *
     * The guard is not an exclusion rule, it is the ABSENCE OF A TAG: every
     * demo row carries routine_concerns NULL, which means "suits any routine"
     * to the engine and matches NOTHING in ConcernCollections' explicit-tag
     * query. Nothing had to be excluded anywhere.
     *
     * MUTATION NOTE: give the demo rows `json_encode(['acne'])` in
     * seedRoutines() — three of them then publish /concern/acne/ and move the
     * acne countdown. Both halves below go red.
     */
    test()->actingAs(rsdAdmin(), 'admin')
        ->postJson('/admin-api/demo-content/routines/import')->assertOk();

    foreach (Product::query()->where('sku', 'like', 'DEMO-RTN-%')->get() as $p) {
        expect($p->routine_concerns)->toBeNull()
            ->and(RoutineConcerns::clean($p->routine_concerns))->toBe([]);
    }

    // No concern page exists, and the router agrees.
    expect(ConcernCollections::live())->toBe([]);
    test()->get('/concern/acne/')->assertNotFound();

    // And the countdown has not moved one row.
    $pages = collect(app(BuildMyRoutine::class)->coverage()['concern_pages'])->keyBy('concern');

    foreach (RoutineConcerns::slugs() as $slug) {
        expect($pages[$slug]['tagged'])->toBe(0)
            ->and($pages[$slug]['live'])->toBeFalse();
    }
});

it('removes every row it made and nothing else', function () {
    /*
     * THE DEFECT: demo content that outlives the Remove button, or a Remove
     * that reaches past its own rows. The brand is its OWN — sharing
     * seedReviews()' 'demo-review-brand' would mean removing the routine demo
     * deletes a brand the review demo still has products on.
     *
     * MUTATION NOTE: point seedRoutines() at 'demo-review-brand' and import
     * both types; removing routines then takes the review demo's brand with it.
     */
    $mine = rsdProduct(['name' => 'My Own Cleanser', 'routine_role' => 'cleanse']);

    test()->actingAs(rsdAdmin(), 'admin')
        ->postJson('/admin-api/demo-content/routines/import')->assertOk();

    expect(Brand::query()->where('slug', 'demo-routine-brand')->exists())->toBeTrue();

    test()->actingAs(rsdAdmin(), 'admin')
        ->postJson('/admin-api/demo-content/routines/remove')->assertOk();

    expect(Product::withTrashed()->where('sku', 'like', 'DEMO-RTN-%')->count())->toBe(0)
        ->and(Brand::query()->where('slug', 'demo-routine-brand')->exists())->toBeFalse()
        ->and(DB::table(DemoSeed::TABLE)->where('type', 'routines')->count())->toBe(0)
        // His own row is untouched.
        ->and(Product::query()->whereKey($mine->id)->value('routine_role'))->toBe('cleanse');
});

it('creates nothing until the button is pressed', function () {
    /*
     * Rule 1. Applying this package must not put a product in his catalogue.
     */
    expect(Product::query()->where('sku', 'like', 'DEMO-RTN-%')->count())->toBe(0);

    $body = test()->actingAs(rsdAdmin(), 'admin')
        ->getJson('/admin-api/routines')->assertOk()->json();

    expect($body['demo']['routines'])->toBe(0);
});

/* ───────────────── 5. turning the section on and off completely ──────────── */

it('turns every routine page off and on from the screen that shows the work', function () {
    /*
     * THE GAP: the switch existed at Store → Modules → Build my routine and
     * was invisible from the screen where the tagging happens, so he either did
     * not know it was there or did not trust it. This writes the SAME setting —
     * there is no second one to disagree with.
     *
     * "Completely" is proved by FETCHING both pages in both states rather than
     * by reading the flag.
     *
     * MUTATION NOTE: point saveModule() at a new key such as
     * 'routines_visible' and this is red — Store → Modules would go on showing
     * the old value while the storefront followed the new one.
     */
    $admin = rsdAdmin();

    // Ships off.
    expect(app(BuildMyRoutine::class)->enabled())->toBeFalse();
    test()->get('/routines')->assertNotFound();
    test()->get('/routines/acne')->assertNotFound();

    test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/routines-module', ['on' => true])
        ->assertOk()
        ->assertJson(['ok' => true, 'module_on' => true]);

    test()->get('/routines')->assertOk();
    test()->get('/routines/acne')->assertOk();

    // The same key the Modules screen reads.
    expect(app(SettingsService::class)->moduleEnabled('build_my_routine', false))->toBeTrue();

    test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/routines-module', ['on' => false])->assertOk();

    test()->get('/routines')->assertNotFound();
    test()->get('/routines/acne')->assertNotFound();
});

it('does not let the tagging role publish the storefront', function () {
    /*
     * THE DEFECT: putting this switch on a catalog.* screen and giving it the
     * catalog.* capability its neighbours have. Everything else on this screen
     * decides WHICH PRODUCT FILLS WHICH STEP; this one puts two pages on the
     * internet. An editor who may retag the catalogue may not publish it.
     *
     * MUTATION NOTE: change the rule to 'catalog.manage' and this is red.
     */
    expect(AdminCapabilities::forPath('POST', 'admin-api/routines-module'))->toBe('store.settings')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/routine-products/7'))->toBe('catalog.manage')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/routines'))->toBe('catalog.view');
});

it('says on the screen what the switch does not turn off', function () {
    /*
     * THE SURPRISE THIS EXISTS AGAINST: he flips the routine section off
     * expecting every routine-ish page to vanish, and /concern/acne/ keeps
     * answering 200 — because concern pages are deliberately not behind this
     * switch (they are ordinary shop pages people reach from Google). Finding
     * that out at the wrong moment is exactly the kind of thing that destroys
     * trust in a switch.
     */
    $src = rsdScreen();

    expect($src)->toContain('What this switch does not cover')
        ->and($src)->toContain('/concern/&lt;concern&gt;/')
        // And that it is the same switch as the Modules screen's.
        ->and($src)->toContain('Store → Modules → Build my routine');
});

it('leaves the module off when the package applies', function () {
    /*
     * Rule 1, and the one the owner is entitled to rely on: applying this moves
     * nothing on the shop.
     */
    expect(app(BuildMyRoutine::class)->enabled())->toBeFalse();
    test()->get('/routines')->assertNotFound();
    expect(ConcernCollections::live())->toBe([]);
});
