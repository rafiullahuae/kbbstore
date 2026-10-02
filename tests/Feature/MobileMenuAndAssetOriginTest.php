<?php

declare(strict_types=1);

use Illuminate\Support\Facades\URL;

/**
 * THE MOBILE MENU OPENS IN EVERY BROWSER, AND ONE BROKEN STEP CANNOT STOP IT.
 *                                                              (2.60.337)
 *
 * ── WHAT THE OWNER SAW ───────────────────────────────────────────────────
 *
 * The menu button worked in Chrome and did nothing in Safari or Opera, and
 * Opera labelled the shop "Connection is not secure".
 *
 * Opera and Safari had loaded the shop over plain http://; Chrome upgrades to
 * https:// by itself. Production calls URL::forceScheme('https'), so every
 * asset address came out https:// -- a DIFFERENT ORIGIN from an http:// page.
 * Module scripts and web fonts are always fetched under cross-origin rules and
 * the host sends no Access-Control-Allow-Origin, so the browser refused both.
 * Reproduced with two real servers and no interception; the browser said:
 *
 *   "Access to script at '.../build/assets/app-….js' from origin '...' has
 *    been blocked by CORS policy: No 'Access-Control-Allow-Origin' header"
 *
 * -- and the same for the Outfit font. Root-relative addresses carry no scheme
 * and no host, so they are always the page's own origin. After the fix, the
 * same reproduction: menu opens, 0 CORS errors, Outfit loaded.
 *
 * ── MUTATION NOTES, each run and each red alone ──────────────────────────
 *
 *   Delete the Vite::createAssetPathsUsing() block            -> cases 1, 2 red
 *   Remove the try/catch around step() in app.js's boot      -> case 3 red
 *   Move initHome out of STEPS (the menu never wires)          -> case 4 red
 */

/** Every address the page uses for the shop's OWN built files. */
function mmaoAssetAddresses(string $html): array
{
    preg_match_all('#(?:src|href)="([^"]*/build/assets/[^"]+)"#', $html, $m);

    // There must BE some, or "none are absolute" passes on a page that
    // stopped loading its assets at all -- the false green this repository
    // keeps finding.
    expect($m[1])->not->toBeEmpty('the page references no built assets at all');

    return $m[1];
}

it('addresses the shop\'s own files from the root, never with a scheme', function () {
    $html = $this->get('/')->assertOk()->getContent();

    foreach (mmaoAssetAddresses($html) as $address) {
        expect(str_starts_with($address, '/'))->toBeTrue("not root-relative: {$address}");
        expect(preg_match('#^[a-z]+://#i', $address))->toBe(0, "carries a scheme: {$address}");
    }
});

it('stays root-relative when the scheme is forced, which is production', function () {
    // The exact condition on the live host: AppServiceProvider forces https in
    // production. That is what made the script a different origin on an
    // http:// page. The forced scheme must not reach the shop's own files.
    URL::forceScheme('https');

    $html = $this->get('/')->assertOk()->getContent();

    foreach (mmaoAssetAddresses($html) as $address) {
        expect(str_starts_with($address, 'https://'))->toBeFalse(
            "the forced scheme reached a built asset, which is the cross-origin split: {$address}"
        );
    }
});

/** The start-up sequence, read from the source Vite builds. */
function mmaoAppJs(): string
{
    return file_get_contents(base_path('resources/js/kbb/app.js'));
}

it('runs each start-up step on its own, so one failure cannot cancel the rest', function () {
    $js = mmaoAppJs();

    // The loop that runs the steps must wrap EACH call. A bare `step()` with
    // nothing around it is the 21-in-a-row shape that let one error leave the
    // menu button dead.
    expect(preg_match('/for \(const step of STEPS\)\s*\{\s*try\s*\{\s*step\(\);\s*\}\s*catch/s', $js))
        ->toBe(1, 'boot() no longer isolates each step');

    // And the old shape -- calls in sequence inside boot -- is gone.
    expect(preg_match('/const boot = \(\) => \{\s*initOverlay\(\);/s', $js))->toBe(0);
});

it('still wires the mobile menu, and in the same position', function () {
    $js = mmaoAppJs();

    preg_match('/const STEPS = \[(.*?)\];/s', $js, $m);
    $steps = array_values(array_filter(array_map('trim', explode(',', $m[1] ?? ''))));

    // All of them, in the order they always ran -- isolating them must not
    // drop or reorder any of them. 22 since Lane PI-B added initListingLoad
    // ("Load more on scroll") directly after initShop, at index 16 -- after
    // initHome, so the mobile menu's position is unchanged.
    // 23 since Lane PS added initAlsoLike (the "You may also like" carousel)
    // LAST, after initNavFit, so no step before it moved.
    expect($steps)->toHaveCount(23);
    expect($steps[0])->toBe('initOverlay');
    expect($steps[8])->toBe('initHome');   // wires the mobile menu
    expect($steps[16])->toBe('initListingLoad');
    expect($steps[21])->toBe('initNavFit');
    expect($steps[22])->toBe('initAlsoLike');
});
