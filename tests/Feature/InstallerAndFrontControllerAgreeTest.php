<?php

declare(strict_types=1);

/**
 * =============================================================================
 * THE INSTALLER AND THE FRONT CONTROLLER MUST LOOK IN THE SAME PLACES
 * =============================================================================
 *
 * Two files find the application folder by trying a list of likely names:
 * `public-web-root/install.php` before anything exists, and
 * `public-web-root/index.php` on every request afterwards.
 *
 * They were allowed to disagree, and the failure that produced is the worst one
 * an installer can have. install.php offered `kbb-app`, the setup guide told the
 * owner to use it, index.php had never heard of it — so the install found the
 * application, ran all 355 migrations, seeded the shop, created the owner
 * account, wrote `.env` and reported **"Your shop is ready"** — and then every
 * single page of the new site was "KBB: could not find the Laravel application".
 * A complete, correct install and a dead site, with nothing in between to
 * suggest which of the two files was lying.
 *
 * It was found by installing into a folder called kbb-app and then opening the
 * site, which is exactly what the owner would have done. Nothing about reading
 * either file suggests it: both are individually right.
 *
 * ── WHY THIS IS A TEST AND NOT A SHARED HELPER ──────────────────────────────
 *
 * Because neither file may have dependencies. index.php runs before Composer's
 * autoloader — it is the file that LOADS the autoloader — and install.php runs
 * when `vendor/` may not exist at all. A shared `require` would be a fourth
 * thing that has to be found before anything can be found. So the lists are
 * duplicated on purpose, and this keeps the duplication honest.
 *
 * MUTATION: delete any `kbb-app` line from index.php's $candidates. Red, by name.
 */

function ifcSource(string $file): string
{
    return (string) file_get_contents(base_path('public-web-root/'.$file));
}

/**
 * The folder names a file will accept, read out of its own candidate list.
 *
 * Parsed from the source rather than executed, because executing either file
 * runs an installer or boots an application.
 *
 * @return list<string>
 */
function ifcCandidates(string $file): array
{
    $src = ifcSource($file);

    preg_match_all("/__DIR__\s*\.\s*'([^']+)'/", $src, $m);

    $out = [];

    foreach ($m[1] as $path) {
        $normalised = trim($path, '/');

        // '..' means "the application is the parent folder" -- a real case,
        // and one both files carry, but not a folder NAME.
        if ($normalised === '..' || $normalised === '') {
            $out[] = '(parent)';

            continue;
        }

        $out[] = basename($normalised);
    }

    return array_values(array_unique($out));
}

it('offers no application folder name that the front controller cannot find', function () {
    $installer = ifcCandidates('install.php');
    $front = ifcCandidates('index.php');

    /*
     * One direction only, and deliberately.
     *
     * index.php knowing a name install.php does not is harmless: it is a site
     * that was set up some other way and still serves. The reverse is the
     * catastrophe above -- the installer accepting a home the front controller
     * will never look in.
     */
    $orphans = array_values(array_diff($installer, $front));

    expect($orphans)->toBe(
        [],
        'install.php will install into '.implode(', ', $orphans).' but index.php does not look there. '
        .'That combination installs a complete, correct shop and then serves "could not find the Laravel '
        .'application" on every page.'
    );
});

it('looks for kbb-app, which is the folder name the setup guide tells the owner to use', function () {
    /*
     * Named explicitly rather than left to the comparison above, because that
     * one also passes if BOTH files forget it -- and the guide would still be
     * telling the owner to use it.
     */
    $guide = (string) file_get_contents(base_path('docs/CUTOVER-EXTRABEAUTY.md'));

    expect(str_contains($guide, 'kbb-app'))->toBeTrue(
        'the cutover guide no longer mentions kbb-app; if the recommended folder name changed, '
        .'both install.php and index.php have to change with it'
    );

    foreach (['install.php', 'index.php'] as $file) {
        expect(in_array('kbb-app', ifcCandidates($file), true))->toBeTrue(
            "{$file} does not look in kbb-app, which is the folder name docs/CUTOVER-EXTRABEAUTY.md tells "
            .'the owner to create'
        );
    }
});

it('lets the installer record where it put the application, and trusts that first', function () {
    $front = ifcSource('index.php');

    /*
     * The list is a guess. It covers the names somebody thought of and fails
     * silently and totally on one they did not, which is how the bug above
     * happened in the first place -- so the guess is a FALLBACK, and the thing
     * that actually knows writes it down.
     *
     * MUTATION: delete the kbb-app-path.php block from index.php. Red.
     */
    expect(str_contains($front, 'kbb-app-path.php'))->toBeTrue(
        'index.php no longer reads the path install.php recorded, so folder discovery is back to guessing'
    );

    expect(str_contains(ifcSource('install.php'), 'kbb-app-path.php'))->toBeTrue(
        'install.php no longer records where it installed the application'
    );

    /*
     * And it must be VERIFIED rather than believed: a recorded path whose
     * bootstrap/app.php has since moved has to fall through to the list, or a
     * stale file from a folder rename takes the site down with no way back.
     */
    expect(preg_match('/kbb-app-path\.php.*?is_file\(\$recorded\s*\.\s*.\/bootstrap\/app\.php/s', $front))->toBe(
        1,
        'index.php uses the recorded path without checking it still holds an application'
    );
});

it('keeps the installer out of every update package', function () {
    /*
     * install.php writes .env and creates an owner account. It refuses to run
     * once a shop exists, but a package that could put it back on a live server
     * is a package that could reopen that door -- and UpdateGuard is the thing
     * that decides. `public-web-root/` is not on its allow-list, so this holds
     * today; it is pinned because the allow-list is edited by hand.
     */
    $guard = new App\Services\Update\UpdateGuard;

    foreach (['public-web-root/install.php', 'public-web-root/index.php', 'public-web-root/kbb-recover.php'] as $path) {
        expect($guard->checkPath($path))->not->toBeNull(
            "UpdateGuard would allow a package to write {$path}; the installer and the recovery script are "
            .'uploaded by hand, never shipped'
        );
    }
});
