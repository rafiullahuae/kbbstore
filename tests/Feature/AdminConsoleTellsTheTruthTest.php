<?php

declare(strict_types=1);

/**
 * Lane DD — the console does not describe machinery it does not have.
 *
 * Three claims, none of which had anything behind it:
 *
 *   1. The amber strip under the top bar told the owner that while the Live /
 *      Sandbox switch read Sandbox, his changes were held back from the live
 *      store until a deploy. The switch sets an attribute on <body>. There is
 *      one database and every screen writes to it either way, so the promise
 *      invited him to try a price or a VAT rate "safely" on the real shop.
 *
 *   2. Safety -> Sandbox & Deploy showed five pre-flight checks lit green, a
 *      diff summary, a Deploy to Live button and a Rollback button, and
 *      promised a database backup on every deploy. The checks were string
 *      literals, the deploy handler was a setTimeout, and no code path in this
 *      application has ever backed up the database from that screen. The real
 *      mechanism is Core Updates, one row down the same sidebar.
 *
 *   3. Store -> SEO & Meta grouped four verification tokens and two tracking
 *      fields under one line saying the section changed nothing about the
 *      storefront. Saving a Google Analytics ID puts Google's tag on every
 *      page; saving a Meta Pixel ID does nothing at all, because nothing reads
 *      `meta_pixel` — the pixel that fires is the one on Marketing Pixels.
 *
 * THE COMMENT TRAP. The notes left in app.blade.php beside each of these
 * necessarily describe the claim that was removed, and a plain search of that
 * file would find the words in the explanation and fail. Every assertion below
 * runs against a copy with HTML and block comments stripped, so it reads code
 * and page copy only. That is the failure five lanes here have already hit.
 */

$lddSource = static function (): string {
    $src = file_get_contents(resource_path('views/admin/app.blade.php'));

    // Comments out, so a guard cannot be satisfied — or defeated — by prose.
    $src = preg_replace('/<!--.*?-->/s', ' ', $src);
    $src = preg_replace('/\{\{--.*?--\}\}/s', ' ', $src);
    $src = preg_replace('#/\*.*?\*/#s', ' ', $src);

    return $src;
};

it('does not tell the owner his changes are staged anywhere', function () use ($lddSource) {
    $code = $lddSource();

    expect(str_contains($code, 'isolated from the live store'))
        ->toBeFalse('The Live / Sandbox switch stages nothing; the strip must not say it does.');

    expect(str_contains($code, 'Everything you change here is saved to the live shop immediately'))
        ->toBeTrue('The strip has to say what the switch actually leaves the owner looking at.');
});

it('does not report a switch of environment that did not happen', function () use ($lddSource) {
    $code = $lddSource();

    expect(str_contains($code, "'Switched to Sandbox'"))
        ->toBeFalse('Nothing switched: the handler sets document.body.dataset.env and stops.');
});

it('offers no deploy, rollback or pre-flight check it cannot perform', function () use ($lddSource) {
    $code = $lddSource();

    foreach ([
        'Deploy to Live',
        'Rollback',
        '5 / 5 passed',
        'Clone of live data',
        'auto-backs-up the live database',
    ] as $claim) {
        expect(str_contains($code, $claim))
            ->toBeFalse('Sandbox & Deploy still advertises "' . $claim . '", which nothing behind it does.');
    }
});

/**
 * The dashboard's third card. It was labelled "live feed" over three literals
 * dated "just now" — a foundation initialised, a monitor watching, a sandbox
 * ready — and hydrateDash() only overwrote them when the store HAD recent
 * orders. A quiet shop read invented events for ever.
 */
it('shows no invented activity on the dashboard', function () use ($lddSource) {
    $code = $lddSource();

    foreach ([
        'Foundation initialised',
        'Debug &amp; Monitor enabled',
        'Sandbox ready',
        'Pre-flight checks armed for first deploy',
    ] as $invented) {
        expect(str_contains($code, $invented))
            ->toBeFalse('The dashboard still prints the invented event "' . $invented . '".');
    }

    expect(str_contains($code, 'No orders yet.'))
        ->toBeTrue('A store with no orders has to be told that, not shown a feed of nothing.');
});

it('sends the owner to the screen that really versions and restores this site', function () use ($lddSource) {
    $code = $lddSource();

    expect(str_contains($code, 'There is no sandbox to deploy from'))
        ->toBeTrue('Safety -> Sandbox & Deploy has to say what is true of this install.');

    expect(str_contains($code, "<button class=\"btn\" onclick=\"go('updates')\">Core Updates"))
        ->toBeTrue('The honest screen has to hand the owner the real one, as Meta & Facebook does.');
});

it('leaves no caller for the deploy and rollback handlers it removed', function () use ($lddSource) {
    $code = $lddSource();

    expect(str_contains($code, 'window.deploy'))->toBeFalse('Exported a handler with no button.');
    expect(str_contains($code, 'window.rollback'))->toBeFalse('Exported a handler with no button.');
    expect(str_contains($code, 'onclick="deploy()"'))->toBeFalse('A button with no handler.');
    expect(str_contains($code, 'onclick="rollback()"'))->toBeFalse('A button with no handler.');
});

it('does not claim the SEO tracking fields leave the storefront alone', function () use ($lddSource) {
    $code = $lddSource();

    expect(str_contains($code, 'nothing here changes how the storefront behaves'))
        ->toBeFalse('A Google Analytics ID saved on that screen loads Google’s tag on every page.');

    expect(str_contains($code, 'Saving an ID here loads Google’s tag on every storefront page'))
        ->toBeTrue('The Analytics box has to say what saving it does.');
});

/**
 * The Meta Pixel box on SEO & Meta writes `meta_pixel`; App\Services\
 * MarketingPixels writes `meta_id` and is the one the storefront fires. Both
 * boxes exist, so the dead one has to say which it is until the owner decides
 * which to keep.
 */
it('says which of the two Meta Pixel boxes is the one that fires', function () use ($lddSource) {
    $code = $lddSource();

    expect(str_contains($code, 'Stored, but no storefront page fires it.'))
        ->toBeTrue('The SEO screen must not present a dead pixel box as a working one.');
});
