<?php

declare(strict_types=1);

use App\Services\ImportConsole\ImportWorkspace;

/**
 * The last shared state between two concurrent runs: the directories the
 * application WRITES to.
 *
 * The previous lane gave each run its own database, its own compiled views and
 * its own package manifest, and demonstrated the result: zero database
 * failures, and eleven filesystem ones. All eleven came from one cause. Two
 * tests empty a shared directory on purpose, because a backfill or an import
 * can only be asserted against a directory whose contents the test put there:
 *
 *     AdminImportScreenTest.php:53,57   purges storage/app/import,
 *                                       in beforeEach AND in afterEach
 *     MediaLibraryTest.php:74-90        empties public/uploads
 *
 * Neither is wrong about its own run. Both are fatal to anybody else's: the
 * other run's CSV is deleted between the upload and the read, and what it
 * reports is
 *
 *     fopen(.../storage/app/import/woo/customers.csv):
 *     Failed to open stream: No such file or directory
 *
 * in a test that never mentions files.
 *
 * WHAT THESE TESTS ARE, AND WHY THEY ARE NOT "the variable is set". A test that
 * reads back the environment variable tests/bootstrap.php just wrote proves
 * only that the assignment happened. The property that matters is the one the
 * other run exercises, so that is what is performed here: this test PERFORMS
 * the purge, against the shared literal path that another process resolves,
 * and then asks whether this run's own fixture survived it. A decoy is placed
 * in the shared directory first and asserted to be gone, so the test cannot
 * pass by the purge quietly doing nothing.
 *
 * Without the per-process roots these two fail outright, because
 * storage_path('app/import') and base_path('storage/app/import') are then the
 * same directory and the purge takes the file it was pointed away from.
 *
 * @see tests/bootstrap.php — the roots themselves, and why they are roots
 *      rather than an overridable root threaded through ImportWorkspace
 */

/** Delete a directory tree, exactly as the two tests under discussion do. */
function wriPurge(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir.'/'.$entry;

        is_dir($path) && ! is_link($path) ? wriPurge($path) : @unlink($path);
    }

    @rmdir($dir);
}

/* ------------------------------------------------ the two live collisions -- */

it('survives a concurrent run purging the shared import directory', function () {
    $workspace = new ImportWorkspace;

    // This run's uploaded CSV, written where the application writes it. Nothing
    // here simulates the upload path -- ImportWorkspace is what chooses it.
    $mine = $workspace->path('customers');
    file_put_contents($mine, "customer_id,email\n1,buyer@example.test\n");

    expect(is_file($mine))->toBeTrue('the fixture was never written: '.$mine);

    /*
     * The other run. Its storage_path('app/import') is resolved from ITS
     * process, and absent a per-process root that is this literal -- the
     * checkout's own storage directory.
     */
    $shared = base_path('storage/app/import');
    $decoy = $shared.'/woo/decoy.csv';

    @mkdir(dirname($decoy), 0o755, true);
    file_put_contents($decoy, "term_id,name\n1,Decoy\n");

    wriPurge($shared);

    // The purge was real. Without this the test would pass against a purge that
    // silently did nothing, which is the failure mode it is most exposed to.
    expect(is_file($decoy))->toBeFalse('the purge did not run, so this test proves nothing');

    // THE ASSERTION.
    expect(is_file($mine))->toBeTrue(
        'a concurrent run purging '.$shared.' deleted this run\'s uploaded CSV at '.$mine
    );

    @unlink($mine);
});

it('survives a concurrent run emptying the shared uploads directory', function () {
    $mine = public_path('uploads/products/lane-ck-fixture.png');

    @mkdir(dirname($mine), 0o755, true);
    file_put_contents($mine, 'not really a png');

    expect(is_file($mine))->toBeTrue('the fixture was never written: '.$mine);

    $shared = base_path('public/uploads');
    $decoy = $shared.'/products/decoy.png';

    @mkdir(dirname($decoy), 0o755, true);
    file_put_contents($decoy, 'decoy');

    wriPurge($shared);

    expect(is_file($decoy))->toBeFalse('the purge did not run, so this test proves nothing');

    expect(is_file($mine))->toBeTrue(
        'a concurrent run emptying '.$shared.' deleted this run\'s upload at '.$mine
    );

    @unlink($mine);
});

/* ------------------------------------------- the roots, stated directly -- */

it('writes into roots named for this process', function () {
    foreach ([
        'storage' => [storage_path(), base_path('storage')],
        'public' => [public_path(), base_path('public')],
    ] as $what => [$mine, $shared]) {
        expect(realpath($mine) ?: $mine)->not->toBe(
            realpath($shared) ?: $shared,
            "the {$what} root is the checkout's own, which every other process also writes to"
        );

        $owned = str_contains((string) $mine, '-'.getmypid().'-');

        expect($owned)->toBeTrue("the {$what} root is not named for this process: {$mine}");

        expect(is_dir($mine))->toBeTrue("the {$what} root does not exist: {$mine}")
            ->and(is_writable($mine))->toBeTrue("the {$what} root is not writable: {$mine}");
    }
});

it('leaves nothing of its own in the checkout it was run from', function () {
    // Give the application something to write, through the real service.
    $workspace = new ImportWorkspace;
    file_put_contents($workspace->path('brands'), "term_id,name,slug\n1,COSRX,cosrx\n");

    $this->get('/')->assertOk();

    /*
     * The checkout's own writable directories. A run that leaves files in these
     * is a run another lane has to clean up after, and -- for public/uploads in
     * particular -- one whose leftovers the NEXT run's backfill test will count
     * as its own.
     */
    foreach ([
        'storage/app/import',
        'public/uploads',
    ] as $relative) {
        $shared = base_path($relative);

        expect(is_dir($shared))->toBeFalse(
            'this run wrote into the checkout at '.$shared.', which every concurrent run shares'
        );
    }

    @unlink($workspace->path('brands'));
});

/* --------------------------------------------- what must NOT have moved -- */

/**
 * Relocating a root is only correct if the tracked content under it still reads
 * identically. Two directories are tracked content rather than scratch, and
 * both are linked back rather than copied -- a copy is a second version of a
 * file git knows about, which is the mistake relocate_public_assets exists to
 * undo.
 */
it('still reads the checkout\'s own tracked content through the moved roots', function () {
    foreach ([
        'public/build/manifest.json' => public_path('build/manifest.json'),
        'storage/catalog/products.json' => storage_path('catalog/products.json'),
    ] as $relative => $through) {
        $tracked = base_path($relative);

        if (! is_file($tracked)) {
            continue;
        }

        expect(is_file($through))->toBeTrue(
            $relative.' is not reachable through the moved root at '.$through
        );

        expect(md5_file($through))->toBe(
            md5_file($tracked),
            $relative.' reads differently through the moved root, so it is a copy and not the original'
        );
    }
});

/**
 * @vite resolves public_path('build/manifest.json'), so a public root without
 * it is not a flaky failure, it is every storefront page in the suite at once.
 * Rendering one is the honest way to assert the link works.
 */
it('renders a page that resolves its assets through the moved public root', function () {
    $this->get('/')->assertOk();

    expect(is_file(public_path('build/manifest.json')))->toBeTrue(
        'the compiled asset manifest is not reachable at '.public_path('build/manifest.json')
    );
});

/* ------------------------------------ the property the move must preserve -- */

/**
 * AdminImportScreenTest:313 and :318 assert that an uploaded file lands at the
 * single literal destination the code chose, whatever the uploaded filename
 * claimed -- an importer that writes outside its workspace being the bug those
 * assertions exist to catch. That test states the destination as the literal
 * storage_path('app/import/woo/brands.csv'), and it still does: the root moved
 * underneath it and the spelling did not change.
 *
 * Restated here as the property rather than the path, so that it is pinned
 * independently of how storage_path() happens to resolve.
 */
it('keeps the import workspace a single directory underneath storage, outside the web root', function () {
    $workspace = new ImportWorkspace;
    $directory = $workspace->directory();

    // One directory, under storage/app, chosen by this code.
    expect($directory)->toBe(storage_path('app/import/woo'));

    // Every entity lands directly in it -- no entity can name its own parent.
    foreach (ImportWorkspace::entities() as $entity) {
        $path = $workspace->path($entity);

        expect(dirname($path))->toBe(
            $directory,
            'the workspace path for '.$entity.' leaves the workspace: '.$path
        );

        $escapes = str_contains($path, '..');

        expect($escapes)->toBeFalse('the workspace path for '.$entity.' is a traversal: '.$path);
    }

    /*
     * And it has no URL. The property is structural rather than a rule:
     * bootstrap/app.php points the public path at a different directory from
     * the application root, so nothing under storage/ is servable. That has to
     * remain true of the moved roots, or an uploaded customer list becomes a
     * download.
     */
    $inWebRoot = str_starts_with(
        realpath($directory) ?: $directory,
        realpath(public_path()) ?: public_path()
    );

    expect($inWebRoot)->toBeFalse('the import workspace is inside the web root: '.$directory);
});
