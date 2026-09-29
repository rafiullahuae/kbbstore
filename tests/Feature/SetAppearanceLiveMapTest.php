<?php

declare(strict_types=1);

use App\Services\SetAppearance;
use App\Services\SetAppearanceLiveMap;
use Illuminate\Support\Facades\Cache;

/**
 * The live preview's fast path is derived from the stylesheet, and stays so.
 *                                                                   (Lane SA3)
 *
 * Appearance → Set redraws its preview as the owner drags, without a request,
 * by re-declaring the very custom properties SetAppearance::css() declares. To
 * do that the screen has to know which property each of 198 controls writes and
 * in what shape — px, tenths of a px, hundredths, an em, a negated ratio.
 *
 * The obvious way to give it that is a table, and this project has twice paid
 * for a second copy of something that stopped agreeing with the first. So
 * SetAppearanceLiveMap derives the whole thing by rendering css() once per
 * field and diffing. What this file pins is the property that makes the
 * derivation safe to rely on:
 *
 *   1. THE MAP IS RIGHT AT EVERY VALUE, not merely at the three it was fitted
 *      at. Every mapped slider is walked across its whole range and the
 *      declaration the map predicts is the declaration css() emits.
 *   2. THE FIELDS IT DECLINES ARE EXACTLY THE STRUCTURAL ONES, named here, so
 *      a field silently dropping out of the fast path is a red test and not a
 *      preview that quietly got slower.
 *   3. THE SHIPPED JAVASCRIPT AGREES WITH PHP about how a number is printed —
 *      tested by extracting the line out of the Blade and running it.
 *
 * MUTATION NOTE — RUN. Change `self::DIVISORS` in SetAppearanceLiveMap from
 * `[1, 10, 100]` to `[1, 100]` and case 2 goes red naming twelve tenths-of-a-
 * pixel fields (p_ring, p_head_f, p_name, p_var, p_more and their `_m` twins)
 * that have fallen off the fast path. Change `'--ksl-nm:'.self::ratio($n('p_name'), 10).'px'`
 * in SetAppearance::listVars() to `…, 100)` and case 1 goes red on p_name at
 * the first value it is walked to.
 */

/** Every field the map claims, with the slider's own bounds beside it. */
function saLiveRanges(): array
{
    $out = [];

    foreach (SetAppearanceLiveMap::build()['fields'] as $key => $entry) {
        if (($entry['c'] ?? false) === true) {
            continue;
        }

        $spec = SetAppearance::SCHEMA[$key];
        $options = is_array($spec[4] ?? null) ? $spec[4] : [];

        $out[$key] = [
            'entry' => $entry,
            'min' => (int) ($options['min'] ?? 0),
            'max' => (int) ($options['max'] ?? 0),
            'step' => max(1, (int) ($options['step'] ?? 1)),
        ];
    }

    return $out;
}

it('predicts the declaration css() really emits, at every step of every slider', function () {
    $defaults = SetAppearance::defaults();
    $ranges = saLiveRanges();

    expect(count($ranges))->toBeGreaterThan(
        130,
        'Almost nothing is on the fast path, so this test is walking a handful of fields and would '
        .'pass on a map that had collapsed.'
    );

    $checked = 0;

    foreach ($ranges as $key => $about) {
        $entry = $about['entry'];

        /* Walked, not sampled at the three points the fit used — the whole
           point is that a fit good at three values is not yet a fit. Long
           sliders are walked in bigger strides so the file stays a second
           rather than a minute, but both end stops are always included. */
        $span = $about['max'] - $about['min'];
        $stride = max($about['step'], (int) ceil($span / 12 / $about['step']) * $about['step']);

        $values = [];

        for ($v = $about['min']; $v <= $about['max']; $v += $stride) {
            $values[] = $v;
        }

        $values[] = $about['max'];

        foreach (array_unique($values) as $value) {
            $expected = $entry['p'].':'.SetAppearanceLiveMap::format(
                $value,
                $entry['d'],
                $entry['n'],
                $entry['s'],
            );

            $css = SetAppearance::css([$key => $value] + $defaults);

            expect(str_contains($css, $expected))->toBeTrue(
                "The live map says moving `{$key}` to {$value} writes `{$expected}`, and the stylesheet "
                .'SetAppearance::css() actually emits does not contain that declaration. The map is '
                .'derived from css(), so this means css() has grown a shape the fitter cannot read — '
                .'and the preview would be drawing a number the shop will not.'
            );

            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(1500);
});

it('puts each field in the block it really belongs to, laptop or phone', function () {
    /*
     * A laptop field and its `_m` twin write the SAME property — `--kset-top`
     * is declared twice, once at `.kset.kset` and once inside the phone's media
     * query. Getting the block wrong would therefore still emit a valid-looking
     * declaration and would silently pin the phone's value to the laptop's,
     * which is the one distinction this screen exists to keep.
     *
     * MUTATION NOTE — RUN. Swap `$m` to `! $m` in SetAppearance::boxVars()'s
     * accessor and this goes red naming top/top_m at `--kset-top`.
     */
    $map = SetAppearanceLiveMap::build();
    $defaults = SetAppearance::defaults();

    $pairs = 0;

    foreach ($map['fields'] as $key => $entry) {
        if (($entry['c'] ?? false) === true || ! isset($map['fields'][$key.'_m'])) {
            continue;
        }

        $twin = $map['fields'][$key.'_m'];

        expect($twin['p'])->toBe(
            $entry['p'],
            "`{$key}` and `{$key}_m` are the same measurement at two widths and must write one property."
        );

        expect($map['blocks'][$entry['b']]['mq'])->toBeNull(
            "`{$key}` is the laptop value and must not be inside a media query."
        );

        expect($map['blocks'][$twin['b']]['mq'])->not->toBeNull(
            "`{$key}_m` is the phone value and must be inside the media query its breakpoint drives."
        );

        /* And the query really is the one the schema's breakpoint field writes:
           the screen rebuilds the prelude from that field's current value, so a
           block pointed at the wrong breakpoint would apply the phone's numbers
           over the wrong span the moment the owner moved either slider. */
        $bp = $map['blocks'][$twin['b']]['mq'];

        expect(str_contains(
            SetAppearance::css($defaults),
            '@media (max-width:'.(int) $defaults[$bp].'px)'
        ))->toBeTrue();

        $pairs++;
    }

    expect($pairs)->toBeGreaterThan(50);
});

it('declines exactly the controls a custom property cannot express', function () {
    /*
     * The fast path is a re-declaration of custom properties. Five kinds of
     * control are not that, and every one of them is listed here by name so
     * that a field falling off the fast path for some other reason — a fitter
     * that stopped recognising a shape, a css() change — is a red test rather
     * than a preview that has quietly gone back to one request per drag.
     *
     * MUTATION NOTE — RUN. Delete the `str_starts_with($diff[0]['prop'], '--')`
     * clause from SetAppearanceLiveMap::changedBy() and this goes red: ci_gap
     * and ci_name_gap join the map, because they happen to be whole
     * declarations of their own inside a compiled stylesheet's rule.
     */
    $declined = array_values(array_diff(
        array_keys(SetAppearance::SCHEMA),
        array_keys(SetAppearanceLiveMap::build()['fields'])
    ));

    sort($declined);

    $switches = array_values(array_filter(
        array_keys(SetAppearance::SCHEMA),
        static fn (string $k): bool => SetAppearance::SCHEMA[$k][0] === 'bool'
    ));

    $expected = array_merge(
        // Every on/off switch: they add or remove a `display:none` RULE, and
        // one of them changes the class list on `.ksl` itself.
        $switches,
        [
            // The fan's cap is an nth-child() in a SELECTOR.
            'fan_max',
            // The fold is a SERVER decision: the rows past it are inside a
            // <details> in the markup, which is what makes it work with no
            // script and keeps every member in the page for a crawler.
            'p_fold_at',
            // The three media queries' own widths.
            'bp', 'p_bp', 'ci_bp',
            // And the set row on the cart page, whose padding, gaps, minimum
            // height, picture and type sizes are real properties re-declared
            // over a compiled Vite stylesheet rather than variables — three of
            // them share one `padding` shorthand.
            //
            // ▲ ADVANCED DELIBERATELY, 29 September (Lane CR): thirteen keys
            //   added, from five to eighteen. The owner asked for a set's row
            //   to have the same depth an ordinary row now has — a minimum
            //   height, the picture's size and radius, the brand and name
            //   sizes, the space around the stepper, and where the circles sit
            //   — and every one of them is the same KIND of control as the five
            //   already here: a property over the compiled sheet, not a custom
            //   property. So they decline for the reason this list exists and
            //   the preview re-renders for them, which is what it already did
            //   for the five beside them.
            'ci_min_h', 'ci_pad_t', 'ci_pad_b', 'ci_pad_x', 'ci_gap', 'ci_name_gap',
            'ci_thumb', 'ci_thumb_r', 'ci_brand_f', 'ci_name_f', 'ci_qty_top', 'ci_qty_bot',
            // A flex keyword on `.kset`, from a select rather than a slider.
            'ci_box_align',
            'ci_min_h_m', 'ci_pad_t_m', 'ci_pad_b_m', 'ci_pad_x_m', 'ci_gap_m', 'ci_name_gap_m',
            'ci_thumb_m', 'ci_thumb_r_m', 'ci_brand_f_m', 'ci_name_f_m',
            'ci_qty_top_m', 'ci_qty_bot_m',
        ]
    );

    sort($expected);

    expect($declined)->toBe($expected);
    // 38 until 29 September; the thirteen above and their twins are why.
    expect(count($declined))->toBe(53);
    // Unmoved, and that is the point: not one control LEFT the fast path.
    expect(count(SetAppearanceLiveMap::build()['fields']))->toBe(160);
});

it('prints a number the same way in the Blade’s JavaScript as it does in PHP', function () {
    /*
     * THE ONE JOINT THIS DESIGN HAS. Everything else about the fast path is
     * derived in PHP from css() itself and cannot drift. The screen, though,
     * has to turn the map's {divisor, negate, suffix} back into text in
     * JavaScript, and if that one line disagrees with
     * SetAppearanceLiveMap::format() the preview shows a number the shop will
     * never draw.
     *
     * So the line is not re-stated here: it is CUT OUT OF THE SHIPPED BLADE and
     * run. If somebody rewrites it, this runs the rewrite.
     *
     * MUTATION NOTE — RUN. Change `String(n / f.d)` to `(n / f.d).toFixed(2)`
     * in resources/views/admin/partials/set-appearance-screen.blade.php and this
     * goes red on the first whole-number ratio it reaches (`1` against `1.00`).
     */
    if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
        $this->markTestSkipped('node is not on PATH; the CI image has it.');
    }

    $blade = (string) file_get_contents(
        resource_path('views/admin/partials/set-appearance-screen.blade.php')
    );

    expect(preg_match(
        "/\n(\s*return \(f\.n [^\n]*f\.s;)\n/",
        $blade,
        $m
    ))->toBe(1, 'The screen no longer carries a single-line number formatter this test can extract.');

    $formula = trim($m[1]);

    $cases = [];

    foreach (saLiveRanges() as $key => $about) {
        foreach ([$about['min'], $about['max'], (int) (($about['min'] + $about['max']) / 2)] as $value) {
            $cases[] = [
                'f' => $about['entry'],
                'n' => $value,
                'php' => SetAppearanceLiveMap::format(
                    $value,
                    $about['entry']['d'],
                    $about['entry']['n'],
                    $about['entry']['s'],
                ),
                'key' => $key,
            ];
        }
    }

    $script = 'const cases = '.json_encode($cases).";\n"
        .'function v(f, n) { '.$formula." }\n"
        ."const bad = cases.filter(c => v(c.f, c.n) !== c.php)\n"
        ."  .map(c => c.key + '@' + c.n + ': js=' + v(c.f, c.n) + ' php=' + c.php);\n"
        ."process.stdout.write(bad.slice(0, 8).join('\\n'));\n";

    $file = tempnam(sys_get_temp_dir(), 'saliv').'.mjs';
    file_put_contents($file, $script);

    $out = trim((string) shell_exec('node '.escapeshellarg($file).' 2>&1'));

    @unlink($file);

    expect($out)->toBe('', 'The screen prints at least one value differently from PHP: '.$out);
    expect(count($cases))->toBeGreaterThan(400);
});

it('derives the map once and reads it back from a key the code itself decides', function () {
    /*
     * Deriving the map renders SetAppearance::css() about 750 times — ~135ms
     * here, and several times that on the shop's own box — on every open of
     * Appearance → Set. It is cached under a hash of the two files it is
     * derived from, so a package that changes either one lands on a different
     * key and nothing has to remember to flush anything.
     *
     * MUTATION NOTE — RUN. Rename `$cacheKey` back to `$key` in
     * SetAppearanceLiveMap::build() and this goes red: the `foreach
     * (SetAppearance::SCHEMA as $key => …)` below it shadows the variable, the
     * map is written under 'ci_name_gap_m', and every read misses. That is the
     * bug this test was written after finding, and a timing assertion is what
     * catches it — the map itself is correct either way.
     */
    Cache::flush();
    SetAppearanceLiveMap::forget();

    $at = microtime(true);
    $cold = SetAppearanceLiveMap::build();
    $coldMs = (microtime(true) - $at) * 1000;

    SetAppearanceLiveMap::forget();

    $at = microtime(true);
    $warm = SetAppearanceLiveMap::build();
    $warmMs = (microtime(true) - $at) * 1000;

    expect($warm)->toBe($cold);

    /* A tenth, against a measured three-hundredth: the margin is there so a
       busy machine cannot fail this, and a cache that is not being read
       cannot pass it. */
    expect($warmMs)->toBeLessThan(
        $coldMs / 10,
        sprintf('The second build took %.1fms against the first\'s %.1fms, so nothing was cached.', $warmMs, $coldMs)
    );

    /* And with no cache at all the answer is the same, because the cache is an
       optimisation and never the source. */
    Cache::flush();
    SetAppearanceLiveMap::forget();

    expect(SetAppearanceLiveMap::build())->toBe($cold);
});
