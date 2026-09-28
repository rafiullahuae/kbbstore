<?php

declare(strict_types=1);

/**
 * What the integrator has to wire, pinned as the FINISHED state. (Lane SP)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ▲ EVERY COUNT BELOW IS 1, AND NONE OF THEM IS AN ABSENCE. ▲
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * CLAUDE.md names the mistake this file is written to avoid and names the three
 * times it has been made: a lane that may not edit routes/web.php or
 * resources/views/admin/app.blade.php writes `expect($web)->not->toContain(...)`
 * to prove it did not quietly wire itself up, and that assertion GOES RED THE
 * MOMENT THE INTEGRATOR DOES THE ONE THING THE LANE ASKED FOR.
 *
 * So these pin the state that can actually regress:
 *
 *   0  is "built, never wired up" — the shape this repository keeps finding;
 *   2  registers both endpoints twice and draws the Sets chip twice.
 *
 * ▲ THIS FILE IS EXPECTED TO BE RED UNTIL THE INTEGRATOR WIRES IT. That is what
 *   it is for. The endpoints themselves are exercised against the real route
 *   file by SetStockRuleTest, through Tests\Support\SetStockAdminRoutes, and the
 *   chip's server half by SetCatalogChipTest against the route that is already
 *   mounted — so nothing is untested in the meantime.
 *
 * ▲ toBe($expected, $message) AND NEVER ->toContain($needle, $message). Pest's
 *   toContain() IS VARIADIC: a message passed as its second argument becomes a
 *   SECOND NEEDLE and the assertion can then only fail, blaming the thing that
 *   has just been fixed. It has happened three times in this repository in one
 *   week and ExpectationsThatCannotFailTest now sweeps for it.
 */
function spWebRoutes(): string
{
    return (string) file_get_contents(base_path('routes/web.php'));
}

function spAdminShell(): string
{
    return (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

it('requires routes/sp-set-stock-admin.php exactly once', function () {
    /*
     * The require goes INSIDE the existing admin-api group — the one carrying
     * `web`, `auth:admin` and NoStoreAdminApi. A POST to this endpoint changes
     * how every order this shop places moves inventory; mounted anywhere else
     * it is a public endpoint for emptying the owner's shelves.
     *
     * MUTATION NOTE. Remove the require and this reads 0; paste it twice and it
     * reads 2. RUN (it reads 0 in the lane's own worktree, which is the state
     * this test exists to end).
     */
    expect(substr_count(spWebRoutes(), "require __DIR__.'/sp-set-stock-admin.php';"))->toBe(
        1,
        'routes/web.php must require routes/sp-set-stock-admin.php EXACTLY ONCE, inside the existing '
        .'admin-api group — the one opened by '
        ."Route::prefix('admin-api')->middleware(NoStoreAdminApi::class) — immediately after the "
        ."sets-admin require. The line is:  require __DIR__.'/sp-set-stock-admin.php';"
    );
});

it('draws the Sets chip on Catalog -> Products exactly once', function () {
    /*
     * The server half is done and tested — CatalogProductsApiController returns
     * `counts.set` and narrows on `filter=set`, pinned by SetCatalogChipTest.
     * What is left is ONE ENTRY in the console's chip list, which lives in
     * app.blade.php and is the integrator's.
     *
     * MUTATION NOTE. Add the entry twice and this reads 2 — which on the screen
     * is two "Sets" chips side by side, both correct and one of them pointless.
     * RUN.
     */
    expect(substr_count(spAdminShell(), "['set', 'Sets']"))->toBe(
        1,
        'resources/views/admin/app.blade.php must add the Sets chip to CP_DERIVED_CHIPS EXACTLY ONCE: '
        ."one entry spelled ['set', 'Sets'], in the array declared at "
        ."'var CP_DERIVED_CHIPS = [', between the Featured and Trash entries."
    );
});

it('marks a set in the product list exactly once', function () {
    /*
     * And the marker, so the owner can tell what he is looking at without
     * pressing the chip. `is_set` is already on every row of
     * /admin-api/catalog-products-list (SetCatalogChipTest pins it); this is the
     * pill that prints it, in cpTable()'s product cell beside the existing
     * "Hidden" and discount pills.
     *
     * MUTATION NOTE. Paste it twice and this reads 2 — two "Set" pills on every
     * set's row. RUN.
     */
    expect(substr_count(spAdminShell(), 'p.is_set ?'))->toBe(
        1,
        'resources/views/admin/app.blade.php must draw the Set pill EXACTLY ONCE, in cpTable()\'s '
        .'product cell, on the .pbrand line that already carries the Hidden and discount pills — '
        .'a (p.is_set ? … : \'\') expression emitting a blue "Set" pill and one trailing space, '
        .'placed immediately before the p.is_visible pill.'
    );
});
