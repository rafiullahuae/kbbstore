<?php

declare(strict_types=1);

/**
 * Growth & Marketing → Marketing Emails — the admin screen (Lane MK).
 *
 * Wired exactly once (CLAUDE.md: pin the FINISHED state, never the absence),
 * tabs that are real tabs (the owner: "i need proper tabs not just throw the
 * content"), and a builder that reorders by list order without asking the page
 * for a single measurement (two existing tests forbid those APIs by name for
 * the same reason).
 *
 * ▲ THE THREE WIRING PINS ARE RED IN THE LANE'S OWN WORKTREE BY DESIGN, until
 * the integrator adds the lines the lane may not (routes/web.php and
 * resources/views/admin/app.blade.php are integrator-owned). They are the
 * "built, never wired up" check CLAUDE.md asks for; tools/mk-wire.py applies
 * the integrator's exact lines to a shadow copy, and the suite is 0 failed
 * there.
 */

function mkPartial(): string
{
    return (string) file_get_contents(base_path('resources/views/admin/partials/marketing-emails-screens.blade.php'));
}

it('is required by routes/web.php exactly once, admin and public', function () {
    $web = (string) file_get_contents(base_path('routes/web.php'));

    expect(substr_count($web, "require __DIR__.'/marketing-emails-admin.php';"))->toBe(1)
        ->and(substr_count($web, "require __DIR__.'/marketing-public.php';"))->toBe(1);

    // The admin file inside the admin-api group, the public one before the
    // catch-all that ends the file.
    $admin = strpos($web, "require __DIR__.'/marketing-emails-admin.php';");
    $public = strpos($web, "require __DIR__.'/marketing-public.php';");
    expect($admin)->toBeGreaterThan(strpos($web, "Route::prefix('admin-api')"))
        ->and($public)->toBeLessThan(strpos($web, "require __DIR__ . '/kbb-brands-blog.php';"));
});

it('is included in the console exactly once, with one sidebar row under Growth & Marketing', function () {
    $app = (string) file_get_contents(base_path('resources/views/admin/app.blade.php'));

    expect(substr_count($app, "@include('admin.partials.marketing-emails-screens')"))->toBe(1)
        // First in Growth & Marketing, tagged "new" (the approved m0 mock).
        // Lane AP: the sidebar is App\Support\AdminNav's (server-rendered), not a NAV literal.
        ->and(array_values(array_filter(\App\Support\AdminNav::GROUPS, fn ($g) => $g['sec'] === 'Growth & Marketing'))[0]['rows'][0])
        ->toMatchArray(['id' => 'mkt-email', 'label' => 'Marketing Emails', 'tag' => 'new'])
        ->and(substr_count($app, "'mkt-email':['Growth & Marketing','Marketing Emails']"))->toBe(1)
        // Integrated beside Lane EK's two ids ('emails-customer','emails-edit'),
        // so the pin names the end of the list rather than its neighbour.
        // In LATE_RENDERED exactly once (later lanes append after it: Lane NF's
        // 'notfoundpage', 2.60.404).
        ->and(preg_match('/const LATE_RENDERED=new Set\(\[([^\]]*)\]\);/', $app, $lr))->toBe(1)
        ->and(substr_count($lr[1], "'mkt-email'"))->toBe(1);

    // The row is NAV's alone: a second registration from the partial is two
    // rows for one screen (AdminNavAndIdsTest).
    expect(mkPartial())->toContain("var GROUP = 'Growth & Marketing';")
        ->and(mkPartial())->not->toContain('kbbAddNavEntry(');
});

it('measures nothing: no layout API anywhere in the screen', function () {
    /*
     * MUTATION: add `el.getBoundingClientRect()` to the drag handler and this
     * names it. The drop target is the pointer event's own target and the
     * half of a row is two CSS drop zones.
     */
    $src = mkPartial();

    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'offsetTop', 'offsetLeft', 'clientWidth', 'clientHeight', 'scrollWidth', 'scrollHeight', 'getComputedStyle', 'ResizeObserver', 'IntersectionObserver', 'elementFromPoint'] as $api) {
        expect(str_contains($src, $api . '(') || str_contains($src, '.' . $api))->toBeFalse("the screen uses {$api}");
    }

    // The drag runs on pointer events, with up/down buttons for the keyboard.
    expect($src)->toContain("addEventListener('pointerdown'")
        ->and($src)->toContain("addEventListener('pointermove'")
        ->and($src)->toContain('data-mke-up=')
        ->and($src)->toContain('data-mke-down=')
        ->and($src)->toContain('class="dz top"');
});

it('draws every tab row as a tablist with tabs, a tabpanel and arrow keys', function () {
    $src = mkPartial();

    expect(substr_count($src, 'role="tablist"'))->toBe(1)       // one tabBar() writes them all
        ->and(substr_count($src, 'role="tab"'))->toBeGreaterThanOrEqual(1)
        ->and(substr_count($src, 'role="tabpanel"'))->toBe(1)
        ->and($src)->toContain("e.key === 'ArrowRight'")
        ->and($src)->toContain("e.key === 'ArrowLeft'")
        ->and($src)->toContain("e.key === 'Home'")
        ->and($src)->toContain("e.key === 'End'")
        ->and($src)->toContain("aria-selected=")
        // The row scrolls inside itself on a phone instead of widening the page.
        ->and($src)->toMatch('/\.mke-tabs\{[^}]*overflow-x:auto/')
        ->and($src)->toMatch('/\.mke-tabs\{[^}]*flex-wrap:nowrap/');

    // Every screen of the module has its tabs: the four main tabs, the
    // template library, the two lists, the order-date period, the builder's
    // two panels and preview width, the send steps and when.
    foreach (["tabBar('main'", "tabBar('tpl'", "tabBar('aud'", "tabBar('period'", "tabBar('bleft'", "tabBar('bright'", "tabBar('dev'", "tabBar('steps'", "tabBar('when'"] as $bar) {
        expect($src)->toContain($bar);
    }
});

it('escapes what it prints and frames the email with no script', function () {
    $src = mkPartial();

    expect($src)->toContain("function esc(s)")
        ->and($src)->toContain('sandbox="allow-same-origin"')
        ->and($src)->not->toMatch('/sandbox="[^"]*allow-scripts/')
        ->and($src)->not->toContain('eval(')
        ->and($src)->not->toContain('new Function');

    // The label drawn over the selected block is a constant, never data.
    expect($src)->toContain("var OUTLINE = {");

    // The view renders on its own (@verbatim, no PHP in it).
    $html = view('admin.partials.marketing-emails-screens')->render();
    expect($html)->toContain("var SCREEN = 'mkt-email';");
});
