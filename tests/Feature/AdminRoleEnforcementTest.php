<?php

declare(strict_types=1);

use App\Models\AdminUser;

/**
 * What `admin_users.role` actually buys you today: nothing.
 *
 * The column exists, AdminController validates it against a four-value
 * vocabulary (owner | manager | support | editor), the Users screen renders it,
 * and a last-owner guard stops the final owner being demoted or deleted. Every
 * one of those reads the column for its OWN CRUD. Nothing anywhere else in the
 * application consults it:
 *
 *     $ grep -rn "role" app/ --include=*.php
 *
 * returns AdminUser::$fillable, AdminController's own validators and last-owner
 * guard, and two SVG `role="img"` attributes. There is no app/Policies, no
 * Gate::define, no ->can(), no @can in the admin views and no role middleware —
 * app/Http/Middleware holds CheckRedirects, NoIndexStaging, NoStoreAdminApi and
 * SecurityHeaders, and that is the whole list.
 *
 * So `auth:admin` is the entire authorization model. Every back-office account
 * is an owner in all but the label, and the labels shown on the Users screen
 * describe an access-control system that was never built.
 *
 * THESE TESTS ARE WRITTEN TO PASS, and they are deliberately written the way
 * round that documents the present behaviour rather than asserting the
 * behaviour anyone would want. They are a tripwire, not a wish: the day a real
 * authorization layer lands, every one of them goes red, and the person who
 * built it gets a list of exactly the reaches that used to be open and a
 * pointer to the design note in the lane report. Writing them as
 * assertForbidden() today would just be a red suite describing a feature that
 * does not exist.
 *
 * Ranked by what the reach costs the owner, worst first.
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

it('lets the lowest role promote itself to owner', function () {
    $support = areUser('support');

    // There has to be a second owner, or the last-owner guard is what refuses
    // and the test would pass for the wrong reason.
    areUser('owner');

    test()->actingAs($support, 'admin')
        ->put('/admin-api/users/'.$support->id, ['role' => 'owner'])
        ->assertOk();

    expect($support->fresh()->role)->toBe('owner');
})->group('role-enforcement');

it('lets the lowest role delete another admin account', function () {
    $support = areUser('support');
    $victim = areUser('manager');

    areUser('owner');

    test()->actingAs($support, 'admin')
        ->delete('/admin-api/users/'.$victim->id)
        ->assertOk();

    expect(AdminUser::find($victim->id))->toBeNull();
})->group('role-enforcement');

it('lets the lowest role reset another admin password and create new accounts', function () {
    $support = areUser('support');
    $victim = areUser('owner');

    test()->actingAs($support, 'admin')
        ->put('/admin-api/users/'.$victim->id, ['password' => 'a-new-password'])
        ->assertOk();

    test()->actingAs($support, 'admin')
        ->post('/admin-api/users', [
            'name' => 'Back Door',
            'email' => 'are-backdoor-'.uniqid().'@example.test',
            'password' => 'secret-secret',
            'role' => 'owner',
        ])
        ->assertSuccessful();
})->group('role-enforcement');

/* ------------------------------------------------------- 2. money and data */

it('lets the lowest role reach the payment provider settings', function () {
    $support = areUser('support');

    test()->actingAs($support, 'admin')
        ->get('/admin-api/payments')
        ->assertOk();
})->group('role-enforcement');

it('lets the lowest role export the entire customer list', function () {
    $support = areUser('support');

    $response = test()->actingAs($support, 'admin')->get('/admin-api/customers/export');

    expect($response->getStatusCode())->toBe(200);
})->group('role-enforcement');

/* ---------------------------------------------------- 3. the column itself */

it('has no authorization layer reading the role column', function () {
    expect(is_dir(base_path('app/Policies')))->toBeFalse();

    $middleware = collect(glob(base_path('app/Http/Middleware/*.php')) ?: [])
        ->map(fn ($p) => basename($p, '.php'))
        ->sort()
        ->values()
        ->all();

    // If a role middleware is ever added, this list changes and the test asks
    // whoever added it to revisit the cases above.
    expect($middleware)->toBe([
        'CheckRedirects',
        'NoIndexStaging',
        'NoStoreAdminApi',
        'SecurityHeaders',
    ]);

    // No gate or policy registration anywhere in the application.
    $appSource = collect(
        iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())))
    )
        ->filter(fn ($f) => $f->isFile() && $f->getExtension() === 'php')
        ->map(fn ($f) => (string) file_get_contents($f->getPathname()))
        ->implode("\n");

    expect($appSource)->not->toContain('Gate::define')
        ->and($appSource)->not->toContain('->authorize(');
})->group('role-enforcement');
