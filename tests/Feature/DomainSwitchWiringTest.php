<?php

declare(strict_types=1);

/*
 * Platform → Domain switch (Lane DW): wired exactly once.
 *
 * Pins the FINISHED state (CLAUDE.md): docs/dw-wiring.json applied to each file
 * -- in memory here, so this is the same test before and after the integrator
 * runs tools/dw-wire.php -- gives one require, one title, one deep-link entry
 * and one include. Zero is "built, never wired"; two is a screen that wraps
 * window.go around its own wrapper.
 */

/** A file with the wiring record applied, as tools/dw-wire.php would. */
function dwWired(string $file): string
{
    $src = (string) file_get_contents(base_path($file));

    foreach (json_decode((string) file_get_contents(base_path('docs/dw-wiring.json')), true, 512, JSON_THROW_ON_ERROR) as $e) {
        if ($e['file'] !== $file || str_contains($src, $e['replacement'])) {
            continue;
        }

        expect(substr_count($src, $e['anchor']))->toBe($e['count'], "block {$e['n']}: {$e['what']}");
        $src = str_replace($e['anchor'], $e['replacement'], $src);
    }

    return $src;
}

it('requires the routes exactly once, inside the guarded admin-api group', function () {
    // MUTATION: delete block 1 from docs/dw-wiring.json and the count is 0.
    $web = dwWired('routes/web.php');
    $line = "require __DIR__.'/domain-switch-admin.php';";

    expect(substr_count($web, $line))->toBe(1)
        // Beside Site URL, whose routes are admin-api routes behind auth:admin.
        ->and(strpos($web, $line))->toBeGreaterThan((int) strpos($web, "require __DIR__.'/site-url-admin.php';"))
        ->and(strpos($web, $line))->toBeGreaterThan((int) strpos($web, "Route::prefix('admin-api')"));
});

it('wires the console exactly once: title, deep link and the include', function () {
    // MUTATION: delete block 4 and the include count is 0; apply it twice and it is 2.
    $app = dwWired('resources/views/admin/app.blade.php');

    expect(substr_count($app, "@include('admin.partials.domain-switch-screen')"))->toBe(1)
        ->and(substr_count($app, "'domainswitch':['Platform','Domain switch']"))->toBe(1)
        ->and(preg_match('/const LATE_RENDERED\s*=\s*new Set\(\[([^\]]*)\]\);/', $app, $m))->toBe(1)
        ->and(substr_count($m[1], "'domainswitch'"))->toBe(1)
        // After the Cache screen, which is the Platform partial it sits below.
        ->and(strpos($app, "@include('admin.partials.domain-switch-screen')"))->toBeGreaterThan((int) strpos($app, "@include('admin.partials.cache-screen')"));

    // The sidebar row is AdminNav's, declared once, live (no `pending`).
    $nav = (string) file_get_contents(app_path('Support/AdminNav.php'));
    expect(substr_count($nav, "['id' => 'domainswitch', 'label' => 'Domain switch', 'read' => 'admin-api/domain-switch', 'late' => true,"))->toBe(1);
});

it('keeps the handover documents that quote LATE_RENDERED in step with the console', function () {
    $app = dwWired('resources/views/admin/app.blade.php');

    foreach (['docs/T1B-ADMIN-APP-BLOCKS.md', 'docs/BG-ADMIN-APP-BLOCKS.md'] as $doc) {
        expect(dwWired($doc))->toContain("'setap','cache','domainswitch','cartpage',");
    }

    // The quoted line in T1B is the console's whole LATE_RENDERED line, and stays so.
    preg_match_all('/const LATE_RENDERED=new Set\(\[[^\]]*\]\);/', dwWired('docs/T1B-ADMIN-APP-BLOCKS.md'), $quoted);
    expect($app)->toContain((string) end($quoted[0]));
});

it('ships a screen with no timer, no polling, no layout measurement and no unescaped value', function () {
    $src = (string) file_get_contents(resource_path('views/admin/partials/domain-switch-screen.blade.php'));

    foreach (['setInterval', 'setTimeout', 'requestAnimationFrame', 'getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'eval(', 'new Function'] as $banned) {
        expect($src)->not->toContain($banned);
    }

    // Every action posts to the one endpoint, with the XSRF header; the screen
    // never calls a provider or a resolver itself.
    expect($src)->toContain("api('/domain-switch/run', body)")
        ->and($src)->toContain("'X-XSRF-TOKEN': cookie('XSRF-TOKEN')")
        ->and($src)->not->toContain('dns.google')
        ->and($src)->toContain("if (title) title.textContent = 'Domain switch';")
        ->and($src)->toContain("if (crumb) crumb.textContent = 'Platform';");

    // Clipboard API, with a fallback for a page served over plain http.
    expect($src)->toContain('navigator.clipboard.writeText')->toContain("document.execCommand('copy')");
});

it('ships the clear-caches migration that the new routes and capability need', function () {
    $m = (string) file_get_contents(database_path('migrations/2027_10_07_210100_clear_caches_domain_switch.php'));

    expect($m)->toContain("base_path('bootstrap/cache/routes-*.php')")
        ->and($m)->toContain('AdminRoles::CACHE_KEY');
});
