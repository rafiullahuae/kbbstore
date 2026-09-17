<?php

declare(strict_types=1);

/**
 * `php artisan migrate` must not delete the repository's own build assets.
 *
 * 2026_08_28_183000_relocate_public_assets moves base_path('public/build') into
 * the configured public path and then DELETES the source, because on the
 * production host that source is a stray copy the updater unpacked under the
 * app root and leaving it there invites somebody to edit the wrong one.
 *
 * In a checkout the same directory is the opposite thing: 37 tracked files. So
 * any `php artisan migrate` whose KBB_PUBLIC_PATH is not the repo's own
 * public/ removed them from the working copy. Three lanes lost the directory
 * that way, and two tests did it on every run without anybody choosing to:
 * PaymentsGatewayTabsTest and AdminMobileOverflowTest boot a preview whose app
 * root is a symlink to base_path() and whose KBB_PUBLIC_PATH is a temporary
 * directory, then rm -rf that directory afterwards — so `KBB_BROWSER_TESTS=1
 * vendor/bin/pest` deleted tracked files and took the only copy with it.
 *
 * It was documented in both of those files. Documentation is not a guard, and
 * the comment in PaymentsGatewayTabsTest had already been rewritten to describe
 * the loss as normal ("guarded on the source existing, and it often does not").
 * This file is the guard, in two halves: the deletion cannot happen in a
 * checkout, and it still happens where it is meant to.
 */

use Illuminate\Support\Facades\File;
use Tests\Support\CompiledCaches;

/**
 * The migration under test, loaded the way the other migration tests load one.
 *
 * Resolved through database_path() and therefore through base_path(), so the
 * two sandbox tests below have to call this BEFORE they re-point the app root
 * — afterwards it would look for the file inside the sandbox.
 */
function relocateAssetsMigration(): object
{
    return require database_path('migrations/2026_08_28_183000_relocate_public_assets.php');
}

/** Absolute paths of every file under a directory, relative names, sorted. */
function treeListing(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }

    $found = [];

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($items as $item) {
        if ($item->isFile()) {
            $found[] = substr($item->getPathname(), strlen($dir) + 1);
        }
    }

    sort($found);

    return $found;
}

/* ------------------------------------------------------------ the incident -- */

it('leaves the repository build assets alone when migrate runs against another public path', function () {
    $build = base_path('public/build');

    if (! is_dir($build)) {
        test()->markTestSkipped('public/build is not present in this checkout, so there is nothing to lose.');
    }

    $before = treeListing($build);

    expect($before)->not->toBeEmpty();

    /*
     * A safety net, not part of the assertion. If the guard ever regresses this
     * test has to FAIL, not quietly take the working copy's tracked files with
     * it — the whole complaint is that a test run deletes them.
     */
    $rescue = storage_path('framework/testing/lane-ce-build-rescue-'.getmypid());

    File::deleteDirectory($rescue);
    File::copyDirectory($build, $rescue);

    $dir = storage_path('framework/testing/lane-ce-relocate-'.getmypid());
    $root = $dir.'/webroot';
    $db = $dir.'/preview.sqlite';

    File::deleteDirectory($dir);
    File::makeDirectory($root, 0o755, true, true);
    touch($db);

    try {
        /*
         * The preview boots' own command, reduced to the part that does the
         * damage: this checkout's artisan, this checkout as the app root, and a
         * throwaway KBB_PUBLIC_PATH. A subprocess rather than an in-process
         * Artisan::call, because that is the shape the incident has and because
         * it proves the guard holds for a plain `php artisan migrate` typed by
         * hand, which is how the other three lanes lost the directory.
         */
        $env = [
            'KBB_PUBLIC_PATH' => $root,
            'APP_ENV' => 'local',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $db,
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'APP_KEY' => (string) config('app.key'),
        ];

        /*
         * A compiled-cache directory of its own, for the same reason the
         * browser previews get one: a shell env prefix ADDS to the inherited
         * environment, so without this the migrate below follows the suite's
         * APP_CONFIG_CACHE and its warm_caches_2_60_4 overwrites the suite's
         * compiled config with this sandbox's settings. That would be read back
         * by the suite at its next boot -- an sqlite sandbox deciding what the
         * run connects to, which is the failure Tests\Support\CompiledCaches
         * exists to prevent.
         */
        $env += CompiledCaches::environmentFor($dir.'/compiled');

        $prefix = '';

        foreach ($env as $key => $value) {
            $prefix .= $key.'='.escapeshellarg($value).' ';
        }

        exec($prefix.'php '.escapeshellarg(base_path('artisan')).' migrate --force 2>&1', $out, $code);

        expect($code)->toBe(0, "the preview migrate failed:\n".implode("\n", array_slice($out, -20)));

        // THE ASSERTION. Every file that was there is still there.
        $after = treeListing($build);

        $lost = array_values(array_diff($before, $after));

        expect($lost)->toBe([], 'migrate deleted tracked files from public/build: '.implode(', ', $lost));

        // And it did not merely leave them behind while copying them out --
        // nothing of the repo's should have been written into the throwaway
        // web root either, because in a checkout there is nothing misplaced.
        expect(treeListing($root.'/build'))->toBe([]);
    } finally {
        /*
         * Put back anything a regression removed, so a failing run reports the
         * failure instead of becoming it.
         *
         * Only when something is actually missing: public/build is shared with
         * every other process working in this checkout, and rewriting 37 files
         * under another run's feet to prove a point they already agree on is
         * not worth the risk of a half-read file.
         */
        if (treeListing($build) !== $before) {
            if (! is_dir($build)) {
                File::makeDirectory($build, 0o755, true, true);
            }

            File::copyDirectory($rescue, $build);
        }

        File::deleteDirectory($rescue);
        File::deleteDirectory($dir);
    }
});

it('is armed in this checkout', function () {
    /*
     * The guard's whole discriminator: the site does not deploy from git, so
     * .git under the app root means a working copy and never the host. In a
     * worktree — which is where several lanes work — .git is a FILE, so this is
     * the assertion that the is_dir/file_exists distinction is the right way
     * round.
     */
    $marker = base_path('.git');

    expect(file_exists($marker))->toBeTrue(
        'base_path(".git") does not exist, so the relocation guard cannot tell this checkout from the production host'
    );
});

/* ------------------------------------------------ and it still does its job -- */

it('still relocates a stray build directory when the app root is not a checkout', function () {
    /*
     * The other half. A guard that switched the migration off everywhere would
     * pass the test above and break the thing the migration exists for, so the
     * production behaviour is exercised here against a throwaway app root with
     * no .git in it.
     */
    $sandbox = storage_path('framework/testing/lane-ce-sandbox-'.getmypid());

    File::deleteDirectory($sandbox);
    File::makeDirectory($sandbox.'/public/build/assets', 0o755, true, true);
    File::makeDirectory($sandbox.'/webroot', 0o755, true, true);

    file_put_contents($sandbox.'/public/build/manifest.json', '{"stray":true}');
    file_put_contents($sandbox.'/public/build/assets/app-strayhash.js', 'console.log(1)');

    $migration = relocateAssetsMigration();

    $originalBase = $this->app->basePath();
    $originalPublic = $this->app->publicPath();

    try {
        $this->app->setBasePath($sandbox);
        $this->app->usePublicPath($sandbox.'/webroot');

        $migration->up();

        // Moved, with the tree shape preserved...
        expect(treeListing($sandbox.'/webroot/build'))
            ->toBe(['assets/app-strayhash.js', 'manifest.json']);

        // ...and the stray copy removed, which is the point of the migration:
        // two build directories on the host is how the wrong one gets edited.
        expect(is_dir($sandbox.'/public/build'))->toBeFalse();
    } finally {
        $this->app->setBasePath($originalBase);
        $this->app->usePublicPath($originalPublic);
        File::deleteDirectory($sandbox);
    }

    // The paths really are back, or every test after this one is measuring the
    // sandbox.
    expect(base_path())->toBe($originalBase)
        ->and(public_path())->toBe($originalPublic);
});

it('does not relocate when the app root is a checkout, however the public path is set', function () {
    $sandbox = storage_path('framework/testing/lane-ce-checkout-'.getmypid());

    File::deleteDirectory($sandbox);
    File::makeDirectory($sandbox.'/public/build', 0o755, true, true);
    File::makeDirectory($sandbox.'/webroot', 0o755, true, true);

    file_put_contents($sandbox.'/public/build/manifest.json', '{"tracked":true}');

    // The one difference from the test above. In a real worktree this is a
    // file rather than a directory; use the harder of the two cases.
    file_put_contents($sandbox.'/.git', "gitdir: /somewhere/else\n");

    $migration = relocateAssetsMigration();

    $originalBase = $this->app->basePath();
    $originalPublic = $this->app->publicPath();

    try {
        $this->app->setBasePath($sandbox);
        $this->app->usePublicPath($sandbox.'/webroot');

        $migration->up();

        expect(treeListing($sandbox.'/public/build'))->toBe(['manifest.json'])
            ->and(treeListing($sandbox.'/webroot/build'))->toBe([]);
    } finally {
        $this->app->setBasePath($originalBase);
        $this->app->usePublicPath($originalPublic);
        File::deleteDirectory($sandbox);
    }
});
