<?php

/**
 * A link to a screen's tab opens that tab.
 *
 * THE DEFECT (found by Lane PM, 2 October 2026): `#catalog/reorder` and
 * go('catalog', 'reorder') opened Catalog on its first tab, Products. The
 * console's route interceptor wraps window.go and forwarded only `id`:
 * `_go(id)`, so go(id, sub)'s tab never arrived. The "Unfinished" list's Open
 * button for a Catalog reorder had to click the tab itself to get there.
 *
 * MUTATION, RUN: put `_go(id);` back on the fall-through line and this is red.
 */
it('forwards every argument from the route interceptor to go(id, sub)', function () {
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect($app)->toContain('function go(id,sub){');

    $at = strpos($app, 'Route interception: hydrate dash, render new screens');
    expect($at)->not->toBeFalse();
    $block = substr($app, $at, 2500);

    // The general path passes everything on ...
    expect($block)->toContain('_go.apply(this, arguments);')
        // ... and no longer drops the tab on the way.
        ->and(preg_match('/\n\s*_go\(id\);\s*\n\s*if\(id===\'dash\'\)/', $block))->toBe(0);
});
