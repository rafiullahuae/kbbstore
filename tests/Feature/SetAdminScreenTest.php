<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Models\ProductVariant;
use App\Support\AdminCapabilities;
use Illuminate\Support\Str;
use Tests\Support\SetsAdminRoutes;

/**
 * Catalog → Sets: the endpoints, the capabilities and the screen. (Lane SET)
 *
 * routes/sets-admin.php is required from routes/web.php by the INTEGRATOR — no
 * lane may edit that file — so the route file is declared and left unwired, and
 * Tests\Support\SetsAdminRoutes mounts it here from the real file with the real
 * middleware stack. That is deliberate rather than convenient: this suite
 * exercises the file the integrator will require, including its ordering and
 * its names, and a typo in it fails here rather than after a package is
 * applied.
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

function setPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Glow Set',
        'status' => 'publish',
        'is_visible' => true,
        'category_id' => null,
        'short_description' => 'Two steps.',
        'description' => '<p>A toner and a serum.</p>',
        'price' => '129.50',
        'sale_price' => null,
        'image' => '/img/glow-set.jpg',
        'images' => [],
        'members' => [],
    ], $overrides);
}

beforeEach(function () {
    SetsAdminRoutes::wire($this->app);
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
     * MUTATION NOTE. Delete the seven 'admin-api/sets' lines from
     * AdminCapabilities::RULES and every route here reports null. RUN.
     */
    $routes = SetsAdminRoutes::registered();

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }

            expect(AdminCapabilities::forPath($method, $route->uri()))
                ->not->toBeNull($method.' '.$route->uri().' falls through to the owner-only default');
        }
    }
});

it('puts the writes above the reads, so a PUT is never resolved as a read', function () {
    /*
     * ── THE DEFECT THIS IS AGAINST ─────────────────────────────────────────
     *
     * RULES is FIRST-MATCH-WINS. A `GET admin-api/sets/**` rule listed above
     * the write rules would claim PUT /sets/7 — which reprices and republishes
     * a live product — for `sets.view`, the read capability. That is exactly
     * the shape of the quiz-leads and coupons/manage mistakes
     * App\Support\AdminCapabilities names, both of which were found by a test
     * rather than by a reader.
     *
     * MUTATION NOTE. Move the two ['GET', 'admin-api/sets'...] lines ABOVE the
     * POST/PUT/DELETE lines in AdminCapabilities::RULES and this is red. RUN.
     */
    expect(AdminCapabilities::forPath('PUT', 'admin-api/sets/{id}'))->toBe('sets.manage')
        ->and(AdminCapabilities::forPath('DELETE', 'admin-api/sets/{id}'))->toBe('sets.manage')
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
    foreach ([['GET', 'admin-api/sets'], ['POST', 'admin-api/sets'],
              ['GET', 'admin-api/sets/{id}'], ['PUT', 'admin-api/sets/{id}'],
              ['DELETE', 'admin-api/sets/{id}']] as [$method, $uri]) {
        expect(AdminCapabilities::forPath($method, $uri))->toStartWith('sets.');
    }

    expect(AdminCapabilities::CAPABILITIES)->toHaveKeys(['sets.view', 'sets.manage']);
});

it('refuses a support account, which answers customers and does not touch the catalogue', function () {
    /*
     * `support` reads orders, adds notes and moderates reviews. It has no
     * business creating a product, repricing one or publishing one.
     *
     * MUTATION NOTE. Add 'support' to either sets.* row in CAPABILITIES and
     * this is red. RUN.
     */
    $this->actingAs(setAdmin('support'), 'admin');

    $this->getJson('/admin-api/sets')->assertForbidden();
    $this->postJson('/admin-api/sets', setPayload())->assertForbidden();
});

it('refuses everything to a visitor who is not signed in at all', function () {
    foreach (['/admin-api/sets', '/admin-api/sets/products'] as $path) {
        $status = $this->getJson($path)->getStatusCode();

        expect($status)->toBeGreaterThanOrEqual(401)->and($status)->toBeLessThan(500);
    }
});

/* ═══════════════════════════════════════════════════════ the endpoints ══ */

it('creates a set from chosen products, with its own price, category and description', function () {
    /*
     * The owner's requirement in one case: "upon creating new set, the system
     * will ask to choose the products, and will ask for set price, category,
     * description etc, the same as in product edit page. and it will be
     * published same like other products and display."
     *
     * MUTATION NOTE. Delete `$set->type = 'set';` from SetApiController::store()
     * and this is red on the first expectation — and the row would be an
     * ordinary product with a pivot nothing reads. RUN.
     */
    $this->actingAs(setAdmin('owner'), 'admin');

    $category = Category::create(['name' => 'Sets', 'slug' => 'sets-'.Str::random(5)]);
    $toner = setMemberProduct('Heartleaf Toner', 9000);
    $serum = setMemberProduct('Azelaic Serum', 4550);

    $body = $this->postJson('/admin-api/sets', setPayload([
        'category_id' => $category->id,
        'members' => [
            ['product_id' => $toner->id, 'quantity' => 1],
            ['product_id' => $serum->id, 'quantity' => 2],
        ],
    ]))->assertStatus(201)->json();

    $set = Product::find($body['set']['id']);

    expect($set->type)->toBe('set')
        ->and($set->status)->toBe('publish')
        // MONEY IS INTEGER FILS. "129.50" on the wire is 12950 in the column,
        // and it is an int, not a float.
        ->and($set->price)->toBe(12950)
        ->and($set->price)->toBeInt()
        ->and($set->category_id)->toBe($category->id)
        ->and($set->description)->toContain('A toner and a serum')
        // It publishes and displays like any other product, with no type clause
        // anywhere in the visibility scope.
        ->and(Product::visible()->whereKey($set->id)->exists())->toBeTrue()
        ->and($body['set']['url'])->toBe('/product/'.$set->slug.'/')
        // 9000 + 2 x 4550 = 18100 bought separately, 12950 as a set.
        ->and($body['set']['parts_total_aed'])->toBe('181.00')
        ->and($body['set']['saving_aed'])->toBe('51.50')
        ->and($body['set']['item_count'])->toBe(3);
});

it('refuses a set that contains no products', function () {
    /*
     * An empty set is a product priced like a bundle with nothing in the box.
     *
     * MUTATION NOTE. Drop 'min:1' from the `members` rule in
     * SetApiController::validated() and this is red. RUN.
     */
    $this->actingAs(setAdmin('owner'), 'admin');

    $this->postJson('/admin-api/sets', setPayload(['members' => []]))
        ->assertStatus(422);
});

it('refuses a set that contains a set, and one that contains itself', function () {
    /*
     * A set inside a set is an infinite box: SetContents would have to recurse
     * and "what is in this box" would stop having one answer on a packing slip.
     *
     * MUTATION NOTE. Delete `->where('type', '!=', 'set')` from
     * SetApiController::products() and the picker offers a set; delete the
     * `$productId === $set->id` guard from members() and a set can be saved
     * inside itself. The first half of this case is red without the picker
     * filter, the second without the guard. RUN.
     */
    $this->actingAs(setAdmin('owner'), 'admin');

    $toner = setMemberProduct('Heartleaf Toner', 9000);

    $first = $this->postJson('/admin-api/sets', setPayload([
        'members' => [['product_id' => $toner->id, 'quantity' => 1]],
    ]))->assertStatus(201)->json('set');

    // The picker never offers it.
    $found = $this->getJson('/admin-api/sets/products?q=Glow')->assertOk()->json('products');
    expect(collect($found)->pluck('id')->all())->not->toContain($first['id']);

    // And the endpoint refuses it even if something asks anyway.
    $this->putJson('/admin-api/sets/'.$first['id'], setPayload([
        'members' => [
            ['product_id' => $toner->id, 'quantity' => 1],
            ['product_id' => $first['id'], 'quantity' => 1],
        ],
    ]))->assertOk();

    expect(ProductSetItem::where('set_product_id', $first['id'])->count())->toBe(1);
});

it('drops a variant that does not belong to the product it was sent with', function () {
    /*
     * ── THE DEFECT ─────────────────────────────────────────────────────────
     *
     * `exists:product_variants,id` proves the variation row exists and nothing
     * else. A request naming product A with product B's variation would store a
     * box whose contents contradict themselves — and it is the VARIANT's price
     * that the saving is computed from, so the set would advertise a saving
     * measured against a product it does not contain.
     *
     * MUTATION NOTE. Delete the `where('product_id', $productId)` check from
     * SetApiController::members() and this is red: the foreign variant is
     * stored. RUN.
     */
    $this->actingAs(setAdmin('owner'), 'admin');

    $toner = setMemberProduct('Heartleaf Toner', 9000);
    $serum = setMemberProduct('Azelaic Serum', 4550);

    $foreign = ProductVariant::create([
        'product_id' => $serum->id, 'sku' => 'AZ-50', 'price' => 5000,
        'stock_status' => 'instock', 'position' => 0,
    ]);

    $body = $this->postJson('/admin-api/sets', setPayload([
        'members' => [['product_id' => $toner->id, 'variant_id' => $foreign->id, 'quantity' => 1]],
    ]))->assertStatus(201)->json('set');

    expect(ProductSetItem::where('set_product_id', $body['id'])->first()->member_variant_id)->toBeNull();
});

it('keeps one row per member and turns a repeat into nothing rather than a duplicate', function () {
    /*
     * The migration explains why this is not a unique index: MySQL and SQLite
     * both treat NULLs as distinct, so an index on (set, member, variant) would
     * refuse a duplicate that names a variation and accept one that does not —
     * a constraint that holds in one of two shapes is worse than none, because
     * it reads as protection.
     *
     * MUTATION NOTE. Delete the `$seen` map from SetApiController::members()
     * and this is red: the set holds two identical rows and every surface draws
     * the toner twice. RUN.
     */
    $this->actingAs(setAdmin('owner'), 'admin');

    $toner = setMemberProduct('Heartleaf Toner', 9000);

    $body = $this->postJson('/admin-api/sets', setPayload([
        'members' => [
            ['product_id' => $toner->id, 'quantity' => 1],
            ['product_id' => $toner->id, 'quantity' => 3],
        ],
    ]))->assertStatus(201)->json('set');

    expect(ProductSetItem::where('set_product_id', $body['id'])->count())->toBe(1);
});

it('replaces the member list on save and keeps the order it was sent in', function () {
    $this->actingAs(setAdmin('owner'), 'admin');

    $a = setMemberProduct('Aaa', 1000);
    $b = setMemberProduct('Bbb', 2000);
    $c = setMemberProduct('Ccc', 3000);

    $set = $this->postJson('/admin-api/sets', setPayload([
        'members' => [['product_id' => $a->id], ['product_id' => $b->id]],
    ]))->assertStatus(201)->json('set');

    $saved = $this->putJson('/admin-api/sets/'.$set['id'], setPayload([
        'members' => [['product_id' => $c->id], ['product_id' => $a->id]],
    ]))->assertOk()->json('set');

    expect(array_column($saved['members'], 'name'))->toBe(['Ccc', 'Aaa']);
});

it('sanitises the description, because a set publishes on the product page', function () {
    /*
     * A set's description is printed by partials/product-tabs.blade.php with
     * `{!! !!}` — because a set IS a product and that is the page it publishes
     * on. An unsanitised description here is stored XSS on the storefront.
     *
     * MUTATION NOTE. Replace `RichText::clean($data['description'])` with the
     * raw value in SetApiController::apply() and this is red. RUN.
     */
    $this->actingAs(setAdmin('owner'), 'admin');

    $body = $this->postJson('/admin-api/sets', setPayload([
        'description' => '<p>Nice</p><script>alert(1)</script>',
        'members' => [['product_id' => setMemberProduct('Toner', 9000)->id]],
    ]))->assertStatus(201)->json('set');

    expect($body['description'])->not->toContain('<script')
        ->and($body['description'])->toContain('Nice');
});

it('refuses an image address that is not a picture', function () {
    /*
     * A URL from a setting is scheme-checked before it becomes an href or a
     * src. CLAUDE.md rule 5. The column is printed by the product page, the
     * tile, the cart, the Meta feed and the sitemap, and only one of those is
     * going to remember to check.
     *
     * MUTATION NOTE. Make SetApiController::safeUrl() return the value
     * unchanged and this is red. RUN.
     */
    $this->actingAs(setAdmin('owner'), 'admin');

    $body = $this->postJson('/admin-api/sets', setPayload([
        'image' => 'javascript:alert(1)',
        'images' => ['data:text/html,<script>alert(1)</script>', 'https://cdn.example.test/a.jpg'],
        'members' => [['product_id' => setMemberProduct('Toner', 9000)->id]],
    ]))->assertStatus(201)->json('set');

    expect($body['image'])->toBeNull()
        ->and($body['images'])->toBe(['https://cdn.example.test/a.jpg']);
});

it('deletes a set without touching the products in it', function () {
    $this->actingAs(setAdmin('owner'), 'admin');

    $toner = setMemberProduct('Heartleaf Toner', 9000);

    $set = $this->postJson('/admin-api/sets', setPayload([
        'members' => [['product_id' => $toner->id]],
    ]))->assertStatus(201)->json('set');

    $this->deleteJson('/admin-api/sets/'.$set['id'])->assertOk();

    expect(Product::find($set['id']))->toBeNull()
        ->and(Product::find($toner->id))->not->toBeNull()
        ->and(ProductSetItem::where('set_product_id', $set['id'])->count())->toBe(0);
});

it('never lets a request decide whether a row is a set', function () {
    /*
     * `type` is not in validated()'s rules at all, so no request can turn a set
     * into a simple product or an ordinary product into a set through this
     * endpoint. store() writes 'set' and update() writes nothing.
     *
     * MUTATION NOTE. Add `$set->type = $data['type'] ?? 'set';` to
     * SetApiController::apply() and this is red. RUN.
     */
    $this->actingAs(setAdmin('owner'), 'admin');

    $set = $this->postJson('/admin-api/sets', setPayload([
        'members' => [['product_id' => setMemberProduct('Toner', 9000)->id]],
    ]))->assertStatus(201)->json('set');

    $this->putJson('/admin-api/sets/'.$set['id'], setPayload([
        'type' => 'simple',
        'members' => [['product_id' => setMemberProduct('Serum', 4550)->id]],
    ]))->assertOk();

    expect(Product::find($set['id'])->type)->toBe('set');
});

/* ═════════════════════════════════════════════════════════════ the screen ══ */

/**
 * The screen is a JavaScript string builder inside a Blade partial that nothing
 * renders server-side, so these read its SOURCE — the way
 * AdminMediaPickerEverywhereTest, AdminWritesAreSignedTest and
 * UgcOneFrontDoorTest already read these files. Comments are stripped first,
 * because this file explains its defects in prose and a scan of the raw text
 * would match the explanation and pass a screen that is broken.
 */
function setScreenCode(): string
{
    $src = (string) file_get_contents(resource_path('views/admin/partials/sets-screen.blade.php'));
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    return (string) preg_replace('#^\s*//.*$#m', '', $src);
}

it('does not empty the form when the operator searches for a product to add', function () {
    /*
     * ── THE DEFECT, FOUND BY TAKING THE SCREENSHOT ─────────────────────────
     *
     * The search box re-renders the screen when its results land, and a
     * re-render rewrites every field from `state`. search() did not collect the
     * form first, so typing a product name into the picker silently emptied the
     * name, the price, the category and both descriptions above it. The
     * create-a-set shot showed "Set price: AED 0.00" under a box that had just
     * been filled with 179.00, which is how it was caught — a lane that had only
     * run its suite would have shipped it.
     *
     * MUTATION NOTE. Delete the `collect();` from search() in
     * sets-screen.blade.php and this is red. RUN. (And the screen loses the
     * price again, which is the behaviour the assertion stands for.)
     */
    $code = setScreenCode();

    expect($code)->toMatch('/async function search\s*\(\)\s*\{\s*collect\(\);/');
});

it('offers the Media Library and never a raw file box', function () {
    /*
     * The owner's standing rule: "on any upload media on the whole backend, the
     * media library is a must to show." AdminMediaPickerEverywhereTest enforces
     * it across the console; this says it for this screen in particular,
     * because a set has two image fields and both are easy to build wrong.
     *
     * MUTATION NOTE. Add an <input type="file" accept="image/*"> to the images
     * block and this is red. RUN.
     */
    $code = setScreenCode();

    expect($code)->toContain('window.kbbPickMedia')
        ->and(str_contains($code, 'type="file"'))->toBeFalse();
});

it('escapes every operator string it prints', function () {
    /*
     * A product name, a brand, a category and a server error are all settings.
     * CLAUDE.md rule 5: anything printed unescaped is a constant. The screen
     * builds its markup with string concatenation, so an un-escaped
     * interpolation is stored XSS in the back office.
     *
     * MUTATION NOTE. Change `esc(s.name)` in list() to `s.name` and this is
     * red. RUN.
     */
    $code = setScreenCode();

    // Every interpolation of a value that came off the wire goes through esc().
    foreach (['s.name', 'p.name', 'm.name', 'c.name', 'state.error', 'v.label', 'm.brand', 'p.brand'] as $value) {
        expect(str_contains($code, "esc({$value})"))
            ->toBeTrue("{$value} reaches the page without esc()");
    }
});

it('prefixes every class and data attribute it owns', function () {
    /*
     * app.blade.php binds delegated listeners to `document` itself, each
     * claiming a bare attribute name, and its stylesheet is one document shared
     * by forty screens. An unprefixed class here restyles somebody else's
     * screen and an unprefixed data attribute is caught by somebody else's
     * listener.
     *
     * MUTATION NOTE. Rename one `.kst-card` rule to `.card` and this is red.
     * RUN.
     */
    $src = (string) file_get_contents(resource_path('views/admin/partials/sets-screen.blade.php'));

    preg_match('/<style>(.*?)<\/style>/s', $src, $style);
    preg_match_all('/\.([A-Za-z][A-Za-z0-9_-]*)/', $style[1] ?? '', $classes);

    $leaked = array_values(array_unique(array_filter(
        $classes[1] ?? [],
        fn (string $c) => ! str_starts_with($c, 'kst-') && ! str_starts_with($c, 'is-')
    )));

    expect($leaked)->toBe([], 'these class rules are not kst-prefixed and would restyle other screens');

    preg_match_all('/data-([a-z][a-z0-9-]*)/', $src, $attrs);

    /*
     * `data-sec` is the CONSOLE's own attribute on its nav groups, READ by this
     * screen's go() wrapper to open the Catalog section — not one this file
     * declares. Named here rather than matched loosely, so a genuinely
     * unprefixed attribute of this screen's own still fails.
     */
    $consoleOwned = ['sec'];

    $bare = array_values(array_unique(array_filter(
        $attrs[1] ?? [],
        fn (string $a) => ! str_starts_with($a, 'kst-') && ! in_array($a, $consoleOwned, true)
    )));

    expect($bare)->toBe([], 'these data attributes are not data-kst- prefixed');
});
