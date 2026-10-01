<?php

declare(strict_types=1);

/**
 * The shop has ONE page background, declared once.                   (Lane BG)
 *
 * ── WHAT WAS WRONG, MEASURED ON THE RENDERED PAGE ──────────────────────────
 *
 * `resources/css/kbb/kbb.css` declared FOUR `body` rules and
 * `kbb-shop.css` and `kbb-product.css` each declared a fifth and a sixth. Which
 * declarations reached a shopper was not answerable by reading them in order,
 * because those last two sheets are pushed onto `@stack('styles')` AFTER
 * kbb.css — so the cascade depended on which page was being served.
 * `getComputedStyle(document.body)` on ten URLs said:
 *
 *     home, cart, checkout, wishlist   rgb(253,239,243) + the botanical SVG
 *     /shop/, a product page           rgb(255,255,255), background-image none
 *     the journal, an article          rgb(255,255,255)   (own document, own sheet)
 *     the review wall                  rgb(255,248,245) + its own cream fade
 *     the skin quiz                    transparent + three radial gradients
 *
 * Nobody chose two backgrounds for the storefront. `background:var(--bg)` in
 * the shop and product sheets is part of a BASE rule — family, colour, line
 * height — duplicated out of kbb.css, and the background came with the copy.
 * Two of kbb.css's own four rules were dead outright: the first re-declared
 * nothing the second did not restate, and the second's `background:#fff` could
 * never win against the designed rule 700 lines below it.
 *
 * ── WHAT THIS PINS, AND WHAT IT DELIBERATELY DOES NOT ──────────────────────
 *
 * It pins that exactly one rule in the three shared sheets gives `body` a
 * background, and that the two sheets loaded after kbb.css give it none.
 *
 * It pins the OPPOSITE for the review wall and the skin quiz: those two are
 * compositions written out in named colours — a cream fade to 520px, three
 * radial gradients in pink and lilac — and flattening art direction is not the
 * same act as removing a duplicated base rule. A later lane that "makes them
 * consistent" fails here by name.
 *
 * MUTATION NOTES, both run in this lane's worktree:
 *   - put `background:var(--bg);` back in kbb-shop.css's body rule
 *       → "kbb-shop.css gives body a background again", and /shop/ measures
 *         rgb(255,255,255) with no image, which is the defect.
 *   - delete the `background-color:#FDEFF3` line from kbb.css
 *       → "no stylesheet gives body a page background at all".
 */
function bgBodyRules(string $file): array
{
    $src = (string) file_get_contents(resource_path('css/kbb/'.$file));

    // Comments first, and it is not a nicety: this lane replaced two dead rules
    // with comments that QUOTE them, so a naive scan reads the explanation as
    // the thing it explains and this guard passes on the defect it is for.
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    preg_match_all('/(?:^|[}\n;])\s*(body\s*\{[^}]*\})/m', $src, $m);

    return array_map(static fn (string $r): string => (string) preg_replace('/\s+/', ' ', $r), $m[1]);
}

/** Does this rule text give `body` a background of any kind? */
function bgDeclaresBackground(string $rule): bool
{
    return (bool) preg_match('/(^|[{;])\s*background(-color|-image)?\s*:/', $rule);
}

it('paints the page background in exactly one place, and it is a layer now', function () {
    /*
     * ▲ THE PAGE BACKGROUND MOVED OFF `body` AND ONTO A LAYER. (Lane BG)
     *
     * This case used to require exactly ONE body rule in the three sheets to
     * declare a background, and that it was kbb.css's `background-color:#FDEFF3`
     * under the designed gradient. The owner then chose "Corner light" from
     * five previews, and it is painted on `html::before` -- a fixed-position
     * layer -- rather than on `body`, because `background-attachment:fixed` is
     * the only other way to keep a diagonal from being stretched over the whole
     * document and it costs a full-background repaint per scroll frame on iOS
     * Safari. Measured at 390 on a 5,600px page, sampling one viewport point at
     * the top, middle and bottom:
     *
     *     attachment:scroll   255,245,236 -> 254,249,251 -> 253,234,243
     *     attachment:fixed    254,250,252 -> 254,250,252 -> 255,251,253
     *     a fixed-POSITION layer   254,250,252 -> 254,250,252 -> 254,250,252
     *
     * THE GUARD IS THE SAME GUARD. What it exists to catch is two rules fighting
     * over the page background -- the defect that had /shop/ white while the home
     * page was pink -- and that question is now asked about the layer and about
     * `body` together.
     */
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

    preg_match_all('/(?:^|[}\n])\s*(html::before\s*\{[^}]*\})/m', $css, $m);

    expect($m[1])->toHaveCount(1, 'kbb.css paints '.count($m[1]).' html::before layers; one is the'
        .' page background and a second would sit on top of it invisibly');

    expect($m[1][0])->toContain('var(--kbb-page-gradient)')
        ->and($m[1][0])->toContain('z-index:-4');

    /*
     * `position:fixed` is in the SHARED block, not in the rule above -- the
     * three layers declare their box once between them. Asserting it on
     * html::before alone was the first shape here and it failed on correct CSS,
     * which is the same "read the wrong node" mistake this lane spent a round
     * removing from other people's tests.
     */
    expect($css)->toContain("html::before,body::before,body::after{")
        ->and($css)->toContain('position:fixed')
        ->and($css)->toContain('pointer-events:none');

    /*
     * AND NO BODY RULE IN ANY OF THE THREE SHEETS PAINTS AN IMAGE. The flat
     * colour under the layer is fine and is what stops a frame with nothing
     * behind the text; a second background IMAGE is the thing that fights.
     */
    $imaged = [];

    foreach (['kbb.css', 'kbb-shop.css', 'kbb-product.css'] as $file) {
        foreach (bgBodyRules($file) as $rule) {
            if (preg_match('/(^|[{;])\s*background(-image)?\s*:\s*(?!none)/', $rule)) {
                $imaged[] = $file.'  '.substr($rule, 0, 90);
            }
        }
    }

    expect($imaged)->toBe([], "a body rule paints a page background image again:\n  "
        .implode("\n  ", $imaged)
        ."\n\nThe page background is html::before. A body background propagates to the canvas,"
        .' which paints BEHIND every one of those layers, so a second one there is both a fight'
        .' and invisible.');
});

it('leaves the two sheets that load after kbb.css out of it', function () {
    /*
     * The whole mechanism of the defect: these two are pushed onto
     * @stack('styles') after kbb.css, so ANY background they give body wins on
     * the pages that load them — which are /shop/, a category, a brand page and
     * every product page.
     */
    foreach (['kbb-shop.css', 'kbb-product.css'] as $file) {
        foreach (bgBodyRules($file) as $rule) {
            expect(bgDeclaresBackground($rule))
                ->toBeFalse($file.' gives body a background again: '.$rule);
        }

        // and they still do the job they are actually for
        expect(implode(' ', bgBodyRules($file)))
            ->toContain('font-family:var(--sans)')
            ->toContain('color:var(--ink)');
    }
});

it('keeps kbb.css down to a type rule, a phone rule, the flat colour and the print reset', function () {
    /*
     * ▲ THREE BECAME FOUR, AND THE FOURTH IS `@media print`. (Lane BG)
     *
     * "Corner light" paints on layers, so the page background's body rule is
     * now a flat colour with `background-image:none`, and a print rule was
     * added beside it that hides the three layers and puts the page back to
     * white -- printed documents should not carry a gradient. This scanner
     * reads body rules wherever they are, media query or not, so that print
     * rule is the fourth. It is counted rather than excluded: a fifth body rule
     * is still the thing worth noticing.
     */
    $rules = bgBodyRules('kbb.css');

    /*
     * ▲ AND A FIFTH, NOTICED (2.60.337): on touch screens the three fixed
     * full-screen layers are switched off and the wash is painted once on
     * `html`, so body goes transparent there to let it through. Pinned to that
     * exact rule and to that media query, so a fifth that is anything else is
     * still red.
     */
    $src = (string) preg_replace(['#/\*.*?\*/#s', '/\s+/'], ['', ' '], (string) file_get_contents(resource_path('css/kbb/kbb.css')));
    expect($src)->toContain('@media (hover:none) and (pointer:coarse){ html::before,body::before,body::after{display:none} html{background:var(--kbb-page-gradient)} body{background-color:transparent} }');
    expect($rules[4] ?? null)->toBe('body{background-color:transparent}');
    $rules = array_slice($rules, 0, 4);

    expect($rules)->toHaveCount(4, 'kbb.css has '.count($rules)." body rules:\n  ".implode("\n  ", $rules));

    expect($rules[0])->toContain('font:400 14px/1.6 Outfit')
        ->and($rules[0])->not->toContain('background');
    expect($rules[1])->toContain('padding-bottom:46px');
    expect($rules[2])->toContain('background-color:#FDEFF3')
        ->and($rules[2])->toContain('background-image:none');
    expect($rules[3])->toContain('background-color:#fff');
});

it('does not flatten the review wall or the skin quiz', function () {
    /*
     * Asserted in the positive, so that "make them all consistent" fails here
     * rather than quietly deleting two designed pages. Both are compositions in
     * named colours, and both were measured as such before they were left
     * alone.
     */
    $wall = (string) file_get_contents(resource_path('views/store/review-wall.blade.php'));
    $quiz = (string) file_get_contents(resource_path('views/store/skin-quiz.blade.php'));

    expect($wall)->toContain('background-image:linear-gradient(180deg,#fff,var(--cream) 520px)');
    expect($quiz)->toContain('radial-gradient(55% 45% at 88% -6%,#FFD3E4,transparent 70%)');
});
