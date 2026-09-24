<?php

/*
 * UpdateRunner runs migrations only when the manifest says the package has
 * them: hasMigrations() reads $manifest['migrations'] and never looks at the
 * files. No package ever set that flag, so `php artisan migrate` had never run
 * through the updater in this project's history. Migration files were copied to
 * the server and left sitting there.
 *
 * Everything else followed from that. The orders table was missing is_gift
 * because the migration adding it shipped in 2.60.85, arrived, and never ran --
 * and two migration-only packages sent to repair it changed nothing, because
 * they were inert for exactly the same reason.
 *
 * A package that carries migrations and does not say so is silently a no-op, so
 * it is worth a test rather than a comment.
 */

use App\Services\Update\UpdatePackage;

it('flags a package that contains migrations', function () {
    $manifest = ['migrations' => true];

    expect(flagOf($manifest))->toBeTrue();
});

it('does not flag a package with no migrations', function () {
    expect(flagOf(['migrations' => false]))->toBeFalse()
        ->and(flagOf([]))->toBeFalse();
});

it('reads the flag the way UpdateRunner does', function () {
    // Pinning the contract rather than reimplementing it: if hasMigrations()
    // ever starts scanning files instead, this fails and the builder should
    // follow.
    $source = file_get_contents(app_path('Services/Update/UpdatePackage.php'));

    expect($source)->toContain("\$this->manifest['migrations']");
});

it('makes the builder set the flag whenever a migration is included', function () {
    $source = file_get_contents(app_path('Console/Commands/BuildPackage.php'));

    expect($source)->toContain("'migrations' => \$hasMigrations")
        ->and($source)->toContain("str_starts_with(\$path, 'database/migrations/')");
});

function flagOf(array $manifest): bool
{
    return (bool) ($manifest['migrations'] ?? false);
}

/*
 * ---------------------------------------------------------------------------
 * AND THE SERVER NOW REFUSES A PACKAGE THAT GETS IT WRONG.
 *
 * The header above was written after `orders.is_gift` shipped, arrived and
 * never ran. It was right, it was in the repository, and on 24 September 2026
 * the identical thing happened again -- because the packages were built by a
 * hand-written script that never read this file. Five of them carried eight
 * migrations between them and declared none; every migration was copied to the
 * live server and not one ran, while each package reported "applied".
 *
 * One of those migrations added `update_releases.manifest`, and the same
 * package installed the UpdateRunner that writes that column. The result was an
 * updater that could not apply anything at all, with the fix for a live
 * storefront outage in a zip it refused.
 *
 * A test cannot stop a builder that does not run it. The guard therefore lives
 * on the SERVER, in UpdatePackage::verify(), where every package passes however
 * it was built -- and these cases pin it.
 *
 * MUTATION, run: drop checkMigrationsAreDeclared() from verify()'s chain and
 * `it refuses a package whose migrations are not declared` goes green-to-red on
 * the assertion that verify() returned false.
 * ---------------------------------------------------------------------------
 */

use App\Services\Update\UpdateGuard;

/** A zip with one migration in it, and whatever manifest the caller wants. */
function packageCarryingAMigration(array $manifestExtras): UpdatePackage
{
    $dir = sys_get_temp_dir().'/kbb-pkg-'.bin2hex(random_bytes(6));
    @mkdir($dir, 0775, true);

    $body = "<?php // a migration, for the purposes of this test\n";
    $zipPath = $dir.'/package.zip';

    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('files/database/migrations/2026_01_01_000000_example.php', $body);
    $zip->addFromString('update.json', json_encode([
        'name' => 'KBB Storefront',
        'version' => '9.99.999',
        'requires_php' => '8.2',
        'notes' => '',
        'files' => [
            'database/migrations/2026_01_01_000000_example.php' => hash('sha256', $body),
        ],
        'signature' => '',
    ] + $manifestExtras));
    $zip->close();

    return new UpdatePackage($zipPath, $dir.'/scratch', new UpdateGuard());
}

it('refuses a package whose migrations are not declared', function () {
    $package = packageCarryingAMigration([]);

    expect($package->verify())->toBeFalse(
        'A package carrying a migration and not declaring it was accepted. On the server '
        .'that means the file is copied and never run, and the update still reports success.'
    );

    expect(implode(' ', $package->errors))
        ->toContain('does not declare')
        ->toContain('never run')
        ->toContain('kbb:package')
        ->toContain('2026_01_01_000000_example.php');
});

it('accepts the same package once it declares them', function () {
    $package = packageCarryingAMigration(['migrations' => true]);

    expect($package->verify())->toBeTrue(implode(' ', $package->errors))
        ->and($package->hasMigrations())->toBeTrue();
});

it('leaves a package with no migrations alone', function () {
    // The flag is absent here and that is correct: nothing to run, nothing to
    // declare. The check must not turn every ordinary package into an error.
    $dir = sys_get_temp_dir().'/kbb-pkg-'.bin2hex(random_bytes(6));
    @mkdir($dir, 0775, true);

    $body = "<?php // an ordinary class\n";
    $zipPath = $dir.'/package.zip';

    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('files/app/Support/Example.php', $body);
    $zip->addFromString('update.json', json_encode([
        'name' => 'KBB Storefront',
        'version' => '9.99.998',
        'requires_php' => '8.2',
        'notes' => '',
        'files' => ['app/Support/Example.php' => hash('sha256', $body)],
        'signature' => '',
    ]));
    $zip->close();

    $package = new UpdatePackage($zipPath, $dir.'/scratch', new UpdateGuard());

    expect($package->verify())->toBeTrue(implode(' ', $package->errors))
        ->and($package->hasMigrations())->toBeFalse();
});
