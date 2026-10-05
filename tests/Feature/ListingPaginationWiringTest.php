<?php

declare(strict_types=1);

use Tests\Support\ListingPaginationRoutes;

/**
 * Lane PG's handover: docs/pg-wiring.json, applied by tools/pg-wire.php.
 *
 * Pins the FINISHED state, never the absence of it (CLAUDE.md): each edit is
 * applied in memory when the integrator has not applied it yet, and the
 * result must carry each line exactly once. Green in the lane's worktree,
 * green after the integrator runs the tool, red for "built, never wired"
 * (zero) and for a doubled merge (two).
 */
function pgWired(): array
{
    $edits = json_decode((string) file_get_contents(base_path('docs/pg-wiring.json')), true, 512, JSON_THROW_ON_ERROR);
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
    /* MUTATION: change any anchor in docs/pg-wiring.json by one character -> red, naming the block. */
    $w = pgWired();

    expect(array_column($w['edits'], 'n'))->toBe([1, 2, 3, 4, 5, 6, 7, 8])
        ->and($w['problems'])->toBe([], implode("\n", $w['problems']));
});

it('mounts the route file inside the guarded admin-api group exactly once', function () {
    $web = pgWired()['files']['routes/web.php'];

    expect(substr_count($web, "require __DIR__.'/pagination-admin.php';"))->toBe(1)
        ->and($web)->toContain("require __DIR__.'/category-header-admin.php'; // Category \"Edit header\" panel (Lane CH)\n        require __DIR__.'/pagination-admin.php';");
});

it('puts the screen in the console exactly once: include, title, sidebar row and deep link', function () {
    /*
     * Zero of any is "built, never wired" (the row with no screen, or a
     * ?go=pagination that opens the dashboard); two registers the row twice.
     * MUTATION: delete block 5 from the JSON -> the include count is 0;
     * paste the include into app.blade.php a second time -> 2.
     */
    $app = pgWired()['files']['resources/views/admin/app.blade.php'];

    expect(substr_count($app, "@include('admin.partials.pagination-screen')"))->toBe(1)
        ->and(substr_count($app, "'pagination':['Catalog','Pagination']"))->toBe(1)
        ->and(substr_count($app, "{screen:'pagination',"))->toBe(1);

    expect(preg_match('/const LATE_RENDERED=new Set\(\[(.*?)\]\);/s', $app, $m))->toBe(1)
        ->and(substr_count($m[1], "'pagination'"))->toBe(1);

    // The screen loads after Product tabs, whose row it does not anchor on but
    // sits beside in the include order the sidebar guard reads.
    expect(strpos($app, "@include('admin.partials.pagination-screen')"))
        ->toBeGreaterThan(strpos($app, "@include('admin.partials.product-tabs-screen')"));
});

it('declares the same sidebar row the partial registers, so the build-time copy cannot drift', function () {
    /*
     * MUTATION: change the partial's `after` or `label` without block 3 and
     * this names the difference (AdminSidebarIsCompleteAtBuildTest says the
     * same once wired).
     */
    $app = pgWired()['files']['resources/views/admin/app.blade.php'];
    $partial = (string) file_get_contents(resource_path('views/admin/partials/pagination-screen.blade.php'));

    preg_match("/\{screen:'pagination',label:'([^']+)',group:'([^']+)',after:\[([^\]]*)\],icon:'([^']+)'\}/", $app, $row);
    preg_match("/label: '([^']+)',\s*icon: '([^']+)',\s*group: '([^']+)',\s*after: \[([^\]]*)\]/", $partial, $call);

    expect($row)->not->toBe([])->and($call)->not->toBe([])
        ->and($row[1])->toBe($call[1])
        ->and($row[2])->toBe($call[3])
        ->and(str_replace(' ', '', $row[3]))->toBe(str_replace(' ', '', $call[4]))
        ->and($row[4])->toBe($call[2])
        ->and($call[3])->toBe('Catalog');
});

it('advances the settled-sidebar pin by exactly one insertion, after Brands', function () {
    $pin = pgWired()['files']['tests/Feature/AdminSidebarIsCompleteAtBuildTest.php'];

    expect(substr_count($pin, "'category-tree', 'brands-manager', 'pagination'],"))->toBe(1);
});

it('reaches both endpoints through the router behind the admin guard', function () {
    ListingPaginationRoutes::wire($this->app);

    $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => $r->uri() === 'admin-api/pagination');

    expect($routes)->toHaveCount(2);

    foreach ($routes as $route) {
        expect(in_array('auth:admin', $route->gatherMiddleware(), true))->toBeTrue(implode(',', $route->methods()));
    }
});

it('ships the clear_caches migration this route change needs', function () {
    expect(glob(database_path('migrations/2027_08_25_1*_clear_caches_listing_pagination.php')))->toHaveCount(1);
});
