<?php

declare(strict_types=1);

/**
 * Lane SX (2.60.418) — a menu link you can see is a menu link you can click.
 *
 * THE OWNER, after 2.60.416/417: "the page shifting speed ... it's back
 * again" — moving between the brand, category and product pages felt slow.
 *
 * WHAT THE SHOP DID. 2.60.416 (Lane MG) gave every mega item two invisible
 * "hover bridges" (its ::before/::after while hovered) so the pointer could
 * travel diagonally from the link to a panel that starts far to one side.
 * They cover the LOWER HALF of the bar from the link out to the panel's ends
 * — exactly where the neighbouring menu links are — and they were drawn ON
 * TOP of those links (z-index 69 against links with none). Measured in
 * Chromium at 1280 on a live-shaped menu (a 97-brand "Brands" panel and a
 * 4-column "Skincare", storage/sx-logs/FOUND.txt):
 *   - 17.5% of every other link's area could not be hit while a mega item
 *     was hovered (635 of 770 sample points; 81-84% at 1024/1440);
 *   - sliding from "Brands" to "Skincare", or "Skincare" to "Sunscreens", at
 *     65% or 80% of the link's height: the pointer was on DIV.navitem, the
 *     old panel stayed open, the link was never hovered (so never
 *     prefetched), and THE CLICK WENT NOWHERE — 4 of 15 clicks dead, against
 *     0 of 15 on 2.60.415. The shopper clicks again, and the shop feels slow.
 *
 * THE FIX, CSS ONLY. While a mega item is hovered every OTHER link is lifted
 * to z-index 69 and the bridges drop to 68; panels stay at 70. So a visible
 * link always wins the pointer and the click, the bridges still fill the
 * bar's empty space, an open panel still covers a wrapped second row, and the
 * hovered link (no z-index) keeps its pointer (z 71) over its panel's edge.
 * After: 770 of 770 points hittable at 1024, 1280 and 1440; 15 of 15 clicks
 * navigate; panels still open and a straight move down still lands in them.
 *
 * Nothing measures layout and nothing new runs: one selector, one number.
 */
function sxMegaCss(): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kbb/kbb.css')));
}

/** The z-index a rule whose selector contains $needle declares, or null. */
function sxZ(string $css, string $needle): ?int
{
    foreach (preg_split('/(?<=\})/', $css) as $rule) {
        $brace = strpos($rule, '{');
        if ($brace === false) {
            continue;
        }
        if (str_contains(substr($rule, 0, $brace), $needle) && preg_match('/z-index:(\d+)/', substr($rule, $brace), $m)) {
            return (int) $m[1];
        }
    }

    return null;
}

it('draws the hover bridges UNDER every other menu link, and both under the panels', function () {
    /*
     * MUTATION: put the bridges back at `z-index:69`. Red here (69 is not
     * below 69); in Chromium "Brands" → "Skincare" at 80% of the link height
     * hits DIV.navitem and the click does not navigate. RUN.
     * MUTATION: delete the `:not(:hover) > .navlink{z-index:69}` rule. Red on
     * the first expectation; same dead click in Chromium. RUN.
     */
    $css = sxMegaCss();

    $idleLinks = sxZ($css, '.mbar .wrap:has(> .navitem:is(.mg-l,.mg-a,.mg-s):hover) > .navitem:not(:hover) > .navlink');
    $bridges = sxZ($css, '.mbar .wrap > .navitem:is(.mg-l,.mg-a,.mg-s):hover::before, .mbar .wrap > .navitem:is(.mg-l,.mg-a,.mg-s):hover::after');
    $panels = sxZ($css, '  .drop');

    expect($idleLinks)->toBeInt('no rule lifts the other menu links while a panel is open')
        ->and($bridges)->toBeInt()
        ->and($panels)->toBe(70)
        ->and($bridges)->toBeLessThan($idleLinks)
        ->and($idleLinks)->toBeLessThan($panels);
});

it('never lifts the hovered link itself, so its pointer still draws over its panel', function () {
    /*
     * The pointer (.navlink::before, z 71) has to paint over the panel's top
     * border (z 70). A z-index on the HOVERED link would make it a stacking
     * context at 69 and push the pointer under the panel.
     *
     * MUTATION: drop `:not(:hover)` from the lifting rule. Red. RUN.
     */
    $css = sxMegaCss();

    expect($css)->toContain('> .navitem:not(:hover) > .navlink{z-index:69}')
        ->and(sxZ($css, '.mbar .navitem.mg-p > .navlink::before'))->toBe(71);
});

it('keeps the bridges and the lift inside the anchor-positioning block, behind the ON classes', function () {
    /*
     * The switch-off guarantee (MegaMenuFitTest) holds for this rule too: it
     * needs an mg- class, and it only exists where the bridges do.
     */
    $css = sxMegaCss();

    expect($css)->toMatch('/@supports \(anchor-name: --a\)\{[^@]*\.navitem:not\(:hover\) > \.navlink\{z-index:69\}[^@]*:hover::after\{content:"";position:absolute;z-index:68;/s');
});
