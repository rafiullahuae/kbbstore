<?php

declare(strict_types=1);

/**
 * Lane PN's handover: docs/pn-wiring.json, applied by tools/pn-wire.php.
 *
 * Pins the FINISHED state, never its absence (CLAUDE.md): each edit is applied
 * in memory when the integrator has not applied it yet, and the resulting
 * files must carry each line exactly once. Green in the lane's worktree, green
 * after the integrator runs the tool; red for "built, never wired" (zero) and
 * for a doubled merge (two).
 */
function pnWired(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }

    $edits = json_decode((string) file_get_contents(base_path('docs/pn-wiring.json')), true, 512, JSON_THROW_ON_ERROR);
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
    /* MUTATION: change block 3's anchor in docs/pn-wiring.json by one character -> red, naming it. */
    $w = pnWired();
    expect(array_column($w['edits'], 'n'))->toBe([1, 2, 3, 4, 5, 6, 7, 8])
        ->and($w['problems'])->toBe([], implode("\n", $w['problems']));
});

it('mounts the admin endpoints inside the guarded admin-api group, exactly once', function () {
    $web = pnWired()['files']['routes/web.php'];
    expect(substr_count($web, "require __DIR__.'/push-admin.php';"))->toBe(1);
    $group = strpos($web, "Route::prefix('admin-api')->middleware(\\App\\Http\\Middleware\\NoStoreAdminApi::class)->group(function () {");
    $at = strpos($web, "require __DIR__.'/push-admin.php';");
    expect($group)->not->toBeFalse()->and($at)->toBeGreaterThan($group)
        ->and($at)->toBeGreaterThan(strpos($web, "require __DIR__.'/cart-tracking-admin.php';"));
    // The shop-side click beacon lives in Lane NT's file, already mounted once.
    expect(substr_count($web, "require __DIR__.'/site-app-push.php';"))->toBe(1);
});

it('includes the screen, its title and its deep link exactly once each', function () {
    $app = pnWired()['files']['resources/views/admin/app.blade.php'];
    expect(substr_count($app, "@include('admin.partials.push-notifications-screen')"))->toBe(1)
        ->and(substr_count($app, "'push':['Growth & Marketing','Push Notifications']"))->toBe(1);
    // Lane GS's record quotes the TITLES line whole: it moves with it.
    foreach (['docs/GS-ADMIN-APP-BLOCKS.md', 'docs/BG-ADMIN-APP-BLOCKS.md'] as $doc) {
        expect(substr_count(pnWired()['files'][$doc], "'carttracking':['Growth & Marketing','Cart Tracking'],'push':['Growth & Marketing','Push Notifications'],"))->toBe(1, $doc);
    }

    preg_match('/const LATE_RENDERED\s*=\s*new Set\(\[([^\]]*)\]\);/', $app, $m);
    expect($m)->not->toBeEmpty('LATE_RENDERED could not be found in the console at all');
    expect(substr_count($m[1], "'push'"))->toBe(1);
    // The two records that quote the Set's line whole move with it.
    foreach (['docs/BG-ADMIN-APP-BLOCKS.md', 'docs/T1B-ADMIN-APP-BLOCKS.md'] as $doc) {
        expect(pnWired()['files'][$doc])->toContain("const LATE_RENDERED=new Set(['cartpanel','push','media',")
            ->and(pnWired()['files'][$doc])->not->toContain("const LATE_RENDERED=new Set(['cartpanel','media',");
    }
});

it('has its sidebar row once, in Growth & Marketing after Cart Tracking, and wraps go() once', function () {
    $rows = \App\Support\AdminNav::rows();
    expect($rows['push']['sec'])->toBe('Growth & Marketing')
        ->and($rows['push']['label'])->toBe('Push Notifications')
        ->and(\App\Support\AdminNav::capability($rows['push']))->toBe('push.view');
    $ids = array_keys($rows);
    expect(array_search('push', $ids, true))->toBe(array_search('carttracking', $ids, true) + 1);

    $src = (string) file_get_contents(resource_path('views/admin/partials/push-notifications-screen.blade.php'));
    expect(substr_count($src, 'window.go = function'))->toBe(1)
        ->and($src)->toContain("var SCREEN = 'push';")
        ->and($src)->toContain("var GROUP = 'Growth & Marketing';")
        ->and($src)->toContain("var TITLE = 'Push Notifications';")
        ->and(substr_count($src, 'window.kbbAddNavEntry({'))->toBe(1)
        ->and($src)->not->toContain('innerHTML')
        ->and($src)->not->toContain('setInterval')
        ->and($src)->not->toContain('getBoundingClientRect');
});

it('ships the clear_caches migration this route change needs, and schedules the tick on the existing cron line', function () {
    expect(glob(database_path('migrations/*_clear_caches_push_notifications.php')))->toHaveCount(1);
    $console = (string) file_get_contents(base_path('routes/console.php'));
    expect(substr_count($console, "Schedule::command('kbb:push-step')"))->toBe(1)
        ->and(substr_count($console, "Schedule::command('kbb:push-geo-import --auto')"))->toBe(1);
});
