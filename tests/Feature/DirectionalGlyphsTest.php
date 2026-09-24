<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Locale;
use Tests\Support\CssDirection;

/**
 * §9.2 — THE ARROWHEADS, WHICH THE MIRRORED LAYOUT NEVER REACHED.
 *
 * docs/rtl-audit.md §9.2 left "directional glyphs" open: the carousel and
 * slider arrows' POSITIONS flip correctly and the shapes inside them do not. It
 * also recorded a piece of good news — that the mega-menu caret and the
 * mobile-nav `›` need nothing, because U+203A is in the Unicode bidi mirroring
 * set and flips itself.
 *
 * ── THE GOOD NEWS WAS VERIFIED RATHER THAN INHERITED ────────────────────────
 *
 * Each candidate glyph was rendered in Chromium twice, centred in a fixed box,
 * once in a left-to-right box and once in a right-to-left one, and the two
 * screenshots compared PIXEL FOR PIXEL — centred so that position cannot differ
 * and only shape can:
 *
 *   U+203A ›   mirrored      U+2192 →   identical
 *   U+2039 ‹   mirrored      U+2190 ←   identical
 *   U+00BB »   mirrored      U+25B6 ▶   identical
 *   U+003E >   mirrored      U+2794 ➔   identical
 *                            U+21A9 ↩   identical
 *
 * So §9.2 is right about `›`, and the same measurement says what it does not:
 * an arrow (U+2192/U+2190) is NOT mirrored by anything, and neither an <svg>
 * path nor a background image is a character at all.
 *
 * ── AND ONE THING §9.2 GETS WRONG ───────────────────────────────────────────
 *
 * It names `.sarrow.prev` / `.sarrow.next` as an open item. Nothing renders
 * them: `.sarrow` survives only in kbb.css, and no view, partial, component or
 * script in this repository emits the class. The home slider's arrows are
 * `.sarr`, and they are CHARACTERS (`‹` and `›`), which mirror themselves. The
 * row below pins that, so if `.sarrow` ever gains markup this test is what says
 * the audit's note has become live again.
 */
beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

function fsGlyphState(bool $arabic, bool $mirrored): void
{
    $s = app(SettingsService::class);
    $s->set(Locale::SETTING_ENABLED, $arabic ? '1' : '0');
    $s->set(Locale::SETTING_RTL, $mirrored ? '1' : '0');
    $s->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
}

/**
 * Declarations of one property, read as CSS rather than matched as text —
 * these files discuss their own arrowheads in comments.
 *
 * @return array<string, string> selector => value
 */
function fsGlyphRules(string $relative, string $property): array
{
    $out = [];

    foreach (CssDirection::declarationsInFile(base_path($relative)) as $d) {
        if ($d['property'] === $property) {
            $out[$d['selector']] = $d['value'];
        }
    }

    return $out;
}

it('mirrors every arrowhead that is a path rather than a character', function () {
    // Each of these is an <svg> inside a control whose POSITION already mirrors,
    // so before this the button moved to the other side of the page and went on
    // pointing the way it points in English.
    $expected = [
        // the mobile mega-menu's expand chevron, `M9 6l6 6-6 6`
        'resources/css/kbb/kbb.css' => '[dir="rtl"] .mm-car',
        // "back to cart" on the checkout header, `M15 18l-6-6 6-6`
        'resources/css/kbb/kbb-checkout.css' => '[dir="rtl"] .kbb-checkout .co-tocart svg',
        // the /app preview's carousel buttons, `m15 18-6-6 6-6` and `m9 18 6-6-6-6`
        'resources/views/store/app.blade.php' => '[dir="rtl"] .car-btn svg',
    ];

    expect($expected)->toHaveCount(3);

    $missing = [];

    foreach ($expected as $file => $selector) {
        $rules = fsGlyphRules($file, 'transform');

        if (($rules[$selector] ?? null) !== 'scaleX(-1)') {
            $missing[] = sprintf(
                '%s: `%s` does not mirror its glyph (found %s)',
                $file,
                $selector,
                isset($rules[$selector]) ? '`' . $rules[$selector] . '`' : 'no rule at all'
            );
        }
    }

    expect($missing)->toBe([], implode("\n", $missing));
});

it('leaves the mega-menu chevron open state alone, because down reads the same either way', function () {
    $rules = fsGlyphRules('resources/css/kbb/kbb.css', 'transform');

    // The closed chevron is mirrored; the OPEN one turns to point down and must
    // not be. `.mm-node.on > .mm-par .mm-car` is (0,4,0) against (0,2,0) for the
    // mirror, so it wins on specificity and nothing has to be written twice —
    // but only for as long as nobody adds a [dir="rtl"] twin for it.
    expect($rules)->toHaveKey('.mm-node.on > .mm-par .mm-car');
    expect($rules['.mm-node.on > .mm-par .mm-car'])->toBe('rotate(90deg)');

    $twins = array_filter(
        array_keys($rules),
        static fn (string $s): bool => str_contains($s, '[dir="rtl"]') && str_contains($s, '.mm-node.on')
    );

    expect(array_values($twins))->toBe([], 'A [dir="rtl"] rule has been added for the OPEN chevron; the mirror and the rotation now compose and it points sideways.');
});

it('picks the order-received arrow from the direction, because U+2192 mirrors itself nowhere', function () {
    fsGlyphState(arabic: true, mirrored: true);
    expect(Locale::isRtl('ar'))->toBeTrue();

    $view = file_get_contents(resource_path('views/store/checkout-success.blade.php'));

    // Rendering this page needs a placed order; the contract being pinned is
    // which glyph the direction chooses, so it is asked of the template and of
    // Locale, which is where the choice is actually made.
    expect($view)->toContain("Locale::isRtl() ? '←' : '→'");

    // And the gate is the DIRECTION, not the language: with the mirrored layout
    // off the page still reads left to right, so forward is still to the right.
    fsGlyphState(arabic: true, mirrored: false);
    expect(Locale::isRtl('ar'))->toBeFalse();
});

it('leaves no directional glyph on the storefront that the bidi algorithm will not turn round', function () {
    /*
     * A SWEEP OF EVERY STOREFRONT VIEW AND SCRIPT, not of a list somebody
     * remembered to keep up to date.
     *
     * The rule it enforces is the one the measurement produced: `‹` and `›`
     * (U+2039/U+203A) may be written literally, because they are Bidi_Mirrored
     * and the engine paints them as each other in a right-to-left run — verified
     * pixel for pixel, centred, in both directions. `→`, `←`, `▶` and `➔` are
     * not, so wherever one of them is printed the DIRECTION has to choose it,
     * and the line that prints it has to say so.
     *
     * COMMENTS ARE STRIPPED FIRST, in all four forms these files write them.
     * Almost every file here documents an admin path as "Appearance → Homepage"
     * in prose, and a sweep that reads its own commentary reports the
     * commentary — the trap CLAUDE.md names, and the reason this is not a grep.
     */
    $unmirrored = ['→', '←', '▶', '➔', '↩'];

    /*
     * ONE EXCEPTION, NAMED. store/app.blade.php is the admin-only design
     * preview: it 404s to a shopper, EnglishRenderWalk excludes it for exactly
     * that reason, and its "View all →" strips are hard-coded English inside a
     * verbatim block where no Blade conditional can reach. Its arrowheads are
     * written up in docs/FS-ARABIC-TYPOGRAPHY.md rather than left unsaid. Its
     * carousel CHEVRONS, which are svg and which a stylesheet can reach, are
     * mirrored — the first test in this file pins that.
     */
    $exceptions = ['views/store/app.blade.php'];

    /*
     * MORE, NAMED AND HANDED OFF. store/skin-quiz.blade.php prints `→` several
     * times, and every one of them is inside a TRANSLATABLE DEFAULT —
     * `t('store.quiz.js_start_cta', 'Start the quiz →')`. The arrow is part of
     * the sentence there, so it belongs to whoever writes the Arabic sentence,
     * and a published Arabic string supplies its own. What is left is the
     * English FALLBACK an untranslated Arabic page shows, which is the same gap
     * as every other untranslated string on that page rather than a bidi defect
     * of its own. Written up in docs/FS-ARABIC-TYPOGRAPHY.md.
     *
     * Listed by the key they sit in so that a FOURTH cannot hide behind them,
     * and the test fails if one stops appearing — which is how this list gets
     * deleted rather than outliving the strings.
     */
    $handedOff = [
        'store.quiz.js_see_routine' => 'a translatable default, so the Arabic string carries its own arrow',
        'store.quiz.js_start_sub' => 'a translatable default; the arrow means "leads to" inside a sentence',
        'store.quiz.js_start_cta' => 'a translatable default, so the Arabic string carries its own arrow',
        /*
         * ARRIVED AFTER THIS SWEEP WAS WRITTEN, from Lane FT's quiz -> routine
         * hand-off, and it is the same shape as the three above rather than a
         * new case: a translatable default whose arrow is part of the sentence
         * it ends. An Arabic translation of "Build my :concern routine →"
         * carries whatever arrow that sentence wants, in whatever position it
         * wants it; what is left here is the ENGLISH fallback an untranslated
         * Arabic page shows, which is the same thing the other three leave.
         *
         * Worth saying why it is an exception and not a fix: the alternative is
         * to strip the arrow out of the key and append a direction-chosen one,
         * which takes a punctuation decision away from the translator for the
         * sake of a fallback that is English anyway.
         */
        'store.quiz.js_routine_link_cta' => 'a translatable default, so the Arabic string carries its own arrow',
        /*
         * LANE Q'S, AND THE FIFTH OF THE SAME KIND rather than a new one. The
         * quiz now falls back from the routine page to the concern collection
         * page when the routine module is off, and that fallback's button ends
         * its sentence with the same arrow in the same position. Everything the
         * note above says about `js_routine_link_cta` applies here unchanged:
         * the arrow is inside a translatable default, so an Arabic translation
         * of "Shop :concern →" carries whatever arrow that sentence wants, and
         * what is left is the English fallback an untranslated Arabic page
         * shows.
         */
        'store.quiz.js_concern_link_cta' => 'a translatable default, so the Arabic string carries its own arrow',
    ];

    $seenHandedOff = [];

    $offenders = [];
    $scanned = 0;

    foreach (['views/store', 'views/partials', 'views/components', 'views/layouts', 'js/kbb'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path($dir)));

        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace(resource_path() . '/', '', $file->getPathname());

            if (in_array($relative, $exceptions, true)) {
                continue;
            }

            $scanned++;

            $source = (string) preg_replace(
                ['/\{\{--.*?--\}\}/s', '/<!--.*?-->/s', '#/\*.*?\*/#s', '#^\s*//.*$#m'],
                '',
                (string) file_get_contents($file->getPathname())
            );

            // The stripped copy is what is READ, so a line NUMBER from it would
            // point at the wrong line of the real file. The offending text is
            // reported instead, which is what somebody has to search for anyway.
            foreach (explode("\n", $source) as $line) {
                foreach ($unmirrored as $glyph) {
                    if (! str_contains($line, $glyph)) {
                        continue;
                    }

                    // Chosen by the direction, on the same line, is the contract.
                    if (preg_match('/isRtl|getAttribute\(\x27dir\x27\)|dir\s*===?\s*\x27rtl\x27/', $line) === 1) {
                        continue;
                    }

                    foreach ($handedOff as $key => $why) {
                        if (str_contains($line, $key)) {
                            $seenHandedOff[$key] = true;

                            continue 2;
                        }
                    }

                    $offenders[] = sprintf('%s prints %s without letting the direction choose it, in: %s', $relative, $glyph, trim(mb_substr(trim($line), 0, 100)));
                }
            }
        }
    }

    expect($scanned)->toBeGreaterThan(40, 'The sweep found almost no storefront files to read; it is asserting on nothing.');
    expect($offenders)->toBe([], implode("\n", $offenders));

    $stale = array_diff(array_keys($handedOff), array_keys($seenHandedOff));

    expect($stale)->toBe([], implode("\n", array_map(
        static fn (string $k): string => "`{$k}` no longer prints a bare arrow — {$handedOff[$k]} — so delete this exception rather than leaving it to cover something else.",
        $stale
    )));
});

it('keeps the nav chevrons on the characters that do mirror themselves', function () {
    // The other half of §9.2's good news, turned into a guard: these four
    // surfaces have always used `‹`/`›`, which need nothing, and this is what
    // notices if one is ever "tidied up" into an arrow that needs everything.
    $surfaces = [
        'resources/views/store/home.blade.php' => ['‹', '›'],         // the hero slider
        'resources/views/store/shop.blade.php' => ['‹', '›'],         // pagination
        'resources/js/kbb/mobile-nav.js' => ['‹'],                    // the sub-panel back button
        'resources/views/store/account/orders.blade.php' => ['›'],    // the order row
    ];

    expect($surfaces)->toHaveCount(4);

    $wrong = [];

    foreach ($surfaces as $file => $glyphs) {
        $source = (string) file_get_contents(base_path($file));

        foreach ($glyphs as $glyph) {
            if (! str_contains($source, $glyph)) {
                $wrong[] = "{$file} no longer uses {$glyph}, the character that mirrors itself";
            }
        }
    }

    expect($wrong)->toBe([], implode("\n", $wrong));
});

it('records that .sarrow renders nowhere, so the audit row for it is stale', function () {
    // §9.2 lists `.sarrow.prev` / `.sarrow.next` among the open directional
    // glyphs. Only kbb.css still mentions the class.
    $markup = [];

    foreach ([resource_path('views'), resource_path('js')] as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), 'sarrow')) {
                $markup[] = str_replace(base_path() . '/', '', $file->getPathname());
            }
        }
    }

    expect($markup)->toBe([], "docs/rtl-audit.md §9.2 calls .sarrow an open directional glyph on the assumption that nothing renders it. Something does now:\n" . implode("\n", $markup) . "\nIts arrowhead has to be mirrored like the three in the first test in this file.");
});
