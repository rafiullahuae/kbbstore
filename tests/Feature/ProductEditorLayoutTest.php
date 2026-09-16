<?php

declare(strict_types=1);

use App\Models\AdminScreenLayout;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\EditorLayoutRoutes;

/**
 * The product editor's panel arrangement: storage, ownership and survival
 * across builds. (Lane AS)
 *
 * WHAT THESE ASSERT AND WHAT THEY LEAVE TO THE BROWSER.
 *
 * Two of the three things that can go wrong here are not visible from PHP at
 * all. Whether a drag actually moves a panel, and whether the arrangement is
 * still there after a reload, are questions about a rendered document — a test
 * that checks the preference row was written proves only that a row was
 * written, and a feature that writes a perfect row and paints the panels in
 * the old order is exactly as broken as one that writes nothing. Those live in
 * the browser check, which performs a real rearrangement with the pointer and
 * with the keyboard, reloads, and reads the order back off the DOM.
 *
 * What PHP can decide, and what is below, is everything about who may write
 * what: that the endpoints refuse anyone who is not a signed-in admin, that
 * one admin cannot reach another's row by any route including naming them in
 * the body, and that the document stored is a bounded list of names rather
 * than whatever was posted.
 *
 * Plus one structural assertion the browser cannot make cheaply: that the
 * panel registry in the Blade file is internally consistent — every key
 * unique, every key a valid stored value, every panel in a real column. That
 * registry is the single list the whole feature reads from, and a duplicate
 * key in it would render one panel twice and silently drop another.
 */

/* ------------------------------------------------------------------ fixtures */

beforeEach(function () {
    EditorLayoutRoutes::wire(app());
});

function layoutAdmin(string $tag = 'a'): AdminUser
{
    return AdminUser::create([
        'name' => 'Layout '.$tag,
        'email' => 'layout-'.$tag.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** The screen this lane owns, and a small valid arrangement for it. */
const LAYOUT_SCREEN = 'product-editor';

function someLayout(): array
{
    return [
        'main' => ['gallery', 'basics', 'description'],
        'side' => ['stock', 'publish'],
    ];
}

/* ---------------------------------------------------------------- the guard */

it('refuses every layout route to anyone who is not a signed-in admin', function () {
    /*
     * A layout looks like the least sensitive thing in the console, which is
     * exactly why it is worth pinning: these are WRITE endpoints reachable by
     * every operator, and the owner is taken from the guard. With no guard
     * there is no owner to take, and the table becomes an anonymous write
     * primitive — one row per admin per invented screen name — and a way to
     * enumerate how many admin accounts exist.
     */
    $calls = [
        ['get', '/admin-api/editor-layout?screen='.LAYOUT_SCREEN],
        ['post', '/admin-api/editor-layout'],
        ['post', '/admin-api/editor-layout-reset'],
    ];

    // 1. Anonymous.
    foreach ($calls as [$verb, $path]) {
        $method = $verb === 'get' ? 'getJson' : 'postJson';

        expect(test()->{$method}($path, [])->status())
            ->toBe(401, $verb.' '.$path.' answered an anonymous caller');
    }

    // 2. A signed-in STOREFRONT CUSTOMER is not an admin.
    $customer = Customer::create([
        'email' => 'layout-shopper-'.uniqid().'@example.test',
        'password' => bcrypt('secret-secret'),
        'first_name' => 'Layout',
        'last_name' => 'Shopper',
    ]);

    test()->actingAs($customer, 'customer');

    foreach ($calls as [$verb, $path]) {
        $method = $verb === 'get' ? 'getJson' : 'postJson';

        expect(test()->{$method}($path, [])->status())
            ->toBe(401, $verb.' '.$path.' answered a storefront customer');
    }

    // 3. A plain `web` user is not an admin either.
    if (class_exists(User::class)) {
        $user = User::create([
            'name' => 'Layout Web',
            'email' => 'layout-web-'.uniqid().'@example.test',
            'password' => bcrypt('secret-secret'),
        ]);

        test()->actingAs($user, 'web');

        foreach ($calls as [$verb, $path]) {
            $method = $verb === 'get' ? 'getJson' : 'postJson';

            expect(test()->{$method}($path, [])->status())
                ->toBe(401, $verb.' '.$path.' answered a plain web user');
        }
    }
});

it('carries the whole admin-api middleware stack on every registered layout route', function () {
    /*
     * Read back off the REGISTERED routes, not off the harness's intent.
     * RouteRegistrar::middleware() REPLACES rather than appends, so a harness
     * that chains it twice registers routes with only the last stack while
     * reading as though it applied both.
     */
    $routes = EditorLayoutRoutes::registered();

    expect($routes)->toHaveCount(3);

    foreach ($routes as $route) {
        // toContain() is VARIADIC in Pest — every argument is another needle,
        // not a failure message.
        expect($route->gatherMiddleware())
            ->toContain('web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class);
    }
});

/* ------------------------------------------------------------- the round trip */

it('stores an arrangement and hands the same one back', function () {
    test()->actingAs(layoutAdmin(), 'admin');

    test()->postJson('/admin-api/editor-layout', [
        'screen' => LAYOUT_SCREEN,
        'layout' => someLayout(),
    ])->assertOk()->assertJson(['ok' => true, 'layout' => someLayout()]);

    test()->getJson('/admin-api/editor-layout?screen='.LAYOUT_SCREEN)
        ->assertOk()
        ->assertJson(['ok' => true, 'layout' => someLayout()]);
});

it('reports no arrangement at all for an operator who has never saved one', function () {
    test()->actingAs(layoutAdmin(), 'admin');

    // null, NOT a default document. The default is whatever the build ships,
    // and a stored copy of it would be the thing that goes stale when a panel
    // is added — the exact failure this feature has to survive.
    $body = test()->getJson('/admin-api/editor-layout?screen='.LAYOUT_SCREEN)
        ->assertOk()
        ->json();

    expect($body['layout'])->toBeNull();
});

it('updates the same row rather than growing the table', function () {
    $admin = layoutAdmin();
    test()->actingAs($admin, 'admin');

    foreach ([['a'], ['b'], ['c']] as $keys) {
        test()->postJson('/admin-api/editor-layout', [
            'screen' => LAYOUT_SCREEN,
            'layout' => ['main' => ['basics'], 'side' => ['stock']],
        ])->assertOk();
    }

    expect(AdminScreenLayout::where('admin_user_id', $admin->id)->count())->toBe(1);
});

/* ------------------------------------------------------------- the ownership */

it('never lets one admin write another admin\'s arrangement', function () {
    $victim = layoutAdmin('victim');
    $attacker = layoutAdmin('attacker');

    // The victim arranges their editor.
    test()->actingAs($victim, 'admin');
    test()->postJson('/admin-api/editor-layout', [
        'screen' => LAYOUT_SCREEN,
        'layout' => ['main' => ['basics'], 'side' => ['stock']],
    ])->assertOk();

    $before = AdminScreenLayout::where('admin_user_id', $victim->id)->firstOrFail()->layout;

    // The attacker names them explicitly, three different ways.
    test()->actingAs($attacker, 'admin');

    foreach ([
        ['admin_user_id' => $victim->id],
        ['adminUserId' => $victim->id],
        ['id' => AdminScreenLayout::where('admin_user_id', $victim->id)->firstOrFail()->id],
    ] as $extra) {
        test()->postJson('/admin-api/editor-layout', array_merge([
            'screen' => LAYOUT_SCREEN,
            'layout' => ['main' => ['gallery'], 'side' => ['brand']],
        ], $extra))->assertOk();
    }

    // The victim's row is byte-for-byte what they left.
    expect(AdminScreenLayout::where('admin_user_id', $victim->id)->firstOrFail()->layout)
        ->toBe($before);

    // And the attacker wrote their OWN row, so the writes did land somewhere.
    expect(AdminScreenLayout::where('admin_user_id', $attacker->id)->firstOrFail()->layout)
        ->toBe(['main' => ['gallery'], 'side' => ['brand']]);

    // Two operators, two rows. Never one shared one.
    expect(AdminScreenLayout::count())->toBe(2);
});

it('never lets one admin read another admin\'s arrangement', function () {
    $victim = layoutAdmin('victim');
    $other = layoutAdmin('other');

    test()->actingAs($victim, 'admin');
    test()->postJson('/admin-api/editor-layout', [
        'screen' => LAYOUT_SCREEN,
        'layout' => someLayout(),
    ])->assertOk();

    test()->actingAs($other, 'admin');

    $body = test()->getJson('/admin-api/editor-layout?screen='.LAYOUT_SCREEN)
        ->assertOk()
        ->json();

    expect($body['layout'])->toBeNull();
});

it('never lets one admin reset another admin\'s arrangement', function () {
    $victim = layoutAdmin('victim');
    $attacker = layoutAdmin('attacker');

    test()->actingAs($victim, 'admin');
    test()->postJson('/admin-api/editor-layout', [
        'screen' => LAYOUT_SCREEN,
        'layout' => someLayout(),
    ])->assertOk();

    test()->actingAs($attacker, 'admin');
    test()->postJson('/admin-api/editor-layout-reset', [
        'screen' => LAYOUT_SCREEN,
        'admin_user_id' => $victim->id,
    ])->assertOk();

    expect(AdminScreenLayout::where('admin_user_id', $victim->id)->firstOrFail()->layout)
        ->toBe(someLayout());
});

it('forgets only the caller\'s own arrangement on reset', function () {
    $admin = layoutAdmin();
    test()->actingAs($admin, 'admin');

    test()->postJson('/admin-api/editor-layout', [
        'screen' => LAYOUT_SCREEN,
        'layout' => someLayout(),
    ])->assertOk();

    test()->postJson('/admin-api/editor-layout-reset', ['screen' => LAYOUT_SCREEN])
        ->assertOk()
        ->assertJson(['ok' => true, 'layout' => null]);

    // The ROW IS GONE, not overwritten with a stored default. Reset has to
    // mean "the arrangement this build ships", and the only way that stays
    // true after a build adds a panel is for there to be nothing stored.
    expect(AdminScreenLayout::where('admin_user_id', $admin->id)->exists())->toBeFalse();

    test()->getJson('/admin-api/editor-layout?screen='.LAYOUT_SCREEN)
        ->assertOk()
        ->assertJson(['layout' => null]);
});

/* ------------------------------------------------------------- the validation */

it('refuses a payload that is not a bounded list of panel names', function () {
    test()->actingAs(layoutAdmin(), 'admin');

    $bad = [
        'no layout at all' => ['screen' => LAYOUT_SCREEN],
        'layout is a string' => ['screen' => LAYOUT_SCREEN, 'layout' => 'main'],
        'column is a string' => ['screen' => LAYOUT_SCREEN, 'layout' => ['main' => 'basics']],
        'column this screen has not' => ['screen' => LAYOUT_SCREEN, 'layout' => ['footer' => ['basics']]],
        'key is a number' => ['screen' => LAYOUT_SCREEN, 'layout' => ['main' => [17]]],
        'key is nested' => ['screen' => LAYOUT_SCREEN, 'layout' => ['main' => [['basics']]]],
        'key carries punctuation' => ['screen' => LAYOUT_SCREEN, 'layout' => ['main' => ['bas<ics']]],
        'key carries a path' => ['screen' => LAYOUT_SCREEN, 'layout' => ['main' => ['../../etc/passwd']]],
        'key is too long' => ['screen' => LAYOUT_SCREEN, 'layout' => ['main' => [str_repeat('a', 41)]]],
        'column is used as storage' => ['screen' => LAYOUT_SCREEN, 'layout' => ['main' => array_fill(0, 65, 'basics')]],
        'no screen' => ['layout' => someLayout()],
        'screen not on the allowlist' => ['screen' => 'orders', 'layout' => ['main' => ['basics']]],
        'screen is an array' => ['screen' => ['product-editor'], 'layout' => ['main' => ['basics']]],
    ];

    foreach ($bad as $why => $payload) {
        expect(test()->postJson('/admin-api/editor-layout', $payload)->status())
            ->toBe(422, 'the endpoint accepted a payload where '.$why);
    }

    // Nothing got through.
    expect(AdminScreenLayout::count())->toBe(0);
});

it('refuses an unknown screen on read and on reset too', function () {
    test()->actingAs(layoutAdmin(), 'admin');

    test()->getJson('/admin-api/editor-layout?screen=orders')->assertStatus(422);
    test()->getJson('/admin-api/editor-layout')->assertStatus(422);
    test()->postJson('/admin-api/editor-layout-reset', ['screen' => 'orders'])->assertStatus(422);

    /*
     * Without the allowlist this table is an anonymous key/value store that
     * any signed-in operator can write unbounded rows into, one per screen
     * name they invent. The allowlist is the bound.
     */
    expect(AdminScreenLayout::count())->toBe(0);
});

it('collapses a panel named in both columns down to one', function () {
    test()->actingAs(layoutAdmin(), 'admin');

    // Rendering one panel twice would duplicate its inputs, and collect() in
    // the screen reads document.querySelectorAll — so the second copy would
    // win and the operator's typing in the first would vanish on save.
    $body = test()->postJson('/admin-api/editor-layout', [
        'screen' => LAYOUT_SCREEN,
        'layout' => [
            'main' => ['basics', 'stock', 'basics'],
            'side' => ['stock', 'brand'],
        ],
    ])->assertOk()->json();

    expect($body['layout'])->toBe([
        'main' => ['basics', 'stock'],
        'side' => ['brand'],
    ]);
});

it('normalises a partial document to carry every column of the screen', function () {
    test()->actingAs(layoutAdmin(), 'admin');

    // A client that sent only the column it changed must not be able to leave
    // the other one ABSENT rather than EMPTY — the difference is whether the
    // reader has to guess.
    $body = test()->postJson('/admin-api/editor-layout', [
        'screen' => LAYOUT_SCREEN,
        'layout' => ['main' => ['basics']],
    ])->assertOk()->json();

    expect($body['layout'])->toBe(['main' => ['basics'], 'side' => []]);
});

/* ------------------------------------------ a name this build does not have */

it('stores a panel name this build does not have rather than refusing it', function () {
    test()->actingAs(layoutAdmin(), 'admin');

    /*
     * THE SERVER DOES NOT HOLD THE PANEL LIST, ON PURPOSE.
     *
     * That list lives in the Blade screen and is edited by whoever adds or
     * removes a panel; a second copy here would be a second list to keep in
     * step, which is precisely how a saved layout comes to disagree with the
     * build. So an unrecognised-but-well-formed name is STORED, not refused —
     * and the client drops it when it reconciles what it read against the
     * panels it actually has.
     *
     * Refusing here would be worse than useless: an operator running a build
     * mid-rollout would get a 422 on every reorder, and the arrangement they
     * already had would be unreadable rather than merely partly stale.
     */
    $body = test()->postJson('/admin-api/editor-layout', [
        'screen' => LAYOUT_SCREEN,
        'layout' => ['main' => ['basics', 'a_panel_from_a_later_build'], 'side' => []],
    ])->assertOk()->json();

    expect($body['layout']['main'])->toBe(['basics', 'a_panel_from_a_later_build']);
});

/* ------------------------------------------------------- the panel registry */

it('keeps the screen\'s panel registry internally consistent', function () {
    /*
     * The registry in the Blade file is THE single list the whole feature
     * reads from: the default arrangement, the arrange controls, the
     * reconciliation of an older saved layout and the reset all derive from
     * it. A duplicate key in it renders one panel twice and silently drops
     * another; a key with punctuation in it is stored in a row and then
     * refused by this endpoint's own validator on the next save, so the
     * operator's arrangement would stop persisting with no error they can see.
     */
    $blade = file_get_contents(
        base_path('resources/views/admin/partials/product-editor-screen.blade.php')
    );

    expect($blade)->toContain('var PANELS = [');

    preg_match('/var PANELS = \[(.*?)\n  \];/s', $blade, $m);
    expect($m)->not->toBeEmpty('could not find the PANELS registry in the screen');

    preg_match_all("/\{\s*key:\s*'([^']+)'.*?col:\s*'([^']+)'/s", $m[1], $panels, PREG_SET_ORDER);

    $keys = array_column($panels, 1);
    $cols = array_column($panels, 2);

    // Every panel the two-column editor actually has.
    expect($keys)->toHaveCount(12);
    expect(array_unique($keys))->toHaveCount(count($keys), 'a panel key is listed twice');

    /*
     * The main image and the gallery are ONE panel, and the count above is not
     * what enforces that -- a count is satisfied by any twelve keys. These are.
     *
     * Splitting them back into two draggable panels is the specific regression
     * worth naming: the owner asked for the two beside each other, and as
     * separate panels an arrangement could put them back in a stack, or in
     * different columns, at which point the request holds only until somebody
     * drags something. Merging them is what makes "side by side" a property of
     * the screen rather than of one operator's saved preference.
     */
    expect($keys)->toContain('images');
    expect($keys)->not->toContain('main_image');
    expect($keys)->not->toContain('gallery');

    foreach ($keys as $key) {
        // The same rule the endpoint validates against, so a key that renders
        // can always also be stored.
        expect($key)->toMatch('/^[a-z0-9_]{1,40}$/');
    }

    foreach ($cols as $col) {
        expect($col)->toBeIn(\App\Http\Controllers\Admin\AdminScreenLayoutApiController::SCREENS[LAYOUT_SCREEN]);
    }

    // Both columns are actually used by the default arrangement — a registry
    // that put everything in one column would render an editor with an empty
    // sidebar and nothing would fail.
    expect(array_unique($cols))->toHaveCount(2);
});

/* ------------------------------------------------------------------- schema */

it('keys the arrangement to one operator and one screen', function () {
    $admin = layoutAdmin();

    AdminScreenLayout::insert([
        'admin_user_id' => $admin->id,
        'screen' => LAYOUT_SCREEN,
        'layout' => json_encode(someLayout()),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The unique index is what makes a write an upsert rather than an append,
    // and what stops a retried request growing the table. Asserted against the
    // DATABASE, so it holds on MySQL as well as on SQLite.
    expect(fn () => AdminScreenLayout::insert([
        'admin_user_id' => $admin->id,
        'screen' => LAYOUT_SCREEN,
        'layout' => json_encode(['main' => [], 'side' => []]),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('drops an operator\'s arrangement with the operator', function () {
    $admin = layoutAdmin();

    test()->actingAs($admin, 'admin');
    test()->postJson('/admin-api/editor-layout', [
        'screen' => LAYOUT_SCREEN,
        'layout' => someLayout(),
    ])->assertOk();

    expect(AdminScreenLayout::count())->toBe(1);

    /*
     * Cascade rather than orphan. admin_users.id is an auto-increment, so an
     * orphaned row is a row that a LATER admin account can be handed: they
     * sign in for the first time and find somebody else's screen arrangement
     * already applied.
     */
    DB::table('admin_users')->where('id', $admin->id)->delete();

    expect(AdminScreenLayout::count())->toBe(0);
});
