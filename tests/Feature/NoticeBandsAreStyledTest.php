<?php

declare(strict_types=1);

/**
 * A NOTICE THAT DOES NOT LOOK LIKE ONE. (Lane PLC, round 2)
 *
 * ── THE DEFECT, FOUND BY LOOKING AT A PICTURE AND THEN MEASURED ────────────
 *
 * This lane put a refusal on the basket page and shot it. The sentence was
 * right and it was rendered in plain body text, because `.co-note` lives in
 * resources/css/kbb/kbb-checkout.css and the basket page does not load that
 * stylesheet. The partial now carries its own rules.
 *
 * Asked to check whether that was a CLASS of defect rather than one instance,
 * the storefront was walked: every page, the stylesheets it actually loads, and
 * every class on it whose name says "notice". One more came back, and it was
 * worse than the first because it was on the checkout itself:
 *
 *     .co-note        background rgb(255,248,245)  colour rgb(94,84,90)   400
 *     .co-note.err    background rgb(255,248,245)  colour rgb(94,84,90)   400
 *     .co-note.ok     background rgb(238,248,241)  colour rgb(31,125,82)  600
 *
 * Measured in Chromium at 1280 on the real checkout. There was NO `.co-note
 * .err` rule anywhere in resources/css — the only `.err` in the whole tree is
 * `.sr-msg.err` in sorina-reviews.css, scoped to a different element. So a
 * refused Place order, a rejected coupon, a declined gateway and the
 * Place-order overlay's own failure notice all rendered as a neutral aside,
 * while a CONFIRMATION was green and bold: the emphasis on exactly the wrong
 * one of the two.
 *
 * ── WHAT THIS PINS, AND WHY IT IS THIS SHAPE ───────────────────────────────
 *
 * The invariant is not "every class has a rule" — that is a stylesheet linter
 * and most classes are hooks. It is narrower and it is the thing that went
 * wrong: `.co-note` is drawn in TWO PLACES on this shop, inside `.kbb-checkout`
 * (the checkout and the order-received page) and inside `.kbb-cartpage` (the
 * basket), and each place styles it from a different sheet. So every MODIFIER
 * anybody emits beside `co-note` must have a rule in BOTH.
 *
 * Emitters include JavaScript: partials/checkout/placing-overlay builds its
 * refusal band with `line.className = 'co-note err'`, and a modifier that only
 * ever appears in a script is exactly the one a stylesheet forgets.
 *
 * MUTATION, run: delete `.kbb-checkout .co-note.err` from kbb-checkout.css →
 * "err is drawn inside .kbb-checkout and has no rule there". Delete the `.ok`
 * line from partials/checkout/return-notice → the same for the basket page.
 */

/** Every modifier emitted beside `co-note`, wherever it is written. */
function noticeModifiers(): array
{
    $found = [];

    $files = array_merge(
        glob(resource_path('views/**/*.blade.php')) ?: [],
        glob(resource_path('views/**/**/*.blade.php')) ?: [],
        glob(resource_path('views/**/**/**/*.blade.php')) ?: [],
        glob(resource_path('js/kbb/*.js')) ?: [],
    );

    foreach ($files as $file) {
        $src = (string) file_get_contents($file);

        /*
         * BOTH SPELLINGS. `class="co-note err"` in markup, and
         * `className = 'co-note err'` in a script — the second is how the
         * overlay builds its own band, and a guard that only read Blade would
         * have been blind to the one modifier that has no other home.
         */
        preg_match_all('/(?:class="|className = \')co-note ([a-z0-9 _-]+)/', $src, $matches);

        foreach ($matches[1] as $rest) {
            foreach (preg_split('/\s+/', trim($rest)) as $modifier) {
                if ($modifier === '' || str_starts_with($modifier, 'co-note')) {
                    continue;
                }

                $found[$modifier][] = str_replace(base_path().'/', '', $file);
            }
        }
    }

    return $found;
}

it('styles every notice modifier in both of the places a notice is drawn', function () {
    $modifiers = noticeModifiers();

    expect(array_keys($modifiers))->not->toBe([], 'no co-note modifiers were found at all — this guard has gone blind');

    /*
     * The two hosts, and the stylesheet each one's rules have to be in. The
     * basket page's are inline in the partial that draws them, for the reason
     * that partial's own header gives: kbb-cart.css is a shared sheet and a
     * basket with nothing to say must not ship the rules for saying it.
     */
    $hosts = [
        '.kbb-checkout' => [resource_path('css/kbb/kbb-checkout.css')],
        '.kbb-cartpage' => [resource_path('views/partials/checkout/return-notice.blade.php')],
    ];

    $missing = [];

    foreach ($hosts as $host => $sources) {
        $css = '';

        foreach ($sources as $source) {
            $css .= (string) file_get_contents($source);
        }

        foreach (array_keys($modifiers) as $modifier) {
            if (! str_contains($css, $host.' .co-note.'.$modifier)) {
                $missing[] = $modifier.' is drawn inside '.$host.' and has no rule there';
            }
        }
    }

    sort($missing);

    expect($missing)->toBe([], implode('; ', $missing));
});

it('keeps a refusal looking different from a confirmation', function () {
    /*
     * The rule above says a rule EXISTS. This says the two say opposite things,
     * because a `.err` rule that happened to repeat `.ok`'s colours would pass
     * the first and reproduce the whole defect.
     *
     * The values rather than a screenshot, because a colour is a fact: #A82F53
     * on #FDECEF is 6.9:1 and #1F7D52 on #EEF8F1 is 5.3:1, both clear of AA for
     * body text, and no shopper can mistake one for the other.
     *
     * MUTATION, run: set the .err rule's colour to #1F7D52 → red on both files.
     */
    foreach ([
        resource_path('css/kbb/kbb-checkout.css'),
        resource_path('views/partials/checkout/return-notice.blade.php'),
    ] as $file) {
        $css = (string) file_get_contents($file);

        /*
         * THE SELECTOR WITH ITS OPENING BRACE, not the bare class name. The
         * first version searched for '.co-note.err' and found it in the
         * COMMENT above the rule — which explains at length what the rule is
         * for — so it read a paragraph of prose instead of a declaration block
         * and failed on the colour. A guard that matches its own documentation
         * is a guard nobody can keep documented.
         */
        $err = substr($css, strpos($css, '.co-note.err{'));
        $err = substr($err, 0, strpos($err, '}'));

        $ok = substr($css, strpos($css, '.co-note.ok{'));
        $ok = substr($ok, 0, strpos($ok, '}'));

        /*
         * str_contains() AND toBeTrue(), NOT toContain($needle, $message).
         *
         * Pest's toContain() is VARIADIC in BOTH directions: every argument
         * after the first is another needle. In the negated form a "message"
         * makes the expectation unfailable, which ExpectationsThatCannotFailTest
         * catches; in this POSITIVE form it makes it unpassable, and it fails
         * with the message printed as the thing that is missing — which is
         * exactly how this line first read, and it is the second time this lane
         * has walked into the same hole.
         */
        expect(str_contains($err, '#A82F53'))
            ->toBeTrue(basename($file).' draws a refusal in something other than the refusal colour')
            ->and(str_contains($ok, '#1F7D52'))
            ->toBeTrue(basename($file).' draws a confirmation in something other than the confirmation colour');
    }
});

it('leaves the rule where the next person will look for it', function () {
    /*
     * ON THE CHECKOUT THE FIX IS IN THE STYLESHEET, not in a second inline
     * block beside the markup. `.co-note.ok` has always been in
     * kbb-checkout.css and an `.err` rule living somewhere else is the drift
     * this whole sweep is about — one page's answer to a question the other
     * page answers differently.
     *
     * resources/css is a Vite SOURCE, so this also means the bundle has to be
     * rebuilt: BuiltCssSelectorsAreCurrentTest is what enforces that, and it
     * went red until `npx vite build` was run and public/build committed.
     */
    $sheet = (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));

    $ok = strpos($sheet, '.kbb-checkout .co-note.ok');
    $err = strpos($sheet, '.kbb-checkout .co-note.err');

    expect($ok)->not->toBeFalse()
        ->and($err)->not->toBeFalse()
        ->and(abs($err - $ok))->toBeLessThan(1400, 'the pair has drifted apart in the stylesheet');

    // And not duplicated into the checkout's own inline block as well.
    expect(substr_count((string) file_get_contents(resource_path('views/store/checkout.blade.php')), '.co-note.err'))
        ->toBe(0, 'the checkout carries a second copy of the rule');
});
