<?php

declare(strict_types=1);

/**
 * The product editor has to be REACHABLE, not merely present.
 *
 * The editor shipped as its own screen and its API routes were wired, and the
 * Edit button on the products list still called cpOpenDetail() — the earlier,
 * narrower panel. So every route into editing landed on the old screen and the
 * owner reasonably concluded the work had not shipped at all. Nothing was
 * broken; nothing pointed at it.
 *
 * That is not a failure a test of either screen could catch, because both
 * screens worked. It lives in the wiring between them, so it is asserted here.
 *
 * Lane AT then retired the older screens outright — cpOpenDetail, cpOpenCreate
 * and the /admin-api/catalog-product-* endpoints — so the assertions below that
 * required them as fallbacks now require their ABSENCE. What has not changed is
 * the rule this file exists for: the editor must be reachable, and reachable
 * first. See tests/Feature/ProductEditorRetirementTest.php for the removals.
 */
function adminConsole(): string
{
    static $src = null;

    return $src ??= (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

function productEditorPartial(): string
{
    static $src = null;

    return $src ??= (string) file_get_contents(
        resource_path('views/admin/partials/product-editor-screen.blade.php')
    );
}

it('sends the products list Edit button to the product editor', function () {
    $src = adminConsole();

    // The handler for [data-cpedit] must reach peoEdit.
    preg_match('/\[data-cpedit\]\'\)\.forEach\((.{0,900}?)\}\);/s', $src, $m);
    $handler = $m[1] ?? '';

    expect($handler)->not->toBe('', 'the Edit button handler could not be found at all')
        ->and($handler)->toContain('window.peoEdit');

    /*
     * There is no fallback panel any more, and that is the point.
     *
     * This assertion used to require cpOpenDetail here, so that a partial which
     * failed to load left editing possible rather than a dead button. Lane AT
     * retired cpOpenDetail: keeping a second editor alive as a safety net is
     * how the console came to have three of them, and how this button ended up
     * pointing at the oldest one. A fallback that is itself a whole second
     * editor is not a fallback, it is the duplication.
     */
    expect($handler)->not->toContain('cpOpenDetail(');
});

it('sends the Add product button to the one create path that remains', function () {
    $src = adminConsole();

    preg_match('/addBtn\.onclick=\(\)=>\{(.{0,600}?)\};/s', $src, $m);
    $handler = $m[1] ?? '';

    expect($handler)->not->toBe('', 'the Add product handler could not be found')
        ->and($handler)->toContain('window.peoNew');

    /*
     * This used to assert that peoNew was tried BEFORE cpOpenCreate, because
     * whichever came first was the screen the owner got. Lane AT removed
     * cpOpenCreate and the three /admin-api/catalog-product-* endpoints behind
     * it, so there is no ordering left to get wrong — there is one create path.
     *
     * Asserted on the CALL rather than on the raw text: an earlier version of
     * this test compared the first occurrence of each NAME and failed on the
     * comment above the code, measuring prose instead of behaviour.
     */
    expect(strpos($handler, 'window.peoNew('))->not->toBeFalse('peoNew is never called')
        ->and($handler)->not->toContain('cpOpenCreate');
});

it('exposes both entry points from the editor partial', function () {
    $src = productEditorPartial();

    expect($src)->toContain('window.peoEdit = function')
        ->and($src)->toContain('window.peoNew = function');
});

it('loads what was asked for only after the bootstrap, so the list cannot win the race', function () {
    /*
     * The bug this pins, which was live and silent: peoEdit() called
     * loadProduct(id) directly after window.go(). go() starts start(), which
     * awaits the bootstrap and then falls back to loadList() when no model is
     * set. The bootstrap is the smaller request and resolved first, so
     * loadList() ran, bumped the sequence counter, and loadProduct's response
     * was discarded as stale by its own guard. Clicking Edit landed on the
     * picker, with no error anywhere.
     *
     * Asserted as a property rather than as a line: the entry points record an
     * intent and switch screens, and start() is the only thing that loads.
     */
    $src = productEditorPartial();

    preg_match('/window\.peoEdit = function\(id\)\{(.{0,400}?)\};/s', $src, $m);
    $edit = $m[1] ?? '';

    expect($edit)->not->toBe('', 'peoEdit could not be found')
        ->and($edit)->toContain('intent')
        // The regression is peoEdit loading for itself again.
        ->and($edit)->not->toContain('loadProduct(');

    // start() honours the intent, and only falls back to the list without one.
    preg_match('/async function start\(\)\{(.{0,900}?)\n  \}/s', $src, $s2);
    $start = $s2[1] ?? '';

    expect($start)->toContain('loadProduct(')
        ->and($start)->toContain('if (!model) loadList();');
});
