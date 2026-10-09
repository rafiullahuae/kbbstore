<?php

declare(strict_types=1);

/*
 * Marketing Pixels Connect (Lane MP): wired exactly once.
 *
 * Pins the FINISHED state (CLAUDE.md): docs/mp-wiring.json applied to each file
 * -- in memory here, so this is the same test before and after the integrator
 * runs tools/mp-wire.php. Zero is "built, never wired"; two registers routes
 * twice and wraps window.paintPixels around its own wrapper.
 */

function mpxWired(string $file): string
{
    $src = (string) file_get_contents(base_path($file));

    foreach (json_decode((string) file_get_contents(base_path('docs/mp-wiring.json')), true, 512, JSON_THROW_ON_ERROR) as $e) {
        if ($e['file'] !== $file || str_contains($src, $e['replacement'])) {
            continue;
        }

        expect(substr_count($src, $e['anchor']))->toBe($e['count'], "block {$e['n']}: {$e['what']}");
        $src = str_replace($e['anchor'], $e['replacement'], $src);
    }

    return $src;
}

it('requires the admin routes once, inside the guarded admin-api group, and the feeds once in the stateless group', function () {
    // MUTATION: delete block 2 from docs/mp-wiring.json and the count is 0.
    $web = mpxWired('routes/web.php');
    $admin = "require __DIR__.'/marketing-pixels-connect-admin.php';";
    $feeds = "require __DIR__.'/marketing-catalog-feeds.php';";

    expect(substr_count($web, $admin))->toBe(1)
        ->and(strpos($web, $admin))->toBeGreaterThan((int) strpos($web, "Route::prefix('admin-api')"))
        ->and(substr_count($web, $feeds))->toBe(1)
        ->and(strpos($web, $feeds))->toBeGreaterThan((int) strpos($web, 'Route::withoutMiddleware(SeoFilesController::STATELESS)'))
        ->and(strpos($web, $feeds))->toBeLessThan((int) strpos($web, "Route::prefix('admin-api')"));
});

it('includes the tabs partial once, after the guide partial it wraps around', function () {
    $app = mpxWired('resources/views/admin/app.blade.php');
    $inc = "@include('admin.partials.marketing-pixels-connect')";

    expect(substr_count($app, $inc))->toBe(1)
        ->and(strpos($app, $inc))->toBeGreaterThan((int) strpos($app, "@include('admin.partials.marketing-pixels-guide')"));
});
