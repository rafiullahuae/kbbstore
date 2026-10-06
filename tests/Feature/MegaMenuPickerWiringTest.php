<?php

declare(strict_types=1);

/**
 * Lane MX's handover: docs/mx-wiring.json, applied by tools/mx-wire.php —
 * Store → Mega Menu → Add items' two routes.
 *
 * Pins the FINISHED state, never the absence of it (CLAUDE.md): the edit is
 * applied in memory when the integrator has not applied it yet, and the
 * result must carry the require exactly once. Green in the lane's worktree,
 * green after the tool runs, red for "built, never wired" (zero) and for a
 * doubled merge (two).
 */
function mxWired(): array
{
    $edits = json_decode((string) file_get_contents(base_path('docs/mx-wiring.json')), true, 512, JSON_THROW_ON_ERROR);
    $files = [];
    $problems = [];

    foreach ($edits as $e) {
        $files[$e['file']] ??= (string) file_get_contents(base_path($e['file']));
        $src = $files[$e['file']];

        if (substr_count($src, $e['replacement']) >= $e['count']) {
            continue;   // the integrator has applied it
        }

        $n = substr_count($src, $e['anchor']);
        if ($n !== $e['count']) {
            $problems[] = "block {$e['n']} ({$e['file']}): anchor found {$n} times, expected {$e['count']}";
            continue;
        }

        $files[$e['file']] = str_replace($e['anchor'], $e['replacement'], $src);
    }

    return ['files' => $files, 'problems' => $problems, 'edits' => $edits];
}

it('can apply the handover, every anchor exactly as often as it says', function () {
    // MUTATION: change the anchor in docs/mx-wiring.json by one character -> red, naming the block.
    $w = mxWired();

    expect(array_column($w['edits'], 'n'))->toBe([1])
        ->and($w['problems'])->toBe([], implode("\n", $w['problems']));
});

it('mounts the two routes once, inside the guarded admin-api group, ahead of POST /mega-menu/{item}', function () {
    /*
     * Zero is the panel drawn with nothing answering it; two registers the
     * routes twice. And order matters: registered after the existing block,
     * POST /mega-menu/pick is taken by `/mega-menu/{item}` and answers 404.
     * MUTATION: move the require below the mega-menu block in the JSON -> red.
     */
    $web = mxWired()['files']['routes/web.php'];

    expect(substr_count($web, "require __DIR__.'/mega-menu-picker-admin.php';"))->toBe(1);

    $require = strpos($web, "require __DIR__.'/mega-menu-picker-admin.php';");
    $group = strpos($web, "Route::prefix('admin-api')->middleware(\\App\\Http\\Middleware\\NoStoreAdminApi::class)->group(function () {");
    expect($group)->toBeInt()
        ->and($require)->toBeGreaterThan($group)
        ->and($require)->toBeLessThan(strpos($web, "Route::post('/mega-menu/{item}',"));

    // The routes file itself: exactly the two, under the capability's prefix.
    $routes = (string) file_get_contents(base_path('routes/mega-menu-picker-admin.php'));
    expect(substr_count($routes, 'Route::'))->toBe(2)
        ->and($routes)->toContain("Route::get('/mega-menu/sources'")
        ->and($routes)->toContain("Route::post('/mega-menu/pick'");
});

it('ships the cache-clearing migration a package that adds a route needs', function () {
    // CLAUDE.md: a route added to the router reaches the live shop only once the compiled table is cleared.
    $files = glob(database_path('migrations/*_clear_caches_mega_menu_picker.php'));
    expect($files)->toHaveCount(1)
        ->and((string) file_get_contents($files[0]))->toContain("base_path('bootstrap/cache/routes-*.php')");
});
