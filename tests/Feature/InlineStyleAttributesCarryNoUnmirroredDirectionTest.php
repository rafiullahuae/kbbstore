<?php

declare(strict_types=1);

use Tests\Support\CssDirection;

/**
 * The surface T6's declaration reader does not see: `style=""` on an element.
 *
 * ── WHAT WENT WRONG, ON THE SHOP ────────────────────────────────────────────
 *
 * RtlReadinessTest reads the storefront stylesheets and the views' inline
 * <style> BLOCKS. It does not read inline style ATTRIBUTES, and an inline
 * attribute is the one declaration a stylesheet cannot answer: an inline
 * declaration beats an author declaration of any specificity, so a physical
 * `right:` written into a tag silently wins over the logical
 * `inset-inline-end:` written for the same element in CSS.
 *
 * `resources/views/store/app.blade.php` had exactly that. syncBottomNav()
 * drew the cart-count badge with
 * `style="position:absolute;top:2px;right:50%;margin-right:-24px"`, which
 * overrode that document's own `.bnav .count{inset-inline-end:50%}` — a rule
 * that had therefore never applied at all. docs/rtl-audit.md Sec. 13.5 found it
 * and could not act on it, because the document hard-coded <html lang="en">
 * with no dir and no [dir="rtl"] rule could match in it. Sec. 9.5 handed both
 * halves on together. The lang/dir half landed (Lane FK,
 * docs/rtl-standalone-documents.md); this is the other half, and this test is
 * what stops the next one arriving unnoticed.
 *
 * ── THE ALLOWANCES ARE VERIFIED, NOT LISTED ─────────────────────────────────
 *
 * Four physical declarations remain in inline attributes and all four are
 * CORRECT, because Lane G gave each an `!important` override in the
 * stylesheets — an author `!important` declaration beats a normal inline one,
 * which is the one lever CSS has here. So an allowance is not a note saying
 * "this one is fine": it NAMES the rule that defends it, and the test asserts
 * that rule is still in the file. Delete Lane G's override and this goes red on
 * the view it was protecting, which is the failure that would otherwise ship as
 * a badge on the wrong side of an Arabic page.
 *
 * ── MUTATION NOTE ───────────────────────────────────────────────────────────
 *
 * Put `right:50%;margin-right:-24px` back on line ~1313 of store/app.blade.php
 * and the first test is red: an undefended physical declaration in an inline
 * style attribute. Delete kbb.css's `[dir="rtl"] .mnav-h .x{...!important}` and
 * the second test is red. Point the sweep at a directory with no Blade in it
 * and the third is red, so "nothing found" can never pass as "nothing wrong".
 */

/** The four inline physical declarations that are allowed, each with its defence. */
const INLINE_STYLE_ALLOWED = [
    /*
     * The product-page gallery badge. Three branches of the same element
     * (labels module, legacy badge_text, sale percentage), all three
     * `top:14px;left:14px`, all three answered by one rule.
     */
    'partials/product-gallery.blade.php|left|14px' => [
        'resources/css/kbb/kbb-product.css',
        '[dir="rtl"] .gmain .lbl{inset-inline-start:14px!important;inset-inline-end:auto!important}',
    ],

    /*
     * The mobile menu's close button, pushed to the far end of its flex row.
     * Under RTL the panel opens from the reading edge and the X belongs at the
     * other one, which the override says in logical terms.
     */
    'partials/drawers.blade.php|margin-left|auto' => [
        'resources/css/kbb/kbb.css',
        '[dir="rtl"] .mnav-h .x{margin-inline-start:auto!important;margin-inline-end:0!important}',
    ],
];

/**
 * Every inline style attribute in the storefront views, parsed per declaration.
 *
 * Emails and invoices are deliberately out of scope: an HTML email is a table
 * layout aimed at clients with no logical-property support, and
 * resources/views/invoices/document.blade.php states in its own header that its
 * sheet stays physical and why.
 *
 * @return array{swept: int, physical: array<int, array{file: string, property: string, value: string, attribute: string}>}
 */
function inlineStyleSweep(): array
{
    $base = base_path('resources/views');
    $swept = 0;
    $physical = [];

    foreach (['store', 'partials', 'components', 'layouts'] as $dir) {
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . '/' . $dir));

        foreach ($walk as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (! preg_match_all('/\bstyle="([^"]*)"/', (string) $source, $matches, PREG_SET_ORDER)) {
                continue;
            }

            $relative = str_replace($base . '/', '', $file->getPathname());

            foreach ($matches as $attribute) {
                $swept++;

                // Wrapped in a selector so the reader sees declarations inside
                // a rule, which is the only shape it parses.
                foreach (CssDirection::declarations('x{' . $attribute[1] . '}') as $declaration) {
                    if (! CssDirection::isPhysical($declaration['property'], $declaration['value'])) {
                        continue;
                    }

                    $physical[] = [
                        'file' => $relative,
                        'property' => $declaration['property'],
                        'value' => $declaration['value'],
                        'attribute' => $attribute[1],
                    ];
                }
            }
        }
    }

    return ['swept' => $swept, 'physical' => $physical];
}

it('lets no inline style attribute carry a direction no stylesheet can answer', function () {
    $sweep = inlineStyleSweep();

    $undefended = [];

    foreach ($sweep['physical'] as $hit) {
        $key = $hit['file'] . '|' . $hit['property'] . '|' . $hit['value'];

        if (! array_key_exists($key, INLINE_STYLE_ALLOWED)) {
            $undefended[] = sprintf(
                '%s  ->  %s: %s   (in style="%s")',
                $hit['file'],
                $hit['property'],
                $hit['value'],
                $hit['attribute'],
            );
        }
    }

    expect($undefended)->toBe([], sprintf(
        "An inline style attribute carries a physical direction property.\n\n%s\n\n"
        . "An inline declaration beats every author rule that is not !important, so a\n"
        . "[dir=\"rtl\"] override cannot mirror this and the element sits on the wrong side\n"
        . "of an Arabic page. Spell it logically (%s), or add an !important override to the\n"
        . "stylesheet and name it in INLINE_STYLE_ALLOWED above.",
        implode("\n", $undefended),
        implode(', ', array_slice(CssDirection::MAP, 0, 3)) . ', ...',
    ));
});

it('keeps the override that defends every allowed inline declaration', function () {
    $missing = [];

    foreach (INLINE_STYLE_ALLOWED as $key => [$stylesheet, $rule]) {
        $css = file_get_contents(base_path($stylesheet));

        // Whitespace-insensitive: the bundle is authored one rule per line, but
        // an edit that reflows a rule must not read as a deleted one.
        $normalise = static fn (string $s): string => preg_replace('/\s+/', '', $s) ?? '';

        if (! str_contains($normalise((string) $css), $normalise($rule))) {
            $missing[] = $key . "\n      wanted in " . $stylesheet . ":\n      " . $rule;
        }
    }

    expect($missing)->toBe([], sprintf(
        "The stylesheet rule that mirrors an inline physical declaration is gone.\n\n%s\n\n"
        . 'Without it the inline declaration wins and the element does not mirror.',
        implode("\n", $missing),
    ));
});

it('is actually reading inline style attributes', function () {
    $sweep = inlineStyleSweep();

    // 210 at the time of writing. A floor, not a pin: views come and go, but a
    // sweep that suddenly finds a handful has stopped matching, and "no
    // physical declarations found" would then be trivially true.
    expect($sweep['swept'])->toBeGreaterThan(180);

    // And the allowances must still be reachable, or they are describing views
    // that have moved on and the first test is guarding less than it claims.
    $found = array_unique(array_map(
        static fn (array $h): string => $h['file'] . '|' . $h['property'] . '|' . $h['value'],
        $sweep['physical'],
    ));

    expect(array_values(array_diff(array_keys(INLINE_STYLE_ALLOWED), $found)))
        ->toBe([], 'A named allowance no longer matches anything — delete it.');
});
