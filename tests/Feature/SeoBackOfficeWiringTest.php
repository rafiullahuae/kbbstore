<?php

/**
 * How the SEO back office is mounted, and what it may not do — Lane S7.
 *
 * ── WHAT THIS FILE DELIBERATELY DOES NOT ASSERT ─────────────────────────────
 *
 * It does NOT assert that `require __DIR__.'/seo-back-office.php';` is absent
 * from routes/web.php. CLAUDE.md records three lanes making exactly that mistake
 * in one day — the article editor, the homepage preview and the shoppable-video
 * screen — because that assertion is correct in a lane's worktree and goes red
 * the moment the integrator does the one thing the lane asked for, and the only
 * way to green it as written is to UNMOUNT the feature.
 *
 * It also cannot assert the require is PRESENT: this lane may not edit
 * routes/web.php, so that would be red today. What it pins instead is the pair
 * of things that can really regress and that this lane owns:
 *
 *   · the screen partial is `@include`d from app.blade.php EXACTLY ONCE. Zero is
 *     the "built, never wired up" shape this repo keeps finding; two defines
 *     window.kbbSeoPreview twice and installs two copies of the Overview.
 *   · the route file registers EXACTLY the two routes, under the exact
 *     middleware stack routes/web.php's admin-api group applies, and each is
 *     mapped to a capability on purpose rather than by the closed default.
 *
 * Both are green in this worktree the day they are written and green after the
 * integrator adds the require.
 */

use App\Http\Controllers\Admin\SeoPreviewApiController;
use App\Models\AdminUser;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SeoBackOfficeRoutes;

it('includes the SEO back office exactly once from the admin bundle', function () {
    /*
     * EXACTLY ONCE, which is the thing that can regress. The partial defines two
     * globals — window.kbbSeoPreview and window.kbbSeoOverview — and a second
     * copy would redefine both, leaving the four editors bound to one instance
     * and the Overview tab to the other. Zero is the shape this repository keeps
     * finding: the fallback update page that 500'd for its whole life, the crawl
     * files mounted with no walk entry.
     */
    $partial = resource_path('views/admin/partials/seo-back-office.blade.php');

    expect(is_file($partial))->toBeTrue();

    $app = file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($app, "@include('admin.partials.seo-back-office')"))->toBe(
        1,
        'the SEO back office is included '
        .substr_count($app, "@include('admin.partials.seo-back-office')")
        .' times from app.blade.php; it must be exactly one'
    );
});

it('adds the Overview subtab exactly once and dispatches to it exactly once', function () {
    /*
     * The same argument one level down. A duplicated subtab button draws two
     * "Overview" tabs on Store → SEO & Meta, and a duplicated dispatch line is
     * harmless today and is the shape that makes a screen paint twice the day
     * either line grows a side effect.
     */
    $app = file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($app, 'data-st="overview"'))->toBe(1);
    expect(substr_count($app, "if(seoTab==='overview') return window.kbbSeoOverview();"))->toBe(1);

    /*
     * AND THE FIVE TABS THAT WERE ALREADY THERE ARE STILL THERE, once each. This
     * lane added a tab to a screen that works; losing one of the other five to a
     * careless string edit is the failure rule 1 exists for and it would be
     * invisible in a diff of a 21,000-line file.
     */
    foreach (['settings', 'redirects', 'schema', 'audit', 'seoaudit'] as $tab) {
        expect(substr_count($app, 'data-st="'.$tab.'"'))->toBe(1, "the {$tab} subtab is no longer drawn exactly once");
    }
});

it('leaves the SEO settings tab posting exactly the keys it posted before', function () {
    /*
     * RULE 1, AT THE ONE PLACE THIS LANE TOUCHED A SAVE PATH — it did not, and
     * this is what says so. The homepage preview is a mount point in the middle
     * of the Search appearance band; if it had been given an id the save handler
     * picks up, or if inserting it had displaced a field, the shop would start
     * publishing something the owner did not type and nobody would find out for
     * weeks.
     *
     * Thirty-eight keys, counted off the handler itself rather than listed here,
     * so this cannot drift into agreeing with a broken handler.
     */
    $app = file_get_contents(resource_path('views/admin/app.blade.php'));

    $start = strpos($app, "document.getElementById('set_save_seo').onclick");
    expect($start)->not->toBeFalse();

    $end = strpos($app, 'SEO settings saved', $start);
    expect($end)->not->toBeFalse();

    $handler = substr($app, $start, $end - $start);

    // Every id the handler reads, which is every control on the tab.
    preg_match_all("/sval\('([a-z0-9_]+)'\)/i", $handler, $m);
    $read = array_values(array_unique($m[1]));

    sort($read);

    expect($read)->toBe([
        'seo_baidu', 'seo_bing', 'seo_def_d', 'seo_ga', 'seo_gsv', 'seo_home_d',
        'seo_home_t', 'seo_merch_cond', 'seo_merch_cost', 'seo_merch_country',
        'seo_merch_freeover', 'seo_merch_returndays', 'seo_og_img', 'seo_org_logo',
        'seo_org_name', 'seo_org_type', 'seo_pin', 'seo_pixel', 'seo_robots_f',
        'seo_robots_i', 'seo_robots_txt', 'seo_sep', 'seo_site_url',
        'seo_sitemap', 'seo_sitemap_images', 'seo_sitename', 'seo_soc_fb',
        'seo_soc_ig', 'seo_soc_li', 'seo_soc_pin', 'seo_soc_tt', 'seo_soc_yt',
        'seo_tpl', 'seo_tw',
    ], 'the SEO settings tab reads a different set of controls than it did before this lane');

    /*
     * AND THE PREVIEW'S MOUNT IS NOT ONE OF THEM. `seo_home_prev` is a div; if
     * it ever appears in this handler somebody has turned it into a field.
     */
    expect($handler)->not->toContain('seo_home_prev');
});

it('mounts exactly two routes, on the stack the admin-api group applies', function () {
    SeoBackOfficeRoutes::wire(app());

    $routes = SeoBackOfficeRoutes::registered();

    expect($routes)->toHaveCount(2, 'routes/seo-back-office.php registered '.count($routes).' routes, not two');

    $seen = [];

    foreach ($routes as $route) {
        $methods = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));
        $seen[] = implode('|', $methods).' '.$route->uri();

        $middleware = $route->gatherMiddleware();

        /*
         * in_array() AND NOT ->toContain($layer, $message).
         *
         * FOUND BY RUNNING THIS, not by reading it. Pest's `toContain` is
         * VARIADIC: every argument is another needle, so the message I wrote as
         * the second argument became a string the middleware list had to contain,
         * and the failure read `Failed asserting that an array contains
         * 'admin-api/seo-preview is missing web'`. That is the same defect this
         * repository's own ExpectationsThatCannotFailTest names by file and line
         * — and in the `not->toContain` direction it does not fail loudly, it
         * passes against nothing. Lane M4 §5 records two assertions that could
         * not fail for exactly this reason.
         */
        foreach (SeoBackOfficeRoutes::STACK as $layer) {
            expect(in_array($layer, $middleware, true))
                ->toBeTrue($route->uri().' is missing '.(is_string($layer) ? $layer : 'a middleware layer'));
        }

        /*
         * A THROTTLE ON BOTH, and they are deliberately different — see
         * routes/seo-back-office.php. seo-tasks runs a whole-catalogue scan and
         * gets the audit's six a minute; seo-preview is two settings reads and
         * is called while somebody types, so six would break the feature.
         */
        $throttled = false;
        foreach ($middleware as $layer) {
            if (is_string($layer) && str_starts_with($layer, 'throttle:')) {
                $throttled = true;
            }
        }

        expect($throttled)->toBeTrue($route->uri().' carries no throttle');
    }

    sort($seen);

    expect($seen)->toBe(['GET admin-api/seo-tasks', 'POST admin-api/seo-preview']);
});

it('maps both routes to a capability on purpose rather than by the closed default', function () {
    SeoBackOfficeRoutes::wire(app());

    foreach (SeoBackOfficeRoutes::registered() as $route) {
        /*
         * AdminCapabilities' default is closed — an unmapped route is owner-only
         * — and AdminCapabilityMapTest exists so that a route is owner-only ON
         * PURPOSE rather than because nobody thought about it. This asserts the
         * two entries for the two routes this lane added, by name, so removing
         * one fails here as well as in the map's own walk.
         */
        expect(AdminCapabilities::for($route))->not->toBeNull($route->uri().' is unmapped');
    }

    expect(AdminCapabilities::forPath('POST', 'admin-api/seo-preview'))->toBe('store.settings');
    expect(AdminCapabilities::forPath('GET', 'admin-api/seo-tasks'))->toBe('system.diagnostics');
});

it('refuses both routes to a signed-out browser', function () {
    /*
     * FAILS CLOSED, and the two are refused for different reasons that both have
     * to hold: the overview is a map of where this shop is weakest and the
     * preview is a template render on demand. /api/* in this application is
     * public by design and neither may go there.
     */
    SeoBackOfficeRoutes::wire(app());

    test()->postJson('/admin-api/seo-preview', ['kind' => 'home'])->assertStatus(401);
    test()->getJson('/admin-api/seo-tasks')->assertStatus(401);
});

it('refuses both routes to a role that is not the owner', function () {
    SeoBackOfficeRoutes::wire(app());

    $editor = AdminUser::create([
        'name' => 'S7 Editor',
        'email' => 's7-editor@example.com',
        'password' => Hash::make('secret-secret'),
        'role' => 'editor',
    ]);

    test()->actingAs($editor, 'admin');

    test()->postJson('/admin-api/seo-preview', ['kind' => 'home'])->assertStatus(403);
    test()->getJson('/admin-api/seo-tasks')->assertStatus(403);
});

it('names every kind it previews and previews every kind it names', function () {
    /*
     * The list is the contract between the four screens and the endpoint. A kind
     * in KINDS that the endpoint cannot build a context for would answer 200 with
     * a preview of nothing; a screen asking for a kind not in KINDS gets a 422 it
     * cannot act on. Both are checked by driving every kind rather than by
     * reading the match arms.
     */
    SeoBackOfficeRoutes::wire(app());

    $owner = AdminUser::create([
        'name' => 'S7 Kinds Owner',
        'email' => 's7-kinds-owner@example.com',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);

    test()->actingAs($owner, 'admin');

    expect(SeoPreviewApiController::KINDS)->toBe(['home', 'category', 'brand', 'article']);

    foreach (SeoPreviewApiController::KINDS as $kind) {
        $response = test()->postJson('/admin-api/seo-preview', [
            'kind' => $kind,
            'title' => 'A title for '.$kind,
            'description' => 'A description for '.$kind,
            'name' => 'Row name',
        ]);

        $response->assertStatus(200);

        expect($response->json('title'))->toBe('A title for '.$kind, $kind.' did not resolve a typed title');
        expect($response->json('kind'))->toBe($kind);
    }
});

it('draws every preview and every finding with text, never with markup', function () {
    /*
     * RULE 5, AT THE PRINT SITE. This screen draws two operator-supplied strings
     * (a title and a description he typed) plus product, category and brand
     * names out of the database. A lane found a live unescaped setting on the
     * storefront homepage this week — `{!! $chip !!}` around `home_ticker` — and
     * this must not be the second one.
     *
     * The guarantee is structural rather than a habit: this partial has NO helper
     * that takes markup. `el(tag, cls, text)` sets .textContent, and every value
     * goes through it or through .textContent directly. So the assertion is that
     * the file contains no assignment of a value into innerHTML at all.
     *
     * MUTATION ACTUALLY RUN: changing one `el('div', 's7-cost', item.cost)` to an
     * innerHTML assignment fails this, naming the line.
     */
    $source = file_get_contents(resource_path('views/admin/partials/seo-back-office.blade.php'));

    // The two places innerHTML is legitimate: clearing a container. Neither is
    // used here — textContent = '' does the same job and cannot take markup.
    expect($source)->not->toContain('innerHTML');
    expect($source)->not->toContain('insertAdjacentHTML');
    expect($source)->not->toContain('outerHTML');
    expect($source)->not->toContain('document.write');

    /*
     * AND THE SNIPPET'S ADDRESS LINE IS NOT A LINK. `site_url` is an operator
     * setting and the project notes require a URL from a setting to be
     * scheme-checked before it becomes an href; the stricter answer is for it
     * never to become one, which is also what a real Google result does. So
     * nothing in this file creates an anchor or sets an href.
     */
    expect($source)->not->toContain("createElement('a')");
    expect($source)->not->toContain('.href =');
});

it('lays the whole back office out with logical properties, so it mirrors', function () {
    /*
     * The owner has said explicitly that he is not launching Arabic immediately
     * and wants everything built for it. A back office laid out with `left`,
     * `padding-left` and `text-align:left` is a back office that has to be
     * written twice, and the second copy is the one nobody maintains.
     *
     * So every directional rule in this file is logical. The check is on the
     * file's own CSS block only — it cannot speak for the rest of the console.
     */
    $source = file_get_contents(resource_path('views/admin/partials/seo-back-office.blade.php'));

    $start = strpos($source, '<style>');
    $end = strpos($source, '</style>');

    expect($start)->not->toBeFalse();
    expect($end)->not->toBeFalse();

    $css = substr($source, $start, $end - $start);

    foreach ([
        'padding-left', 'padding-right', 'margin-left', 'margin-right',
        'border-left', 'border-right', 'text-align:left', 'text-align:right',
        'text-align: left', 'text-align: right',
    ] as $physical) {
        // str_contains() and not ->not->toContain($needle, $message): see the
        // note in the route test above — the second argument is another NEEDLE,
        // and in the negated direction that makes the assertion unfailable.
        expect(str_contains($css, $physical))
            ->toBeFalse("the back office uses {$physical}, which does not mirror in Arabic");
    }

    // And it really does use the logical ones, so this asserted something.
    expect($css)->toContain('border-inline-start');
    expect($css)->toContain('text-align:start');
});
