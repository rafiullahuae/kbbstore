<?php

declare(strict_types=1);

/*
 * Catalog → Image SEO (Lane IR): wired exactly once.
 *
 * Pins the FINISHED state (CLAUDE.md): docs/ir-wiring.json applied to each file
 * -- in memory here, so this is the same test before and after the integrator
 * runs tools/ir-wire.php -- gives one require, one title, one deep-link entry
 * and one include. Zero is "built, never wired"; two is a screen that wraps
 * window.go around its own wrapper.
 */

/** A file with the wiring record applied, as tools/ir-wire.php would. */
function irWired(string $file): string
{
    $src = (string) file_get_contents(base_path($file));

    foreach (json_decode((string) file_get_contents(base_path('docs/ir-wiring.json')), true, 512, JSON_THROW_ON_ERROR) as $e) {
        if ($e['file'] !== $file || str_contains($src, $e['replacement'])) {
            continue;
        }

        expect(substr_count($src, $e['anchor']))->toBe($e['count'], "block {$e['n']}: {$e['what']}");
        $src = str_replace($e['anchor'], $e['replacement'], $src);
    }

    return $src;
}

it('requires the routes exactly once, inside the guarded admin-api group', function () {
    // MUTATION: delete block 1 from docs/ir-wiring.json and the count is 0.
    $web = irWired('routes/web.php');
    $line = "require __DIR__.'/image-seo-admin.php';";

    expect(substr_count($web, $line))->toBe(1)
        ->and(strpos($web, $line))->toBeGreaterThan((int) strpos($web, "require __DIR__.'/webp-admin.php';"))
        ->and(strpos($web, $line))->toBeGreaterThan((int) strpos($web, "Route::prefix('admin-api')"));
});

it('wires the console exactly once: title, deep link and the include', function () {
    // MUTATION: delete block 4 and the include count is 0; apply it twice and it is 2.
    $app = irWired('resources/views/admin/app.blade.php');

    expect(substr_count($app, "@include('admin.partials.image-seo-screen')"))->toBe(1)
        ->and(substr_count($app, "'imageseo':['Catalog','Image SEO']"))->toBe(1)
        ->and(preg_match('/const LATE_RENDERED\s*=\s*new Set\(\[([^\]]*)\]\);/', $app, $m))->toBe(1)
        ->and(substr_count($m[1], "'imageseo'"))->toBe(1);

    $nav = (string) file_get_contents(app_path('Support/AdminNav.php'));
    expect(substr_count($nav, "['id' => 'imageseo', 'label' => 'Image SEO', 'read' => 'admin-api/image-seo', 'late' => true,"))->toBe(1);
});

it('keeps the handover documents that quote LATE_RENDERED in step with the console', function () {
    $app = irWired('resources/views/admin/app.blade.php');

    foreach (['docs/T1B-ADMIN-APP-BLOCKS.md', 'docs/BG-ADMIN-APP-BLOCKS.md'] as $doc) {
        expect(irWired($doc))->toContain("'routines','imageseo','security',");
    }

    preg_match_all('/const LATE_RENDERED=new Set\(\[[^\]]*\]\);/', irWired('docs/T1B-ADMIN-APP-BLOCKS.md'), $quoted);
    expect($app)->toContain((string) end($quoted[0]));
});

it('ships a screen with no timer, no polling, no layout measurement and no unescaped value', function () {
    $src = (string) file_get_contents(resource_path('views/admin/partials/image-seo-screen.blade.php'));

    foreach (['setInterval', 'setTimeout', 'requestAnimationFrame', 'requestIdleCallback', 'getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'eval(', 'new Function', 'innerHTML = data', 'keyup', "'keydown'"] as $banned) {
        expect($src)->not->toContain($banned);
    }

    // A search is a submit, never a request per keystroke.
    expect($src)->toContain("document.addEventListener('submit'")
        ->and($src)->toContain("'X-XSRF-TOKEN': cookie('XSRF-TOKEN')")
        ->and($src)->toContain("if (title) title.textContent = 'Image SEO';")
        ->and($src)->toContain("if (crumb) crumb.textContent = 'Catalog';")
        // The owner's 10-point rating sits in front of every URL.
        ->and($src)->toContain("'<div class=\"isx-url\">' + scoreBadge(i.ten, i.reasons) + '<code>' + esc(i.url)")
        ->and($src)->toContain('How the score works');
});

it('ships the clear-caches migration that the new routes, capability and tile need', function () {
    $m = (string) file_get_contents(database_path('migrations/2027_10_08_120100_clear_caches_image_seo.php'));

    expect($m)->toContain("base_path('bootstrap/cache/routes-*.php')")
        ->and($m)->toContain('AdminRoles::CACHE_KEY')
        ->and($m)->toContain("storage_path('framework/views/*.php')");
});
