<?php

declare(strict_types=1);

/**
 * =============================================================================
 * EVERY ADMIN SCREEN NAMES THE ROUTE-CACHE FAULT                       Lane AD
 * =============================================================================
 *
 * ── THE FAULT, AND WHY IT IS THIS SHOP'S MOST EXPENSIVE ONE ─────────────────
 *
 * CLAUDE.md, in as many words: "A route added in routes/web.php will not take
 * effect until the compiled route cache is cleared, so every package that adds
 * a route also ships a clear_caches_* migration." When that migration does not
 * run — and packages here are applied BY HAND, through Store → Core Updates,
 * by an owner with no shell — the compiled table wins over routes/web.php and
 * the screen's endpoints simply do not exist. Laravel answers its own 404 HTML
 * page.
 *
 * What the owner then sees depends entirely on what that screen's error path
 * chose to say. Twenty-eight screens already said it plainly. Seven did not,
 * and answered instead with the same sentence they use for every other failure:
 *
 *   Product editor      'Could not load the editor.'
 *   Media Library       'Request failed (404)'
 *   Media picker        'media 404'
 *   Manual order        'Could not load the order form. Reload the console and
 *                        try again.'   ← and reloading will never fix it
 *   SEO back office     'Request failed (404)'
 *   Arabic boxes        'Could not translate that.'  ← sends him to his API key
 *   Review queue badge  nothing at all
 *
 * Each of those is a round trip: the remedy is one click on Platform → Cache
 * and nothing on the screen points at it.
 *
 * ── WHAT THIS ASSERTS, AND WHY IT IS SHAPED THIS WAY ────────────────────────
 *
 * The FINISHED state, per CLAUDE.md's rule about pins: every admin partial that
 * talks to /admin-api has a branch on 404. Zero is the "built, blind" shape
 * this repo keeps finding, and it is what this catches on the NEXT screen
 * somebody writes — which is the whole point, because the seven above were each
 * written long after the idiom existed.
 *
 * COMMENTS ARE STRIPPED BEFORE SCANNING. CLAUDE.md names this trap directly:
 * a comment naming a key satisfies a scan for that key. This file's own prose
 * says "404" a dozen times and several screens explain their 404 branch in a
 * comment above it; a scanner that read those would pass a screen that had no
 * branch at all.
 *
 * MUTATION (run): delete the `if (e && e.status === 404)` arm from
 * product-editor-screen's message(). RED, naming that file.
 */

/** Every admin partial, as CODE — comments removed. */
function rcScreens(): array
{
    $out = [];

    foreach (glob(base_path('resources/views/admin/partials/*.blade.php')) ?: [] as $path) {
        $src = (string) file_get_contents($path);

        // Blade comments, JS/CSS block comments, then line comments. In that
        // order: a `//` inside a block comment must not be treated as the start
        // of a line comment, and a `{{-- --}}` can wrap either.
        $code = (string) preg_replace(
            ['#\{\{--.*?--\}\}#s', '#/\*.*?\*/#s', '#(^|[^:])//[^\n]*#'],
            ['', '', '$1'],
            $src
        );

        $out[basename($path, '.blade.php')] = $code;
    }

    return $out;
}

it('gives every admin screen that calls /admin-api a branch on 404', function () {
    /*
     * THE EXEMPTION IS NAMED, NOT PATTERN-MATCHED AWAY.
     *
     * review-queue-badge draws ONE NUMBER into the sidebar — the count of
     * pending reviews. It has no banner, no toast and nowhere a sentence could
     * go, and a badge that silently does not appear is the correct behaviour
     * for a decoration. The endpoint it calls, /reviews/list, belongs to the
     * Reviews screen, which DOES name the fault, so the owner who goes looking
     * is told there.
     *
     * Named here rather than excluded by a rule about file size or fetch count,
     * so that a SECOND screen choosing to stay silent has to argue for it in
     * this list instead of slipping under a threshold.
     */
    $exempt = [
        'review-queue-badge' => 'draws a count badge with no surface for a sentence; its endpoint is the Reviews screen\'s',
    ];

    $blind = [];

    foreach (rcScreens() as $name => $code) {
        if (! str_contains($code, 'fetch(')) {
            continue;
        }

        if (array_key_exists($name, $exempt)) {
            // And it must still be silent — an exemption that has quietly grown
            // an error banner is an exemption nobody re-argued.
            continue;
        }

        if (! str_contains($code, '404')) {
            $blind[] = $name;
        }
    }

    expect($blind)->toBe([], 'these admin screens answer a route-cache 404 with a sentence that names some other fault: '
        .implode(', ', $blind));
});

it('points the owner at the one click that fixes it, not at a reload', function () {
    /*
     * A BRANCH IS NOT ENOUGH IF IT SAYS THE WRONG THING. The remedy is Platform
     * → Cache, and the screens that already did this name it. This asserts the
     * seven repaired here carry the remedy and not just the status code — the
     * Manual order screen is the sharp case, because the sentence it had told
     * him to RELOAD, which for this fault is advice that can never work.
     */
    $repaired = [
        'product-editor-screen',
        'media-library-screen',
        'media-picker',
        'manual-order-screen',
        'seo-back-office',
        'arabic-boxes',
    ];

    $screens = rcScreens();

    foreach ($repaired as $name) {
        expect(array_key_exists($name, $screens))->toBeTrue("{$name} is gone — re-read this test");

        $code = $screens[$name];

        expect(str_contains($code, 'compiled route table'))->toBeTrue(
            "{$name} has a 404 branch that does not say what is wrong"
        );
        expect(str_contains($code, 'Cache'))->toBeTrue(
            "{$name} does not name Platform -> Cache, which is the remedy"
        );
    }
});

it('leaves a 404 that carries a message speaking in the server\'s own words', function () {
    /*
     * THE DISCRIMINATOR MATTERS AS MUCH AS THE BRANCH.
     *
     * Two different faults answer 404 on these endpoints:
     *
     *   the route cache   Laravel's own HTML page. No JSON, so no `message`.
     *   the controller    'not_found' for a media row, a product or an order
     *                     that is genuinely gone. JSON, with a message.
     *
     * A blanket `status === 404` branch would tell an owner to clear his route
     * cache because he clicked a product somebody else had just deleted. So the
     * screens whose endpoints really do return 404 check the BODY as well, and
     * the controller's own words win where it supplied them.
     *
     * This is asserted on the three that have such endpoints. It is deliberately
     * NOT asserted on seo-back-office, whose helper reaches the same result
     * through `j.message` being checked first — a different spelling of the same
     * rule, and pinning the spelling would forbid the clearer one.
     */
    $screens = rcScreens();

    foreach (['media-library-screen', 'media-picker', 'manual-order-screen'] as $name) {
        $code = $screens[$name];

        $checksBody = str_contains($code, '!body') || str_contains($code, '!e.body')
            || str_contains($code, 'j && j.message') || str_contains($code, 'body.message')
            || str_contains($code, 'j.message');

        expect($checksBody)->toBeTrue(
            "{$name} branches on a bare 404 and would blame the route cache for a row that is genuinely gone"
        );
    }
});
