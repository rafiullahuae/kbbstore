<?php

declare(strict_types=1);

/**
 * =============================================================================
 * A STYLESHEET EDIT THAT WAS NEVER BUILT IS AN EDIT THAT DOES NOT EXIST
 * =============================================================================
 *
 * `resources/css/kbb/*.css` are Vite SOURCES. The page reaches them through
 * @vite(), which resolves `public/build/manifest.json` and serves the hashed
 * file it names. Nothing serves the source. `package.json` defines no `build`
 * script, so the build is a hand-typed `npx vite build` that is easy to skip —
 * and skipping it costs nothing locally and everything on the server, because
 * the change is real in the repo, real in the diff, real in the package, and
 * absent from the site.
 *
 * It has already cost a round here: a header control was wired end to end in
 * the source, shipped, and moved nothing, because the bundle the shop was
 * actually loading predated it.
 *
 * ── WHAT THIS CAN CHECK, AND WHAT IT CANNOT ─────────────────────────────────
 *
 * The built file is minified, so it cannot be diffed against its source. But
 * CSS CUSTOM PROPERTY NAMES survive minification untouched: a minifier may not
 * rename them, because the only thing that resolves `var(--x)` is the literal
 * string `--x` at run time. So the set of property names declared across the
 * sources must equal the set declared across the bundles, exactly.
 *
 * That is not every possible staleness — a changed colour slips through — but
 * it is the shape this project's stale builds actually take, because a new
 * control in this admin is a new custom property nine times out of ten.
 *
 * Both directions matter. A name in the source and not the bundle is an
 * unbuilt edit. A name in the bundle and not the source is a rule that was
 * deleted and is still being served, which is how a removed control keeps
 * working on the site and nowhere else.
 *
 * MUTATION: add `--kbb-not-built: 1;` to any file under resources/css/kbb.
 * Red until the bundle is rebuilt.
 */

/** Every custom property NAME declared in a set of stylesheets. */
function cssDeclaredProps(array $files): array
{
    $names = [];

    foreach ($files as $file) {
        preg_match_all('/(--[a-zA-Z][\w-]*)\s*:/', (string) file_get_contents($file), $m);
        $names = array_merge($names, $m[1]);
    }

    $names = array_values(array_unique($names));
    sort($names);

    return $names;
}

it('serves a bundle built from the stylesheets in this commit', function () {
    $sources = glob(base_path('resources/css/kbb/*.css')) ?: [];
    $bundles = glob(base_path('public/build/assets/*.css')) ?: [];

    expect($sources)->not->toBeEmpty('the kbb stylesheet sources have moved; re-point this test');
    expect($bundles)->not->toBeEmpty(
        'public/build carries no CSS at all. A migration in this project removes public/build as a '
        .'side effect and `git add -A` then stages the deletion — see BuildAssetsTest'
    );

    $declared = cssDeclaredProps($sources);
    $shipped = cssDeclaredProps($bundles);

    $unbuilt = array_values(array_diff($declared, $shipped));
    $orphaned = array_values(array_diff($shipped, $declared));

    expect($unbuilt)->toBe(
        [],
        'these custom properties are declared in resources/css/kbb and are NOT in the built bundle, '
        .'so whatever they drive is dead on the site: ' . implode(', ', $unbuilt)
        . ' — run `npx vite build` and commit public/build'
    );

    expect($orphaned)->toBe(
        [],
        'the built bundle still declares custom properties the sources no longer do, so the site is '
        .'being served CSS that was deleted here: ' . implode(', ', $orphaned)
        . ' — run `npx vite build` and commit public/build'
    );
});
