<?php

declare(strict_types=1);

/*
 * Safety → 404 page reaches the console and the router only through the
 * integrator's files (routes/web.php, resources/views/admin/app.blade.php),
 * which this lane may not edit. docs/nf-wiring.json carries the edits;
 * tools/nf-wire.php applies them. These tests apply the same record in memory,
 * so they pin the FINISHED state — green before the integrator wires it and
 * after — and never "not wired yet", which would go red the moment he did the
 * one thing asked of him. (Lane NF)
 */

use Tests\Support\NotFoundPageRoutes;

function nfWired(): array
{
    $edits = json_decode((string) file_get_contents(base_path('docs/nf-wiring.json')), true, 512, JSON_THROW_ON_ERROR);
    $files = [];
    $problems = [];

    foreach ($edits as $e) {
        $files[$e['file']] ??= (string) file_get_contents(base_path($e['file']));
        $src = $files[$e['file']];

        // Applied: the replacement is there and no unapplied anchor is left
        // (an anchor that is part of its own replacement cannot be counted).
        if (str_contains($src, $e['replacement'])
            && (str_contains($e['replacement'], $e['anchor']) || substr_count($src, $e['anchor']) === 0)) {
            continue;
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
    /* MUTATION: change any anchor in docs/nf-wiring.json by one character -> red, naming the block. */
    $w = nfWired();

    // Block 3 (the LATE_NAV row) retired at integration: the row is in AdminNav.
    expect(array_column($w['edits'], 'n'))->toBe([1, 2, 4, 5, 6, 7, 8, 9])
        ->and($w['problems'])->toBe([], implode("\n", $w['problems']));
});

it('mounts the route file inside the guarded admin-api group exactly once', function () {
    $web = nfWired()['files']['routes/web.php'];

    expect(substr_count($web, "require __DIR__.'/not-found-page-admin.php';"))->toBe(1)
        ->and($web)->toContain("require __DIR__.'/pagination-admin.php';      // Catalog → Pagination (Lane PG)\n        require __DIR__.'/not-found-page-admin.php';");
});

it('puts the screen in the console exactly once: include, title, sidebar row and deep link', function () {
    /* MUTATION: duplicate block 5 -> the include count is 2 and the screen wraps window.go around itself. */
    $app = nfWired()['files']['resources/views/admin/app.blade.php'];

    expect(substr_count($app, "@include('admin.partials.not-found-page-screen')"))->toBe(1)
        ->and(substr_count($app, "'notfoundpage':['Safety','404 page']"))->toBe(1)
        // The row itself lives in App\Support\AdminNav since lane AP (2.60.404).
        ->and(substr_count((string) file_get_contents(app_path('Support/AdminNav.php')), "['id' => 'notfoundpage', 'label' => '404 page', 'cap' => 'notfoundpage.manage'"))->toBe(1);

    expect(preg_match('/const LATE_RENDERED=new Set\(\[(.*?)\]\);/s', $app, $m))->toBe(1)
        ->and(substr_count($m[1], "'notfoundpage'"))->toBe(1);

    expect(strpos($app, "@include('admin.partials.not-found-page-screen')"))
        ->toBeGreaterThan(strpos($app, "@include('admin.partials.pagination-screen')"));
});

it('declares the same sidebar row the partial registers, so the build-time copy cannot drift', function () {
    // AdminNav (lane AP) is the sidebar's single definition; the partial's own
    // kbbAddNavEntry call must name the same label, icon and group.
    $nav = (string) file_get_contents(app_path('Support/AdminNav.php'));
    $partial = (string) file_get_contents(resource_path('views/admin/partials/not-found-page-screen.blade.php'));

    preg_match("/\['id' => 'notfoundpage', 'label' => '([^']+)', 'cap' => '[^']+', 'late' => true, 'icon' => '([^']+)'\]/", $nav, $row);
    preg_match("/label: '([^']+)',\s*icon: '([^']+)',\s*group: '([^']+)'/", $partial, $call);

    expect($row)->not->toBe([])->and($call)->not->toBe([])
        ->and($row[1])->toBe($call[1])
        ->and($row[2])->toBe($call[2])
        ->and($call[3])->toBe('Safety');
});

it('keeps every other lane’s wiring record applicable after this one', function () {
    /*
     * Lane PG's record quotes 'sets','product-tabs','pagination','banners'. An
     * id inserted inside a quoted run leaves that lane's record with neither
     * its replacement nor its anchor in the console, and its wiring test goes
     * red. This record appends at the end of LATE_RENDERED instead.
     * MUTATION: anchor block 4 on 'product-tabs','pagination','banners', -> red.
     */
    $wired = nfWired()['files'];
    foreach (glob(base_path('docs/*-wiring.json')) as $f) {
        if (str_ends_with($f, '/nf-wiring.json')) {
            continue;
        }
        foreach (json_decode((string) file_get_contents($f), true) as $e) {
            if (! isset($wired[$e['file']])) {
                continue;
            }
            if (\Tests\Support\RetiredNavLiterals::superseded($e['file'], $e['replacement'])) {
                continue;
            }
            $src = $wired[$e['file']];
            expect(str_contains($src, $e['replacement']) || substr_count($src, $e['anchor']) === $e['count'])
                ->toBeTrue(basename($f).' block '.$e['n'].' ('.$e['file'].')');
        }
    }
});

it('pins the Safety menu in the settled sidebar with one insertion', function () {
    $pin = nfWired()['files']['tests/Feature/AdminSidebarIsCompleteAtBuildTest.php'];

    expect(substr_count($pin, "'Safety' => ['debug', 'sandbox', 'democontent', 'notfoundpage'],"))->toBe(1);
});

it('keeps the three handover documents that quote LATE_RENDERED in step with the console', function () {
    $w = nfWired()['files'];
    foreach (['docs/T1B-ADMIN-APP-BLOCKS.md', 'docs/BG-ADMIN-APP-BLOCKS.md', 'docs/GS-ADMIN-APP-BLOCKS.md'] as $doc) {
        // Lane CT (2.60.449) appended 'inquiries' after this lane's id, so the
        // tail every record quotes is now ...,'notfoundpage','inquiries']);
        expect(str_contains($w[$doc], "'spotted','mkt-email','mkt-health','notfoundpage','inquiries']);"))->toBeTrue($doc);
    }
});

it('reaches both endpoints through the router behind the admin guard', function () {
    NotFoundPageRoutes::wire($this->app);

    $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => $r->uri() === 'admin-api/not-found-page');

    expect($routes)->toHaveCount(2);

    foreach ($routes as $route) {
        expect(in_array('auth:admin', $route->gatherMiddleware(), true))->toBeTrue(implode(',', $route->methods()));
    }
});

it('ships the clear_caches migration this route change needs', function () {
    expect(glob(database_path('migrations/2027_08_25_1*_clear_caches_not_found_page.php')))->toHaveCount(1);
});
