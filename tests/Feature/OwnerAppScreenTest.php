<?php

declare(strict_types=1);

/*
 * App → Owner App (Lane OA4). The owner: "keep controls under App (Main menu)
 * > Site App / Owner App".
 *
 * Pins the FINISHED state (CLAUDE.md): docs/oa4-wiring.json applied to the
 * console — in memory here, so the test is the same before and after the
 * integrator runs tools/oa4-wire.php — gives exactly one row, one title, one
 * deep-link entry and one include; the screen MOUNTS the existing panels
 * rather than copying them; Users & Roles keeps a one-line pointer.
 */

use App\Models\AdminUser;
use Tests\Support\OwnerAppRoutes as OA;

/** app.blade.php (or another file) with the wiring record applied, as tools/oa4-wire.php would. */
function oa4Wired(string $file): string
{
    $src = (string) file_get_contents(base_path($file));
    foreach (json_decode((string) file_get_contents(base_path('docs/oa4-wiring.json')), true, 512, JSON_THROW_ON_ERROR) as $e) {
        if ($e['file'] !== $file || str_contains($src, $e['replacement'])) {
            continue;
        }
        expect(substr_count($src, $e['anchor']))->toBe($e['count'], "block {$e['n']}: {$e['what']}");
        $src = str_replace($e['anchor'], $e['replacement'], $src);
    }

    return $src;
}

it('wires App → Owner App exactly once: row, title, deep link and the include', function () {
    // DEFECT: "built, never wired up" (zero) or a row registered twice (two).
    // MUTATION: delete block 4 from docs/oa4-wiring.json and the include count is 0.
    $app = oa4Wired('resources/views/admin/app.blade.php');
    expect(substr_count($app, "@include('admin.partials.owner-app-screen')"))->toBe(1)
        ->and(substr_count($app, "{screen:'ownerapp',label:'Owner App',group:'App'"))->toBe(1)
        ->and(substr_count($app, "'ownerapp':['App','Owner App']"))->toBe(1)
        ->and(substr_count($app, "'pagination','ownerapp','banners',"))->toBe(1);

    // After the roles screen, whose owner-app-access partial defines window.kbbOwnerAppAdmin.
    expect(strpos($app, "@include('admin.partials.owner-app-screen')"))->toBeGreaterThan((int) strpos($app, "@include('admin.partials.admin-roles-screen')"));
});

it('mounts the existing panels on its own screen instead of copying them, and leaves Users & Roles a pointer', function () {
    // DEFECT: a second copy of the access screen that drifts from the first.
    // MUTATION: paste owner-app-access's markup into the screen partial.
    $screen = (string) file_get_contents(resource_path('views/admin/partials/owner-app-screen.blade.php'));
    expect($screen)->toContain("screen: SCREEN,\n      label: 'Owner App',")
        ->toContain("group: 'App',")
        ->toContain("var SCREEN = 'ownerapp';")
        ->toContain("mount.call(base, host.querySelector('[data-oa-admin]'));")
        ->toContain('Owner app settings moved to <b>App → Owner App</b>')
        ->toContain("el.closest('[data-rl-screen]')")
        ->not->toContain('data-set="idle_hours"')->not->toContain('fetch(');

    // The access panel and the customise card are each still included exactly once, from one place.
    $access = (string) file_get_contents(resource_path('views/admin/partials/owner-app-access.blade.php'));
    expect(substr_count($access, "@include('admin.partials.owner-app-customise')"))->toBe(1);
    $roles = (string) file_get_contents(resource_path('views/admin/partials/admin-roles-screen.blade.php'));
    expect(substr_count($roles, "@include('admin.partials.owner-app-access')"))->toBe(1);
});

it('keeps every endpoint behind ownerapp.manage: a Full Admin in, a manager out', function () {
    OA::wire($this->app);
    $owner = OA::admin();
    $manager = AdminUser::query()->create(['name' => 'M', 'email' => 'm2@example.com', 'password' => 'secret-password', 'role' => 'manager']);
    foreach (['/admin-api/owner-app', '/admin-api/owner-app/ui'] as $path) {
        $this->actingAs($manager, 'admin')->getJson($path)->assertForbidden();
        $this->actingAs($owner, 'admin')->getJson($path)->assertOk();
    }
});
