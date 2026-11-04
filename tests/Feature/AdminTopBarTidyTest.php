<?php

declare(strict_types=1);

/*
 * The owner, 4 November, on the admin's top bar: "the refresh button doesn't
 * work, also takeout the live sandbox stuff to Console main settings. and
 * remove the phase 0 text etc."
 */

it('reloads the screen after the refresh button clears the caches, and still reloads when the clear fails', function () {
    /*
     * The defect: the button cleared the caches and left the screen exactly as
     * it was, so "refresh" refreshed nothing the owner could see.
     * MUTATION: drop either kbbReload() call. RED.
     */
    $src = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $fn = substr($src, (int) strpos($src, 'async function kbbPurge('));
    $fn = substr($fn, 0, (int) strpos($fn, 'let toastT;'));

    expect(substr_count($fn, 'kbbReload('))->toBe(2)
        ->and($src)->toContain('function kbbReload(ms){ setTimeout(function(){ try { window.location.reload(); }');
});

it('moves the Live / Sandbox label switch from the top bar into Console preferences, once', function () {
    /*
     * MUTATION: leave the switch in the top bar, or draw it twice. RED.
     */
    $src = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $top = strpos($src, '<div class="top">');
    $bar = substr($src, (int) $top, (int) strpos($src, 'userchip', (int) $top) - (int) $top);
    $console = substr($src, (int) strpos($src, 'function renderConsole(){'));
    $console = substr($console, 0, (int) strpos($console, "\n}\n"));

    expect($bar)->not->toContain('id="envtog"')
        ->and(substr_count($src, 'id="envtog"'))->toBe(1)
        ->and($console)->toContain('id="envtog"')->toContain('kbbEnvBind();')
        ->and($src)->toContain("document.body.dataset.env = kbbEnvGet();");
});

it('shows no "Phase 0" placeholder anywhere the owner can see it', function () {
    /*
     * MUTATION: put the PHASE 0 flag back. RED.
     */
    $src = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect($src)->not->toContain('PHASE 0 · FOUNDATION PREVIEW')
        ->not->toContain('<title>K-Beauty Bliss — Admin · Phase 0 Foundation</title>')
        ->not->toContain('v0.1.0 · Foundation')
        ->not->toContain("prog('Phase 0 · Foundation'");
});
