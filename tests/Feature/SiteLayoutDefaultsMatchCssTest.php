<?php

declare(strict_types=1);

use App\Services\SiteLayout;

/**
 * The shipped defaults live in TWO places, and this is what keeps them one. W1
 *
 * ── WHY THERE ARE TWO PLACES AT ALL ─────────────────────────────────────────
 *
 * SiteLayout::cssVariables() returns the EMPTY STRING while every setting is at
 * its shipped value, so a shop that applies the package and touches nothing
 * gains not one byte on any page — which is rule 1, and which is why the
 * defaults have to be expressed in the stylesheet as well as in the schema. The
 * stylesheet is where the shop actually gets them; the schema is where the
 * screen draws them and where a save is validated.
 *
 * Two copies of a number is exactly the shape this repo keeps paying for, so
 * they are pinned against each other rather than trusted. If either moves alone
 * this file goes red and names the pair.
 *
 * A THIRD COPY exists and is unavoidable: store/blog.blade.php and
 * store/post.blade.php are STANDALONE DOCUMENTS with their own <html>, their own
 * <head> and their own :root, and they load no shared stylesheet — so the only
 * way they can have a default is `var(--site-max, 1680px)` written in their own
 * inline style. That fallback is pinned here too.
 */
function w1Root(): string
{
    $sheet = (string) file_get_contents(base_path('resources/css/kbb/kbb.css'));

    // Comments stripped: this sheet's :root block explains every token at
    // length and names several numbers in prose that are not declarations.
    return (string) preg_replace('#/\*.*?\*/#s', '', $sheet);
}

it('declares the same number in kbb.css as the schema ships', function (string $key, string $token, string $unit) {
    /*
     * MUTATION: change `--site-max:1680px` to 1400px in kbb.css and leave the
     * schema at 1680 — this is red, naming the pair. That is the one defect this
     * file exists for, and it is not hypothetical: the stylesheet is what the
     * shop renders from, so a schema that disagrees with it shows the owner a
     * number his shop is not using and stays that way until he moves the slider.
     */
    $fields = SiteLayout::SCHEMA;

    expect($fields)->toHaveKey($key);

    $default = $fields[$key][2];

    /*
     * toContain takes NEEDLES and not a message — Pest's `toContain` is variadic,
     * so a "helpful" second argument becomes a second string the haystack must
     * also contain, and the assertion fails on the message itself. That is how
     * the first draft of this file reported nine failures whose text was its own
     * error messages. assertStringContainsString takes the message.
     */
    expect(str_contains(w1Root(), $token.':'.$default.$unit))->toBeTrue(
        "kbb.css must declare {$token}:{$default}{$unit} to match SiteLayout::SCHEMA['{$key}']"
    );
})->with([
    ['max', '--site-max', 'px'],
    ['gutter', '--site-gutter-min', 'px'],
    ['gutter_wide', '--site-gutter-max', 'px'],
    ['tile', '--kbb-tile', 'px'],
    ['gap', '--kbb-gap', 'px'],
    ['cols_floor', '--kbb-cols-floor', ''],
    ['cols_cap', '--kbb-cols-cap', ''],
]);

it('gives the shop listing its own tile minimum, in the sheet and in the schema', function () {
    /*
     * `tile_shop` is not in :root — it is declared on `#grid` in kbb-shop.css,
     * because it belongs to one grid rather than to the page. It reaches there
     * through a fallback, `var(--kbb-tile-shop, 220px)`, so the setting can
     * override it from the emitted :root block without the sheet naming the
     * setting.
     *
     * MUTATION: change either 220 and this is red.
     */
    $shop = (string) preg_replace(
        '#/\*.*?\*/#s',
        '',
        (string) file_get_contents(base_path('resources/css/kbb/kbb-shop.css'))
    );

    expect(SiteLayout::SCHEMA['tile_shop'][2])->toBe(220);
    expect($shop)->toContain('--kbb-tile:var(--kbb-tile-shop,220px)');
});

it('gives the standalone documents the same fallback the shared sheet declares', function () {
    /*
     * THE THIRD COPY, and the reason it has to exist: the Journal index and an
     * article page each carry their own <html> and load no shared stylesheet, so
     * `var(--site-max)` resolves to nothing there unless a fallback is written
     * in. Appearance → Site layout emits its block into those two heads as well,
     * so a moved slider still reaches them; the fallback is what they use until
     * one moves.
     *
     * MUTATION: change 1680 to 1400 in either view and this is red.
     */
    $max = SiteLayout::SCHEMA['max'][2];
    $lo = SiteLayout::SCHEMA['gutter'][2];
    $hi = SiteLayout::SCHEMA['gutter_wide'][2];

    foreach (['store/blog.blade.php', 'store/post.blade.php'] as $view) {
        $source = (string) file_get_contents(base_path('resources/views/'.$view));

        expect(str_contains($source, "max-width:var(--site-max,{$max}px)"))->toBeTrue($view);
        expect(str_contains(
            $source,
            "clamp(var(--site-gutter-min,{$lo}px),2.2vw,var(--site-gutter-max,{$hi}px))"
        ))->toBeTrue($view);
    }
});

it('finds no var(--site-max) fallback anywhere in resources/views that disagrees', function () {
    /*
     * The general form of the case above, so a THIRD standalone document added
     * later cannot ship a stale number. Every `var(--site-max, N)` in
     * resources/views must name the schema's default.
     *
     * MUTATION: write `var(--site-max,1400px)` into any view and this is red,
     * with the file and the number in the message.
     */
    $max = SiteLayout::SCHEMA['max'][2];
    $found = 0;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('resources/views'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (! preg_match_all('/var\(--site-max,\s*(\d+)px\)/', $source, $m)) {
            continue;
        }

        foreach ($m[1] as $value) {
            $found++;
            expect((int) $value)->toBe($max, 'stale --site-max fallback in '.$file->getFilename());
        }
    }

    // And the guard is actually looking at something.
    expect($found)->toBeGreaterThan(0);
});

it('emits every numeric setting into a property named by a constant in the service', function () {
    /*
     * Rule 5, from the other end: the map from setting to custom property is a
     * private constant, so the only thing a save can influence is the number.
     * This case pins that EVERY numeric field is in that map — a field added
     * later with no property is a slider that saves and moves nothing, which is
     * the exact defect ProductStyles' "Columns · tablet" has today.
     *
     * MUTATION: add a `range` field to SCHEMA without adding it to PX_VARS or
     * UNITLESS_VARS and this is red, naming the key.
     */
    $reflection = new ReflectionClass(SiteLayout::class);

    $mapped = array_merge(
        array_keys($reflection->getConstant('PX_VARS')),
        array_keys($reflection->getConstant('UNITLESS_VARS')),
    );

    foreach (SiteLayout::SCHEMA as $key => $definition) {
        if ($definition[0] !== 'range') {
            continue;
        }

        expect(in_array($key, $mapped, true))->toBeTrue(
            "range field '{$key}' is emitted as no custom property"
        );
    }

    // The two that are not ranges are handled explicitly and named here so a
    // reader knows they were not forgotten.
    expect(SiteLayout::SCHEMA['header_follows'][0])->toBe('bool');
    expect(SiteLayout::SCHEMA['pin'][0])->toBe('select');
});
