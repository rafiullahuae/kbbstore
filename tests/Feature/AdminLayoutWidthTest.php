<?php

declare(strict_types=1);

/**
 * How wide the admin console is allowed to be.
 *
 * The console used to run full-bleed with its CONTENT capped at 1180px, which
 * put the cap in the wrong place. Measured in Chromium before the change: on a
 * 1920px screen 468px of empty page sat to the right of every table, and on a
 * 2560px screen 1108px did — while the products table, which needs the width,
 * was scrolling inside its own box with the Edit column out of sight.
 *
 * The cap now lives on the shell: the console fills the browser and stops at
 * 1920px, centred beyond that. After the change the unused strip is 24px at
 * both 1920 and 2560, and the wrap went 1180 -> 1624.
 *
 * Pest has no layout engine, so these assert the rules that produce those
 * numbers; the measurements are in the commit message. What matters is that
 * the cap cannot quietly move back onto the inner element, which is the shape
 * of the original bug.
 */
function adminShellCss(): string
{
    static $src = null;

    return $src ??= (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

it('caps the console shell at 1920 and centres it beyond that', function () {
    $css = adminShellCss();

    preg_match('/\.app\{([^}]*)\}/', $css, $m);
    $app = $m[1] ?? '';

    expect($app)->toContain('max-width:1920px')
        ->and($app)->toContain('margin-inline:auto')
        // Without width:100% the grid would shrink-to-fit on some engines
        // rather than filling the viewport up to the cap.
        ->and($app)->toContain('width:100%');
});

it('does not cap the content wrapper any more', function () {
    $css = adminShellCss();

    preg_match('/\.wrap\{([^}]*)\}/', $css, $m);
    $wrap = $m[1] ?? '';

    // The precise regression: a fixed px cap back on .wrap re-creates the dead
    // strip on every wide screen.
    expect($wrap)->not->toMatch('/max-width:\s*\d+px/')
        ->and($wrap)->toContain('max-width:100%')
        // A grid/flex child that cannot shrink stretches its parent instead.
        ->and($wrap)->toContain('min-width:0');
});

it('keeps long prose to a readable measure even though the wrapper is wide', function () {
    $css = adminShellCss();

    // Widening the wrapper must not turn body copy into 1600px lines. These two
    // are the rules that hold the measure, so they are the reason the widening
    // is safe — assert them rather than assuming they stayed.
    expect($css)->toMatch('/\.page-head p\{[^}]*max-width:680px/')
        ->and($css)->toMatch('/\.echelp\{[^}]*max-width:60ch/');
});

it('spends less width on padding as the screen narrows', function () {
    $css = adminShellCss();

    preg_match('/\.content\{([^}]*)\}/', $css, $m);
    $content = $m[1] ?? '';

    // clamp rather than another breakpoint: 24px on a desktop, 14px on a phone,
    // and no edge to get wrong in between.
    expect($content)->toMatch('/padding:clamp\(14px,\s*1\.4vw,\s*24px\)/');
});
