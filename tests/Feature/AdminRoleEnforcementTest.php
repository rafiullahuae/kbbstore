<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceAdminCapability;
use App\Models\AdminUser;
use App\Services\AdminPathService;
use App\Support\AdminCapabilities;

/**
 * What `admin_users.role` buys you. It used to be nothing.
 *
 * ---------------------------------------------------------------------------
 * THIS FILE WAS REWRITTEN, AND THAT WAS THE POINT OF IT
 * ---------------------------------------------------------------------------
 *
 * The previous version of this file was a tripwire, not a wish. It asserted
 * that a `support` account COULD promote itself to owner, COULD delete other
 * admins, COULD reset an owner's password, COULD open the payment gateway
 * credentials and COULD download the entire customer list — every one of those
 * written with ->assertOk(), deliberately the wrong way round, documenting the
 * behaviour rather than asking for it. Its own doc comment said so:
 *
 *     "the day a real authorization layer lands, every one of them goes red,
 *      and the person who built it gets a list of exactly the reaches that
 *      used to be open"
 *
 * That day is this package. Every reach the old file listed is now asserted
 * shut, in the same order, one test per reach, so the diff of this file reads
 * as the before-and-after of the hole. Nothing was deleted: the last test,
 * which pinned the ABSENCE of any authorization layer, has been turned into its
 * mirror image and now pins the presence of one.
 *
 * The layer itself is App\Support\AdminCapabilities (the map) and
 * App\Http\Middleware\EnforceAdminCapability (the enforcement). The map's own
 * shape, its ordering and its closed default are covered next door in
 * AdminCapabilityMapTest; this file is the behaviour over HTTP.
 *
 * Ranked by what the reach cost the owner, worst first — the old file's order.
 */
function areUser(string $role, string $name = 'ARE'): AdminUser
{
    return AdminUser::create([
        'name' => $name.' '.$role,
        'email' => 'are-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

/* ------------------------------------------------- 1. privilege escalation */

it('refuses to let the lowest role promote itself to owner', function () {
    $support = areUser('support');

    // There has to be a second owner, or the last-owner guard is what refuses
    // and the test would pass for the wrong reason — it would go green even
    // with this whole package reverted.
    areUser('owner');

    $response = test()->actingAs($support, 'admin')
        ->put('/admin-api/users/'.$support->id, ['role' => 'owner']);

    $response->assertForbidden();

    expect($support->fresh()->role)->toBe('support');
})->group('role-enforcement');

it('refuses to let the lowest role delete another admin account', function () {
    $support = areUser('support');
    $victim = areUser('manager');

    areUser('owner');

    test()->actingAs($support, 'admin')
        ->delete('/admin-api/users/'.$victim->id)
        ->assertForbidden();

    expect(AdminUser::find($victim->id))->not->toBeNull();
})->group('role-enforcement');

it('refuses to let the lowest role reset another admin password or create accounts', function () {
    $support = areUser('support');
    $victim = areUser('owner');
    $wasHashed = $victim->fresh()->password;

    test()->actingAs($support, 'admin')
        ->put('/admin-api/users/'.$victim->id, ['password' => 'a-new-password'])
        ->assertForbidden();

    expect($victim->fresh()->password)->toBe($wasHashed);

    $before = AdminUser::count();

    test()->actingAs($support, 'admin')
        ->post('/admin-api/users', [
            'name' => 'Back Door',
            'email' => 'are-backdoor-'.uniqid().'@example.test',
            'password' => 'secret-secret',
            'role' => 'owner',
        ])
        ->assertForbidden();

    expect(AdminUser::count())->toBe($before);
})->group('role-enforcement');

it('refuses every non-owner role the users screen, not just support', function (string $role) {
    $actor = areUser($role);
    areUser('owner');

    test()->actingAs($actor, 'admin')->get('/admin-api/users')->assertForbidden();
    test()->actingAs($actor, 'admin')
        ->put('/admin-api/users/'.$actor->id, ['role' => 'owner'])
        ->assertForbidden();

    expect($actor->fresh()->role)->toBe($role);
})->with(['manager', 'support', 'editor'])->group('role-enforcement');

/* ------------------------------------------------------- 2. money and data */

it('refuses the lowest role the payment provider settings', function () {
    $support = areUser('support');

    test()->actingAs($support, 'admin')->get('/admin-api/payments')->assertForbidden();
    test()->actingAs($support, 'admin')->post('/admin-api/payments', [])->assertForbidden();
})->group('role-enforcement');

it('refuses even a manager the payment provider settings', function () {
    // Gateway credentials are the owner's alone. A manager runs the shop; the
    // encrypted Stripe/Tabby/Tamara secrets are not part of running the shop.
    test()->actingAs(areUser('manager'), 'admin')->get('/admin-api/payments')->assertForbidden();
})->group('role-enforcement');

it('refuses the lowest role the customer export', function () {
    $support = areUser('support');

    test()->actingAs($support, 'admin')->get('/admin-api/customers/export')->assertForbidden();
})->group('role-enforcement');

it('still lets support read one customer while refusing it the whole list', function () {
    // The split that makes customers.export a capability of its own: answering
    // a ticket needs one record, and never needs all of them at once.
    $support = areUser('support');

    expect(test()->actingAs($support, 'admin')->get('/admin-api/customers/list')->getStatusCode())
        ->not->toBe(403);

    test()->actingAs($support, 'admin')->get('/admin-api/customers/export')->assertForbidden();
})->group('role-enforcement');

/* ------------------------------------------- 3. the owner is never shut out */

it('lets the owner reach every one of the reaches the other roles lose', function () {
    $owner = areUser('owner');

    foreach ([
        '/admin-api/users',
        '/admin-api/payments',
        '/admin-api/settings',
        '/admin-api/customers/export',
        '/admin-api/schema-inspect',
        '/admin-api/import/status',
        '/admin-api/updates',
        '/admin-api/analytics',
        '/'.AdminPathService::current(),
    ] as $path) {
        $status = test()->actingAs($owner, 'admin')->get($path)->getStatusCode();

        expect($status !== 403)->toBeTrue("owner was refused {$path} (status {$status})");
    }
})->group('role-enforcement');

it('lets the owner promote and demote accounts exactly as before', function () {
    $owner = areUser('owner');
    $victim = areUser('support');

    test()->actingAs($owner, 'admin')
        ->put('/admin-api/users/'.$victim->id, ['role' => 'manager'])
        ->assertOk();

    expect($victim->fresh()->role)->toBe('manager');
})->group('role-enforcement');

it('leaves the admin login and logout outside the permission layer', function () {
    // The escape hatch. Whatever the map says, no role can be sealed into a
    // session it cannot end, and nobody can be refused the login form — both
    // routes are registered outside the auth:admin group, so this layer never
    // sees them.
    $path = AdminPathService::current();

    test()->get('/'.$path.'/login')->assertOk();

    test()->actingAs(areUser('support'), 'admin')
        ->post('/'.$path.'/logout')
        ->assertStatus(302);
})->group('role-enforcement');

it('sends a signed-out visitor to the login form rather than a dead 403', function () {
    // EnforceAdminCapability runs BEFORE auth:admin. If it answered an
    // unauthenticated request itself, a logged-out owner would meet a 403 with
    // no link to anywhere — on a host with no shell, that is unrecoverable.
    test()->get('/admin-api/users')->assertStatus(302);
    test()->get('/'.AdminPathService::current())->assertStatus(302);
})->group('role-enforcement');

/* ---------------------------------------------------- 4. the column itself */

it('has an authorization layer reading the role column', function () {
    // The mirror image of the old test, which asserted every one of these was
    // absent. The middleware list is still pinned exactly, for the same reason
    // it was before: if it changes again, whoever changed it should come and
    // read this file.
    $middleware = collect(glob(base_path('app/Http/Middleware/*.php')) ?: [])
        ->map(fn ($p) => basename($p, '.php'))
        ->sort()
        ->values()
        ->all();

    expect($middleware)->toBe([
        /*
         * Lane FQ, and I have come and read this file as instructed.
         *
         * CacheHeaders authorises nothing and reads no role. It sets one
         * response header -- Cache-Control -- and it takes exactly one decision,
         * on the request PATH: whether the page is one of the customer's own
         * (account, wishlist, cart, checkout), which gets no-store instead of
         * no-cache. Who is asking never enters into it.
         *
         * It is inert on every route this file is about. It leaves alone any
         * response that already carries a Cache-Control of its own, and both
         * back-office surfaces already do: Admin\PageController sets no-store on
         * the console by hand, and NoStoreAdminApi sets it on the whole
         * /admin-api group. tests/Feature/CacheHeaderPolicyTest.php asserts that
         * non-interference on the console itself rather than on this source.
         *
         * It is also NOT REGISTERED YET. bootstrap/app.php is the integrator's
         * and is on BuildPackage::NEVER_SHIP, so the one line that appends it to
         * the web group is written out in docs/FQ-CACHE-HEADERS.md and applied
         * by hand. Until then the class exists and nothing calls it.
         */
        'CacheHeaders',
        'CheckRedirects',
        'EnforceAdminCapability',
        'NoIndexStaging',
        'NoStoreAdminApi',
        'SecurityHeaders',
        /*
         * Lane EP, and I have come and read this file as instructed.
         *
         * SetLocaleFromPath authorises nothing and reads no role. It strips a
         * language prefix off the path before the router runs and sets
         * App::setLocale(); it takes no decision that depends on who is asking,
         * and App\Support\Locale::localisable() steps aside for the admin path
         * and /admin-api entirely, so the back office never carries a language
         * segment and this class is inert on every route this file is about.
         *
         * It is global rather than in a group because middleware in the `web`
         * group runs after the router has matched, which is too late to change
         * which route matches. See the class doc and the block in
         * bootstrap/app.php that registers it.
         */
        'SetLocaleFromPath',
    ]);

    // Still no Gate and no policy: four fixed roles and one map, not an RBAC
    // system. This half of the old assertion is unchanged and stays true.
    $appSource = collect(
        iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())))
    )
        ->filter(fn ($f) => $f->isFile() && $f->getExtension() === 'php')
        ->map(fn ($f) => (string) file_get_contents($f->getPathname()))
        ->implode("\n");

    expect(str_contains($appSource, 'Gate::define'))->toBeFalse('a Gate appeared; this file assumes the map is the whole layer')
        ->and(str_contains($appSource, '->authorize('))->toBeFalse('a policy appeared; this file assumes the map is the whole layer');

    // And the layer is actually wired, not merely present as a file.
    expect(str_contains((string) file_get_contents(base_path('bootstrap/app.php')), EnforceAdminCapability::class))
        ->toBeTrue('EnforceAdminCapability is not registered in bootstrap/app.php');
})->group('role-enforcement');

it('reads the role column for a role the map does not recognise, and closes', function () {
    // The column is NOT NULL DEFAULT 'owner' and AdminController validates
    // every write, so this takes a hand-edited row. It still must not open.
    $odd = areUser('owner');
    $odd->forceFill(['role' => 'wizard'])->save();

    expect(AdminCapabilities::canonicalRole('wizard'))->toBeNull();

    test()->actingAs($odd->fresh(), 'admin')->get('/admin-api/users')->assertForbidden();
    test()->actingAs($odd->fresh(), 'admin')->get('/admin-api/stats')->assertForbidden();
})->group('role-enforcement');

it('treats the legacy staff spelling as support rather than locking it out', function () {
    // 0001_01_01_000003's comment says owner|manager|staff; AdminController has
    // always validated owner|manager|support|editor. A row reading 'staff' is
    // possible on a long-lived install and is aliased, not denied.
    $staff = areUser('owner');
    $staff->forceFill(['role' => 'staff'])->save();

    expect(AdminCapabilities::canonicalRole('staff'))->toBe('support');

    test()->actingAs($staff->fresh(), 'admin')->get('/admin-api/users')->assertForbidden();
    expect(test()->actingAs($staff->fresh(), 'admin')->get('/admin-api/stats')->getStatusCode())
        ->not->toBe(403);
})->group('role-enforcement');

/* ------------------------------------------------- 5. the reach, per role */

/**
 * The matrix. One row per role, listing paths it must reach and paths it must
 * not, chosen so that every capability group in the map is represented at least
 * once on one side or the other.
 *
 * "Can reach" is asserted as "not 403" rather than "200" on purpose: 403 is the
 * only status this layer produces, so anything else means the permission check
 * let the request through to its handler, which is exactly and only what is
 * being tested here. Asserting 200 would couple these rows to whatever each
 * handler happens to do with an empty test database.
 */
dataset('role reach', [
    'manager' => ['manager',
        ['/admin-api/orders-list', '/admin-api/orders-export', '/admin-api/customers/list',
            '/admin-api/customers/export', '/admin-api/catalog-products-list', '/admin-api/categories',
            '/admin-api/blocks', '/admin-api/reviews/list', '/admin-api/coupons',
            '/admin-api/newsletter', '/admin-api/shipping', '/admin-api/analytics'],
        ['/admin-api/users', '/admin-api/payments', '/admin-api/settings', '/admin-api/ecommerce',
            '/admin-api/mail', '/admin-api/updates', '/admin-api/schema-inspect',
            '/admin-api/import/status', '/admin-api/demo-content'],
    ],
    'support' => ['support',
        ['/admin-api/stats', '/admin-api/orders-list', '/admin-api/customers/list',
            '/admin-api/reviews/list', '/admin-api/quiz-leads'],
        ['/admin-api/users', '/admin-api/payments', '/admin-api/settings', '/admin-api/analytics',
            '/admin-api/orders-export', '/admin-api/customers/export', '/admin-api/newsletter',
            '/admin-api/coupons', '/admin-api/catalog-products-list', '/admin-api/categories',
            '/admin-api/blocks', '/admin-api/updates', '/admin-api/schema-inspect'],
    ],
    'editor' => ['editor',
        ['/admin-api/stats', '/admin-api/catalog-products-list', '/admin-api/categories',
            '/admin-api/brands', '/admin-api/attributes', '/admin-api/blocks', '/admin-api/media',
            '/admin-api/homepage', '/admin-api/review-settings', '/admin-api/reviews/list'],
        ['/admin-api/users', '/admin-api/payments', '/admin-api/settings', '/admin-api/analytics',
            '/admin-api/orders-list', '/admin-api/customers/list', '/admin-api/customers/export',
            '/admin-api/newsletter', '/admin-api/coupons', '/admin-api/updates',
            '/admin-api/schema-inspect', '/admin-api/reviews/export'],
    ],
]);

it('gives each role exactly the reach the map promises', function (string $role, array $allowed, array $refused) {
    $actor = areUser($role);
    areUser('owner');

    foreach ($allowed as $path) {
        $status = test()->actingAs($actor, 'admin')->get($path)->getStatusCode();

        expect($status !== 403)->toBeTrue("{$role} was refused {$path}, which the map grants it");
    }

    foreach ($refused as $path) {
        $status = test()->actingAs($actor, 'admin')->get($path)->getStatusCode();

        expect($status === 403)->toBeTrue("{$role} reached {$path}, which the map does not grant it (status {$status})");
    }
})->with('role reach')->group('role-enforcement');

it('lets every role reach the console shell, or the role cannot work at all', function (string $role) {
    $status = test()->actingAs(areUser($role), 'admin')->get('/'.AdminPathService::current())->getStatusCode();

    expect($status !== 403)->toBeTrue("{$role} cannot open the admin console at all");
})->with(['owner', 'manager', 'support', 'editor'])->group('role-enforcement');

/* ------------------------------------- 5. what the staff list hands back */

/**
 * LANE DJ — the staff list is a list of people who can sign in, so every row it
 * reads is by definition a row with a usable credential in it.
 *
 * `admin_users` carries `password` and `remember_token`. The screen shows a
 * name, an email, a role and a date. CustomersApiController already learned
 * this lesson on the customers table and carries an explicit select() with a
 * comment saying why; AdminController::users() now does the same, so the hashes
 * are never loaded rather than merely never printed.
 *
 * WHAT THIS ACTUALLY CATCHES, stated honestly, because it is narrower than it
 * looks. Today the method hands back an array it builds field by field, so no
 * model-level setting can leak anything through it: adding makeVisible(-
 * ['password']) to the query changes nothing about the response, and this test
 * goes green on that mutation. What it catches is the realistic regression —
 * the day somebody returns the rows themselves instead of the built array,
 * which is the shorter and more obvious way to write this method and the way
 * it would be written by anyone adding a column in a hurry. Mutating the body
 * to `->get()` with no map() fails it on the first loop below.
 *
 * $hidden is deliberately not what is asserted either. AdminUser::$hidden lists
 * both columns and would cover that mutation on its own today — but $hidden is
 * a serialisation rule, one edit to that array away from not applying, and it
 * does nothing about a hash sitting in memory in the meantime. The select() in
 * the method is the belt for that half; this is the braces, on the response the
 * browser actually receives.
 */
it('never hands a password hash or a remember token to the staff list', function () {
    $owner = areUser('owner', 'List');
    $other = areUser('manager', 'List');

    $response = test()->actingAs($owner, 'admin')->get('/admin-api/users');

    $response->assertOk();

    $body = $response->getContent();

    // The list has to be a real list, or every assertion below passes on an
    // empty response and proves nothing.
    expect(str_contains($body, $other->email))
        ->toBeTrue('the staff list did not contain the accounts it is supposed to list');

    foreach (['password', 'legacy_password', 'remember_token'] as $column) {
        expect(str_contains($body, $column))
            ->toBeFalse('the staff list response carries a "'.$column.'" field');
    }

    // The stored hash itself, not only the key it would arrive under: a rename
    // of the field would sail straight past the loop above.
    foreach ([$owner, $other] as $account) {
        $hash = $account->fresh()->getAuthPassword();

        expect($hash)->not->toBe('', 'the fixture has no stored hash, so this proves nothing');

        expect(str_contains($body, $hash))
            ->toBeFalse('the staff list response contains a stored password hash');
    }
})->group('role-enforcement');
