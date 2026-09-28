<?php

declare(strict_types=1);

/**
 * What the integrator has to wire, pinned as the FINISHED state. (Lane SET)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ▲ EVERY COUNT BELOW IS 1, AND NONE OF THEM IS AN ABSENCE. ▲
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * CLAUDE.md names the mistake this file is written to avoid, and names the
 * three times it has been made: a lane that may not edit routes/web.php or
 * resources/views/admin/app.blade.php reasonably wants to prove it did not
 * quietly wire itself up, and writes `expect($web)->not->toContain(...)`. That
 * assertion is correct in the lane's worktree and GOES RED THE MOMENT THE
 * INTEGRATOR DOES THE ONE THING THE LANE ASKED FOR, and the only way to green
 * it as written is to unmount the feature. It cost this project three round
 * trips in one day.
 *
 * So these pin the state that can actually regress:
 *
 *   0  is "built, never wired up" — the shape this repository keeps finding;
 *   2  registers six routes twice and includes the screen twice, which wraps
 *      window.go around its own wrapper and puts two "Sets" rows in the
 *      sidebar.
 *
 * Both are real failures, and both are caught by the same assertion — which is
 * green in this worktree the day it is written AND after the wiring lands.
 *
 * ▲ THIS FILE IS EXPECTED TO BE RED UNTIL THE INTEGRATOR WIRES IT. That is what
 *   it is for. The endpoints themselves are exercised against the real route
 *   file by SetAdminScreenTest, through Tests\Support\SetsAdminRoutes, so
 *   nothing is untested in the meantime.
 */
function setWebRoutes(): string
{
    return (string) file_get_contents(base_path('routes/web.php'));
}

function setAdminShell(): string
{
    return (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

it('requires routes/sets-admin.php exactly once', function () {
    /*
     * The require goes INSIDE the existing admin-api group — the one carrying
     * `web`, `auth:admin` and NoStoreAdminApi. These endpoints create, reprice,
     * republish and delete products, and GET /admin-api/sets/products lists the
     * whole catalogue by name and SKU. Mounted anywhere else they are a public
     * endpoint for rearranging the shop.
     *
     * MUTATION NOTE. Remove the require and this reads 0; paste it twice and it
     * reads 2. RUN (it reads 0 in the lane's own worktree, which is the state
     * this test exists to end).
     */
    expect(substr_count(setWebRoutes(), "require __DIR__.'/sets-admin.php';"))->toBe(
        1,
        "routes/web.php must require routes/sets-admin.php EXACTLY ONCE, inside the existing "
        ."admin-api group (the one opened by Route::prefix('admin-api')->middleware(NoStoreAdminApi::class)), "
        ."immediately after the catalog-admin require:\n\n"
        ."    // Catalog -> Sets. A set is a `products` row with type='set' plus the\n"
        ."    // product_set_items pivot; same group as the rest of Catalog.\n"
        ."    require __DIR__.'/sets-admin.php';\n"
    );
});

it('includes the Sets screen exactly once', function () {
    /*
     * ONE @include, and it must sit BEFORE the address-bar wrapper at the very
     * end of resources/views/admin/app.blade.php — the block whose comment says
     * "this is installed LAST, after every include above, and wraps whatever the
     * final window.go turns out to be". A screen included after it would wrap
     * window.go a second time and the address bar would stop following the
     * screen.
     *
     * MUTATION NOTE. Include it twice and this reads 2 — which in the console
     * means two "Sets" rows in the sidebar and window.go wrapped around its own
     * wrapper. RUN.
     */
    expect(substr_count(setAdminShell(), "@include('admin.partials.sets-screen')"))->toBe(
        1,
        "resources/views/admin/app.blade.php must include the Sets screen EXACTLY ONCE, on its own "
        ."line immediately after @include('admin.partials.instagram-screen') and BEFORE the final "
        ."address-bar wrapper block:\n\n"
        ."    @include('admin.partials.sets-screen')\n"
    );
});

it('routes a deep link to the Sets screen instead of the dashboard', function () {
    /*
     * ── A DEFECT THIS CONSOLE HAS ALREADY SHIPPED FOUR TIMES ───────────────
     *
     * go(id) reads `TITLES[id]` for the breadcrumb and LATE_RENDERED decides
     * whether a `?go=` / `#` deep link is replayed at boot. An id in neither
     * opens the DASHBOARD under whatever breadcrumb was last set: that is
     * exactly what happened to ugcvideo, ugcsections, ugcstyle and instagram,
     * and it is why the lane that photographed them had to call window.go() by
     * hand.
     *
     * The Sets screen is safe to arm on the condition that set carries: its
     * window.go wrapper calls render() BEFORE load(), synchronously, so the
     * replay's marker inside #content is already destroyed by the time its task
     * runs and nothing is drawn twice.
     *
     * MUTATION NOTE. Drop 'sets' from either TITLES or LATE_RENDERED and this
     * is red — and ?go=sets opens the dashboard. RUN.
     */
    $shell = setAdminShell();

    /*
     * str_contains() AND NOT ->toContain($needle, $message).
     *
     * Pest's toContain() IS VARIADIC, so the second argument is read as a
     * SECOND NEEDLE and not as a failure message — the assertion then looks
     * for the sentence "Add 'sets':['Catalog','Sets'] to the TITLES map…"
     * inside the admin shell, which is not there and never will be. This case
     * stayed red AFTER the wiring was done, blaming the thing that had just
     * been fixed, which is the worst way for a wiring pin to fail.
     *
     * It is the third of these in one day — UgcLikeApiTest's own comment names
     * the trap and GeWpExporterTest walked into it an hour before this — so
     * the shape is worth spelling out rather than just correcting.
     */
    expect(str_contains($shell, "'sets':['Catalog','Sets']"))->toBeTrue(
        "Add 'sets':['Catalog','Sets'] to the TITLES map in resources/views/admin/app.blade.php, "
        ."or ?go=sets and #sets open the dashboard."
    );

    expect(preg_match('/const LATE_RENDERED=new Set\(\[[^\]]*\x27sets\x27/', $shell))->toBe(
        1,
        "Add 'sets' to LATE_RENDERED in resources/views/admin/app.blade.php, or a deep link to the "
        ."Sets screen is not replayed at boot and opens the dashboard."
    );
});
