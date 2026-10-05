<?php

declare(strict_types=1);

/**
 * Platform → Users & Roles — the console screen (Lane RL).
 *
 * Wired exactly once (CLAUDE.md: pin the FINISHED state, never the absence),
 * tabs that are real tabs, writes signed the way this console signs them,
 * people-typed text escaped, and no layout measuring.
 *
 * ▲ THE TWO WIRING PINS ARE RED IN THE LANE'S OWN WORKTREE BY DESIGN, until
 * the integrator adds the two lines the lane may not (routes/web.php and
 * resources/views/admin/app.blade.php are integrator-owned). They are the
 * "built, never wired up" check CLAUDE.md asks for; tools/rl-wire.py applies
 * exactly those lines, and the suite is green with them applied.
 */
function rlPartial(): string
{
    return (string) file_get_contents(resource_path('views/admin/partials/admin-roles-screen.blade.php'));
}

/** The partial's script with comments removed, so prose cannot satisfy a scan. */
function rlCode(): string
{
    $src = rlPartial();
    $start = strpos($src, '<script>');

    return (string) preg_replace(['#/\*.*?\*/#s', '#(^|[^:])//[^\n]*#m'], ['', '$1'], substr($src, (int) $start));
}

it('is required by routes/web.php exactly once, inside the admin-api group', function () {
    /*
     * Zero is "built, never wired up"; two registers every route twice.
     * MUTATION: delete the require and the Roles tab answers 404 on the shop.
     */
    $web = (string) file_get_contents(base_path('routes/web.php'));

    expect(substr_count($web, "require __DIR__.'/admin-roles.php';"))->toBe(1);
    expect(strpos($web, "require __DIR__.'/admin-roles.php';"))->toBeGreaterThan(strpos($web, "Route::prefix('admin-api')"));
});

it('is included in the console exactly once, after the block whose renderUsers it replaces', function () {
    /*
     * The partial REBINDS window.renderUsers. Included above the live-wiring
     * block that assigns the old one, it would be overwritten and the owner
     * would get the old four-role screen. MUTATION: move the include above line
     * ~18475 and the position assertion goes red.
     */
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $include = "@include('admin.partials.admin-roles-screen')";

    expect(substr_count($app, $include))->toBe(1)
        ->and(strpos($app, $include))->toBeGreaterThan(strpos($app, 'window.renderUsers = async function'));

    // The screen already has its row, title and dispatch entry: nothing new.
    // Lane AP: the sidebar is App\Support\AdminNav's (server-rendered), not a NAV literal.
    expect(\App\Support\AdminNav::rows()['users']['label'] ?? null)->toBe('Users & Roles');
    expect(\App\Support\AdminNav::rows()['users']['sec'])->toBe('Platform')
        ->and(substr_count($app, "users:['Platform','Users & Roles']"))->toBe(1);
});

it('owns renderUsers alone, adds no second sidebar row, and draws itself on ?go=users', function () {
    /*
     * One screen, one owner. A second partial rebinding renderUsers would make
     * the screen depend on include order; a kbbAddNavEntry would be a second
     * "Users & Roles" row (AdminNavAndIdsTest). The `cur` boot is what makes a
     * deep link land here: the first go() runs before any partial exists.
     */
    $owners = 0;
    foreach (glob(resource_path('views/admin/partials/*.blade.php')) ?: [] as $file) {
        $owners += substr_count((string) file_get_contents($file), 'window.renderUsers =');
    }

    expect($owners)->toBe(1)
        ->and(rlCode())->toContain('window.renderUsers = render;')
        ->and(rlCode())->toContain("if (typeof cur !== 'undefined' && cur === 'users') render();")
        ->and(rlCode())->not->toContain('kbbAddNavEntry(');
});

it('paints before it waits, so a deep link never shows an empty panel', function () {
    // MUTATION: make render() `await load()` before its innerHTML and this fails.
    preg_match('/function render\(\) \{(.*?)\n  \}/s', rlCode(), $m);

    expect($m)->not->toBeEmpty('render() could not be found');
    expect(strpos($m[1], 'innerHTML'))->toBeLessThan(strpos($m[1], 'load()'))
        ->and($m[1])->not->toContain('await');
});

it('has real tabs: tablist, tab, tabpanel, selection and arrow keys', function () {
    // The owner asked for "proper tabs not just throw the content" on the
    // email screens; the same standard here. MUTATION: drop role="tablist".
    $code = rlCode();

    foreach (['role="tablist"', 'role="tab"', 'role="tabpanel"', 'aria-selected', 'aria-controls', "'ArrowRight'", "'Home'", "'End'"] as $needle) {
        expect($code)->toContain($needle);
    }
});

it('signs every write with X-XSRF-TOKEN and never the absent meta tag', function () {
    // MUTATION: swap in 'X-CSRF-TOKEN' and every save is a 419.
    expect(rlCode())->toContain("'X-XSRF-TOKEN': cookie('XSRF-TOKEN')")
        ->not->toContain("'X-CSRF-TOKEN'")
        ->not->toContain('csrf-token');
});

it('never concatenates a person-typed field into markup without esc()', function () {
    /*
     * Role names, descriptions, titles and member names are typed by staff and
     * printed into innerHTML. MUTATION: change `esc(r.name)` to `r.name` in the
     * role card and this names the line.
     */
    // A markup literal (one holding < or >) followed by a raw field. Plain-text
    // confirm() prompts are not markup and are not matched.
    preg_match_all("/'[^'\n]*[<>][^'\n]*'\s*\+\s*(?:m|r|x|e|s|c|src)\.(?:name|email|description|role_title|role_name|title|label)\b/", rlCode(), $raw);

    expect($raw[0])->toBe([]);
    expect(substr_count(rlCode(), 'esc('))->toBeGreaterThan(30);
});

it('measures nothing: no layout API anywhere in the screen', function () {
    // CLAUDE.md rule 4: this project sizes with CSS. MUTATION: add
    // `el.getBoundingClientRect()` to repaintKeeping() and this is red.
    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'clientHeight', 'scrollWidth', 'scrollHeight', 'getComputedStyle', 'ResizeObserver'] as $api) {
        expect(rlCode())->not->toContain($api);
    }
});

it('names the compiled route table on a silent 404 and the server\'s own words otherwise', function () {
    // The route cache is the one fault a fresh package hits; the remedy is
    // Platform → Cache. MUTATION: drop `!d.error` and a deleted role's own 404
    // is blamed on the route cache.
    $code = rlCode();
    expect($code)->toContain("r.status === 404 && !d.message && !d.error")
        ->toContain('compiled route table')
        ->toContain('Platform → Cache');
});
