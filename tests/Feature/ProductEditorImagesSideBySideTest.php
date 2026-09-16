<?php

declare(strict_types=1);

/**
 * The owner asked for the main image and the gallery to sit beside each other
 * in the BACKEND PRODUCT EDITOR — Catalogue → Products → Edit — so the wide
 * empty space next to a 260px preview stops being wasted.
 *
 * These guards are about the two ways that request can quietly stop holding:
 * the two halves being split back into separate draggable panels, and the
 * two-column rule being keyed to the viewport instead of to the panel.
 */
function peoScreen(): string
{
    return file_get_contents(
        base_path('resources/views/admin/partials/product-editor-screen.blade.php')
    );
}

it('renders the main image and the gallery inside one grid, main first', function () {
    $blade = peoScreen();

    // One panel, one grid, both halves inside it.
    expect($blade)->toContain("'<div class=\"peo-mediawrap\"><div class=\"peo-media\">'");

    $open  = strpos($blade, 'function imagesView(){');
    $close = strpos($blade, 'function mainImageView(){');
    expect($open)->toBeGreaterThan(0)
        ->and($close)->toBeGreaterThan($open);

    $body = substr($blade, $open, $close - $open);

    $main = strpos($body, 'mainImageView()');
    $gal  = strpos($body, 'galleryView()');

    expect($main)->toBeGreaterThan(0, 'imagesView does not render the main image')
        ->and($gal)->toBeGreaterThan(0, 'imagesView does not render the gallery');

    /*
     * Source order, not just presence. The preview is the left track, and in
     * the stacked fallback it is what an operator sees first — a gallery that
     * renders above the photograph it is a gallery OF reads as the wrong way
     * round at exactly the width where there is no visual grouping left to
     * explain it.
     */
    expect($main)->toBeLessThan($gal, 'the gallery renders before the main image');
});

it('asks how wide the panel is, not how wide the window is', function () {
    $blade = peoScreen();

    /*
     * This is the load-bearing one. The panel's width is not a function of the
     * viewport: .peo-grid keeps a 320px sidebar from 901px up, and the operator
     * can drag this panel into either column. At a 901px viewport the main
     * column is about 473px — a viewport-keyed `min-width:901px` rule would
     * call that wide enough and leave each alt-text box about 27px.
     */
    expect($blade)->toContain('.peo-mediawrap{container-type:inline-size')
        ->and($blade)->toMatch('/@container\s*\(min-width:\s*620px\)/');

    // And the two-column rule lives inside that query, nowhere else.
    expect($blade)->toMatch(
        '/@container\s*\(min-width:\s*620px\)\s*\{\s*(?:\/\*.*?\*\/\s*)?\.peo-media\{grid-template-columns:260px minmax\(0,1fr\)\}/s'
    );
});

it('falls back to the layout this screen already had, not to a broken grid', function () {
    $blade = peoScreen();

    preg_match('/\n\.peo-media\{([^}]*)\}/', $blade, $m);

    expect($m)->not->toBeEmpty('the base .peo-media rule is gone');

    /*
     * The single-column stack is the BASE and the two-column split is what the
     * container query ADDS. A browser that does not understand @container
     * therefore renders what this screen rendered before the change rather
     * than a two-column grid it cannot measure. Putting the columns in the
     * base rule and unsetting them in the query would invert that, and the
     * failure would only ever show up on somebody else's browser.
     */
    expect($m[1])->not->toContain('grid-template-columns');
    expect($m[1])->toContain('display:grid');
});

it('leaves every upload control exactly once in the document', function () {
    $blade = peoScreen();

    /*
     * Both halves used to be their own card and are now nested one level
     * deeper. The handlers below them are delegated and bind by id, so a
     * duplicated id would bind the wrong element and a dropped one would bind
     * nothing — an Upload button that silently does nothing is the failure
     * this pins.
     */
    foreach (['peo-mainpick', 'peo-mainfile', 'peo-galdrop', 'peo-galfile', 'peo-gal'] as $id) {
        expect(substr_count($blade, 'id="' . $id . '"'))
            ->toBe(1, $id . ' appears more than once, or not at all');
    }
});
