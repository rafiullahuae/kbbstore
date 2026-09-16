<?php

declare(strict_types=1);

/**
 * Catalog → Categories & Brands: the screen itself.
 *
 * The layout assertions are structural because Pest has no layout engine. The
 * real measurement was taken in Chromium against #content (NOT
 * document.documentElement.scrollWidth, which this admin's
 * body{overflow:hidden} makes structurally blind — it reported 1280 and 390
 * unchanged while the sensitivity probe was overflowing the content column by
 * 392px and 534px respectively):
 *
 *   width  state       content.scrollW  content.clientW  OVERFLOW
 *   1280   categories  1032             1032             0
 *   1280   brands      1032             1032             0
 *   1280   redirects   1032             1032             0
 *   1280   editor      1032             1032             0
 *   1280   delete      1032             1032             0
 *   1280   merge       1032             1032             0
 *   1280   PROBE 1400  1424             1032             392   <- sensitive
 *   390    (all six)   390              390              0
 *   390    PROBE 900   924              390              534   <- sensitive
 *
 * The desktop probe is 1400px, deliberately wider than the 1032px content
 * column. A 900px probe fits inside that column and would have proved nothing
 * at desktop width.
 *
 * What these assertions pin is the pairing that makes that measurement hold:
 * containers that are allowed to shrink, and a scroller allowed to be narrower
 * than the table inside it.
 */
function catTreeSource(): string
{
    return (string) file_get_contents(
        resource_path('views/admin/partials/category-tree-screen.blade.php')
    );
}

it('ships the screen as its own partial and includes it once', function () {
    expect(is_file(resource_path('views/admin/partials/category-tree-screen.blade.php')))->toBeTrue();

    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($app, "@include('admin.partials.category-tree-screen')"))->toBe(1);
});

it('lets every container on the screen shrink below its content width', function () {
    $css = catTreeSource();

    // The grid itself, and — the half that actually bites, and the half that
    // shipped broken on the Coupons screen — its children. A grid item's
    // default min-width is auto, i.e. "at least as wide as my content".
    expect($css)->toMatch('/\.ct-wrap\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.ct-wrap\s*>\s*\*\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.ct-row\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.ct-row\s*>\s*\*\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.ct-main\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.ct-main\s*>\s*\*\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.ct-stats\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.ct-stats\s*>\s*\*\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.ct-card\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.ct-head\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.ct-head\s*>\s*\*\{[^}]*min-width:0/');
});

it('gives the redirects table a scroller that is allowed to be narrow', function () {
    $css = catTreeSource();

    // overflow-x:auto alone is not a scroller. Without min-width:0 it grows to
    // its content and scrolls nothing.
    expect($css)->toMatch('/\.ct-scroll\{[^}]*overflow-x:auto/')
        ->and($css)->toMatch('/\.ct-scroll\{[^}]*min-width:0/');
});

it('lets the stat tiles and the editor grid wrap instead of forcing a row', function () {
    $css = catTreeSource();

    // repeat(auto-fit, minmax(140px, 1fr)) cannot go narrower than 140px per
    // track. minmax(min(140px,100%), 1fr) lets a track give way on a phone and
    // keeps the floor everywhere else.
    expect($css)->toMatch('/\.ct-stats\{[^}]*minmax\(min\(140px,\s*100%\),\s*1fr\)/')
        ->and($css)->toMatch('/\.ct-grid2\{[^}]*minmax\(min\(200px,\s*100%\),\s*1fr\)/');
});

it('lets the longest strings on the row wrap rather than stretch it', function () {
    $css = catTreeSource();

    // A category path is the longest token on the screen —
    // /product-category/skincare/cleansers-and-makeup-removers/oil-based-.../
    // is one unbroken string, and without overflow-wrap it sets the row's
    // minimum width single-handedly.
    expect($css)->toMatch('/\.ct-path\{[^}]*overflow-wrap:anywhere/')
        ->and($css)->toMatch('/\.ct-name\{[^}]*overflow-wrap:anywhere/');
});

it('keeps the modal box inside the viewport at any width', function () {
    $css = catTreeSource();

    // width:min(560px,100%) rather than width:560px, and border-box so the
    // 18px padding is inside that, not added to it.
    expect($css)->toMatch('/\.ct-modal-box\{[^}]*width:min\(560px,\s*100%\)/')
        ->and($css)->toMatch('/\.ct-modal-box\{[^}]*box-sizing:border-box/');
});

it('escapes every operator-supplied string before it reaches innerHTML', function () {
    $js = catTreeSource();

    // The screen builds its DOM with innerHTML, so a category named
    // <img onerror=…> is stored XSS against the next admin to open the tab.
    expect($js)->toContain("function esc(s)")
        ->and($js)->toMatch('/replace\(\/\[&<>"\x27\]\/g/');

    // Spot-check that the fields an operator controls actually go through it.
    foreach (['esc(c.name)', 'esc(c.path || c.slug)', 'esc(b.name)', 'esc(r.from)'] as $needle) {
        expect($js)->toContain($needle);
    }
});

it('reaches images through the shared Media Library, and uploads nowhere itself', function () {
    $js = catTreeSource();

    /*
     * This used to assert the dialog posted to endpoint('/media/upload') itself,
     * behind a bare browser "Choose File". That control was the one in the
     * owner's screenshot: a file input can only send a file from this computer,
     * so an image ALREADY in the library had to be found and uploaded a second
     * time. The dialog now opens window.kbbPickMedia instead, which lists what
     * is already there and carries its own Upload new.
     *
     * The rule the old assertion was really protecting is unchanged and is
     * asserted harder here: ONE upload path in this application, with one set of
     * type and size rules and one place where the SVG screening lives. The
     * screen now has NO upload code of its own at all — the picker owns it — so
     * the count is zero rather than one, which is the stronger version of the
     * same guarantee.
     */
    expect(str_contains($js, '/media/upload'))
        ->toBeFalse('the category screen has grown a second upload path of its own');

    expect(str_contains($js, 'window.kbbPickMedia({'))
        ->toBeTrue('the category dialog no longer opens the shared Media Library');

    // And the picker it defers to is still the one that posts to that endpoint.
    $picker = (string) file_get_contents(
        resource_path('views/admin/partials/media-picker.blade.php')
    );

    expect(str_contains($picker, "'/media/upload'"))
        ->toBeTrue('the shared picker no longer uploads through the one media endpoint');
});

it('sends only a sibling group when a row is dragged', function () {
    $js = catTreeSource();

    // A drop onto a row at a different level is ignored rather than guessed
    // at: re-parenting by accident would move an indexed URL, and that is what
    // the Parent field in the editor is for.
    expect($js)->toContain('siblingsOf(dragId).indexOf(id) === -1')
        ->and($js)->toContain('function siblingsOf(id)');
});
