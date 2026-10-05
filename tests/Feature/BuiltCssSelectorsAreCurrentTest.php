<?php

declare(strict_types=1);

/**
 * Lane QA — the half of "the bundle is stale" that property names do not cover.
 *
 * BuiltCssIsCurrentTest compares the set of CUSTOM PROPERTY NAMES declared in
 * resources/css/kbb/*.css against the set declared in public/build/assets/*.css,
 * and its own docblock says what it cannot see: "a changed colour slips
 * through". So does a NEW RULE. Adding
 *
 *     .kbb-card-cart{min-height:44px}
 *
 * to a source and forgetting `npx vite build` declares no custom property, so
 * that guard is green and the shop is serving a bundle without the rule. This
 * project has already lost a round to exactly that — a control wired end to end
 * in the source, shipped, and moving nothing because the bundle predated it —
 * and `package.json` still defines no `build` script, so the build is a
 * hand-typed command and CI does not run it.
 *
 * ── WHY SELECTORS CAN BE COMPARED AND DECLARATIONS CANNOT ───────────────────
 *
 * The bundle is minified, so `padding: 14px 20px` and `padding:14px 20px` are
 * the same rule written two ways and a diff of declarations is noise. Selectors
 * are not: a minifier may drop whitespace and quotes, but it may not rename a
 * class, an attribute or a pseudo-element, because the only thing that matches
 * them is the literal text.
 *
 * Measured on the tree this was written against: 2,426 distinct selectors in the
 * sources and 2,424 in the bundles, and after the four normalisations below the
 * two sets are equal. The normalisations are exactly what the minifier does and
 * nothing else:
 *
 *   [data-x="y"]  ->  [data-x=y]      quotes dropped from attribute values
 *   a > b         ->  a>b             space dropped around a combinator
 *   ::after       ->  :after          the legacy one-colon form
 *   runs of space ->  one space
 *
 * ── BOTH DIRECTIONS, FOR DIFFERENT DEFECTS ──────────────────────────────────
 *
 * A selector in the source and not the bundle is an UNBUILT edit: the rule
 * exists in the repo, in the diff and in the package, and not on the site.
 * A selector in the bundle and not the source is a DELETED rule still being
 * served, which is how a control that was removed keeps working on the shop and
 * nowhere else.
 *
 * MUTATION NOTE, run: add `.kbb-not-built-marker{color:red}` to
 * resources/css/kbb/kbb.css -> RED, ".kbb-not-built-marker" listed as in the
 * source and not in the bundle. Rebuilding with `npx vite build` makes it green
 * again. BuiltCssIsCurrentTest stays GREEN through that whole cycle, because the
 * rule declares no custom property.
 */

/**
 * admin-skin-preview.css is NOT a Vite entry.
 *
 * vite.config.js lists nine stylesheets and this is not one of them — the admin
 * console loads it directly, so none of its 219 `.skinprev` selectors is ever
 * expected in a bundle. Checked against vite.config.js below rather than
 * hard-coded here, so a file promoted to an entry is compared from that day.
 */
function qaViteCssEntries(): array
{
    $config = (string) file_get_contents(base_path('vite.config.js'));

    // The owner app's one stylesheet (Lane MAC) is an entry too, and is held
    // to the same rule: what is served is what is in the commit.
    preg_match_all("#'(resources/css/(?:kbb|owner-app)/[a-z0-9-]+\.css)'#", $config, $m);

    return array_map(static fn (string $p): string => base_path($p), array_unique($m[1]));
}

/** Every selector in a set of stylesheets, normalised the way a minifier writes them. */
function qaSelectors(array $files): array
{
    $found = [];

    foreach ($files as $file) {
        $css = (string) file_get_contents($file);
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        if (! preg_match_all('#([^{}();]+)\{#', $css, $m)) {
            continue;
        }

        foreach ($m[1] as $block) {
            if (trim($block) === '' || str_starts_with(trim($block), '@')) {
                continue;
            }

            foreach (explode(',', $block) as $one) {
                $one = trim((string) preg_replace('/\s+/', ' ', $one));

                if ($one === '' || str_starts_with($one, '@')) {
                    continue;
                }

                // A @keyframes stop is not a selector: `from`, `to`, `0%`, `100%`.
                if (preg_match('#^(from|to|[\d.]+%( *,? *[\d.]+%)*)$#i', $one)) {
                    continue;
                }

                $one = (string) preg_replace('#\[([^\]=]+)=["\']([^"\']*)["\']\]#', '[$1=$2]', $one);
                $one = (string) preg_replace('#\s*([>+~])\s*#', '$1', $one);
                $one = str_replace('::', ':', $one);

                $found[trim($one)] = basename($file);
            }
        }
    }

    return $found;
}

it('serves a bundle whose rules are the rules in this commit', function () {
    $sources = qaViteCssEntries();
    $bundles = glob(base_path('public/build/assets/*.css')) ?: [];

    expect($sources)->not->toBe([], 'vite.config.js names no stylesheet entries, so this check is blind');
    expect($bundles)->not->toBe([], 'public/build/assets holds no CSS, so this check is blind');

    $inSource = qaSelectors($sources);
    $inBundle = qaSelectors($bundles);

    expect(count($inSource))->toBeGreaterThan(1000, 'almost no selectors were read, so this check is blind');

    $unbuilt = array_keys(array_diff_key($inSource, $inBundle));
    $orphaned = array_keys(array_diff_key($inBundle, $inSource));

    sort($unbuilt);
    sort($orphaned);

    $report = function (array $list, array $where): string {
        return implode("\n", array_map(
            static fn (string $s): string => '  ' . $s . (isset($where[$s]) ? '   (' . $where[$s] . ')' : ''),
            array_slice($list, 0, 40)
        ));
    };

    expect($unbuilt)->toBe(
        [],
        "these selectors are in resources/css/kbb and NOT in public/build — run `npx vite build`:\n"
        . $report($unbuilt, $inSource)
        . "\n\nUntil the bundle is rebuilt the rule is real in the repo, real in the diff, real in the"
        . ' package and absent from the shop.'
    );

    expect($orphaned)->toBe(
        [],
        "these selectors are SERVED by public/build and no longer exist in resources/css/kbb:\n"
        . $report($orphaned, $inBundle)
        . "\n\nA deleted rule that is still being served keeps a removed control working on the site"
        . ' and nowhere else.'
    );
});
