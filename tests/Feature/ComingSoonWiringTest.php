<?php

declare(strict_types=1);

/*
 * Appearance → Coming Soon page (Lane CS): wired exactly once.
 *
 * Pins the FINISHED state (CLAUDE.md): docs/cs-wiring.json applied to each file
 * -- in memory here, so this is the same test before and after the integrator
 * runs tools/cs-wire.php -- gives one require, one title, one deep-link entry
 * and one include. Zero is "built, never wired"; two is a screen that wraps
 * window.go around its own wrapper.
 */

/** A file with the wiring record applied, as tools/cs-wire.php would. */
function csWired(string $file): string
{
    $src = (string) file_get_contents(base_path($file));

    foreach (json_decode((string) file_get_contents(base_path('docs/cs-wiring.json')), true, 512, JSON_THROW_ON_ERROR) as $e) {
        if ($e['file'] !== $file || str_contains($src, $e['replacement'])) {
            continue;
        }

        expect(substr_count($src, $e['anchor']))->toBe($e['count'], "block {$e['n']}: {$e['what']}");
        $src = str_replace($e['anchor'], $e['replacement'], $src);
    }

    return $src;
}

it('requires the routes exactly once, inside the guarded admin-api group', function () {
    // MUTATION: delete block 1 from docs/cs-wiring.json and the count is 0.
    $web = csWired('routes/web.php');
    $line = "require __DIR__.'/coming-soon-admin.php';";

    expect(substr_count($web, $line))->toBe(1)
        ->and(strpos($web, $line))->toBeGreaterThan((int) strpos($web, "require __DIR__.'/site-app-admin.php';"))
        ->and(strpos($web, $line))->toBeGreaterThan((int) strpos($web, "Route::prefix('admin-api')"));
});

it('wires the console exactly once: title, deep link and the include', function () {
    // MUTATION: delete block 4 and the include count is 0; apply it twice and it is 2.
    $app = csWired('resources/views/admin/app.blade.php');

    expect(substr_count($app, "@include('admin.partials.coming-soon-screen')"))->toBe(1)
        ->and(substr_count($app, "'comingsoon':['Appearance','Coming Soon page']"))->toBe(1)
        ->and(preg_match('/const LATE_RENDERED\s*=\s*new Set\(\[([^\]]*)\]\);/', $app, $m))->toBe(1)
        ->and(substr_count($m[1], "'comingsoon'"))->toBe(1);

    $nav = (string) file_get_contents(app_path('Support/AdminNav.php'));
    expect(substr_count($nav, "['id' => 'comingsoon', 'label' => 'Coming Soon page', 'read' => 'admin-api/coming-soon', 'late' => true,"))->toBe(1);
});

it('keeps the handover documents that quote LATE_RENDERED in step with the console', function () {
    $app = csWired('resources/views/admin/app.blade.php');

    foreach (['docs/T1B-ADMIN-APP-BLOCKS.md', 'docs/BG-ADMIN-APP-BLOCKS.md'] as $doc) {
        expect(csWired($doc))->toContain("'cartpanel','push','media','tax','comingsoon','tr-settings',");
    }

    preg_match_all('/const LATE_RENDERED=new Set\(\[[^\]]*\]\);/', csWired('docs/T1B-ADMIN-APP-BLOCKS.md'), $quoted);
    expect($app)->toContain((string) end($quoted[0]));
});

it('ships a screen with no timer, no polling, no layout measurement and no unescaped value', function () {
    $src = (string) file_get_contents(resource_path('views/admin/partials/coming-soon-screen.blade.php'));

    foreach (['setInterval', 'setTimeout', 'requestAnimationFrame', 'requestIdleCallback', 'getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'eval(', 'new Function', 'innerHTML = data', 'keyup', "'keydown'"] as $banned) {
        expect($src)->not->toContain($banned);
    }

    // No request per keystroke: the preview is drawn on `change` and on a press, never on `input`.
    preg_match("/addEventListener\\('input', function \\(e\\) \\{(.*?)\\n  \\}\\);/s", $src, $input);
    expect($input[1] ?? '')->not->toBe('')->and($input[1])->not->toContain('preview(')->and($input[1])->not->toContain('api(');

    expect($src)->toContain("'X-XSRF-TOKEN': cookie('XSRF-TOKEN')")
        ->and($src)->toContain("if (title) title.textContent = 'Coming Soon page';")
        ->and($src)->toContain("if (crumb) crumb.textContent = 'Appearance';")
        ->and($src)->toContain('sandbox=""')
        ->and($src)->toContain("var SCREEN = 'comingsoon';");
});

it('ships the clear-caches migration that the new routes and capability need', function () {
    $m = (string) file_get_contents(database_path('migrations/2027_10_10_120000_clear_caches_coming_soon.php'));

    expect($m)->toContain("base_path('bootstrap/cache/routes-*.php')")
        ->and($m)->toContain('AdminRoles::CACHE_KEY')
        ->and($m)->toContain("storage_path('framework/views/*.php')");
});

it('leaves every other lane\'s wiring record whole: nothing it already wrote stops being found', function () {
    /*
     * Each lane's wiring test re-applies its JSON in memory and counts on its
     * replacement still being in the file. Inserting this lane's entries in
     * the MIDDLE of somebody else's replacement (a LATE_RENDERED run, a TITLES
     * pair, the line after an include) made five of those go red on the first
     * wired run -- ListingPagination, NotFoundPage and OwnerApp among them.
     * MUTATION: point block 3's anchor at "'emails-edit','siteapp','spotted','mkt-email','notfoundpage']);"
     * (appending 'comingsoon' at the end of the set) and Lane PW's and Lane
     * NF's records are reported broken here.
     */
    $others = [];

    foreach (glob(base_path('docs/*-wiring.json')) ?: [] as $file) {
        if (basename($file) === 'cs-wiring.json') {
            continue;
        }

        foreach (json_decode((string) file_get_contents($file), true) ?: [] as $e) {
            foreach (['anchor', 'replacement'] as $key) {
                if (($e[$key] ?? '') !== '') {
                    $others[] = [basename($file).' block '.($e['n'] ?? '?'), $e['file'] ?? '', $e[$key]];
                }
            }
        }
    }

    $broken = [];

    foreach (array_unique(array_column(json_decode((string) file_get_contents(base_path('docs/cs-wiring.json')), true), 'file')) as $file) {
        $before = (string) file_get_contents(base_path($file));
        $after = csWired($file);

        foreach ($others as [$label, $target, $needle]) {
            if ($target === $file && str_contains($before, $needle) && ! str_contains($after, $needle)) {
                $broken[] = $label.' ('.$file.')';
            }
        }
    }

    expect(array_values(array_unique($broken)))->toBe([]);
});
