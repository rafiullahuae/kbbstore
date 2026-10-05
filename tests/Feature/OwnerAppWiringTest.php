<?php

declare(strict_types=1);

/*
 * The owner app's wiring (Lane MAC). CLAUDE.md forbids the lane to edit
 * routes/web.php, so tools/mac-wire.php applies docs/mac-wiring.json.
 *
 * The second and third tests pin the FINISHED state — each line present
 * exactly once — and are therefore RED until the integrator runs
 *     php tools/mac-wire.php
 * Zero is "built, never wired up"; two registers the routes or the hooks twice
 * (two "New order" pushes per order). Both are real failures.
 */

it('applies cleanly: every anchor is present exactly once, and the result carries each line once', function () {
    $edits = json_decode((string) file_get_contents(base_path('docs/mac-wiring.json')), true, 512, JSON_THROW_ON_ERROR);
    $files = [];

    foreach ($edits as $e) {
        $files[$e['file']] ??= (string) file_get_contents(base_path($e['file']));
        if (str_contains($files[$e['file']], $e['replacement'])) {
            continue;
        }
        expect(substr_count($files[$e['file']], $e['anchor']))->toBe($e['count'], "anchor {$e['n']} in {$e['file']}");
        $files[$e['file']] = str_replace($e['anchor'], $e['replacement'], $files[$e['file']]);
    }

    expect(substr_count($files['routes/web.php'], "require __DIR__.'/owner-app.php';"))->toBe(1)
        ->and(substr_count($files['routes/web.php'], "require __DIR__.'/owner-app-admin.php';"))->toBe(1)
        ->and(substr_count($files['bootstrap/providers.php'], 'App\\Providers\\OwnerAppServiceProvider::class'))->toBe(1);

    // The admin file lands INSIDE the admin-api group: after its opening line.
    $web = $files['routes/web.php'];
    expect(strpos($web, "require __DIR__.'/owner-app-admin.php';"))->toBeGreaterThan(strpos($web, "Route::prefix('admin-api')"));
});

it('is wired: routes/web.php requires both owner-app route files exactly once', function () {
    $web = (string) file_get_contents(base_path('routes/web.php'));

    expect(substr_count($web, "require __DIR__.'/owner-app.php';"))->toBe(1, "Run: php tools/mac-wire.php")
        ->and(substr_count($web, "require __DIR__.'/owner-app-admin.php';"))->toBe(1, "Run: php tools/mac-wire.php");
});

it('is wired: the provider that records orders and stock is registered exactly once', function () {
    $providers = (string) file_get_contents(base_path('bootstrap/providers.php'));

    expect(substr_count($providers, 'App\\Providers\\OwnerAppServiceProvider::class'))->toBe(1, "Run: php tools/mac-wire.php");
});

it('ships the migration that clears the compiled routes, config and views', function () {
    $src = (string) file_get_contents(base_path('database/migrations/2027_08_25_100200_clear_caches_owner_app.php'));

    expect($src)->toContain('bootstrap/cache/routes-*.php')
        ->and($src)->toContain('framework/views/*.php')
        ->and($src)->toContain('bootstrap/cache/services.php');
});
