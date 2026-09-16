<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Support\AdminCapabilities;

/**
 * The owner keeps the site, whatever the capability map says.
 *
 * This is the one property that cannot be got wrong. The host has no shell and
 * no database console — if a package shipped a map that refused the owner a
 * screen, there would be no way in to correct it. A mistake in the rules may
 * cost a manager a page; it must never cost the owner the site.
 *
 * Written from the integrator's side rather than the lane's, because "we are
 * confident it is safe" is not the same as "it is asserted". It checks the
 * property directly rather than by sampling routes: every capability the map
 * mentions anywhere, granted to the owner, with no reliance on the route list
 * being complete.
 */
it('grants the owner every capability the map knows about', function () {
    $all = array_keys(AdminCapabilities::CAPABILITIES);

    expect($all)->not->toBeEmpty('the capability map is empty, so this check proves nothing');

    $missing = array_values(array_filter(
        $all,
        static fn (string $capability): bool => ! AdminCapabilities::roleCan('owner', $capability)
    ));

    expect($missing)->toBe(
        [],
        'the owner is refused ' . count($missing) . ' capabilit' . (count($missing) === 1 ? 'y' : 'ies')
            . ' — on a host with no shell that is unrecoverable: ' . implode(', ', $missing)
    );
});

it('treats an account with no role as an owner, matching the column default', function () {
    /*
     * admin_users.role is NOT NULL DEFAULT 'owner', so every account already on
     * the live site is an owner and nobody loses anything on the day this
     * ships. A model instance that came back with a null role would be an
     * account that can reach nothing, while the same row reloaded next request
     * is an owner.
     */
    $user = AdminUser::create([
        'name' => 'Rescue',
        'email' => 'rescue-' . uniqid() . '@example.com',
        'password' => bcrypt('secret-secret'),
    ]);

    expect($user->role)->toBe('owner', 'a new admin account came back without the column default applied');
    expect($user->fresh()->role)->toBe('owner', 'the stored row is not an owner');
});

it('refuses an unknown role everything, rather than assuming the best', function () {
    // Fail closed. The opposite convention is what let a coupon rule treat
    // "I do not recognise this" as "no restriction" and ship that way.
    foreach (array_keys(AdminCapabilities::CAPABILITIES) as $capability) {
        expect(AdminCapabilities::roleCan('something-nobody-defined', $capability))
            ->toBeFalse("an unrecognised role was granted {$capability}");
    }
});
