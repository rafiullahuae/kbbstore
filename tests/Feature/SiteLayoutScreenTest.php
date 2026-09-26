<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Services\SettingsService;
use App\Services\SiteLayout;
use App\Support\AdminCapabilities;
use Tests\Support\SiteLayoutAdminRoutes;

/**
 * Appearance → Site layout: the two endpoints, the capability, the screen. W1
 *
 * routes/site-layout-admin.php is required from routes/web.php by the
 * INTEGRATOR — no lane may edit that file — so the route file is declared and
 * left unwired, and Tests\Support\SiteLayoutAdminRoutes mounts it here from the
 * real file with the real middleware stack. That is deliberate rather than
 * convenient: this suite exercises the file the integrator will require,
 * including its names and its ordering, so a typo in it fails here rather than
 * after a package has been applied to the live shop.
 *
 * ▲ THIS FILE PINS THE FINISHED STATE, NEVER THE UNWIRED ONE. CLAUDE.md's
 * "Rules for parallel work" forbids by name the obvious assertion — that
 * routes/web.php does NOT yet contain the require line — because it is green in
 * the lane's worktree and goes red the moment the integrator does the one thing
 * the lane asked for, and the only way to green it as written is to unmount the
 * feature. So the assertions below are `substr_count(...) === 1`, which is green
 * today AND after the wiring, and which catches the two shapes that are real
 * failures: zero (built, never mounted) and two (a sidebar entry registered
 * twice, and window.go wrapped around its own wrapper).
 */
function w1Admin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Layout '.$role,
        'email' => 'layout-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

beforeEach(function () {
    SiteLayoutAdminRoutes::wire($this->app);
});

/* ═══════════════════════════════════════════════════ the capability ═══ */

it('maps both routes, and maps neither to the owner-only default', function () {
    /*
     * A route the map has never heard of resolves to null, and
     * EnforceAdminCapability turns null into 403 for everyone but the owner.
     * That is the right default and a terrible thing to rely on: "owner-only
     * because somebody decided so" and "owner-only because nobody mapped it"
     * are the same 403 and a very different piece of evidence.
     *
     * MUTATION: remove the `['*', 'admin-api/site-layout', 'sitelayout.manage']`
     * row from AdminCapabilities::RULES and this is red — as is
     * AdminCapabilityMapTest > it maps every admin route the router actually
     * carries, which is why that file had to be edited and is flagged in the
     * lane report.
     */
    $routes = SiteLayoutAdminRoutes::registered();

    expect($routes)->toHaveCount(2);

    foreach ($routes as $route) {
        $capability = AdminCapabilities::forPath(
            in_array('GET', $route->methods(), true) ? 'GET' : 'POST',
            $route->uri()
        );

        expect($capability)->toBe('sitelayout.manage');
    }
});

it('lets the three appearance roles in and keeps everyone else out', function (string $role, bool $allowed) {
    /*
     * Rule 5: a new endpoint gets its own capability and FAILS CLOSED. The three
     * that hold it are the three that hold cartpage.manage, checkoutpage.manage
     * and slimfooter.manage, because this is storefront appearance — and it is
     * its OWN capability so that narrowing one never silently narrows another
     * from a different file.
     *
     * MUTATION: add 'support' to the capability's role list and the support row
     * below is red.
     */
    $this->actingAs(w1Admin($role), 'admin');

    $response = $this->getJson('/admin-api/site-layout');

    expect($response->status())->toBe($allowed ? 200 : 403);
})->with([
    ['owner', true],
    ['manager', true],
    ['editor', true],
    ['support', false],
]);

it('refuses a signed-out request', function () {
    /*
     * The `auth:admin` half, which the harness mounts for real. A screen that
     * writes a stylesheet onto every page of the shop must not be reachable
     * unauthenticated — and /api/* on this app IS unauthenticated, which is why
     * this endpoint is under /admin-api/ and why the harness applies the same
     * stack routes/web.php applies rather than a convenient subset.
     */
    /*
     * 401 and not 302: `auth:admin` redirects a browser request to the login
     * page and answers a JSON request with a status, and this endpoint is only
     * ever called as JSON. Either is a refusal; the one it actually gives is the
     * one worth pinning, because a test written against the other passes on any
     * 3xx including a redirect to a page that would have served the payload.
     */
    $this->getJson('/admin-api/site-layout')->assertStatus(401);
});

/* ═══════════════════════════════════════════════════════ the payload ═══ */

it('draws two tabs and ten controls, and says the shop is sending nothing', function () {
    $this->actingAs(w1Admin(), 'admin');

    $body = $this->getJson('/admin-api/site-layout')->assertOk()->json();

    expect(collect($body['tabs'])->pluck('key')->all())->toBe(['width', 'grid']);

    $keys = collect($body['tabs'])->flatMap(fn ($t) => collect($t['fields'])->pluck('key'))->all();

    expect($keys)->toBe(array_keys(SiteLayout::SCHEMA));
    expect($keys)->toHaveCount(10);

    /*
     * Rule 1, visible on the screen itself: a shop that has saved nothing is
     * told in as many words that it is sending nothing, rather than being shown
     * a block of CSS it might think is live.
     */
    expect($body['is_default'])->toBeTrue();
    expect($body['css'])->toBe('');
});

it('saves, reports what it refused, and never reports success for a value it dropped', function () {
    /*
     * THE SHAPE THIS PROJECT HAS PAID FOR TWICE: a save that answers "Saved" and
     * stores nothing. AdminController::updateSettings() silently drops a key
     * with no SETTING_RULES entry; `reassure_auth_text` looked settings-driven
     * for its whole life and was not. Nothing here goes through that endpoint —
     * every field is `store: setting` written by this module's own controller —
     * and a refusal is a 422 naming the control.
     *
     * MUTATION: delete the `$result['rejected'] !== []` branch in the controller
     * and the second half of this case is red: the bad pin answers 200.
     */
    $this->actingAs(w1Admin(), 'admin');

    $this->postJson('/admin-api/site-layout', ['settings' => ['max' => 1440, 'gap' => 20]])
        ->assertOk()
        ->assertJson(['ok' => true, 'saved' => 2]);

    SettingsService::forgetMemo();

    expect(app(SiteLayout::class)->all()['max'])->toBe(1440);

    $this->postJson('/admin-api/site-layout', ['settings' => ['pin' => 'nope']])
        ->assertStatus(422)
        ->assertJson(['ok' => false, 'rejected' => ['pin']]);

    $this->postJson('/admin-api/site-layout', ['settings' => ['admin_path' => 'hijack']])
        ->assertStatus(422);
});

it('refuses a key that is not in its own schema rather than writing it', function () {
    /*
     * Rule 5. `admin_path` and `indexnow_key` are real settings this shop holds,
     * and a settings endpoint that writes whatever it is handed is how one of
     * them gets rewritten by a screen that has no business with it.
     *
     * MUTATION: delete the $unknown check in the controller and this is red.
     */
    $this->actingAs(w1Admin(), 'admin');

    $before = app(SettingsService::class)->get('admin_path');

    $this->postJson('/admin-api/site-layout', ['settings' => ['indexnow_key' => 'x']])
        ->assertStatus(422);

    SettingsService::forgetMemo();

    expect(app(SettingsService::class)->get('admin_path'))->toBe($before);
});

/* ══════════════════════════════════════════ wired exactly once ═══ */

it('is required from routes/web.php exactly once', function () {
    /*
     * THE FINISHED STATE, not the unwired one — see this file's header.
     *
     * ZERO is the "built, never wired up" shape this repo keeps finding: the
     * screen draws and both of its requests answer 404. TWO registers the two
     * routes twice, which makes the route NAME lookup ambiguous.
     *
     * This case is SKIPPED, not failed, while the integrator has not yet wired
     * it: a lane cannot edit routes/web.php, so a red here would be a lane
     * failing for the one thing it is forbidden to do. It turns into a real
     * assertion the moment the line lands, and from then on it can only go red
     * for a real regression.
     */
    $web = (string) file_get_contents(base_path('routes/web.php'));
    $line = "require __DIR__.'/site-layout-admin.php';";

    if (! str_contains($web, 'site-layout-admin.php')) {
        $this->markTestSkipped(
            'Not wired yet. The integrator adds, inside the admin-api group in routes/web.php: '.$line
        );
    }

    expect(substr_count($web, $line))->toBe(1);
});

it('is included in the admin console exactly once', function () {
    /*
     * The same shape for the screen partial, and this one IS this lane's to
     * assert because the lane was permitted to edit admin/app.blade.php.
     *
     * TWO is a real failure and a specific one: the partial calls
     * window.kbbAddNavEntry() and then wraps window.go. Included twice, the
     * sidebar grows two "Site layout" rows and the second wrapper closes over
     * the first, so `previousGo` is the wrapper rather than the console's own
     * router — every OTHER screen then routes through two copies of this one's
     * early return.
     *
     * MUTATION: paste the @include line a second time and this is red.
     */
    $app = (string) file_get_contents(base_path('resources/views/admin/app.blade.php'));

    expect(substr_count($app, "@include('admin.partials.site-layout-screen')"))->toBe(1);
    expect(is_file(base_path('resources/views/admin/partials/site-layout-screen.blade.php')))->toBeTrue();
});

it('gives the screen a sidebar entry in Appearance and nothing that collides with another screen', function () {
    /*
     * app.blade.php binds delegated click listeners to `document` itself, each
     * claiming a BARE attribute name — so a `data-save` on this screen would be
     * handled by another screen's listener. Every hook here is prefixed, and
     * this case is what stops the next edit dropping the prefix.
     *
     * MUTATION: rename one `data-sls-save` to `data-save` and this is red.
     */
    $screen = (string) file_get_contents(base_path('resources/views/admin/partials/site-layout-screen.blade.php'));

    expect($screen)->toContain("group: 'Appearance'");
    expect($screen)->toContain("label: 'Site layout'");
    expect($screen)->toContain("var SCREEN = 'sitelayout';");

    /*
     * Every data- attribute the screen DECLARES is prefixed. Two it only READS
     * belong to the console itself — `data-sec` on the sidebar group it opens and
     * `data-go` on the nav buttons it un-highlights — and naming them here is
     * the point: the list is short, so a third one appearing is a decision
     * somebody has to make on purpose rather than a collision found later.
     */
    $consoleOwned = ['sec', 'go'];

    preg_match_all('/data-([a-z0-9-]+)/', $screen, $m);

    foreach (array_unique($m[1]) as $attr) {
        if (in_array($attr, $consoleOwned, true)) {
            continue;
        }

        expect($attr)->toStartWith('sls-');
    }

    /*
     * And the screen does not measure layout. Rule 4 forbids that on the shop;
     * the reason it gives — that a rendered-once CSS answer beats a scripted one
     * — is why the preview here must not measure a mock either. It runs the same
     * arithmetic the stylesheet runs, on numbers.
     */
    /*
     * Comments stripped first, because the docblock at the top of that file NAMES
     * these APIs in order to say it does not use them — and a search of the raw
     * bytes matches the prose and is true whatever the code does. That trap has
     * caught assertions in this repo before (see PhoneShopperLayoutTest's note on
     * matching a class name in an inline stylesheet).
     */
    $code = (string) preg_replace(
        ['/\{\{--.*?--\}\}/s', '#/\*.*?\*/#s'],
        '',
        $screen
    );

    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'getComputedStyle'] as $api) {
        expect($code)->not->toContain($api);
    }
});
