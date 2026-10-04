<?php

declare(strict_types=1);

use App\Services\Update\ClassDependencyScan;
use Symfony\Component\Finder\Finder;

/*
 * Every shipped PHP file passes the updater's own class-dependency scan.
 *                                                                (2.60.374)
 *
 * THE DEFECT: app/Services/Mail/Kit/KitSamples.php imported a NAMESPACE
 * (`use App\Mail;` then `new Mail\OrderConfirmation`). That is valid PHP, but
 * UpdatePackage::verify() runs ClassDependencyScan, which read `App\Mail` as a
 * class that exists nowhere, and refused the whole 2.60.374 package -- found at
 * the build, after the full suite had passed, because no test ever ran the scan
 * over the code. The owner would have seen "references App\Mail, which is
 * neither in this package nor installed on this server" on Core Updates.
 *
 * So the scan runs here over every file a package can carry, against this
 * checkout: anything it cannot resolve here, it cannot resolve on the server.
 *
 * MUTATION (RUN): put `use App\Mail;` back in KitSamples.php and this is red
 * naming that file.
 */
it('finds nothing the updater would refuse in any shipped PHP file', function () {
    $files = [];

    foreach ((new Finder)->files()->in([base_path('app'), base_path('routes'), base_path('database/migrations')])->name('*.php') as $f) {
        // relative path => absolute path, the shape UpdatePackage hands it.
        $files[ltrim(str_replace(base_path(), '', $f->getRealPath()), '/')] = $f->getRealPath();
    }

    $result = (new ClassDependencyScan(base_path()))->run($files);

    expect(array_map(fn ($g) => $g['referenced_by'].' -> '.$g['class'], $result['missing']))->toBe([]);
});
