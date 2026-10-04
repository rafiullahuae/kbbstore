<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminRolesController;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Services\SecurityModule;
use App\Support\AdminCapabilities;
use App\Support\AdminRoles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AdminRolesRoutes;

/**
 * Platform → Users & Roles: editable roles (Lane RL, plan row 53).
 *
 * The owner: "pre defined roles like Store Manager, SEO Manager, Sub Admin,
 * Full admin, Inventory Manager etc. with full control of making edits to those
 * roles or create a custom new role ... and further edit for that specific
 * person and a custom name to that role ... secure ... must not have any
 * hanging, bugs or errors."
 *
 * Each case says what it would catch on the shop, and how to make it go red.
 */
beforeEach(function () {
    AdminRolesRoutes::wire(app());
});

function rlUser(string $role = 'owner', array $extra = []): AdminUser
{
    $u = AdminUser::create([
        'name' => 'RL '.$role,
        'email' => 'rl-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
    if ($extra !== []) {
        $u->forceFill($extra)->save();
    }

    return $u->fresh();
}

function rlRole(string $slug): AdminRole
{
    return AdminRole::query()->where('slug', $slug)->firstOrFail();
}

/** What the map answered before this package, owner short-circuit included. */
function rlLegacyCan(string $role, ?string $cap): bool
{
    return AdminCapabilities::canonicalRole($role) === 'owner' || AdminCapabilities::roleCan($role, $cap);
}

/* ============================================ 1. nobody's access moves today */

it('answers every route rule exactly as the old static map did, for every legacy role, with the roles table seeded', function () {
    /*
     * THE PROMISE THE PACKAGE SHIPS ON: "every existing account keeps exactly
     * the access it has today". Walks every RULES row and every ROUTE_NAMES
     * entry for owner, manager, support, editor and the legacy 'staff' spelling,
     * and compares AdminRoles::can() (what the middleware now asks) with the old
     * map's answer. On the shop a miss is a manager who suddenly cannot open
     * Orders, or a support account that can suddenly export customers.
     *
     * MUTATION: map 'manager' to 'sub-admin' in AdminRoles::LEGACY and this goes
     * red on store.settings; drop 'orders.edit' from the support preset default
     * (e.g. return [] for customer-support in presetDefault()) and it goes red
     * on PUT admin-api/orders/{id}/address.
     */
    $caps = array_unique(array_merge(array_column(AdminCapabilities::RULES, 2), array_values(AdminCapabilities::ROUTE_NAMES), [null, 'no.such.capability']));
    expect(count(AdminCapabilities::RULES))->toBeGreaterThan(250);

    $checked = 0;
    foreach (['owner', 'manager', 'support', 'editor', 'staff'] as $legacy) {
        $u = rlUser($legacy);
        foreach ($caps as $cap) {
            expect(AdminRoles::can($u, $cap))->toBe(rlLegacyCan($legacy, $cap), "{$legacy} / ".var_export($cap, true));
            $checked++;
        }
        $old = $legacy === 'owner' ? AdminRoles::known() : AdminCapabilities::forRole($legacy);
        expect(AdminRoles::for($u))->toEqualCanonicalizing($old);
    }
    expect($checked)->toBeGreaterThan(300);
});

it('answers every route exactly as the map did BEFORE this lane touched it, from a frozen fixture', function () {
    /*
     * The case above compares against the map as it is NOW, so a change to the
     * map itself (giving 'manager' a capability, re-pointing a route at a new
     * one) would move both sides together and stay green. This one compares
     * against tests/Fixtures/admin-access-before-roles.json: the answer the map
     * at 2.60.375 gave, per RULES row and route name, for owner, manager,
     * support and editor -- generated from `git show a441b1a:` of the file.
     *
     * MUTATION: give seo.audit to 'manager' in AdminCapabilities::CAPABILITIES
     * (or point admin-api/seo-tasks back at a manager capability) and this
     * names the row. A row removed since is skipped; a row ADDED since has no
     * "before" and is not this test's business.
     */
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/admin-access-before-roles.json')), true);
    $roles = explode(' ', $fixture['roles']);
    $users = [];
    foreach ($roles as $legacy) {
        $users[$legacy] = rlUser($legacy);
    }

    $now = [];
    foreach (AdminCapabilities::RULES as [$m, $p, $cap]) {
        $now[$m.' '.$p] ??= $cap;
    }
    $checked = 0;
    foreach (['rules' => $now, 'names' => AdminCapabilities::ROUTE_NAMES] as $kind => $current) {
        foreach ($fixture[$kind] as $key => $answers) {
            if (! array_key_exists($key, $current)) {
                continue;
            }
            foreach ($roles as $i => $legacy) {
                expect(AdminRoles::can($users[$legacy], $current[$key]))->toBe($answers[$i] === '1', "{$legacy} on {$key}");
                $checked++;
            }
        }
    }
    expect($checked)->toBeGreaterThan(1100);
});

it('gives the same answers when the code is on the server before its migration has run', function () {
    /*
     * A package copies its files before it runs its migrations. In that window
     * admin_roles does not exist and every check must still answer from the
     * code default — not 500, and not "no access" for every manager.
     *
     * MUTATION: delete the Schema::hasTable() guard in AdminRoles::roles() and
     * this is a QueryException.
     */
    $manager = rlUser('manager');
    $support = rlUser('support');
    Schema::drop('admin_roles');
    AdminRoles::flush();

    foreach (AdminCapabilities::RULES as [, , $cap]) {
        expect(AdminRoles::can($manager, $cap))->toBe(rlLegacyCan('manager', $cap))
            ->and(AdminRoles::can($support, $cap))->toBe(rlLegacyCan('support', $cap));
    }
    expect(Cache::get(AdminRoles::CACHE_KEY))->toBeNull('an empty table was cached and would outlive the migration');
});

it('keeps answering for every legacy role over HTTP, through the middleware', function () {
    /*
     * The same promise, end to end: the middleware is what actually refuses.
     * MUTATION: make EnforceAdminCapability call AdminCapabilities::roleCan()
     * on role_id-less accounts only and the SEO case in the next block breaks;
     * make it skip AdminRoles and these still pass — which is why both exist.
     */
    test()->actingAs(rlUser('manager'), 'admin')->getJson('/admin-api/stats')->assertOk();
    test()->actingAs(rlUser('support'), 'admin')->getJson('/admin-api/customers/export')->assertForbidden();
    test()->actingAs(rlUser('editor'), 'admin')->getJson('/admin-api/payments')->assertForbidden();
    test()->actingAs(rlUser('manager'), 'admin')->getJson('/admin-api/users')->assertForbidden();
});

/* ===================================================== 2. the role catalogue */

it('seeds the eight predefined roles, every one following the code default', function () {
    /*
     * capabilities NULL is what makes a preset follow the map, so a capability
     * a later lane grants to 'manager' reaches every Store Manager without a
     * re-save. MUTATION: seed presets with their lists frozen (json) and the
     * null assertion goes red.
     */
    $rows = AdminRole::query()->orderBy('id')->get();

    expect($rows->pluck('slug')->all())->toBe(array_keys(AdminRoles::PRESETS))
        ->and($rows->pluck('name')->all())->toBe(['Full Admin', 'Sub Admin', 'Store Manager', 'SEO Manager', 'Inventory Manager', 'Marketing Manager', 'Customer Support', 'Content Editor'])
        ->and($rows->every(fn ($r) => $r->is_preset && $r->capabilities === null))->toBeTrue();
});

it('puts every capability in exactly one plain-English section, and nothing that is not a capability', function () {
    /*
     * A capability missing here is a tick the owner can never set; an extra one
     * is a tick that stores nothing. MUTATION: add 'foo.manage' => ['owner'] to
     * AdminCapabilities::CAPABILITIES without a label and this names it.
     */
    $listed = [];
    foreach (AdminRoles::SECTIONS as [$key, $label, $caps]) {
        expect($label)->not->toBe('')->and($caps)->not->toBeEmpty("section {$key} is empty");
        foreach ($caps as $cap => $text) {
            expect($text)->toBeString()->not->toBe('')->not->toContain('.manage');
            $listed[] = $cap;
        }
    }

    expect(array_count_values($listed))->each->toBe(1)
        ->and(array_values(array_diff(AdminRoles::known(), $listed)))->toBe([], 'capabilities with no tick')
        ->and(array_values(array_diff($listed, AdminRoles::known())))->toBe([], 'ticks with no capability')
        ->and(collect(AdminRoles::SECTIONS)->pluck(1)->all())->toContain('Orders', 'Products & catalogue', 'Inventory', 'Customers', 'SEO', 'Appearance', 'Marketing', 'Emails', 'Settings & platform', 'Users & Roles');
});

it('gives each preset what its name says, and keeps the owner-only levers on Full Admin alone', function () {
    /*
     * The presets are the owner's starting points, so each is pinned on the
     * lines that matter. MUTATION: add 'payments.manage' to SUB_ADMIN_EXTRA and
     * the owner-only loop goes red; add 'marketing.email.send' to the Marketing
     * Manager list and the D11 line goes red ("Send for Owner and Administrator
     * only", plan row 53).
     */
    $d = fn (string $slug) => AdminRoles::presetDefault($slug);

    expect($d('full-admin'))->toEqualCanonicalizing(AdminRoles::known())
        ->and($d('store-manager'))->toBe(AdminCapabilities::forRole('manager'))
        ->and($d('customer-support'))->toBe(AdminCapabilities::forRole('support'))
        ->and($d('content-editor'))->toBe(AdminCapabilities::forRole('editor'))
        ->and(array_diff(AdminCapabilities::forRole('manager'), $d('sub-admin')))->toBe([])
        ->and($d('sub-admin'))->toContain('store.settings', 'emails.manage', 'seo.audit')
        ->and($d('seo-manager'))->toContain('seo.audit', 'catalog.manage', 'pages.manage', 'posts.manage')
        ->and(array_intersect($d('seo-manager'), ['orders.view', 'customers.view', 'orders.money']))->toBe([])
        ->and($d('inventory-manager'))->toContain('catalog.manage', 'sets.stock', 'orders.view')
        ->and(array_intersect($d('inventory-manager'), ['customers.view', 'orders.money']))->toBe([])
        ->and($d('marketing-manager'))->toContain('marketing.email.manage', 'banners.manage')
        ->and($d('marketing-manager'))->not->toContain('orders.view');

    foreach (AdminRoles::PRESETS as $slug => $_) {
        expect(array_diff($d($slug), AdminRoles::known()))->toBe([], "{$slug} names a capability the map does not know")
            ->and(in_array('admin.access', $d($slug), true))->toBeTrue("{$slug} cannot sign in");
        if ($slug === 'full-admin') {
            continue;
        }
        foreach (['users.manage', 'roles.manage', 'updates.manage', 'payments.manage', 'platform.site_url', 'storefront.adminbar', 'storefront.quick_edit'] as $owners) {
            expect($d($slug))->not->toContain($owners);
        }
        $sends = in_array('marketing.email.send', $d($slug), true);
        expect($sends)->toBe(in_array($slug, ['sub-admin', 'store-manager'], true), "{$slug} campaign send");
    }
});

/* =========================================== 3. roles, assigned and tweaked */

it('puts a member on the SEO Manager role and lets them reach exactly what it holds', function () {
    /*
     * The feature, end to end over HTTP. MUTATION: drop the role_id branch in
     * AdminRoles::roleOf() and this member is a Content Editor again (their
     * tier): seo-tasks goes 403.
     */
    $owner = rlUser('owner');
    $seo = rlUser('editor');

    test()->actingAs($owner, 'admin')
        ->putJson("/admin-api/roles/members/{$seo->id}", ['role_id' => rlRole('seo-manager')->id, 'role_title' => 'Head of SEO'])
        ->assertOk();

    $seo = $seo->fresh();
    expect($seo->role_id)->toBe(rlRole('seo-manager')->id)
        ->and($seo->role)->toBe('editor')
        ->and($seo->role_title)->toBe('Head of SEO');

    expect(AdminRoles::can($seo, 'seo.audit'))->toBeTrue()
        ->and(AdminRoles::can($seo, 'orders.view'))->toBeFalse();
    // The refusal names the role as the screen shows it and the permission in
    // words. MUTATION: pass null as $named in EnforceAdminCapability and the
    // sentence says "Your role (editor)" — a role this person is not on.
    test()->actingAs($seo, 'admin')->getJson('/admin-api/orders')->assertForbidden()
        ->assertJsonPath('message', 'Your role (SEO Manager) does not have the "See orders" permission (orders.view).');
    test()->actingAs($seo, 'admin')->getJson('/admin-api/stats')->assertOk();
});

it('applies a per-person grant and a per-person revoke on top of the role', function () {
    /*
     * "further edit for that specific person". MUTATION: swap the array_diff
     * of revokes in AdminRoles::resolve() for array_merge and the revoked
     * catalogue stays open.
     */
    $owner = rlUser('owner');
    $m = rlUser('editor');

    test()->actingAs($owner, 'admin')->putJson("/admin-api/roles/members/{$m->id}", [
        'role_id' => rlRole('seo-manager')->id,
        'grants' => ['orders.view', 'catalog.view'],   // catalog.view is already in the role: tidied away
        'revokes' => ['catalog.manage', 'orders.money'], // orders.money was never in it: tidied away
    ])->assertOk();

    $m = $m->fresh();
    expect($m->grants)->toBe(['orders.view'])
        ->and($m->revokes)->toBe(['catalog.manage'])
        ->and(AdminRoles::can($m, 'orders.view'))->toBeTrue()
        ->and(AdminRoles::can($m, 'catalog.manage'))->toBeFalse()
        ->and(AdminRoles::can($m, 'catalog.view'))->toBeTrue();
    test()->actingAs($m, 'admin')->getJson('/admin-api/orders')->assertOk();
});

it('creates a custom role from a preset, renames it, re-ticks it and deletes it once nobody is on it', function () {
    /*
     * The Roles tab's whole life cycle. MUTATION: remove the in-use count from
     * destroy() and the 409 becomes a 200 that strands the member on a role
     * that no longer exists (= no access).
     */
    $owner = rlUser('owner');
    $from = rlRole('store-manager');

    $made = test()->actingAs($owner, 'admin')->postJson('/admin-api/roles', ['name' => 'Weekend lead', 'from' => $from->id])->assertOk();
    $role = AdminRole::find($made->json('id'));
    expect($role->is_preset)->toBeFalse()
        ->and($role->tier)->toBe('manager')
        ->and($role->capabilities)->toEqualCanonicalizing(AdminRoles::presetDefault('store-manager'));

    test()->actingAs($owner, 'admin')->putJson("/admin-api/roles/{$role->id}", ['name' => 'Weekend manager', 'capabilities' => ['admin.access', 'dashboard.view', 'orders.view']])->assertOk();
    expect($role->fresh()->name)->toBe('Weekend manager')
        ->and($role->fresh()->capabilities)->toBe(['admin.access', 'dashboard.view', 'orders.view']);

    $m = rlUser('support');
    test()->actingAs($owner, 'admin')->putJson("/admin-api/roles/members/{$m->id}", ['role_id' => $role->id])->assertOk();
    test()->actingAs($owner, 'admin')->deleteJson("/admin-api/roles/{$role->id}")->assertStatus(409)->assertJsonPath('error', 'in_use');

    test()->actingAs($owner, 'admin')->putJson("/admin-api/roles/members/{$m->id}", ['role_id' => rlRole('customer-support')->id])->assertOk();
    test()->actingAs($owner, 'admin')->deleteJson("/admin-api/roles/{$role->id}")->assertOk();
    expect(AdminRole::find($role->id))->toBeNull();
});

it('lets a preset be edited and restored, and never deleted', function () {
    /*
     * "presets editable but restorable to default". MUTATION: make restore()
     * write the default LIST instead of null and the preset stops following
     * the code (the null assertion goes red).
     */
    $owner = rlUser('owner');
    $sm = rlRole('store-manager');

    test()->actingAs($owner, 'admin')->putJson("/admin-api/roles/{$sm->id}", ['name' => 'Shop Manager', 'capabilities' => ['admin.access', 'orders.view']])->assertOk();
    $manager = rlUser('manager');
    expect(AdminRoles::can($manager, 'orders.money'))->toBeFalse('the edit did not reach legacy managers');

    test()->actingAs($owner, 'admin')->deleteJson("/admin-api/roles/{$sm->id}")->assertStatus(422)->assertJsonPath('error', 'preset');

    test()->actingAs($owner, 'admin')->postJson("/admin-api/roles/{$sm->id}/restore")->assertOk()
        ->assertJsonPath('roles.2.edited', false);
    expect($sm->fresh()->capabilities)->toBeNull()
        ->and($sm->fresh()->name)->toBe('Store Manager')
        ->and(AdminRoles::can($manager->fresh(), 'orders.money'))->toBeTrue();
});

it('will not narrow Full Admin', function () {
    // MUTATION: drop the 'locked' check in update() and Full Admin can be
    // ticked down to nothing — on the one role that has to be able to fix it.
    $owner = rlUser('owner');
    test()->actingAs($owner, 'admin')->putJson('/admin-api/roles/'.rlRole('full-admin')->id, ['capabilities' => ['admin.access']])
        ->assertStatus(422)->assertJsonPath('error', 'locked');
    expect(AdminRoles::can($owner, 'users.manage'))->toBeTrue();
});

/* ================================================ 4. unknown keys are refused */

it('refuses a capability key the map does not know, everywhere one can be written', function () {
    /*
     * A stored unknown key is a permission somebody believes they granted and
     * nobody enforces — or, the day a lane adds that key, one that silently
     * starts working. MUTATION: replace $this->keys() with AdminRoles::clean()
     * in the controller and these become 200s that drop the key in silence.
     */
    $owner = rlUser('owner');
    $m = rlUser('support');
    $sm = rlRole('store-manager');

    test()->actingAs($owner, 'admin')->postJson('/admin-api/roles', ['name' => 'Godlike', 'from' => $sm->id, 'capabilities' => ['admin.access', 'god.mode']])
        ->assertStatus(422)->assertJsonPath('error', 'unknown_capability')->assertJsonPath('unknown', ['god.mode']);
    test()->actingAs($owner, 'admin')->putJson("/admin-api/roles/{$sm->id}", ['capabilities' => ['orders.view', 'orders.*']])
        ->assertStatus(422)->assertJsonPath('error', 'unknown_capability');
    test()->actingAs($owner, 'admin')->putJson("/admin-api/roles/members/{$m->id}", ['grants' => ['payments.manager']])
        ->assertStatus(422);
    test()->actingAs($owner, 'admin')->putJson("/admin-api/roles/members/{$m->id}", ['role_id' => 99999])
        ->assertStatus(422)->assertJsonPath('error', 'unknown_role');

    expect(AdminRole::query()->where('name', 'Godlike')->exists())->toBeFalse()
        ->and($sm->fresh()->capabilities)->toBeNull()
        ->and($m->fresh()->grants)->toBeNull();
});

/* ===================================================== 5. no way upward */

/** A non-Full-Admin who has been handed both management capabilities. */
function rlDelegate(): AdminUser
{
    return rlUser('manager', ['role_id' => rlRole('sub-admin')->id, 'grants' => ['users.manage', 'roles.manage']]);
}

it('lets a delegate with users.manage and roles.manage reach the screen, inside their own access', function () {
    // The control case for the five refusals below: without it they would all
    // pass with the endpoints simply unreachable.
    $sub = rlDelegate();
    $support = rlUser('support');

    test()->actingAs($sub, 'admin')->getJson('/admin-api/roles/members')->assertOk();
    test()->actingAs($sub, 'admin')->putJson("/admin-api/roles/members/{$support->id}", ['role_id' => rlRole('store-manager')->id])->assertOk();
    expect($support->fresh()->role)->toBe('manager');
});

it('refuses to let anyone below Full Admin change their own role or permissions', function () {
    /*
     * The self-promotion hole, the one this whole permission layer was built
     * for, re-opened by grantable roles if nothing stops it. Undoing a revoke
     * the owner put on them is caught twice (here, and by beyond_your_access,
     * since they no longer hold it); swapping their own role SIDEWAYS is caught
     * by this rule alone. MUTATION: delete the 'own_access' rule in
     * AdminRoles::refusal() and the role swap is a 200 (the revoke PUT turns
     * into a beyond_your_access 403, so its error code goes red too).
     */
    $sub = rlDelegate();
    $sub->forceFill(['revokes' => ['cache.manage']])->save();
    $sub = $sub->fresh();

    test()->actingAs($sub, 'admin')->putJson("/admin-api/roles/members/{$sub->id}", ['revokes' => []])
        ->assertForbidden()->assertJsonPath('error', 'own_access');
    test()->actingAs($sub, 'admin')->putJson("/admin-api/roles/members/{$sub->id}", ['role_id' => rlRole('store-manager')->id])
        ->assertForbidden()->assertJsonPath('error', 'own_access');
    // Their own name and title are not access.
    test()->actingAs($sub, 'admin')->putJson("/admin-api/roles/members/{$sub->id}", ['name' => 'Sam', 'role_title' => 'Ops lead'])->assertOk();

    // And the role they are on is not theirs to re-tick.
    test()->actingAs($sub, 'admin')->putJson('/admin-api/roles/'.rlRole('sub-admin')->id, ['name' => 'Sub Admin+'])
        ->assertForbidden()->assertJsonPath('error', 'own_role');

    expect($sub->fresh()->revokes)->toBe(['cache.manage'])->and($sub->fresh()->role_id)->toBe(rlRole('sub-admin')->id);
});

it('refuses to let anyone hand out a capability they do not hold', function () {
    /*
     * MUTATION: return null from beyond() / drop the beyond_your_access rule and
     * a Sub Admin mints a role with payments.manage — the gateway keys — and
     * puts a colleague (or a second account of their own) on it.
     */
    $sub = rlDelegate();
    $support = rlUser('support');

    test()->actingAs($sub, 'admin')->postJson('/admin-api/roles', ['name' => 'Payments', 'from' => rlRole('store-manager')->id, 'capabilities' => ['admin.access', 'payments.manage']])
        ->assertForbidden()->assertJsonPath('error', 'beyond_your_access');
    test()->actingAs($sub, 'admin')->putJson("/admin-api/roles/members/{$support->id}", ['grants' => ['updates.manage']])
        ->assertForbidden()->assertJsonPath('error', 'beyond_your_access');
    test()->actingAs($sub, 'admin')->putJson("/admin-api/roles/members/{$support->id}", ['role_id' => rlRole('full-admin')->id])
        ->assertForbidden()->assertJsonPath('error', 'beyond_your_access');
    test()->actingAs($sub, 'admin')->postJson('/admin-api/roles/members', ['name' => 'Alt', 'email' => 'alt@example.test', 'password' => 'secret-secret', 'role_id' => rlRole('full-admin')->id])
        ->assertForbidden();
    // The older account endpoints take a legacy role name: the same rule.
    test()->actingAs($sub, 'admin')->putJson("/admin-api/users/{$support->id}", ['role' => 'owner'])
        ->assertForbidden()->assertJsonPath('error', 'beyond_your_access');
    test()->actingAs($sub, 'admin')->putJson("/admin-api/users/{$sub->id}", ['role' => 'owner'])
        ->assertForbidden();

    expect($support->fresh()->role)->toBe('support')
        ->and(AdminUser::query()->where('email', 'alt@example.test')->exists())->toBeFalse()
        ->and(AdminRole::query()->where('name', 'Payments')->exists())->toBeFalse();
});

it('refuses to let anyone touch an account or a role that outranks them', function () {
    /*
     * A delegate resetting a Full Admin's password is a takeover without ever
     * touching a role. MUTATION: drop the 'outranks' rule and the password PUT
     * is a 200.
     */
    $sub = rlDelegate();
    $owner = rlUser('owner');
    rlUser('owner');

    test()->actingAs($sub, 'admin')->putJson("/admin-api/roles/members/{$owner->id}", ['password' => 'taken-over-1'])
        ->assertForbidden()->assertJsonPath('error', 'outranks');
    test()->actingAs($sub, 'admin')->putJson("/admin-api/users/{$owner->id}", ['password' => 'taken-over-1'])
        ->assertForbidden();
    test()->actingAs($sub, 'admin')->deleteJson("/admin-api/roles/members/{$owner->id}")->assertForbidden();
    test()->actingAs($sub, 'admin')->putJson('/admin-api/roles/'.rlRole('full-admin')->id, ['name' => 'Nobody'])->assertForbidden();

    expect(password_verify('taken-over-1', (string) $owner->fresh()->password))->toBeFalse()
        ->and(rlRole('full-admin')->name)->toBe('Full Admin');
});

it('never demotes or deletes the last Full Admin, whoever asks', function () {
    /*
     * The host-has-no-other-way-in invariant (AdminCapabilities' docblock).
     * MUTATION: drop the last_full_admin rule and the only owner moves himself
     * to Store Manager — and nobody can ever reach Users & Roles again.
     */
    $owner = rlUser('owner');
    AdminUser::query()->where('role', 'owner')->where('id', '!=', $owner->id)->delete();

    test()->actingAs($owner, 'admin')->putJson("/admin-api/roles/members/{$owner->id}", ['role_id' => rlRole('store-manager')->id])
        ->assertStatus(422)->assertJsonPath('error', 'last_full_admin');
    expect($owner->fresh()->role)->toBe('owner');

    $second = rlUser('owner');
    test()->actingAs($owner, 'admin')->putJson("/admin-api/roles/members/{$second->id}", ['role_id' => rlRole('sub-admin')->id])->assertOk();
    test()->actingAs($owner, 'admin')->deleteJson("/admin-api/roles/members/{$owner->id}")->assertStatus(422)->assertJsonPath('error', 'self');
});

it('refuses the role endpoints to every role that does not hold the capability', function (string $legacy) {
    // Fails closed. MUTATION: map 'admin-api/roles' to admin.access in RULES
    // and every role below reads the role list.
    $u = rlUser($legacy);
    test()->actingAs($u, 'admin')->getJson('/admin-api/roles')->assertForbidden()->assertJsonPath('capability', 'roles.manage');
    test()->actingAs($u, 'admin')->getJson('/admin-api/roles/members')->assertForbidden()->assertJsonPath('capability', 'users.manage');
    test()->actingAs($u, 'admin')->postJson('/admin-api/roles', ['name' => 'Mine', 'from' => 1])->assertForbidden();
})->with(['manager', 'support', 'editor']);

it('maps every route the file registers, members to users.manage and the rest to roles.manage', function () {
    /*
     * MUTATION: move the two members rules below 'admin-api/roles/*' and
     * PUT roles/members resolves to roles.manage: the account list on the
     * wrong capability.
     */
    $routes = AdminRolesRoutes::registered();
    expect($routes)->toHaveCount(9);
    foreach ($routes as $route) {
        $want = str_starts_with($route->uri(), 'admin-api/roles/members') ? 'users.manage' : 'roles.manage';
        expect(AdminCapabilities::for($route))->toBe($want, $route->uri());
    }
    expect(AdminCapabilities::CAPABILITIES['roles.manage'])->toBe(['owner']);
});

/* ======================================================= 6. fails closed */

it('gives an account whose role row has gone no access at all, and leaves the owner untouched', function () {
    /*
     * A role_id that names nothing is a closed door, not "fall back to
     * something". MUTATION: make roleOf() fall back to the tier preset when the
     * row is missing and this member is a Store Manager again.
     */
    $m = rlUser('manager', ['role_id' => 424242]);
    expect(AdminRoles::for($m))->toBe([]);
    test()->actingAs($m, 'admin')->getJson('/admin-api/stats')->assertForbidden();

    // An owner pointed at a narrow role is STILL an owner: the column decides.
    $o = rlUser('owner', ['role_id' => rlRole('customer-support')->id]);
    expect(AdminRoles::can($o, 'payments.manage'))->toBeTrue();
    test()->actingAs($o, 'admin')->getJson('/admin-api/roles')->assertOk();
});

it('puts an account back on its legacy role when only the legacy column is written', function () {
    /*
     * kbb:admin --role and the older PUT /admin-api/users write `role` alone.
     * MUTATION: delete the saving hook in AdminUser and the account keeps its
     * old SEO role while the screen and the CLI say "support".
     */
    $m = rlUser('editor', ['role_id' => rlRole('seo-manager')->id]);
    $m->role = 'support';
    $m->save();

    expect($m->fresh()->role_id)->toBeNull()
        ->and(AdminRoles::roleOf($m->fresh())['slug'])->toBe('customer-support');
});

/* ============================================= 7. fast, and never stale */

it('works out an account\'s access once per request and flushes it on every role write', function () {
    /*
     * MUTATION: drop the static::saved flush in AdminRole::booted() and the
     * second read returns the cached list: the owner unticks a box and the
     * member keeps the permission until the cache is cleared by hand.
     */
    $m = rlUser('manager');
    $sm = rlRole('store-manager');

    DB::enableQueryLog();
    expect(AdminRoles::can($m, 'orders.money'))->toBeTrue();
    DB::flushQueryLog();
    foreach (range(1, 50) as $_) {
        AdminRoles::can($m, 'orders.money');
        AdminRoles::can($m, 'catalog.manage');
    }
    expect(DB::getQueryLog())->toBe([], 'a permission check reached the database');
    expect(Cache::get(AdminRoles::CACHE_KEY))->toBeArray();

    $sm->capabilities = ['admin.access'];
    $sm->save();

    expect(Cache::get(AdminRoles::CACHE_KEY))->toBeNull()
        ->and(AdminRoles::can($m, 'orders.money'))->toBeFalse();
});

it('reads the role table from the cache, not the database, on a warm request', function () {
    // MUTATION: replace Cache::get() in AdminRoles::roles() with a direct query
    // and the middleware costs one query on every admin request.
    $m = rlUser('manager');
    AdminRoles::roles();   // warm
    app('request')->attributes->remove('kbb.roles.roles');
    AdminRoles::flush((int) $m->id);

    DB::enableQueryLog();
    DB::flushQueryLog();
    AdminRoles::can($m, 'orders.view');
    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'admin_roles'))->all())->toBe([]);
});

it('lists two members or twenty in the same number of queries', function () {
    /*
     * The Members tab works out every member's effective access. MUTATION: call
     * AdminRole::find($u->role_id) inside the map() and the count grows with
     * the list.
     */
    $owner = rlUser('owner');
    $count = function () use ($owner) {
        AdminRoles::flush();
        DB::enableQueryLog();
        DB::flushQueryLog();
        test()->actingAs($owner, 'admin')->getJson('/admin-api/roles/members')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    rlUser('support', ['role_id' => rlRole('seo-manager')->id, 'grants' => ['orders.view']]);
    $count();            // the first request in a test warms settings and the session: not the list
    $few = $count();
    foreach (range(1, 18) as $i) {
        rlUser(['manager', 'support', 'editor'][$i % 3], $i % 2 ? ['role_id' => rlRole('inventory-manager')->id] : []);
    }
    expect($count())->toBe($few)->and($few)->toBeLessThan(12);
});

/* ======================================================= 8. the paper trail */

it('records who changed which role, and what they added and took away', function () {
    /*
     * MUTATION: remove the audit() call from update() and the security log has
     * no row for a role that just gained "Refund and void payments".
     */
    $owner = rlUser('owner');
    $role = rlRole('content-editor');
    $m = rlUser('support');

    test()->actingAs($owner, 'admin')->putJson("/admin-api/roles/{$role->id}", [
        'capabilities' => array_values(array_merge(AdminRoles::presetDefault('content-editor'), ['orders.money'])),
    ])->assertOk();
    test()->actingAs($owner, 'admin')->putJson("/admin-api/roles/members/{$m->id}", ['role_id' => $role->id, 'role_title' => 'Copy lead'])->assertOk();

    $row = AuditEvent::query()->where('event', AdminRolesController::AUDIT_ROLE)->latest('id')->first();
    expect($row)->not->toBeNull()
        ->and($row->summary)->toBe('Role changed: Content Editor')
        ->and($row->after)->toContain('added: Refund and void payments')
        ->and($row->actor_id)->toBe($owner->id);

    $acct = AuditEvent::query()->where('event', SecurityModule::E_ACCOUNT)->where('summary', 'like', 'Back-office access changed:%')->latest('id')->first();
    expect($acct->after)->toContain('role: Content Editor')->toContain('title: Copy lead')
        ->and($acct->before)->toContain('role: Customer Support');
});

/* ================================================= 9. stored safely */

it('stores names and titles as text, without control characters', function () {
    // Every one of these is printed by the console through esc(); the server's
    // part is to store text. MUTATION: drop text() and the newline survives.
    $owner = rlUser('owner');
    $m = rlUser('support');
    test()->actingAs($owner, 'admin')->putJson("/admin-api/roles/members/{$m->id}", ['role_title' => "Head\nof <b>SEO</b>"])->assertOk();
    expect($m->fresh()->role_title)->toBe('Head of <b>SEO</b>');

    test()->actingAs($owner, 'admin')->postJson('/admin-api/roles', ['name' => 'Store manager', 'from' => rlRole('store-manager')->id])
        ->assertStatus(422)->assertJsonPath('error', 'name_taken');
});

it('never returns a password hash or remember token from the members list', function () {
    $owner = rlUser('owner');
    $body = test()->actingAs($owner, 'admin')->getJson('/admin-api/roles/members')->assertOk()->getContent();
    expect($body)->not->toContain('password')->not->toContain('remember_token')->not->toContain('$2y$');
});

/* ============================================ 10. the migration, both ways */

it('rolls the migration back and forward again on this database', function () {
    /*
     * A package can be rolled back from Core Updates. MUTATION: drop the
     * dropIndex() in down() and SQLite refuses to drop role_id.
     */
    $m = require base_path('database/migrations/2027_07_31_100000_create_admin_roles.php');
    $m->down();
    expect(Schema::hasTable('admin_roles'))->toBeFalse()->and(Schema::hasColumn('admin_users', 'role_id'))->toBeFalse();
    $u = rlUser('manager');
    expect(AdminRoles::can($u, 'orders.money'))->toBeTrue('a manager lost access with the roles tables gone');

    $m->up();
    $m->up();   // twice: a half-applied run finishes rather than failing
    expect(AdminRole::query()->count())->toBe(8)->and(Schema::hasColumn('admin_users', 'revokes'))->toBeTrue();
});
