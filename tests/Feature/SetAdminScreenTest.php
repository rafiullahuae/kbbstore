<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Support\AdminCapabilities;
use App\Support\SetPricing;
use Illuminate\Support\Str;
use Tests\Support\SetsAdminRoutes;

/**
 * Catalog → Sets: the list, the picker's search, and delete. (Lane SET,
 * rebuilt for the merge by Lane SP)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THIS SCREEN NO LONGER EDITS ANYTHING, AND THREE OF ITS SIX ENDPOINTS ARE
 * GONE WITH THE EDITOR THAT CALLED THEM.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The owner asked for the Set editor to become part of the PRODUCT editor, and
 * it has — SetProductEditorTest is the file that covers creating and saving a
 * set, and it covers it against ProductEditorApiController. What this file
 * still covers is what routes/sets-admin.php still carries:
 *
 *     GET    /admin-api/sets            the list
 *     GET    /admin-api/sets/products   the member picker's catalogue search,
 *                                       which the PRODUCT editor now calls
 *     DELETE /admin-api/sets/{id}       delete one
 *
 * The cases about creating, saving, sanitising a description and refusing an
 * image address were deleted with the endpoints they exercised, not weakened:
 * every one of those rules now lives in ProductEditorApiController, which had
 * its own for all of them already, and that is the whole saving of the merge.
 *
 * routes/sets-admin.php is required from routes/web.php by the INTEGRATOR — no
 * lane may edit that file — so Tests\Support\SetsAdminRoutes mounts it here
 * from the real file with the real middleware stack.
 */
function setAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Sets '.$role,
        'email' => 'sets-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

function setMemberProduct(string $name, int $fils): Product
{
    return Product::create([
        'slug' => 'm-'.Str::slug($name).'-'.Str::random(6),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $fils,
        'sku' => 'SKU-'.Str::upper(Str::random(5)),
        'stock_status' => 'instock',
    ]);
}

/** @param list<array{0: Product, 1: int}> $members */
function setFixtureSet(array $members, array $overrides = []): Product
{
    $set = Product::create(array_merge([
        'slug' => 'set-'.Str::random(8),
        'name' => 'Glow Set',
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 19900,
        'stock_status' => 'instock',
    ], $overrides));

    $position = 0;

    foreach ($members as [$product, $quantity]) {
        ProductSetItem::create([
            'set_product_id' => $set->id,
            'member_product_id' => $product->id,
            'quantity' => $quantity,
            'position' => $position++,
        ]);
    }

    SetPricing::forget((int) $set->id);

    return $set->fresh();
}

beforeEach(function () {
    SetsAdminRoutes::wire($this->app);
    SetPricing::forget();
});

/* ═══════════════════════════════════════════════════ the capabilities ═══ */

it('maps every route in the file, and maps none of them to the owner-only default', function () {
    /*
     * A route the map has never heard of resolves to null, and
     * EnforceAdminCapability turns null into 403 for everyone but the owner.
     * That is the right default and a terrible thing to rely on: "owner-only
     * because somebody decided so" and "owner-only because nobody mapped it"
     * are the same 403 and a very different piece of evidence.
     *
     * MUTATION NOTE. Delete the 'admin-api/sets' lines from
     * AdminCapabilities::RULES and this is red. RUN.
     */
    $routes = SetsAdminRoutes::registered();

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD') ?? 'GET';

        expect(AdminCapabilities::forPath($method, $route->uri()))->toStartWith(
            'sets.',
            $method.' '.$route->uri().' must resolve to a sets capability, not to the owner-only default.'
        );
    }
});

it('puts the write above the reads, so a DELETE is never resolved as a read', function () {
    /*
     * ── THE DEFECT THIS IS AGAINST ─────────────────────────────────────────
     *
     * RULES is FIRST-MATCH-WINS. A `GET admin-api/sets/**` rule listed above
     * the write rules would claim DELETE /sets/7 — which destroys a published
     * product — for `sets.view`, the read capability. That is exactly the shape
     * of the quiz-leads and coupons/manage mistakes
     * App\Support\AdminCapabilities names, both found by a test rather than by
     * a reader.
     *
     * The POST and PUT rules are still mapped although the routes are gone.
     * That is deliberate and it is not dead weight: they are an ALLOWLIST, and
     * a rule for a path nothing serves costs nothing, while removing them would
     * mean the day anybody re-adds a write under /sets it resolves to `null`
     * and is owner-only by accident rather than by decision.
     *
     * MUTATION NOTE. Move the two ['GET', 'admin-api/sets'...] lines ABOVE the
     * write lines in AdminCapabilities::RULES and this is red. RUN.
     */
    expect(AdminCapabilities::forPath('DELETE', 'admin-api/sets/{id}'))->toBe('sets.manage')
        ->and(AdminCapabilities::forPath('PUT', 'admin-api/sets/{id}'))->toBe('sets.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/sets'))->toBe('sets.manage')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/sets'))->toBe('sets.view')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/sets/products'))->toBe('sets.view');
});

it('does not hand the Sets screen out on catalog.manage', function () {
    /*
     * The whole point of per-capability gating: granting somebody the Sets
     * screen must not hand them the product editor, the category tree and the
     * brands editor — and narrowing catalog.manage one day must not narrow this
     * with it from another file with nothing to notice.
     *
     * MUTATION NOTE. Change the sets rules to 'catalog.manage' and this is red.
     * RUN.
     */
    foreach ([['GET', 'admin-api/sets'], ['GET', 'admin-api/sets/products'],
              ['DELETE', 'admin-api/sets/{id}']] as [$method, $uri]) {
        expect(AdminCapabilities::forPath($method, $uri))->toStartWith('sets.');
    }

    expect(AdminCapabilities::CAPABILITIES)->toHaveKeys(['sets.view', 'sets.manage']);
});

it('refuses a support account, which answers customers and does not touch the catalogue', function () {
    /*
     * `support` reads orders, adds notes and moderates reviews. It has no
     * business listing, searching or deleting products.
     *
     * MUTATION NOTE. Add 'support' to either sets.* row in CAPABILITIES and
     * this is red. RUN.
     */
    $this->actingAs(setAdmin('support'), 'admin');

    $this->getJson('/admin-api/sets')->assertForbidden();
    $this->deleteJson('/admin-api/sets/1')->assertForbidden();
});

it('refuses everything to a visitor who is not signed in at all', function () {
    foreach (['/admin-api/sets', '/admin-api/sets/products'] as $path) {
        $status = $this->getJson($path)->getStatusCode();

        expect($status)->toBeGreaterThanOrEqual(401)->and($status)->toBeLessThan(500);
    }
});

/* ═══════════════════════════════════════════════════════ the endpoints ═══ */

it('lists every set with what is in it and what it costs today', function () {
    /*
     * ▲ THE PRICE ON THE LIST IS THE DERIVED ONE, not the cached column.
     *   A set priced as a discount off its parts works its price out from the
     *   members' current prices, and `products.price` is only a cache of that
     *   which can lag when a member is repriced elsewhere. The list asks for
     *   the real figure — and asks for every set's members in THREE batched
     *   queries rather than one per set, which is what SetEagerLoad is for.
     *
     * MUTATION NOTE. Change index()'s `price_aed` back to `(int) $s->price` and
     * this is red: the list shows AED 199.00 for a set the shop is selling at
     * AED 126.00. RUN.
     */
    $this->actingAs(setAdmin('owner'), 'admin');

    $toner = setMemberProduct('Heartleaf Toner', 9000);
    $serum = setMemberProduct('Azelaic Serum', 5000);

    $set = setFixtureSet([[$toner, 1], [$serum, 1]], [
        'name' => 'Glow Starter Set',
        // Priced as 10% off the parts, with `products.price` left at a stale
        // 199.00 on purpose -- which is exactly the state a member repriced
        // elsewhere leaves behind.
        'price' => 19900,
        'set_price_mode' => SetPricing::MODE_PERCENT,
        'set_discount' => 1000,
    ]);

    $rows = collect($this->getJson('/admin-api/sets')->assertOk()->json('sets'))->keyBy('id');

    expect($rows[$set->id]['name'])->toBe('Glow Starter Set')
        ->and($rows[$set->id]['member_count'])->toBe(2)
        ->and($rows[$set->id]['price_aed'])->toBe('126.00')
        ->and($rows[$set->id]['price_mode'])->toBe(SetPricing::MODE_PERCENT);
});

it('costs three statements for the whole list, not one per set', function () {
    /*
     * The derived price is only affordable on a list because SetEagerLoad
     * batches every set's members in one pass. Two hundred sets must not be two
     * hundred statements inside a console page.
     *
     * MUTATION NOTE. Delete `SetEagerLoad::on($sets)` from
     * SetApiController::index() and this is red — each set then loads its own
     * members through SetPricing's fallback, one aggregate each. RUN.
     */
    $this->actingAs(setAdmin('owner'), 'admin');

    for ($i = 1; $i <= 6; $i++) {
        setFixtureSet([[setMemberProduct('Member '.$i, 1000 * $i), 1]], [
            'set_price_mode' => SetPricing::MODE_AMOUNT,
            'set_discount' => 100,
        ]);
    }

    // Warm whatever a first request in this process warms, so what is counted
    // is the listing rather than the boot.
    $this->getJson('/admin-api/sets')->assertOk();

    \Illuminate\Support\Facades\DB::flushQueryLog();
    \Illuminate\Support\Facades\DB::enableQueryLog();
    $this->getJson('/admin-api/sets')->assertOk();
    $queries = \Illuminate\Support\Facades\DB::getQueryLog();
    \Illuminate\Support\Facades\DB::disableQueryLog();

    $perSet = array_values(array_filter(
        $queries,
        fn ($q) => str_contains((string) $q['query'], 'product_set_items')
            && str_contains((string) $q['query'], 'SUM(')
    ));

    expect($perSet)->toBe([], 'No set may price itself with a statement of its own on this list.');
});

it('searches for members without letting a wildcard match everything', function () {
    /*
     * ── TWO DEFECTS IN ONE CASE, AND THE MYSQL RUN FOUND THE FIRST ─────────
     *
     * 1. THE ESCAPE CHARACTER. This shipped with `ESCAPE '\'`, which is a
     *    SYNTAX ERROR on MySQL — the backslash escapes the closing quote — so
     *    the member picker answered 500 to every search on the real database
     *    while a green SQLite suite said nothing. It is `!` now, the character
     *    every other search in this back office already uses. This case is red
     *    under `-c phpunit-mysql.xml` without that fix and green on SQLite
     *    either way, which is exactly why the parity run is required.
     *
     * 2. THE WILDCARDS. `%` and `_` are LIKE metacharacters, so a search for
     *    "%" unescaped matches the WHOLE CATALOGUE — an operator typing a
     *    stray % would be offered every product in the shop as a member.
     *
     * ▲ IT IS THE PRODUCT EDITOR THAT CALLS THIS NOW. The endpoint did not move
     *   with the editor: it is a catalogue search that excludes sets, one
     *   implementation, and the "What is in the box" panel calls it exactly as
     *   the retired screen did.
     *
     * MUTATION NOTE. Delete escapeLike()'s body (return $term) and the second
     * half is red: the "%" search returns the toner. RUN.
     */
    $this->actingAs(setAdmin('owner'), 'admin');

    $toner = setMemberProduct('Heartleaf Toner', 9000);

    $hit = $this->getJson('/admin-api/sets/products?q=Heartleaf')->assertOk()->json('products');
    expect(collect($hit)->pluck('id')->all())->toContain($toner->id);

    // A bare wildcard is a literal, not "everything".
    $wild = $this->getJson('/admin-api/sets/products?q=%25')->assertOk()->json('products');
    expect(collect($wild)->pluck('id')->all())->not->toContain($toner->id);

    $under = $this->getJson('/admin-api/sets/products?q=Heartleaf_Toner')->assertOk()->json('products');
    expect(collect($under)->pluck('id')->all())->not->toContain($toner->id);
});

it('never offers a set as something to put inside a set', function () {
    /*
     * A set inside a set is an infinite box: SetContents would have to recurse
     * and "what is in this box" would stop having one answer on a packing slip.
     * The picker is the screen's half of that rule;
     * ProductEditorApiController::writeSetMembers() is the door's, and
     * SetProductEditorTest pins it.
     *
     * MUTATION NOTE. Delete `->where('type', '!=', 'set')` from
     * SetApiController::products() and this is red. RUN.
     */
    $this->actingAs(setAdmin('owner'), 'admin');

    $toner = setMemberProduct('Heartleaf Toner', 9000);
    $set = setFixtureSet([[$toner, 1]], ['name' => 'Heartleaf Set']);

    $found = collect($this->getJson('/admin-api/sets/products?q=Heartleaf')->assertOk()->json('products'))
        ->pluck('id')->all();

    expect($found)->toContain($toner->id)
        ->and($found)->not->toContain($set->id);
});

it('deletes a set without touching the products in it', function () {
    $this->actingAs(setAdmin('owner'), 'admin');

    $toner = setMemberProduct('Heartleaf Toner', 9000);
    $set = setFixtureSet([[$toner, 1]]);

    $this->deleteJson('/admin-api/sets/'.$set->id)->assertOk();

    expect(Product::find($set->id))->toBeNull()
        ->and(Product::find($toner->id))->not->toBeNull()
        ->and(ProductSetItem::where('set_product_id', $set->id)->count())->toBe(0);
});

/* ═════════════════════════════════════════════════════════════ the screen ══ */

/**
 * The screen is a JavaScript string builder inside a Blade partial that nothing
 * renders server-side, so these read its SOURCE — the way
 * AdminMediaPickerEverywhereTest, AdminWritesAreSignedTest and
 * UgcOneFrontDoorTest already read these files. Comments are stripped first,
 * because this file explains its decisions in prose and a scan of the raw text
 * would match the explanation and pass a screen that does not do the thing.
 */
function setScreenCode(): string
{
    $src = (string) file_get_contents(resource_path('views/admin/partials/sets-screen.blade.php'));
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    return (string) preg_replace('#^\s*//.*$#m', '', $src);
}

it('offers no upload path at all, because pictures are the product editor\'s', function () {
    /*
     * The owner's standing rule is "on any upload media on the whole backend,
     * the media library is a must to show", and
     * AdminMediaPickerEverywhereTest enforces it across the console. This
     * screen now goes one better: it has no image field of any kind, because
     * pictures belong to the product editor.
     *
     * MUTATION NOTE. Add an <input type="file" accept="image/*"> to this screen
     * and this is red. RUN.
     */
    $code = setScreenCode();

    expect(str_contains($code, 'type="file"'))->toBeFalse()
        ->and(str_contains($code, 'kbbPickMedia'))->toBeFalse(
            'There is nothing on this screen to pick a picture FOR any more.'
        );
});

it('escapes every operator string it prints', function () {
    /*
     * A set name, a category and a server error are all settings. CLAUDE.md
     * rule 5: anything printed unescaped is a constant. The screen builds its
     * markup with string concatenation, so an un-escaped interpolation is
     * stored XSS in the back office.
     *
     * MUTATION NOTE. Change `esc(s.name)` in list() to `s.name` and this is
     * red. RUN.
     */
    $code = setScreenCode();

    foreach (['s.name', 'state.error', 's.status'] as $value) {
        expect(str_contains($code, 'esc('.$value.')'))->toBeTrue(
            $value.' is operator-supplied and must be escaped.'
        );
    }
});

it('prefixes every class and data attribute it owns', function () {
    /*
     * app.blade.php binds delegated listeners to `document` itself, each
     * claiming a bare attribute name — so an unprefixed `data-` attribute here
     * is a listener somewhere else in the console firing on this screen's
     * buttons. The class rules are the same argument for styles: a bare `.k`
     * rule would restyle every other screen in the console.
     *
     * MUTATION NOTE. Rename one class to `.row` or one attribute to
     * `data-edit=` and this is red. RUN.
     */
    $src = (string) file_get_contents(resource_path('views/admin/partials/sets-screen.blade.php'));

    preg_match_all('/^\.([a-z][a-z0-9-]*)/mi', $src, $classes);

    $leaked = array_values(array_unique(array_filter(
        $classes[1] ?? [],
        fn (string $c) => ! str_starts_with($c, 'kst-') && ! str_starts_with($c, 'is-')
    )));

    expect($leaked)->toBe([], 'these class rules are not kst-prefixed and would restyle other screens');

    preg_match_all('/data-([a-z][a-z0-9-]*)/', $src, $attrs);

    /*
     * `data-sec` and `data-go` are the CONSOLE's own attributes, read here to
     * find the sidebar row and the nav group — not written by this screen.
     */
    $foreign = array_values(array_unique(array_filter(
        $attrs[1] ?? [],
        fn (string $a) => ! str_starts_with($a, 'kst-') && ! in_array($a, ['sec', 'go'], true)
    )));

    expect($foreign)->toBe([], 'these data attributes are not data-kst- prefixed');
});
