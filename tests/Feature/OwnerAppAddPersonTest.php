<?php

declare(strict_types=1);

/*
 * App → Owner App → Team → "+ Add person".
 *
 * The owner, 6 October, on the moved screen: "there i can not see the users
 * list, neither i can assign or create new user access ... there should come
 * all users list, and add new too". Every account was listed, but there was no
 * way to make one from here: that lived only on Users & Roles, a screen away.
 * The form is the two endpoints that already exist, in order, each behind its
 * own capability — nothing new on the server to secure.
 */

use App\Models\AdminRole;
use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminRolesRoutes;
use Tests\Support\OwnerAppRoutes as OA;

beforeEach(function () {
    AdminRolesRoutes::wire(app());
    OA::wire($this->app);
});

it('makes the account, switches the app on with its PIN, and lists the person in the team', function () {
    // DEFECT: no way to give a NEW person the app from App → Owner App.
    $owner = OA::admin();
    $role = AdminRole::query()->where('slug', 'store-manager')->firstOrFail();

    $made = $this->actingAs($owner, 'admin')->postJson('/admin-api/roles/members', [
        'name' => 'Mona', 'email' => 'mona@example.test', 'password' => 'secret-secret', 'role_id' => $role->id,
    ])->assertOk();
    $id = (int) $made->json('id');

    $this->actingAs($owner, 'admin')->putJson("/admin-api/owner-app/members/{$id}", ['enabled' => true, 'pin' => '482615'])
        ->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('has_pin', true);

    $team = collect($this->actingAs($owner, 'admin')->getJson('/admin-api/owner-app')->assertOk()->json('members'));
    $mona = $team->firstWhere('email', 'mona@example.test');
    expect($mona)->not->toBeNull()
        ->and($mona['enabled'])->toBeTrue()
        ->and($mona['has_pin'])->toBeTrue()
        ->and($mona['role'])->toBe($role->name)
        ->and($team->pluck('email')->all())->toContain($owner->email);
});

it('refuses someone who does not manage people, at both steps', function () {
    // The form is only a front: the server says no, and nothing is made.
    $support = AdminUser::query()->create(['name' => 'S', 'email' => 's@example.test', 'password' => 'secret-secret', 'role' => 'support']);
    $role = AdminRole::query()->where('slug', 'store-manager')->firstOrFail();

    $this->actingAs($support, 'admin')->postJson('/admin-api/roles/members', [
        'name' => 'X', 'email' => 'x@example.test', 'password' => 'secret-secret', 'role_id' => $role->id,
    ])->assertForbidden();
    $this->actingAs($support, 'admin')->putJson('/admin-api/owner-app/members/'.$support->id, ['enabled' => true, 'pin' => '482615'])
        ->assertForbidden();

    expect(AdminUser::query()->where('email', 'x@example.test')->exists())->toBeFalse()
        ->and(DB::table('owner_app_members')->count())->toBe(0);
});

it('draws the team count and the Add person form from the screen, in that order of calls', function () {
    /* MUTATION: drop the '!/roles/members' call or the enabled:true PUT -> red. */
    $src = (string) file_get_contents(resource_path('views/admin/partials/owner-app-access.blade.php'));
    expect($src)->toContain("data-oa=\"add\">+ Add person</button>")
        ->toContain("api('POST', '!/roles/members', { name: f.name, email: f.email, password: f.password, role_id: parseInt(f.role_id, 10) })")
        ->toContain("api('PUT', '/members/' + r.data.id, { enabled: true, pin: f.pin })")
        ->toContain("api('GET', '!/roles')")
        // The roles list is fetched when the form opens, never on load.
        ->toContain('<option value="">Choose a role</option>')
        ->toContain("' people') + ', ' + on + ' on the app</small>")
        // A password or PIN is never kept in a variable between paints.
        ->not->toContain("addVals.password")->not->toContain("addVals.pin");

    // Only the owner-app prefix or an explicit '!' admin-api path: no other host or base.
    expect(substr_count($src, "'/admin-api' + path.slice(1)"))->toBe(1);
});
