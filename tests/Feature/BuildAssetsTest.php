<?php

/*
 * A migration in this project relocates assets and removes public/build as a
 * side effect. Any `git add -A` after running migrations therefore stages the
 * deletion of every compiled asset, and it has happened: the 2.60.109 bump
 * committed exactly that, which silently dropped the rebuilt bundle carrying
 * the quick-view handler out of the package.
 *
 * These assert against git's tracked content rather than the working tree, for
 * two reasons: the working tree is unreliable here precisely because running
 * this suite deletes those files, and what ships is what is committed. A
 * package is built from `git show`, so committed content is the thing that
 * matters.
 */

function tracked(string $path): ?string
{
    $out = shell_exec('git -C '.escapeshellarg(base_path()).' show HEAD:'.escapeshellarg($path).' 2>/dev/null');

    return ($out === null || $out === '') ? null : $out;
}

it('has every asset the Vite manifest references committed', function () {
    $manifestRaw = tracked('public/build/manifest.json');

    expect($manifestRaw)->not->toBeNull('public/build/manifest.json is not committed — assets were probably deleted by a migration and staged with git add -A.');

    $manifest = json_decode((string) $manifestRaw, true);
    expect($manifest)->toBeArray()->not->toBeEmpty();

    $missing = [];
    foreach ($manifest as $entry) {
        foreach (array_merge([$entry['file'] ?? null], $entry['css'] ?? []) as $file) {
            if ($file !== null && tracked('public/build/'.$file) === null) {
                $missing[] = $file;
            }
        }
    }

    expect($missing)->toBe([], 'Assets referenced by the manifest are not committed: '.implode(', ', $missing));
});

it('ships a bundle containing the quick-view handler', function () {
    // Quick view existed for months with a button, a modal, CSS, a route and a
    // controller, and nothing in the shipped JavaScript listening for it. The
    // handler living in resources/js is not enough: public/build is what the
    // server serves, so the built bundle is what has to carry it.
    $files = preg_split('/\R/', (string) shell_exec(
        'git -C '.escapeshellarg(base_path()).' ls-files public/build/assets 2>/dev/null'
    )) ?: [];

    $found = false;
    foreach ($files as $file) {
        if ($file !== '' && str_ends_with($file, '.js') && str_contains((string) tracked($file), 'data-kbb-qv')) {
            $found = true;
            break;
        }
    }

    expect($found)->toBeTrue('No committed bundle contains the quick-view handler — resources/js changed without rebuilding public/build.');
});
