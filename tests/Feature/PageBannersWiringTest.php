<?php

declare(strict_types=1);

use Tests\Support\PageBannersRoutes;

/**
 * Pages → Page banners: the wiring, pinned at its FINISHED state. (Lane SS)
 *
 * ▲ EVERY COUNT BELOW IS `=== 1`, AND NONE OF THEM IS AN ABSENCE (CLAUDE.md,
 * "Do not pin that your own work is NOT wired up yet"). They are taken on the
 * files AS THEY READ WITH docs/ss-wiring.json IN PLACE: the real file where the
 * integrator has run `php tools/ss-wire.php`, the same edits applied in memory
 * where he has not. So they are green in the lane and after the merge, and red
 * on what is really broken: an edit applied twice (a sidebar row drawn twice,
 * a screen included twice and window.go wrapped around its own wrapper), or an
 * anchor another lane has moved so the edit can no longer be applied.
 */
function ssWired(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }

    $edits = json_decode((string) file_get_contents(base_path('docs/ss-wiring.json')), true, 512, JSON_THROW_ON_ERROR);
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

    return $memo = ['files' => $files, 'problems' => $problems, 'edits' => $edits];
}

it('can apply every edit of the handover, each anchor exactly as often as it says', function () {
    /* MUTATION: change block 3's anchor in docs/ss-wiring.json by one character -> red, naming it. */
    $w = ssWired();

    expect(array_column($w['edits'], 'n'))->toBe(range(1, 8))
        ->and($w['problems'])->toBe([], implode("\n", $w['problems']));
});

it('mounts the route file inside the guarded admin-api group exactly once', function () {
    $web = ssWired()['files']['routes/web.php'];

    expect(substr_count($web, "require __DIR__.'/page-banners-admin.php';"))->toBe(1)
        ->and($web)->toContain("require __DIR__.'/page-editor-admin.php';\n        require __DIR__.'/page-banners-admin.php';");
});

it('includes the screen, its sidebar row, its title and its deep link exactly once each', function () {
    $app = ssWired()['files']['resources/views/admin/app.blade.php'];

    expect(substr_count($app, "@include('admin.partials.page-banners-screen')"))->toBe(1)
        ->and(substr_count($app, "['pagebanners','Page banners',"))->toBe(1)
        ->and(substr_count($app, "'pagebanners':['Pages','Page banners']"))->toBe(1);

    preg_match('/const LATE_RENDERED\s*=\s*new Set\(\[([^\]]*)\]\);/', $app, $m);
    expect($m)->not->toBeEmpty('LATE_RENDERED could not be found in the console at all');
    expect(substr_count($m[1], "'pagebanners'"))->toBe(1);
});

it('wraps window.go for its own id once, and adds no sidebar row of its own', function () {
    /*
     * The row is the static NAV entry above. A kbbAddNavEntry() call here
     * would be a second registration path for the same row.
     */
    $src = (string) file_get_contents(resource_path('views/admin/partials/page-banners-screen.blade.php'));

    expect(substr_count($src, 'window.go = function'))->toBe(1)
        ->and($src)->toContain("var SCREEN = 'pagebanners';")
        ->and($src)->not->toContain('kbbAddNavEntry(')
        ->and($src)->toContain('window.kbbPickMedia(')
        ->and($src)->not->toContain('type="file"');
});

it('reaches the endpoints through the router behind the admin guard', function () {
    PageBannersRoutes::wire($this->app);

    $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => $r->uri() === 'admin-api/page-banners');

    expect($routes)->toHaveCount(2);

    foreach ($routes as $route) {
        expect(in_array('auth:admin', $route->gatherMiddleware(), true))->toBeTrue($route->methods()[0]);
    }
});

it('ships the clear_caches migration this route change needs', function () {
    expect(glob(database_path('migrations/2027_08_13_1*_clear_caches_page_banners.php')))->toHaveCount(1);
});
