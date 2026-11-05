<?php

declare(strict_types=1);

/**
 * =============================================================================
 * THE TOP BAR'S REFRESH BUTTON (id="purgeBtn", kept so nothing anchored to it moves)
 * =============================================================================
 *
 * It began as "clear all caches" for a host with no shell. It never worked:
 * kbbPurge() called api(), which is not global -- it lives inside the console's
 * own script block -- so every press threw "api is not defined". 2.60.643 added
 * a page reload after it, which the owner saw as a reload with a red error.
 *
 * The owner, 5 November: "i don't want to reload the page, i want an instant
 * refresh, same like we have in owner app ... it must be refreshed without
 * page load instantly". So it redraws the screen in the address bar through
 * window.go(), which fetches that screen's data again, and nothing else. The
 * live shop has a shell now and every package clears the caches itself; the
 * explicit clear stays in Platform -> Cache.
 */
it('keeps the refresh control in the top bar, which every screen draws', function () {
    $src = (string) file_get_contents(base_path('resources/views/admin/app.blade.php'));

    $top = strpos($src, '<div class="top">');
    $end = strpos($src, '</div>', strpos($src, 'userchip', (int) $top));
    $bar = substr($src, (int) $top, (int) $end - (int) $top);

    expect($top)->not->toBeFalse('the admin top bar has moved; re-point this test')
        ->and($bar)->toContain('id="purgeBtn"')
        ->and($bar)->toContain('onclick="kbbRefresh(this)"');
});

it('redraws the current screen through window.go without reloading the page or calling a helper it cannot see', function () {
    /*
     * MUTATION: put `location.reload()` back, or call api() from here -- RED.
     * MUTATION: drop the sub-address (#orders/56187 would redraw the list,
     * not that order) -- RED.
     */
    $src = (string) file_get_contents(base_path('resources/views/admin/app.blade.php'));
    $fn = substr($src, (int) strpos($src, 'function kbbRefresh('));
    $fn = substr($fn, 0, (int) strpos($fn, '/* ── A FAILURE MAY NOT WEAR A SUCCESS TICK'));

    expect($fn)->toContain('window.go(id, sub)')->toContain('window.go(id)')
        ->toContain("split('/')")
        ->not->toContain('location.reload')
        ->not->toContain('api(')
        ->not->toContain('fetch(')
        ->and($src)->not->toContain('function kbbPurge(')
        ->and($src)->not->toContain('function kbbReload(');
});

it('cannot be pressed twice at once, and always lets go of the guard', function () {
    $src = (string) file_get_contents(base_path('resources/views/admin/app.blade.php'));
    $fn = substr($src, (int) strpos($src, 'function kbbRefresh('));
    $fn = substr($fn, 0, (int) strpos($fn, '/* ── A FAILURE MAY NOT WEAR A SUCCESS TICK'));

    expect($fn)->toContain('if (kbbRefreshing) return;')->toContain('finally')->toContain('kbbRefreshing = false');
});

it('still offers clearing every cache, on Platform -> Cache, through the shared endpoint', function () {
    expect((string) file_get_contents(base_path('app/Http/Controllers/Admin/CacheApiController.php')))->toContain('in:compiled,application,all');
});
