<?php

declare(strict_types=1);

/**
 * =============================================================================
 * A BUILD ARTEFACT WHOSE NAME MOVES WITHOUT ITS CONTENT IS A DIFF NOBODY CAN
 * REVIEW
 * =============================================================================
 *
 * THE DEFECT. `resources/js/kbb/app.js` carried `import '../../css/kbb/kbb.css';`
 * while kbb.css was ALSO a Vite entry in its own right and the storefront layout
 * ALSO named it explicitly. Rollup folds a chunk's dependencies into that
 * chunk's content hash, so every edit to a stylesheet renamed the JavaScript
 * bundle — with the JavaScript unchanged to the byte.
 *
 * MEASURED, and this is the whole of the evidence:
 *
 *   public/build/assets/app-DSE-434-.js   commit 85fa244   45,971 bytes
 *   public/build/assets/app-iyd4z9xL.js   commit fcebbc0   45,971 bytes
 *   md5 32ea782cd95d7c9505c931b4632a2e98 for both, `cmp` silent.
 *
 * `git diff --stat 85fa244 fcebbc0 -- resources/js` is empty. Rebuilding
 * 85fa244's tree in isolation reproduces `app-DSE-434-.js` exactly; replacing
 * ONLY resources/css/kbb/kbb.css with fcebbc0's copy and rebuilding produces
 * `app-iyd4z9xL.js`, same 45,971 bytes. With the import gone the same
 * experiment leaves the name alone. docs/rtl-audit.md §13.7 has the table.
 *
 * WHY IT MATTERS HERE MORE THAN IT WOULD ELSEWHERE. This shop is not a git
 * deploy: changes reach the server as zip packages that somebody with no shell
 * applies by hand, and CLAUDE.md's first landmine is five packages built
 * against a stale tree and applied anyway. A package whose file list says
 * `app-<newhash>.js` is unreviewable — the reviewer cannot tell a real
 * behaviour change from a stylesheet edit that dragged the name along. It also
 * littered: 21 orphaned `app-*.js` accumulated in public/build/assets before
 * one commit (965b0f0) swept them out.
 *
 * ── WHAT THIS PINS ──────────────────────────────────────────────────────────
 *
 * Two halves, and the second is the one that makes the first safe:
 *
 *   1. the JavaScript entry must not import a stylesheet, so its hash depends
 *      on JavaScript alone;
 *   2. every view that loads that bundle must ask for kbb.css BY NAME, because
 *      with the import gone the manifest entry no longer carries a `css` array
 *      and @vite has nothing to infer the stylesheet from.
 *
 * Without 2, someone writes `@vite('resources/js/kbb/app.js')` in a new layout
 * and ships a storefront page with no stylesheet at all.
 *
 * MUTATION 1: put `import '../../css/kbb/kbb.css';` back into
 * resources/js/kbb/app.js. Red on the first test.
 * MUTATION 2: drop `'resources/css/kbb/kbb.css'` from the @vite call in
 * resources/views/layouts/store.blade.php. Red on the second, naming the view.
 * Both run and confirmed.
 */

/** The JS entries vite.config.js builds, as repo-relative paths. */
function stableAssetJsEntries(): array
{
    $config = (string) file_get_contents(base_path('vite.config.js'));

    preg_match_all("#'(resources/js/[^']+\.js)'#", $config, $m);

    return array_values(array_unique($m[1]));
}

it('builds the storefront JavaScript from JavaScript alone, so its name moves only when it does', function () {
    $entries = stableAssetJsEntries();

    // The premise. If the config is ever restructured so this finds nothing,
    // every assertion below passes against an empty list.
    expect($entries)->not->toBeEmpty('vite.config.js lists no JavaScript entry — this guard would be checking nothing.');
    expect($entries)->toContain('resources/js/kbb/app.js');

    $importing = [];

    foreach ($entries as $entry) {
        $js = (string) file_get_contents(base_path($entry));

        // Only the import statements, so the paragraph above explaining why
        // there is no CSS import here does not trip its own guard.
        if (preg_match_all('/^\s*import\s+[^;]*?[\'"]([^\'"]+\.css)[\'"]\s*;/m', $js, $m)) {
            foreach ($m[1] as $css) {
                $importing[] = "$entry imports $css";
            }
        }
    }

    expect($importing)->toBe([], implode("\n", [
        'A Vite JavaScript entry imports a stylesheet:',
        ...$importing,
        '',
        'Rollup folds a chunk\'s dependencies into its content hash, so every edit to that',
        'stylesheet will rename the built JavaScript with the JavaScript unchanged — measured',
        'at 45,971 identical bytes under two different names (see the top of this file).',
        'These stylesheets are already Vite entries of their own and the views already name',
        'them, so the import buys nothing and costs an unreviewable rename in every package.',
    ]));
});

it('makes every view that loads the storefront bundle ask for its stylesheet by name', function () {
    /*
     * With the import gone the manifest entry for app.js carries no `css` array,
     * so @vite emits a <script> and nothing else. A view that names the bundle
     * without naming the stylesheet renders a storefront page with no CSS.
     */
    $offenders = [];

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('resources/views')));

    foreach ($it as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $blade = (string) file_get_contents($file->getPathname());
        $rel = str_replace(base_path().'/', '', $file->getPathname());

        // Every @vite(...) call in the view, with its whole argument.
        if (! preg_match_all('/@vite\s*\(\s*(\[[^\]]*\]|[\'"][^\'"]*[\'"])\s*\)/', $blade, $m)) {
            continue;
        }

        foreach ($m[1] as $argument) {
            if (! str_contains($argument, 'resources/js/kbb/app.js')) {
                continue;
            }

            if (! str_contains($argument, 'resources/css/kbb/kbb.css')) {
                $offenders[] = "$rel: @vite($argument)";
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These views load resources/js/kbb/app.js without naming resources/css/kbb/kbb.css:',
        ...$offenders,
        '',
        'app.js deliberately does not import the stylesheet (see the file\'s own header and',
        'BuiltAssetNamesAreStableTest above it), so the manifest entry carries no `css` array',
        'and @vite has nothing to infer it from. The page would render with no storefront CSS.',
    ]));
});

it('serves one app bundle, not the pile of orphans a moving name leaves behind', function () {
    /*
     * The other half of the same defect. Each rename left the previous
     * `app-*.js` in public/build/assets, because nothing removes it and
     * `git add -A` stages the new one beside it: 21 of them had accumulated
     * before commit 965b0f0 deleted them in a batch. Every one of those went
     * into a package as a file the shop would never request.
     */
    $built = glob(base_path('public/build/assets/app-*.js')) ?: [];

    expect($built)->not->toBeEmpty('public/build/assets carries no app bundle at all — run `npx vite build`.');

    $names = array_map('basename', $built);
    sort($names);

    expect($names)->toHaveCount(1, implode("\n", [
        'public/build/assets carries more than one app bundle: '.implode(', ', $names).'.',
        'Only the one manifest.json names is ever served; the rest are dead weight in every',
        'package built from this tree. `npx vite build` writes the current one — delete the',
        'others with `git rm`.',
    ]));

    // And the one that is there is the one the manifest points at.
    $manifest = json_decode((string) file_get_contents(base_path('public/build/manifest.json')), true);
    $served = basename($manifest['resources/js/kbb/app.js']['file'] ?? '');

    expect($names[0])->toBe($served, 'public/build/assets holds '.$names[0].' but manifest.json serves '.$served.' — the build and the manifest are from different runs.');
});
