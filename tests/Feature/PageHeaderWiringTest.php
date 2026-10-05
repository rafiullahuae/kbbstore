<?php

declare(strict_types=1);

use Tests\Support\PageHeaderRoutes;

/**
 * Lane PH's handover: docs/ph-wiring.json, applied by tools/ph-wire.php.
 *
 * Pins the FINISHED state, never the absence of it (CLAUDE.md): each edit is
 * applied in memory when the integrator has not applied it yet, and the
 * resulting files must carry each line exactly once. So this is green in the
 * lane's worktree and green after the integrator runs the tool, and red for
 * "built, never wired" (zero) and for a doubled merge (two).
 */
function phWired(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }

    $edits = json_decode((string) file_get_contents(base_path('docs/ph-wiring.json')), true, 512, JSON_THROW_ON_ERROR);
    $files = [];
    $problems = [];

    foreach ($edits as $e) {
        $files[$e['file']] ??= (string) file_get_contents(base_path($e['file']));
        $src = $files[$e['file']];

        if (\Tests\Support\RetiredNavLiterals::superseded($e['file'], $e['replacement'])) {
            continue;   // edited the retired NAV/LATE_NAV literals; the row is AdminNav's now (Lane AP)
        }

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

    return $memo = ['files' => $files, 'problems' => $problems, 'edits' => $edits];
}

it('can apply every edit of the handover, each anchor exactly as often as it says', function () {
    /* MUTATION: change block 3's anchor in docs/ph-wiring.json by one character -> red, naming it. */
    $w = phWired();

    expect(array_column($w['edits'], 'n'))->toBe(range(1, 9))
        ->and($w['problems'])->toBe([], implode("\n", $w['problems']));
});

it('mounts the route file inside the guarded admin-api group exactly once, under Page banners', function () {
    $web = phWired()['files']['routes/web.php'];

    expect(substr_count($web, "require __DIR__.'/page-header-admin.php';"))->toBe(1)
        ->and($web)->toContain("require __DIR__.'/page-banners-admin.php';   // Pages → Page banners (Lane SS)\n        require __DIR__.'/page-header-admin.php';");
});

it('includes the screen, its sidebar row, its title and its deep link exactly once each', function () {
    $app = phWired()['files']['resources/views/admin/app.blade.php'];

    expect(substr_count($app, "@include('admin.partials.page-header-screen')"))->toBe(1)
        // Lane AP: the row is App\Support\AdminNav's.
        ->and(\App\Support\AdminNav::rows()['pageheader']['label'] ?? null)->toBe('Page header')
        ->and(substr_count($app, "'pageheader':['Pages','Page header']"))->toBe(1);

    preg_match('/const LATE_RENDERED\s*=\s*new Set\(\[([^\]]*)\]\);/', $app, $m);
    expect($m)->not->toBeEmpty('LATE_RENDERED could not be found in the console at all');
    expect(substr_count($m[1], "'pageheader'"))->toBe(1);
});

it('wraps window.go for its own id once, and adds no sidebar row of its own', function () {
    $src = (string) file_get_contents(resource_path('views/admin/partials/page-header-screen.blade.php'));

    expect(substr_count($src, 'window.go = function'))->toBe(1)
        ->and($src)->toContain("var SCREEN = 'pageheader';")
        ->and($src)->not->toContain('kbbAddNavEntry(')
        ->and($src)->toContain('window.kbbPickMedia(')
        ->and($src)->not->toContain('type="file"');
});

it('reaches the endpoints through the router behind the admin guard', function () {
    PageHeaderRoutes::wire($this->app);

    $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/page-header'));

    expect($routes)->toHaveCount(3);

    foreach ($routes as $route) {
        expect(in_array('auth:admin', $route->gatherMiddleware(), true))->toBeTrue($route->uri());
    }
});

it('ships the clear_caches migration this route change needs', function () {
    expect(glob(database_path('migrations/2027_08_14_1*_clear_caches_page_header.php')))->toHaveCount(1);
});
