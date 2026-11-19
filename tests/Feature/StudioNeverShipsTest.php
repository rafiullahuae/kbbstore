<?php

/*
 * KBB Studio (studio/) is the owner's offline Mac video / ad tool, a SEPARATE project from the shop. The owner,
 * 19 Nov 2026: "always keep the kbb studio and the kbeautybliss.com completely separate". It ships as its own zip
 * (studio/reel-studio/tools/package-mac.sh) and installs in the studio's own Configure → Updates; a website package
 * must never carry it. UpdateGuard would refuse a website package holding studio/ files ("Path outside the permitted
 * areas") and with it every shop fix in the same zip, so `kbb:package` leaves studio/ out in the first place.
 *
 * Mutation note: take 'studio/' out of BuildPackage::NEVER_SHIP and the first case is red; give UpdateGuard a
 * studio/ prefix and the second is.
 */

use App\Console\Commands\BuildPackage;
use App\Services\Update\UpdateGuard;

it('never puts KBB Studio into a website package', function () {
    $neverShip = (new ReflectionClassConstant(BuildPackage::class, 'NEVER_SHIP'))->getValue();

    expect($neverShip)->toContain('studio/');
});

it('keeps studio/ outside every path the website updater may write', function () {
    $allowed = (new ReflectionClassConstant(UpdateGuard::class, 'ALLOWED_PREFIXES'))->getValue();

    foreach ($allowed as $prefix) {
        expect(str_starts_with('studio/reel-studio/app/core/config.js', $prefix))->toBeFalse();
    }
});
