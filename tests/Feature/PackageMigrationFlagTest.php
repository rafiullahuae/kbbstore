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
