<?php

/**
 * The Modules screen's "where its settings live" text, against where the
 * console actually puts that screen.
 *
 * ── THE DEFECT
 *
 * ModuleRegistry::REGISTRY carries, per module, a human-readable path (field 5)
 * and a deep-link route key (field 6). Its own header says field 5 is "where
 * that screen lives, so the admin can link straight to it" — one screen, named
 * twice, in two notations.
 *
 * Two of the forty-three rows named a place the console does not have:
 *
 *   product_labels   said "Catalogue → Product Labels". The console files it
 *                    under Growth & Marketing → Product Labels, and there is no
 *                    "Catalogue" group in the sidebar at all — "Catalogue" is a
 *                    group on the MODULES page itself, which is exactly what
 *                    makes the wrong text so easy to read as right.
 *   product_sorting  said "Store → Catalog → Reorder". Catalog is its own
 *                    top-level section and has not been under Store since the
 *                    group was created; the Reorder tab is at Catalog → Reorder.
 *
 * Both route keys were correct, so both deep links worked and nothing failed —
 * the owner was simply sent to the wrong part of the sidebar by hand.
 *
 * ── WHY THIS IS CHECKED AGAINST THE CONSOLE AND NOT AGAINST A LIST
 *
 * A test carrying its own table of expected paths is a third copy of the same
 * fact, and the third copy drifts exactly like the second one did. So this
 * reads `const TITLES` out of resources/views/admin/app.blade.php — the map the
 * console itself uses for the breadcrumb and the page heading — plus the rows
 * the self-registering partials add through kbbAddNavEntry(). Renaming a screen
 * in the console breaks this test, which is the point.
 *
 * ── THE ONE DIVERGENCE THIS DOES NOT FAIL ON
 *
 * `newsletter` names Appearance → Homepage, which is where the panel is
 * switched on — the Newsletter screen says so itself, in as many words — while
 * its route key opens Growth & Marketing → Newsletter, where the wording and
 * the subscriber list live. Both are real screens and both are relevant, so
 * which of the two fields is wrong is the owner's call and not a typo to
 * quietly correct. It is named here so that the rule below still means
 * something and the exception cannot spread silently.
 */

use App\Services\ModuleRegistry;

/** resources/views/admin/app.blade.php, read once. */
function mrsConsoleSource(): string
{
    static $src = null;

    return $src ??= (string) file_get_contents(base_path('resources/views/admin/app.blade.php'));
}

/**
 * Every place the console can put a screen, as "Group → Label" => screen id.
 *
 * Two sources, because the sidebar has two: `const TITLES` for the built-in
 * rows, and each self-registering partial's kbbAddNavEntry() call for the four
 * screens that insert themselves after buildNav() has run.
 *
 * A section holding one row of its own name — Catalog → "Catalog" — is also
 * registered under the bare section name, because "Catalog → Reorder" is what a
 * person writes for a tab on it and "Catalog → Catalog → Reorder" is not.
 *
 * @return array<string, string>
 */
function mrsConsolePaths(): array
{
    static $paths = null;

    if ($paths !== null) {
        return $paths;
    }

    $src = mrsConsoleSource();

    // The TITLES object literal, matched by balancing its own braces rather
    // than by a greedy pattern that would run to the end of the file.
    $at = strpos($src, 'const TITLES=');
    $open = strpos($src, '{', $at);
    $depth = 0;
    $end = $open;

    for ($i = $open, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        }

        if ($src[$i] === '}') {
            $depth--;

            if ($depth === 0) {
                $end = $i;
                break;
            }
        }
    }

    preg_match_all(
        "/'?([a-zA-Z0-9-]+)'?\s*:\s*\['([^']*)','((?:[^'\\\\]|\\\\.)*)'\]/",
        substr($src, $open, $end - $open + 1),
        $matches,
        PREG_SET_ORDER
    );

    $paths = [];

    foreach ($matches as $hit) {
        $group = html_entity_decode($hit[2], ENT_QUOTES | ENT_HTML5);
        $title = html_entity_decode($hit[3], ENT_QUOTES | ENT_HTML5);

        $paths[$group . ' → ' . $title] = $hit[1];

        if ($group === $title) {
            $paths[$group] = $hit[1];
        }
    }

    foreach (glob(base_path('resources/views/admin/partials/*.blade.php')) as $partial) {
        preg_match_all('/kbbAddNavEntry\(\{(.*?)\}\)/s', (string) file_get_contents($partial), $calls, PREG_SET_ORDER);

        foreach ($calls as $call) {
            if (preg_match("/label:\s*'([^']*)'/", $call[1], $label)
                && preg_match("/group:\s*'([^']*)'/", $call[1], $group)) {
                // 'injected' rather than a screen id: these rows are placed by
                // the partial and their id is a constant inside it, so the id
                // is not readable here. Their PATH is, which is what rule one
                // needs and all this map is asked for.
                $paths[$group[1] . ' → ' . $label[1]] ??= 'injected';
            }
        }
    }

    return $paths;
}

/** The longest console path this claim starts with, or null. */
function mrsResolve(string $claim): ?array
{
    $best = null;

    foreach (mrsConsolePaths() as $path => $screen) {
        if ($claim !== $path && ! str_starts_with($claim, $path . ' → ')) {
            continue;
        }

        // Longest wins: "Store → Ecommerce → Checkout" must resolve to the
        // Ecommerce screen and not to some shorter path that also matches.
        if ($best === null || strlen($path) > strlen($best[0])) {
            $best = [$path, $screen];
        }
    }

    return $best;
}

/** Field 5 values that name no screen at all, and are not paths. */
const MRS_SENTINELS = ['', 'No settings screen', 'Its own screen'];

it('names a real console location in every module row that names one at all', function () {
    $checked = 0;
    $wrong = [];

    foreach (ModuleRegistry::REGISTRY as $key => $row) {
        $claim = (string) $row[4];

        if (in_array($claim, MRS_SENTINELS, true)) {
            continue;
        }

        $checked++;

        if (mrsResolve($claim) === null) {
            $wrong[$key] = $claim;
        }
    }

    // Every row that claims a screen, measured rather than assumed: 43 rows in
    // the registry, 8 of which name no screen.
    expect($checked)->toBe(35);
    expect($wrong)->toBe([]);
});

it('sends the operator to the group the console really files each screen under', function () {
    // The two that were wrong, named rather than left to the sweep above — so
    // the diff that reintroduces either one fails on a sentence about itself
    // instead of on a count.
    expect(ModuleRegistry::REGISTRY['product_labels'][4])->toBe('Growth & Marketing → Product Labels');
    expect(mrsResolve('Growth & Marketing → Product Labels')[1])->toBe('labels');
    expect(ModuleRegistry::REGISTRY['product_labels'][5])->toBe('labels');

    expect(ModuleRegistry::REGISTRY['product_sorting'][4])->toBe('Catalog → Reorder');
    expect(mrsResolve('Catalog → Reorder')[1])->toBe('catalog');
    expect(ModuleRegistry::REGISTRY['product_sorting'][5])->toBe('catalog:reorder');

    // And the sidebar really has no "Catalogue" group, which is the whole
    // reason the old text read as plausible.
    foreach (array_keys(mrsConsolePaths()) as $path) {
        expect($path)->not->toStartWith('Catalogue');
    }
});

it('points the written path and the Open button at one screen, bar one known divergence', function () {
    $diverged = [];

    foreach (ModuleRegistry::REGISTRY as $key => $row) {
        $claim = (string) $row[4];
        $route = (string) $row[5];

        if (in_array($claim, MRS_SENTINELS, true) || $route === '') {
            continue;
        }

        $found = mrsResolve($claim);

        if ($found === null || $found[1] === 'injected') {
            continue;
        }

        // The route may name a tab: 'ecommerce:checkout' is the Ecommerce
        // screen. The screen is what has to match.
        if ($found[1] !== explode(':', $route)[0]) {
            $diverged[$key] = $claim . ' vs ' . $route;
        }
    }

    // `newsletter` only — see this file's header. Both fields name a real
    // screen and both screens are relevant to the module, so the row is
    // reported rather than silently rewritten.
    expect(array_keys($diverged))->toBe(['newsletter']);
});
