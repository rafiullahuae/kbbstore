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

it('gives body a page background in exactly one place', function () {
    $withBackground = [];

    foreach (['kbb.css', 'kbb-shop.css', 'kbb-product.css'] as $file) {
        foreach (bgBodyRules($file) as $rule) {
            if (bgDeclaresBackground($rule)) {
                $withBackground[] = $file.'  '.substr($rule, 0, 90);
            }
        }
    }

    expect($withBackground)->toHaveCount(1, "the shop's page background is declared in "
        .count($withBackground)." places:\n  ".implode("\n  ", $withBackground)
        ."\n\nMore than one is how /shop/ came to be white while the home page was pink."
        .' None at all means no page has a background.');

    expect($withBackground[0])->toStartWith('kbb.css');
    expect($withBackground[0])->toContain('background-color:#FDEFF3');
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

it('keeps kbb.css down to one type rule, one phone rule and one background rule', function () {
    $rules = bgBodyRules('kbb.css');

    expect($rules)->toHaveCount(3, "kbb.css has ".count($rules)." body rules:\n  ".implode("\n  ", $rules));

    expect($rules[0])->toContain('font:400 14px/1.6 Outfit')
        ->and($rules[0])->not->toContain('background');
    expect($rules[1])->toContain('padding-bottom:46px');
    expect($rules[2])->toContain('background-color:#FDEFF3');
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
