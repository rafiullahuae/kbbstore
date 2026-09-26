<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\DemoContentController;

/**
 * =============================================================================
 * TWO DEFECTS THE OWNER FOUND BEFORE ANY TEST DID
 * =============================================================================
 *
 * His words: "i can't add any section, neither i can see any demo data on the
 * demo content page."
 *
 * ── 1 · EVERY WRITE FROM TWO SCREENS WAS REFUSED, ALWAYS ────────────────────
 *
 * `ugc-sections-screen` and `ugc-appearance-screen` signed their writes with
 * `X-CSRF-TOKEN`, read from a `<meta name="csrf-token">` tag — AND THIS ADMIN
 * HAS NO SUCH TAG. `document.querySelector` returned null, the helper answered
 * '', and Laravel refused every POST and PUT with 419 "CSRF token mismatch".
 *
 * Reproduced in Chromium before the fix: POST /admin-api/ugc-sections -> 419.
 * The screen caught it and printed "That section could not be saved.", which
 * names the symptom and hides the cause — so it looked like a validation
 * problem with the section rather than a request that never got through.
 *
 * NOBODY COULD EVER HAVE CREATED A SECTION. Not a regression: this path was
 * wrong from the day it was written, and the demo sections existed only because
 * they were seeded straight into the database.
 *
 * The console has always used `X-XSRF-TOKEN` from the `XSRF-TOKEN` COOKIE
 * Laravel sets on every response — app.blade.php's own api() does it, and so
 * does ugc-library-screen, which is why UPLOADING a clip worked while creating
 * a section did not.
 *
 * ── 2 · A DEMO TYPE THE SCREEN NEVER DREW ──────────────────────────────────
 *
 * `DemoContentController::TYPES` gained 'videos' and the Demo Content SCREEN
 * keeps its own list of cards in app.blade.php. Adding the type made it
 * importable through the API and left it INVISIBLE, so the owner opened the
 * page, saw nine cards, and correctly concluded the demo data did not exist.
 */
function awsPartials(): array
{
    return glob(resource_path('views/admin/partials/*.blade.php')) ?: [];
}

/** A file's text with its /* *\/ comments removed, so prose cannot satisfy a scan. */
function awsCode(string $path): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path));
}

it('signs every admin write with the token this console actually issues', function () {
    /*
     * THE CONSOLE HAS NO csrf-token META TAG, and that is the fact the whole
     * defect rests on — so it is asserted first. If one is ever added, this
     * case is the place to decide whether the meta scheme becomes legitimate,
     * rather than two screens quietly depending on a tag nobody put there.
     */
    $shell = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(str_contains($shell, 'name="csrf-token"'))->toBeFalse(
        'The admin now ships a csrf-token meta tag. Decide deliberately whether screens may read it; '
        .'until then the only signing scheme in this console is the XSRF-TOKEN cookie.');

    // And the scheme that IS issued, in the shell every screen loads inside.
    expect(str_contains($shell, "X-XSRF-TOKEN"))->toBeTrue();

    /*
     * No screen may read that absent tag, and none may send the header that
     * goes with it. Both halves, because either one alone is a screen that is
     * half-converted and still broken.
     *
     * MUTATION: put `X-CSRF-TOKEN` back into ugc-sections-screen and this is
     * red, naming the file.
     */
    $offenders = [];

    foreach (awsPartials() as $file) {
        $code = awsCode($file);

        if (str_contains($code, 'meta[name=csrf-token]') || str_contains($code, "'X-CSRF-TOKEN'")) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['These screens sign writes with a token this admin does not issue, so every write they make'],
        ['is refused with 419 and reported as though the DATA were at fault:'],
        $offenders,
        ['', "Use the cookie: opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');"]
    )));
});

it('gives every screen that writes a way to read the cookie', function () {
    /*
     * The other half of the same rule: a screen that sends the header must
     * actually have the reader, or it sends `undefined` and is refused exactly
     * as before — which is the shape a half-applied fix would leave.
     */
    $missing = [];

    foreach (awsPartials() as $file) {
        $code = awsCode($file);

        /*
         * TWO LEGITIMATE READERS, and the first draft of this case knew only
         * one. `homepage-content-screen` sends the header and calls
         * `window.uToken()` — the shell's own helper in app.blade.php, which
         * reads the very same XSRF-TOKEN cookie. It was reported as an offender
         * and is not one. What is being asserted is that a screen sending the
         * header HAS a reader, not which of the two it picked.
         */
        $reads = str_contains($code, 'document.cookie') || str_contains($code, 'uToken');

        if (str_contains($code, 'X-XSRF-TOKEN') && ! $reads) {
            $missing[] = basename($file);
        }
    }

    expect($missing)->toBe([], 'these screens send X-XSRF-TOKEN and have no way to read the cookie: '
        .implode(', ', $missing));
});

it('draws a card for every demo type the API can import', function () {
    /*
     * BOTH DIRECTIONS, because each one is a different failure the owner sees:
     *
     *   a type with no card  — the feature is invisible and he concludes it is
     *                          not there, which is what happened with 'videos';
     *   a card with no type  — the button is drawn and the import 404s or does
     *                          nothing, which is worse than absent.
     *
     * MUTATION: drop the 'videos' row from DEMO_CONTENT_TYPES in app.blade.php, or drop
     * 'videos' from DemoContentController::TYPES, and this is red either way.
     */
    $shell = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect((bool) preg_match('/const DEMO_CONTENT_TYPES\s*=\s*\[(.*?)\n\];/s', $shell, $m))
        ->toBeTrue('the Demo Content screen no longer declares DEMO_CONTENT_TYPES where this test can read it');

    preg_match_all("/^\s*\['([a-z_]+)',/m", $m[1], $found);

    $drawn = $found[1];
    $importable = (new ReflectionClass(DemoContentController::class))->getConstant('TYPES');

    sort($drawn);
    $importable = array_values($importable);
    sort($importable);

    expect($drawn)->toBe($importable,
        "The Demo Content screen draws a different set of cards than the API can import.\n"
        ."drawn:      ".implode(', ', $drawn)."\n"
        ."importable: ".implode(', ', $importable));
});
