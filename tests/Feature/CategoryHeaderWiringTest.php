<?php

declare(strict_types=1);

use Tests\Support\CategoryHeaderRoutes;

/**
 * Lane CH's handover: docs/ch-wiring.json, applied by tools/ch-wire.php.
 *
 * Pins the FINISHED state, never the absence of it (CLAUDE.md): the edit is
 * applied in memory when the integrator has not applied it yet, and the
 * result must carry the require exactly once. Green in the lane's worktree,
 * green after the integrator runs the tool, red for "built, never wired"
 * (zero) and for a doubled merge (two).
 */
function chWired(): array
{
    $edits = json_decode((string) file_get_contents(base_path('docs/ch-wiring.json')), true, 512, JSON_THROW_ON_ERROR);
    $files = [];
    $problems = [];

    foreach ($edits as $e) {
        $files[$e['file']] ??= (string) file_get_contents(base_path($e['file']));
        $src = $files[$e['file']];

        if (str_contains($src, $e['replacement'])) {
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

it('can apply the handover, its anchor exactly as often as it says', function () {
    /* MUTATION: change the anchor in docs/ch-wiring.json by one character -> red, naming it. */
    $w = chWired();

    expect(array_column($w['edits'], 'n'))->toBe([1])
        ->and($w['problems'])->toBe([], implode("\n", $w['problems']));
});

it('mounts the route file inside the guarded admin-api group exactly once, under Page header', function () {
    $web = chWired()['files']['routes/web.php'];

    expect(substr_count($web, "require __DIR__.'/category-header-admin.php';"))->toBe(1)
        ->and($web)->toContain("require __DIR__.'/page-header-admin.php';    // Pages → Page header (Lane PH)\n        require __DIR__.'/category-header-admin.php';");
});

it('reaches both endpoints through the router behind the admin guard', function () {
    CategoryHeaderRoutes::wire($this->app);

    $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/category-header'));

    expect($routes)->toHaveCount(2);

    foreach ($routes as $route) {
        expect(in_array('auth:admin', $route->gatherMiddleware(), true))->toBeTrue($route->uri())
            ->and($route->methods())->toBe(['POST']);
    }
});

it('ships the clear_caches migration this route change needs', function () {
    expect(glob(database_path('migrations/2027_08_23_1*_clear_caches_category_header.php')))->toHaveCount(1);
});
