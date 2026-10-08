<?php

declare(strict_types=1);

/*
 * Lane SEO's handover, pinned in its FINISHED state (CLAUDE.md "Do not pin that
 * your own work is NOT wired up yet"): every file is read with
 * docs/seo-wiring.json applied in memory, exactly as tools/seo-wire.php would,
 * so these are green in the lane's worktree AND after the integrator runs the
 * tool -- and red if a block is lost or applied twice.
 */

/** A file with the wiring record applied, as tools/seo-wire.php would. */
function seoWired(string $file): string
{
    $src = (string) file_get_contents(base_path($file));

    foreach (json_decode((string) file_get_contents(base_path('docs/seo-wiring.json')), true, 512, JSON_THROW_ON_ERROR) as $e) {
        if ($e['file'] !== $file || str_contains($src, $e['replacement'])) {
            continue;
        }

        expect(substr_count($src, $e['anchor']))->toBe($e['count'], "block {$e['n']}: {$e['what']}");
        $src = str_replace($e['anchor'], $e['replacement'], $src);
    }

    return $src;
}

it('requires the public feed once, inside the session-less crawl-file group', function () {
    // MUTATION: delete block 1 from docs/seo-wiring.json and the count is 0.
    $web = seoWired('routes/web.php');
    $line = "require __DIR__.'/merchant-feed.php';";
    $group = strpos($web, 'Route::withoutMiddleware(SeoFilesController::STATELESS)->group(function () {');

    expect(substr_count($web, $line))->toBe(1)
        ->and(strpos($web, $line))->toBeGreaterThan((int) strpos($web, "Route::get('/llms.txt'"))
        ->and(strpos($web, $line))->toBeGreaterThan((int) $group)
        // Before the group closes (the IndexNow key route is the first line after it).
        ->and(strpos($web, $line))->toBeLessThan((int) strpos($web, "Route::get('/{key}.txt'"));
});

it('requires the admin endpoints once, inside the guarded admin-api group', function () {
    $web = seoWired('routes/web.php');
    $line = "require __DIR__.'/merchant-feed-admin.php';";

    expect(substr_count($web, $line))->toBe(1)
        ->and(strpos($web, $line))->toBeGreaterThan((int) strpos($web, "Route::prefix('admin-api')"))
        ->and(strpos($web, $line))->toBeGreaterThan((int) strpos($web, "require __DIR__.'/push-admin.php';"));
});

it('wires the console exactly once: title, deep link and the include', function () {
    // MUTATION: delete block 5 and the include count is 0; apply it twice and it is 2.
    $app = seoWired('resources/views/admin/app.blade.php');

    expect(substr_count($app, "@include('admin.partials.merchant-feed-screen')"))->toBe(1)
        ->and(substr_count($app, "'merchantfeed':['Growth & Marketing','Google Shopping feed']"))->toBe(1)
        ->and(preg_match('/const LATE_RENDERED\s*=\s*new Set\(\[([^\]]*)\]\);/', $app, $m))->toBe(1)
        ->and(substr_count($m[1], "'merchantfeed'"))->toBe(1);

    $nav = (string) file_get_contents(app_path('Support/AdminNav.php'));
    expect(substr_count($nav, "['id' => 'merchantfeed', 'label' => 'Google Shopping feed', 'read' => 'admin-api/merchant-feed', 'late' => true,"))->toBe(1);
});

it('keeps the handover documents that quote LATE_RENDERED in step with the console', function () {
    $app = seoWired('resources/views/admin/app.blade.php');

    foreach (['docs/T1B-ADMIN-APP-BLOCKS.md', 'docs/BG-ADMIN-APP-BLOCKS.md', 'docs/GS-ADMIN-APP-BLOCKS.md'] as $doc) {
        expect(seoWired($doc))->toContain("'wabutton','searchterms','merchantfeed','carttracking',")
            ->not->toContain("'wabutton','searchterms','carttracking',");
    }

    // Lane PN's recorded include block stays whole (PushWiringTest re-applies it otherwise).
    $pn = collect(json_decode((string) file_get_contents(base_path('docs/pn-wiring.json')), true))->firstWhere('anchor', "@include('admin.partials.cart-tracking-screen')\n");
    expect(substr_count($app, $pn['replacement']))->toBe(1);

    preg_match_all('/const LATE_RENDERED=new Set\(\[[^\]]*\]\);/', seoWired('docs/T1B-ADMIN-APP-BLOCKS.md'), $quoted);
    expect($app)->toContain((string) end($quoted[0]));
});

it('lists the feed route in both route walks once it is registered, and reserves its first segment', function () {
    expect(substr_count(seoWired('tests/Support/EnglishRenderWalk.php'), "'feeds/google-merchant.xml' => \$file,"))->toBe(1)
        ->and(substr_count(seoWired('tests/Feature/StorefrontRouteWalkTest.php'), "'feeds/google-merchant.xml' => ['status' => 200],"))->toBe(1)
        // RootSlugCollisionTest: a root-level post slugged `feeds` would otherwise claim the address.
        ->and(\App\Http\Controllers\Store\PageController::RESERVED_SLUGS)->toContain('feeds');
});

it('ships a screen with no timer, no polling, no layout measurement, and escapes what it prints', function () {
    $src = (string) file_get_contents(resource_path('views/admin/partials/merchant-feed-screen.blade.php'));

    foreach (['setInterval', 'setTimeout', 'requestAnimationFrame', 'getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'eval(', 'new Function'] as $banned) {
        expect($src)->not->toContain($banned);
    }

    expect($src)->toContain("'X-XSRF-TOKEN': cookie('XSRF-TOKEN')")
        ->and($src)->toContain("if (title) title.textContent = 'Google Shopping feed';")
        ->and($src)->toContain('navigator.clipboard.writeText')->toContain("document.execCommand('copy')")
        // Every server value goes through esc() before it reaches markup.
        ->and($src)->toContain("esc(st.url)")->toContain("esc(row[k])");
});

it('ships the migration the new routes and capability need', function () {
    $m = (string) file_get_contents(database_path('migrations/2027_10_08_100000_clear_caches_seo_feed.php'));

    expect($m)->toContain("base_path('bootstrap/cache/routes-*.php')")
        ->and($m)->toContain('AdminRoles::CACHE_KEY');
});
