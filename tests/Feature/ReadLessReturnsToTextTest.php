<?php

declare(strict_types=1);

/*
 * "READ LESS" LEFT THE SHOPPER BELOW THE TEXT IT HAD JUST FOLDED UP
 *                                                           (Lane PI-A, item 9)
 *
 * ON THE SHOP, the owner's 390px screenshot: he opened the long description,
 * read to the end, pressed "Read less ↑" -- the text collapsed ABOVE him and the
 * page kept its scroll position, so he was looking at Customer Reviews. Measured
 * in Chromium on a description as long as his: pressed at scrollY 1779 (390) /
 * 1333 (1280), the page came to rest at 3134 / 1608 -- in the footer at 390 --
 * with the tab bar 1569px / 536px above the top of the screen.
 *
 * resources/js/kbb/tabs.js now calls scrollIntoView({block: 'nearest'}) on
 * #details when -- and only when -- a panel is COLLAPSED. 'nearest' does
 * nothing when the block is already on screen, which is how this avoids
 * measuring anything (CLAUDE.md rule 4); the sticky header is allowed for by
 * scroll-margin-top in kbb-product.css. After: the tab bar rests 12px below the
 * header (139px at 390, 106px at 1280), and a collapse made with the text still
 * on screen moves the page by 0px.
 *
 * The short description's toggle is a CSS checkbox whose label disappears once
 * opened, so it has no "Read less" and nothing to wire.
 */

function rlSource(): string
{
    $src = (string) file_get_contents(resource_path('js/kbb/tabs.js'));

    // Comments out first, so a sentence about the fix is never counted as it.
    $src = (string) preg_replace('~/\*.*?\*/~s', '', $src);

    return (string) preg_replace('~(^|\s)//[^\n]*~m', '$1', $src);
}

it('scrolls the details block back into view when Read less collapses it, and only then', function () {
    /*
     * MUTATIONS, RUN:
     *   the `if (!open) bringBackIntoView(root);` line deleted -- red here;
     *   `!open` changed to `open` (scroll on EXPAND) -- red here.
     */
    $src = rlSource();

    expect(substr_count($src, 'bringBackIntoView('))->toBe(2, 'defined once and called once')
        ->and($src)->toContain('if (!open) bringBackIntoView(root);')
        ->and($src)->toContain("scrollIntoView({ block: 'nearest', behavior: still ? 'auto' : 'smooth' })")
        ->and($src)->toContain("matchMedia('(prefers-reduced-motion: reduce)')");
});

it('measures nothing to decide it', function () {
    foreach (['getBoundingClientRect', 'offsetTop', 'offsetHeight', 'offsetWidth', 'clientHeight', 'scrollHeight',
        'getComputedStyle', 'scrollY', 'pageYOffset'] as $api) {
        expect(rlSource())->not->toContain($api);
    }
});

it('ships in the built bundle and stylesheet the page loads', function () {
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $js = (string) file_get_contents(public_path('build/'.$manifest['resources/js/kbb/app.js']['file']));
    $css = (string) file_get_contents(public_path('build/'.$manifest['resources/css/kbb/kbb-product.css']['file']));

    expect($js)->toContain('scrollIntoView({block:"nearest",behavior:')
        ->and($css)->toContain('.details{scroll-margin-top:106px}')
        ->and($css)->toContain('.details{scroll-margin-top:139px}');
});
