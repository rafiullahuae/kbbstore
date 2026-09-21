<?php

declare(strict_types=1);

/**
 * =============================================================================
 * THE PURGE BUTTON HAS TO BE ON EVERY SCREEN, NOT ON THE CACHE SCREEN
 * =============================================================================
 *
 * This host has no shell. A package that adds a route leaves the compiled route
 * table holding the old one, so the new screen answers "not found"; a saved
 * setting can sit behind a stale compiled config. `artisan optimize:clear` is
 * the cure and the owner cannot run it.
 *
 * Platform → Cache already has buttons for that — and they were the wrong
 * answer, because a purge you have to NAVIGATE to is one you reach only after
 * you already suspect caching, and the symptom never looks like caching. It
 * looks like a screen that 404s, or a setting that will not stick.
 *
 * So it is in the top bar, which every screen draws.
 *
 * MUTATION: move the button inside the cache screen partial. Red.
 */
it('puts a purge control in the top bar, which every screen draws', function () {
    $src = (string) file_get_contents(base_path('resources/views/admin/app.blade.php'));

    $top = strpos($src, '<div class="top">');
    $end = strpos($src, '</div>', strpos($src, 'userchip', (int) $top));

    expect($top)->not->toBeFalse('the admin top bar has moved; re-point this test');

    $bar = substr($src, (int) $top, (int) $end - (int) $top);

    expect(str_contains($bar, 'id="purgeBtn"'))->toBeTrue(
        'the clear-cache button is no longer in the top bar, so it is reachable only from the screen '
        .'an owner visits after they already suspect caching'
    );

    expect(str_contains($bar, 'kbbPurge('))->toBeTrue('the purge button is wired to nothing');
});

it('clears everything in one press, through the endpoint the cache screen already uses', function () {
    /*
     * target=all, not a second implementation. Two code paths that clear caches
     * are two that can drift, and the one nobody looks at is the one that rots.
     *
     * MUTATION: change 'all' to 'compiled'. Red — the application cache, which
     * is where a stale setting lives, would survive a press of a button whose
     * title says it clears everything.
     */
    $src = (string) file_get_contents(base_path('resources/views/admin/app.blade.php'));

    $fn = substr($src, (int) strpos($src, 'async function kbbPurge('));
    $fn = substr($fn, 0, (int) strpos($fn, 'let toastT;'));

    expect(preg_match("/api\(\s*'\/cache\/clear'/", $fn))->toBe(
        1,
        'the purge button no longer calls the shared endpoint'
    );

    expect(preg_match("/target\s*:\s*'all'/", $fn))->toBe(
        1,
        "the purge button asks for something other than 'all', so a button titled Clear all caches "
        .'leaves some of them standing'
    );

    // The endpoint must still accept it.
    expect(str_contains(
        (string) file_get_contents(base_path('app/Http/Controllers/Admin/CacheApiController.php')),
        'in:compiled,application,all'
    ))->toBeTrue('the clear endpoint no longer accepts target=all');
});

it('cannot be pressed twice at once', function () {
    /*
     * Not a correctness bug — a second purge is harmless — but it rebuilds what
     * the first one just rebuilt, which on a shop with no shell is a slow page
     * for no reason, at the exact moment somebody is already frustrated.
     */
    $src = (string) file_get_contents(base_path('resources/views/admin/app.blade.php'));

    $fn = substr($src, (int) strpos($src, 'async function kbbPurge('));
    $fn = substr($fn, 0, (int) strpos($fn, 'let toastT;'));

    expect(str_contains($fn, 'if (kbbPurging) return;'))->toBeTrue('the purge button is re-entrant');
    expect(str_contains($fn, 'finally'))->toBeTrue(
        'the in-flight guard is not released in a finally, so one failed purge disables the button '
        .'for the rest of the session'
    );
});
