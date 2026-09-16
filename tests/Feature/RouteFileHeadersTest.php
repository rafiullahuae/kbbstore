<?php

declare(strict_types=1);

/*
 * A route file's header is the first thing the next reader trusts.
 *
 * Every feature lane writes its routes in its own file, because CLAUDE.md
 * forbids a lane from editing routes/web.php, and heads the file "NOT LOADED
 * YET" so the integrator knows to mount it. The integrator then mounts it --
 * and nothing goes back to correct the header. Fourteen files were still
 * announcing themselves as unmounted while their routes served live traffic,
 * which is how a reader concludes a working endpoint is dead code and goes
 * looking for the bug somewhere else.
 *
 * This pins the invariant rather than those fourteen files: if routes/web.php
 * or routes/api.php requires a file, that file may not claim to be unmounted.
 */

it('never claims a route file is unmounted when it is required', function () {
    // Owned by a lane still in flight; this lane must not edit it.
    $laneOwned = ['catalog-product-create-admin.php'];

    $wiring = file_get_contents(base_path('routes/web.php'))
        . file_get_contents(base_path('routes/api.php'));

    $lying = [];

    foreach (glob(base_path('routes/*.php')) as $file) {
        $name = basename($file);

        if (in_array($name, $laneOwned, true)) {
            continue;
        }

        $body = (string) file_get_contents($file);

        $claimsUnmounted = str_contains($body, 'NOT LOADED YET')
            || str_contains($body, 'NOT WIRED YET');

        // How web.php and api.php mount a sibling: require __DIR__.'/<name>';
        $isRequired = str_contains($wiring, "/{$name}'");

        if ($claimsUnmounted && $isRequired) {
            $lying[] = $name;
        }
    }

    expect($lying)->toBe([]);
});
