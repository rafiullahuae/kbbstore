<?php

declare(strict_types=1);

/**
 * The five edits the integrator applies, pinned in their FINISHED state.
 * (Lane GS)
 *
 * ── WHY THIS COUNTS 1 AND NEVER ASSERTS AN ABSENCE ──────────────────────────
 *
 * CLAUDE.md, in as many words: a lane that cannot edit `routes/web.php` or
 * `resources/views/admin/app.blade.php` reasonably wants to prove it did not
 * quietly wire itself up, and the obvious assertion —
 * `expect($web)->not->toContain('my-routes.php')` — *"goes red the moment the
 * integrator does the one thing the lane asked for, and the only way to green
 * it as written is to UNMOUNT the feature. It happened three times in one day."*
 *
 * So what is pinned is the shape that can actually regress, and it is red in
 * BOTH directions:
 *
 *   0  the "built, never wired up" shape this repository keeps finding — a
 *      screen the owner cannot reach, or ten endpoints that 404.
 *   2  a sidebar entry registered twice, which wraps `window.go` around its own
 *      wrapper and draws the screen into `#content` twice.
 *
 * This file is therefore RED on the branch as it ships and GREEN after the
 * merge, and it stays a real guard afterwards. `docs/GS-ADMIN-APP-BLOCKS.md`
 * carries the five blocks with their verified anchors and says which
 * assertions are red before they are applied.
 *
 * ── AND THE DOCUMENT IS CHECKED AGAINST THE CONSOLE, NOT TRUSTED ────────────
 *
 * The last case reads the blocks out of that document and asserts each
 * replacement is in the file it names exactly once. An anchor another lane has
 * since edited is a block that cannot be applied mechanically, and the failure
 * would otherwise surface as a half-wired console rather than as an error —
 * which is the argument `TranslationConsoleTest` makes for the same shape.
 */
function gscConsole(): string
{
    return (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

function gscWeb(): string
{
    return (string) file_get_contents(base_path('routes/web.php'));
}

it('wires the Grid sections screen exactly once', function () {
    $app = gscConsole();
    $web = gscWeb();

    /*
     * MUTATION NOTE, and each was run by deleting the line and putting it back:
     *
     *   block 1 — remove the require and every /admin-api/grid-sections… path
     *             404s; the screen loads and its list says the route cache is
     *             stale. This count goes to 0.
     *   block 2 — remove the @include and the screen does not exist at all;
     *             EverythingIsMountedOnceTest goes red beside this.
     *   block 3 — remove the TITLES row and ?go=gridsections opens the
     *             DASHBOARD, silently.
     *   block 4 — remove the LATE_RENDERED id and a deep link to the screen
     *             opens the dashboard on a page load.
     */
    expect(substr_count($web, "require __DIR__.'/grid-sections-admin.php';"))
        ->toBe(1, 'block 1: the routes file is required the wrong number of times');

    expect(substr_count($app, "@include('admin.partials.grid-sections-screen')"))
        ->toBe(1, 'block 2: the screen partial is included the wrong number of times');

    expect(substr_count($app, "'gridsections':['Appearance','Grid sections']"))
        ->toBe(1, 'block 3: the TITLES row is present the wrong number of times');

    expect(substr_count($app, "'paygw','sitelayout','slimfooter','gridsections']);"))
        ->toBe(1, 'block 4: the LATE_RENDERED id is present the wrong number of times');
});

it('keeps the handover document and the applied console in step', function () {
    $doc = (string) file_get_contents(base_path('docs/GS-ADMIN-APP-BLOCKS.md'));

    $sections = preg_split('/^## Block /m', $doc);
    array_shift($sections);

    expect($sections)->toHaveCount(5);

    // Which file each block is applied to, in order. Block 5 is the one that is
    // NOT in the console — it keeps the other lane's handover document in step,
    // which is a fourth edit that is easy to leave out and is the case
    // TranslationConsoleTest goes red on when it is.
    $targets = [
        base_path('routes/web.php'),
        resource_path('views/admin/app.blade.php'),
        resource_path('views/admin/app.blade.php'),
        resource_path('views/admin/app.blade.php'),
        base_path('docs/T1B-ADMIN-APP-BLOCKS.md'),
    ];

    foreach ($sections as $i => $section) {
        preg_match_all('/```\n(.*?)```/s', $section, $fences);

        expect($fences[1])->toHaveCount(2, 'block '.($i + 1).' is not an anchor and a replacement');

        $replacement = rtrim($fences[1][1], "\n");

        expect(trim($replacement))->not->toBe('', 'block '.($i + 1).' has an empty replacement');

        expect(substr_count((string) file_get_contents($targets[$i]), $replacement))->toBe(
            1,
            'block '.($i + 1).' is not applied to '.basename($targets[$i]).' exactly once'
        );
    }
});

it('reaches the screen through the router once it is wired', function () {
    /*
     * The require is the integrator's, so the ROUTER is asked rather than the
     * file — a require that landed outside the guarded `admin-api` group would
     * satisfy the count above and put ten unauthenticated endpoints on the
     * shop. That is the failure this case exists for and it is not theoretical:
     * the group is what carries `auth:admin`.
     */
    $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => $r->uri() === 'admin-api/grid-sections'
            || str_starts_with($r->uri(), 'admin-api/grid-sections/'));

    expect($routes)->toHaveCount(10, 'the ten grid-section endpoints are not in the router');

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();

        /*
         * `in_array` and not `expect(...)->toContain(...)`: on an ARRAY,
         * toContain() takes its arguments as further NEEDLES rather than as a
         * failure message, so the message would be asserted as a second
         * middleware and the case would fail while the route was correctly
         * guarded. Measured — it did, on `admin-api/grid-sections/reorder`,
         * with `auth:admin` present.
         */
        expect(in_array('auth:admin', $middleware, true))->toBeTrue(
            $route->uri().' is registered outside the guarded admin-api group: '
            .implode(',', $middleware)
        );
    }
});
