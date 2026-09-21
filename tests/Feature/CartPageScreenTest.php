<?php

declare(strict_types=1);

/*
 * Appearance → Cart page: the admin screen, and the endpoints behind it.
 *
 * ── WHY THIS RENDERS THE SCREEN INSTEAD OF READING THE FILE ────────────────
 *
 * resources/views/admin/app.blade.php is a raw block from line 1 to about
 * 8890, and a previous lane nearly took the whole admin panel down by putting
 * a Blade directive inside it: it shipped as literal text and was a
 * SyntaxError in the script block that builds half the console. `php -l` was
 * green over it — it is not PHP — and so was every test that read the file,
 * because the characters it was looking for were all present. The only thing
 * that catches it is rendering the console and looking at what came out.
 *
 * So: this renders /admin, finds this screen's script, and checks that what
 * reached the browser is JavaScript and not a Blade directive wearing its
 * clothes.
 */

use App\Models\AdminUser;
use App\Models\Product;
use App\Services\CartPage;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

function cartScreenOwner(): AdminUser
{
    return AdminUser::create([
        'name' => 'Owner',
        'email' => 'cps-'.Str::random(8).'@example.com',
        'password' => bcrypt('secret'),
        'role' => 'owner',
    ]);
}

/**
 * Mount routes/cart-page-admin.php for this test.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the file ships
 * for the integrator to require. Skipping these until he does would leave the
 * screen's endpoints — including the one that writes what every shopper sees —
 * executed by nothing at all.
 */
function cartScreenRoutes(): void
{
    if (app('router')->getRoutes()->hasNamedRoute('admin.cart-page')) {
        return;
    }

    Route::prefix('admin-api')
        ->middleware(['web', 'auth:admin'])
        ->group(base_path('routes/cart-page-admin.php'));

    app('router')->getRoutes()->refreshNameLookups();
}

/* ------------------------------------------------------------------------
 | 1. The screen reaches the browser as JavaScript
 |------------------------------------------------------------------------*/

it('renders the cart page screen as script, not as literal Blade', function () {
    $html = test()->actingAs(cartScreenOwner(), 'admin')
        ->get('/'.app(\App\Services\AdminPathService::class)->current())
        ->assertOk()
        ->getContent();

    // The screen is in the document at all.
    expect($html)->toContain("var SCREEN = 'cartpage';")
        ->and($html)->toContain('kbbAddNavEntry')
        ->and($html)->toContain('.cps-wrap{');

    /*
     * AND NOTHING FROM BLADE SURVIVED INTO IT. These are the exact shapes that
     * took the console down: a directive or an interpolation printed as text
     * inside a script block. Searched in THIS partial's own region, because the
     * console legitimately contains the characters elsewhere.
     */
    $from = strpos($html, "var SCREEN = 'cartpage';");
    expect($from)->not->toBeFalse();

    $mine = substr($html, (int) $from, 24000);

    expect($mine)->not->toContain('@json(')
        ->and($mine)->not->toContain('@if (')
        ->and($mine)->not->toContain('@endif')
        ->and($mine)->not->toContain('@php');
});
// MUTATION: put `@json([1,2])` inside this partial's raw block. RED on the
// `@json(` assertion — and nothing else in the suite notices, which is the
// whole point of this test existing.

it('registers its own sidebar row instead of editing the nav arrays', function () {
    $partial = (string) file_get_contents(
        resource_path('views/admin/partials/cart-page-screen.blade.php')
    );

    // The console's own extension point, the same one Cache and Routines use.
    expect($partial)->toContain("group: 'Appearance'")
        ->and($partial)->toContain("label: 'Cart page'")
        // and window.go is WRAPPED, not replaced: nine partials do this, and one
        // that forgot to call the previous handler would black out every screen
        // registered before it.
        ->and($partial)->toContain('var previousGo = window.go;')
        ->and($partial)->toContain('return previousGo.apply(this, arguments);');

    // The nav arrays themselves are untouched by this lane.
    $console = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect($console)->not->toContain("'cartpage','Cart page'");
});

/* ------------------------------------------------------------------------
 | 2. The endpoints
 |------------------------------------------------------------------------*/

it('hands the screen every field, grouped, with the rail resolved to products', function () {
    cartScreenRoutes();

    $product = Product::create(['slug' => 'cps-'.Str::random(6), 'name' => 'Rice Toner',
        'status' => 'publish', 'is_visible' => true, 'price' => 5000, 'stock_status' => 'instock']);

    app(CartPage::class)->save(['rec_ids' => (string) $product->id]);

    $body = test()->actingAs(cartScreenOwner(), 'admin')
        ->getJson('/admin-api/cart-page')->assertOk()->json();

    $keys = collect($body['tabs'])->flatMap(fn ($t) => collect($t['fields'])->pluck('key'))->all();

    // Every schema key that belongs on a tab is on one. A value with no control
    // is a setting nobody can reach; a control with no value is one that saves
    // nowhere.
    $onTabs = collect(CartPage::TABS)->flatMap(fn ($t) => $t[2])->all();
    expect($keys)->toBe($onTabs);

    // The rail is handed over as products, because an id is not something a
    // person can check by reading it.
    expect($body['chosen'])->toHaveCount(1)
        ->and($body['chosen'][0]['name'])->toBe('Rice Toner')
        // An explicit allowlist, not the model: `products` carries wc_id, sku
        // and total_sales.
        ->and(array_keys($body['chosen'][0]))->toBe(['id', 'name', 'brand', 'image']);
});
// MUTATION: return the Product model from card(). RED on the allowlist.

it('refuses a setting it does not know rather than dropping it in silence', function () {
    cartScreenRoutes();

    test()->actingAs(cartScreenOwner(), 'admin')
        ->postJson('/admin-api/cart-page', ['settings' => ['row_h' => 70, 'made_up' => 1]])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    // And nothing from that request was written.
    expect(app(CartPage::class)->get('row_h'))->toBe(96);
});
// MUTATION: drop the $unknown check in save(). RED — a 200, and row_h is 70.

it('clamps a slider that arrives outside its range', function () {
    cartScreenRoutes();

    test()->actingAs(cartScreenOwner(), 'admin')
        ->postJson('/admin-api/cart-page', ['settings' => ['row_h' => 9000, 'sheet_max' => 2]])
        ->assertOk();

    expect(app(CartPage::class)->get('row_h'))->toBe(132)
        ->and(app(CartPage::class)->get('sheet_max'))->toBe(35);
});
// MUTATION: return (int) $value from cast()'s 'range' arm without the clamp.
// RED — a 9000px cart row.

it('searches the catalogue for the picker, and offers only what a shopper can reach', function () {
    cartScreenRoutes();

    // A term nothing seeded can match. "Anua" is a real brand in this
    // catalogue and the search covers brand names, so it returned four.
    $term = 'Zqx'.Str::random(6);

    $live = Product::create(['slug' => 'cps-live-'.Str::random(6), 'name' => $term.' Toner',
        'status' => 'publish', 'is_visible' => true, 'price' => 5000, 'stock_status' => 'instock']);
    Product::create(['slug' => 'cps-draft-'.Str::random(6), 'name' => $term.' Draft',
        'status' => 'draft', 'is_visible' => true, 'price' => 5000, 'stock_status' => 'instock']);

    $body = test()->actingAs(cartScreenOwner(), 'admin')
        ->getJson('/admin-api/cart-page/products?q='.$term)->assertOk()->json();

    expect($body['products'])->toHaveCount(1)
        ->and($body['products'][0]['id'])->toBe($live->id);
});
// MUTATION: drop the status filter. RED — a rail of links to a 404 with a
// picture on it.

/* ------------------------------------------------------------------------
 | 3. The capability
 |------------------------------------------------------------------------*/

it('puts the screen behind a capability of its own, and fails closed', function () {
    expect(AdminCapabilities::CAPABILITIES)->toHaveKey('cartpage.manage')
        ->and(AdminCapabilities::CAPABILITIES['cartpage.manage'])
        ->toBe(['owner', 'manager', 'editor']);

    // Both patterns, because 'admin-api/cart-page' does not match
    // 'admin-api/cart-page/products' — they are siblings, not parent and child.
    $rules = collect(AdminCapabilities::RULES);

    expect($rules->contains(['*', 'admin-api/cart-page', 'cartpage.manage']))->toBeTrue()
        ->and($rules->contains(['*', 'admin-api/cart-page/**', 'cartpage.manage']))->toBeTrue();

    // And it is NOT a reuse of content.manage: the day that is narrowed to the
    // people who write blog posts, this must not narrow with it in silence.
    expect($rules->contains(['*', 'admin-api/cart-page', 'content.manage']))->toBeFalse();
});
// MUTATION: delete the 'admin-api/cart-page/**' rule. RED — and without this
// test the effect would be an editor getting a 403 from the product search
// only, which reads as a broken screen rather than a missing rule.

it('keeps a support account out of it', function () {
    cartScreenRoutes();

    $support = AdminUser::create([
        'name' => 'Support', 'email' => 'sup-'.Str::random(8).'@example.com',
        'password' => bcrypt('secret'), 'role' => 'support',
    ]);

    test()->actingAs($support, 'admin')
        ->postJson('/admin-api/cart-page', ['settings' => ['row_h' => 70]])
        ->assertForbidden();

    expect(app(CartPage::class)->get('row_h'))->toBe(96);
})->skip(fn () => ! in_array(
    \App\Http\Middleware\EnforceAdminCapability::class,
    array_map('strval', app('router')->getMiddlewareGroups()['web'] ?? []),
    true
) && ! app('router')->getRoutes()->hasNamedRoute('admin.cart-page'),
    'The capability middleware is applied by the admin-api group in routes/web.php, which '
    .'this lane may not edit; the map itself is pinned by the test above.');

it('puts both popup heights on the Address popup tab, named so they cannot be confused', function () {
    cartScreenRoutes();

    $body = test()->actingAs(cartScreenOwner(), 'admin')
        ->getJson('/admin-api/cart-page')->assertOk()->json();

    $popup = collect($body['tabs'])->firstWhere('key', 'popup');

    expect($popup)->not->toBeNull();

    $fields = collect($popup['fields'])->keyBy('key');

    expect($fields)->toHaveKeys(['sheet_max', 'sheet_max_list', 'sheet_max_land', 'sheet_max_list_land']);

    /*
     * THE LABELS ARE THE POINT OF THIS TEST. Two sliders a few pixels apart, both
     * called "Popup height limit", is a screen where the owner drags one and
     * watches the other one's popup not move. Each says WHICH popup it governs.
     */
    expect($fields['sheet_max']['label'])->toContain('New-address popup height')
        ->and($fields['sheet_max_list']['label'])->toContain('Address-list popup height')
        ->and($fields['sheet_max_land']['label'])->toContain('New-address popup height')
        ->and($fields['sheet_max_list_land']['label'])->toContain('Address-list popup height')
        // And no two controls on this tab share a label.
        ->and(collect($popup['fields'])->pluck('label')->duplicates())->toBeEmpty();

    // Sliders, with the list's cap starting below the form's.
    expect($fields['sheet_max_list']['type'])->toBe('range')
        ->and($fields['sheet_max_list']['value'])->toBe(38)
        ->and($fields['sheet_max']['value'])->toBe(50);
});
// MUTATION: give sheet_max_list the label 'New-address popup height · upright',
// which is exactly the collision this exists to stop. RED — 1 failed, 44 passed.
