<?php

declare(strict_types=1);

/**
 * Lane PW's handover: docs/pwa-wiring.json, applied by tools/pwa-wire.php.
 *
 * Pins the FINISHED state, never the absence of it (CLAUDE.md): each edit is
 * applied in memory when the integrator has not applied it yet, and the
 * resulting files must carry each line exactly once. Green in the lane's
 * worktree, green after the integrator runs the tool, red for "built, never
 * wired" (zero) and for a doubled merge (two).
 */
function pwaWired(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }

    $edits = json_decode((string) file_get_contents(base_path('docs/pwa-wiring.json')), true, 512, JSON_THROW_ON_ERROR);
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
    /* MUTATION: change block 3's anchor in docs/pwa-wiring.json by one character -> red, naming it. */
    $w = pwaWired();
    expect(array_column($w['edits'], 'n'))->toBe([1, 2, 4, 5, 6, 7, 8, 9]) // block 3 (the NAV group) retired at integration: AdminNav holds it
        ->and($w['problems'])->toBe([], implode("\n", $w['problems']));
});

it('mounts the shop files inside the stateless group at the top, exactly once', function () {
    /* Inside that group the manifest, worker and offline page carry no
       session cookie; outside it every response would set one. MUTATION:
       move block 1's require below the group's closing `});` -> red. */
    $web = pwaWired()['files']['routes/web.php'];
    expect(substr_count($web, "require __DIR__.'/site-app.php';"))->toBe(1);

    $open = strpos($web, 'Route::withoutMiddleware(SeoFilesController::STATELESS)->group(function () {');
    $close = strpos($web, '});', (int) $open);
    $at = strpos($web, "require __DIR__.'/site-app.php';");
    expect($open)->not->toBeFalse()->and($at)->toBeGreaterThan($open)->and($at)->toBeLessThan($close);

    // Above every catch-all page route, which would otherwise answer /offline.
    expect($at)->toBeLessThan(strpos($web, "Route::get('/', \\App\\Http\\Controllers\\Store\\HomeController::class)"));
});

it('mounts the admin endpoints inside the guarded admin-api group, exactly once', function () {
    $web = pwaWired()['files']['routes/web.php'];
    expect(substr_count($web, "require __DIR__.'/site-app-admin.php';"))->toBe(1)
        ->and($web)->toContain("require __DIR__.'/whatsapp-button-admin.php';\n        require __DIR__.'/site-app-admin.php';");
});

it('adds the App group once, right after Platform, with Site App as its static first row', function () {
    /* kbbAddNavEntry never invents a group, and a row it registers must anchor
       on a NAV id (AdminNavAndIdsTest, section 5). So App holds Site App in NAV
       itself, and the Owner App row (another lane) registers after 'siteapp'.
       MUTATION: make block 3's group `items:[]` and register Site App from the
       partial -> AdminNavAndIdsTest reports it as resting on no NAV anchor. */
    // Integrated in 2.60.404 onto App\Support\AdminNav (lane AP), which replaced
    // the NAV literal block 3 edited: Site App is the group's static first row.
    $nav = (string) file_get_contents(app_path('Support/AdminNav.php'));
    expect(substr_count($nav, "['id' => 'siteapp', 'label' => 'Site App', 'cap' => 'siteapp.manage', 'icon'"))->toBe(1);
    $secs = array_column(\App\Support\AdminNav::GROUPS, 'sec');
    expect(array_search('Platform', $secs, true))->toBeLessThan(array_search('App', $secs, true))
        ->and(array_search('App', $secs, true))->toBeLessThan(array_search('Safety', $secs, true));
});

it('includes the screen, its title and its deep link exactly once each', function () {
    $app = pwaWired()['files']['resources/views/admin/app.blade.php'];
    expect(substr_count($app, "@include('admin.partials.site-app-screen')"))->toBe(1)
        ->and(substr_count($app, "screen:'siteapp'"))->toBe(0)
        ->and(substr_count($app, "'siteapp':['App','Site App']"))->toBe(1);

    preg_match('/const LATE_RENDERED\s*=\s*new Set\(\[([^\]]*)\]\);/', $app, $m);
    expect($m)->not->toBeEmpty('LATE_RENDERED could not be found in the console at all');
    expect(substr_count($m[1], "'siteapp'"))->toBe(1);
});

it('keeps the three handover records that quote LATE_RENDERED in step with the console', function () {
    $w = pwaWired();
    $app = $w['files']['resources/views/admin/app.blade.php'];
    preg_match('/const LATE_RENDERED=new Set\(\[[^\]]*\]\);/', $app, $line);

    foreach (['docs/GS-ADMIN-APP-BLOCKS.md', 'docs/BG-ADMIN-APP-BLOCKS.md', 'docs/T1B-ADMIN-APP-BLOCKS.md'] as $doc) {
        expect($w['files'][$doc] ?? (string) file_get_contents(base_path($doc)))->toContain("'emails-edit','siteapp','spotted','mkt-email','notfoundpage']);");
    }
    expect($line[0])->toContain("'emails-edit','siteapp','spotted','mkt-email','notfoundpage']);");
});

it('wraps go() once for its own id and adds no sidebar row of its own', function () {
    $src = (string) file_get_contents(resource_path('views/admin/partials/site-app-screen.blade.php'));
    expect(substr_count($src, 'window.go = function'))->toBe(1)
        ->and($src)->toContain("var SCREEN = 'siteapp';")
        ->and($src)->toContain("var GROUP = 'App';")
        ->and($src)->toContain("var TITLE = 'Site App';")
        ->and($src)->not->toContain('kbbAddNavEntry(');
});

it('ships the clear_caches migration this route change needs', function () {
    expect(glob(database_path('migrations/*_clear_caches_site_app.php')))->toHaveCount(1);
});
