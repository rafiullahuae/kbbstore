<?php

declare(strict_types=1);

/**
 * Appearance → Footer as FOUR PAGES, each with a live preview.        (Lane FT)
 *
 * THE OWNER, 4 October, with a screenshot of the old screen
 * (docs/ft-owner-screenshot.png): "The footer page is completely messed up. I
 * want total 4 pages in footer. Desktop, Mobile. Cart-Checkout Footer for
 * Desktop and Mobile. and also i need previews on each page. make it super
 * easy to use. and complete controls."
 *
 * THE DEFECT, as it looked in the admin: one screen, thirteen tabs, two
 * different footers mixed in one strip ("Site footer · layout desktop" beside
 * "On a phone" — which is the CART bar's phone, not the site footer's), and a
 * preview that was a hand-drawn copy of the slim bar only. The site footer had
 * no preview at all.
 */

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\SiteFooter;
use App\Services\SlimFooter;
use App\Support\AdminCapabilities;
use App\Support\AdminRoles;
use App\Support\AdminSearchIndex;
use App\Support\FooterPages;
use App\Support\FooterPreviewSettings;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;

function ftAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'FT '.$role, 'email' => 'ft-'.$role.'-'.Str::random(8).'@example.com',
        'password' => bcrypt('secret'), 'role' => $role,
    ]);
}

/** The slim bar as the cart page prints it, with the stylesheet it pushes. */
function ftBar(): string
{
    SettingsService::forgetMemo();

    return Blade::render("@include('partials.slim-footer')@stack('styles')");
}

function ftPreview(\Tests\TestCase $t, string $page, array $settings = [], string $role = 'owner'): \Illuminate\Testing\TestResponse
{
    return $t->actingAs(ftAdmin($role), 'admin')
        ->postJson('/admin-api/slim-footer/preview', ['page' => $page, 'settings' => $settings]);
}

/* ═══════════════════════ 1. four pages, wired once ═════════════════════════ */

it('serves four pages, and the screen and its routes are wired exactly once', function () {
    /*
     * Pins the FINISHED state (CLAUDE.md): one include of the screen, one
     * require of the route file, the preview route registered. Zero is the
     * "built, never wired up" shape; two registers a sidebar entry twice and
     * wraps window.go around its own wrapper.
     *
     * MUTATION: drop 'bar-m' from FooterPages::PAGES and the label list is red.
     */
    $body = $this->actingAs(ftAdmin(), 'admin')->getJson('/admin-api/slim-footer')->assertOk()->json();

    expect(array_column($body['pages'], 'label'))->toBe([
        'Site footer · Desktop', 'Site footer · Mobile',
        'Cart & Checkout footer · Desktop', 'Cart & Checkout footer · Mobile',
    ]);

    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $web = (string) file_get_contents(base_path('routes/web.php'));
    $routes = (string) file_get_contents(base_path('routes/slim-footer-admin.php'));

    expect(substr_count($app, "@include('admin.partials.slim-footer-screen')"))->toBe(1)
        ->and(substr_count($web, "require __DIR__.'/slim-footer-admin.php';"))->toBe(1)
        ->and(substr_count($routes, "Route::post('/slim-footer/preview'"))->toBe(1)
        ->and(app('router')->getRoutes()->getByName('admin.slim-footer.preview'))->not->toBeNull();

    // The screen draws the pages it is sent, as big tabs the sidebar search
    // can click (a button whose class ends in "tab", with role=tab).
    $screen = (string) file_get_contents(resource_path('views/admin/partials/slim-footer-screen.blade.php'));
    expect($screen)->toContain('class="sfs-ptab" role="tab" data-sfs-page="')
        ->and($screen)->toContain("pages = body.pages || [];");
});

/* ═══════════════════ 2. every control, on the right page ═══════════════════ */

it('draws every key of both footers on a page, device keys only on their own device', function () {
    /*
     * THE DEFECT this guards: a key added to SiteFooter or SlimFooter with no
     * home on any page is a setting with no control — it saves nothing and
     * nobody can find it. And a phone-only slider drawn on the Desktop page is
     * the old screen's confusion back again.
     *
     * MUTATION: delete 'site_{dev}_pb' from FooterPages::SITE and the first
     * expectation names site_d_pb and site_m_pb; move 'm_gap' into BAR_D and
     * the second is red.
     */
    $drawn = [];
    $wrong = [];
    $twice = [];

    foreach (FooterPages::pages() as $page) {
        $keys = FooterPages::keys($page['key']);

        foreach (array_count_values($keys) as $k => $n) {
            if ($n > 1) {
                $twice[] = "{$page['key']}: {$k}";
            }
        }

        foreach ($keys as $k) {
            $drawn[$k][] = $page['key'];
            $own = FooterPages::deviceOf($k);

            if ($own !== null && $own !== $page['device']) {
                $wrong[] = "{$k} on {$page['key']}";
            }

            $schema = $page['footer'] === 'site' ? SiteFooter::SCHEMA : SlimFooter::SCHEMA;
            expect($schema)->toHaveKey($k);
        }
    }

    $all = array_merge(array_keys(SiteFooter::SCHEMA), array_keys(SlimFooter::SCHEMA));

    expect(array_values(array_diff($all, array_keys($drawn))))->toBe([], 'keys with no page')
        ->and($wrong)->toBe([], 'device keys on the other device’s page')
        ->and($twice)->toBe([], 'a key drawn twice on one page');

    // A shared key is on BOTH device pages of its footer; a device key on one.
    foreach (FooterPages::shared() as $k) {
        $footer = isset(SiteFooter::SCHEMA[$k]) ? 'site' : 'bar';
        expect($drawn[$k])->toBe(["{$footer}-d", "{$footer}-m"], "{$k} is shared and should be on both device pages");
    }

    foreach (array_diff($all, FooterPages::shared()) as $k) {
        expect(count($drawn[$k]))->toBe(1, "{$k} is device-only and should be on one page");
    }
});

it('writes a shared control to one setting, whichever page saves it', function () {
    /*
     * "Shared, device-independent settings ... appear on both device pages but
     * write the SAME key through the SAME endpoint." A second, per-page copy
     * would be two places to correct and one of them silently wrong.
     *
     * MUTATION: give the mobile page its own copy of the accent (a
     * `site_m_c_accent`) and the one-field and one-row assertions are red.
     */
    $owner = ftAdmin();
    $body = $this->actingAs($owner, 'admin')->getJson('/admin-api/slim-footer')->json();
    $fields = array_merge(...array_map(static fn ($t) => array_column($t['fields'], 'key'), $body['tabs']));

    expect(array_count_values($fields)['site_c_accent'])->toBe(1)
        ->and($body['shared'])->toContain('site_c_accent')->toContain('brand')->toContain('tone')
        ->and($body['shared'])->not->toContain('site_d_pt')->not->toContain('m_gap')->not->toContain('pad_y');

    $this->actingAs($owner, 'admin')->postJson('/admin-api/slim-footer', ['settings' => ['site_c_accent' => '#a82f53']])->assertOk();
    SettingsService::forgetMemo();

    expect(Setting::query()->where('key', 'like', '%c_accent%')->pluck('key')->all())->toBe(['sitefooter_site_c_accent'])
        ->and(app(SiteFooter::class)->all()['site_c_accent'])->toBe('#A82F53');
});

/* ═══════════════════════ 3. the preview is the shop ════════════════════════ */

it('renders the slim bar preview with the partial the cart page uses', function () {
    /*
     * THE DEFECT: the old preview was a hand-written copy of the bar's CSS and
     * markup in the admin's own JavaScript — "a drawing, not the live page" —
     * so it could disagree with the shop the first time either was touched.
     *
     * MUTATION: draw the bar in admin/previews/footer.blade.php with markup of
     * its own instead of @include('partials.slim-footer') and the first
     * assertion is red.
     */
    $html = ftPreview($this, 'bar-d')->assertOk()->json('html');

    preg_match('#<footer class="kbb-slimfoot.*?</footer>#s', ftBar(), $real);

    expect($html)->toContain($real[0])
        ->and($html)->toContain('<!doctype html>')
        ->and($html)->toContain('.kbb-slimfoot{')
        // The pushed scripts stack is never printed: the frame runs nothing.
        ->and($html)->not->toContain('IntersectionObserver');
});

it('shows an unsaved change in the preview and stores nothing', function () {
    /*
     * The whole point of a preview: the owner moves a control and sees it
     * BEFORE Save. And it must not be a save in disguise.
     *
     * MUTATION: drop the `$app->instance(SettingsService::class, $overlay)`
     * line in preview() and the first two assertions are red; make
     * FooterPreviewSettings::set() pass through and the row count moves.
     */
    $rows = Setting::query()->count();

    $site = ftPreview($this, 'site-d', ['site_name_text' => 'Preview Name & Co', 'site_c_accent' => '#112233'])->assertOk()->json('html');
    $bar = ftPreview($this, 'bar-m', ['brand_style' => 'text', 'brand' => 'Bar & Brand', 'm_brand_size' => 150])->assertOk()->json('html');

    expect($site)->toContain('<footer class="kft')
        ->and($site)->toContain('>Preview Name &amp; Co</p>')
        ->and($site)->toContain('--kft-accent:#112233')
        ->and($bar)->toContain('<b>Bar &amp; Brand</b>')
        ->and($bar)->toContain('--sf-m-bf:1.5');

    SettingsService::forgetMemo();
    expect(Setting::query()->count())->toBe($rows)
        ->and(app(SiteFooter::class)->all()['site_name_text'])->toBe('K-Beauty Bliss')
        ->and(app(SlimFooter::class)->get('brand'))->toBe('K-BEAUTY BLISS')
        // And the overlay is gone after the render: the container hands back
        // the real service, not the preview's.
        ->and(app(SettingsService::class))->not->toBeInstanceOf(FooterPreviewSettings::class);

    expect(fn () => (new FooterPreviewSettings(app(SettingsService::class), []))->set('x', 'y'))
        ->toThrow(LogicException::class);
});

it('casts a preview value exactly as the save would, and refuses a key of no schema', function () {
    /*
     * The preview prints into a style attribute and into the page. A value
     * the save would refuse must not reach it either.
     *
     * MUTATION: print the overlay value raw in FooterPreviewSettings::get()
     * bypassing the services' cast — impossible as written, since all()
     * casts every read — or let an unknown key through and the 422 is red.
     */
    $html = ftPreview($this, 'site-d', ['site_c_accent' => 'red;}body{display:none', 'site_sheen' => 'bogus'])->assertOk()->json('html');

    expect($html)->toContain('--kft-accent:#C13E63')
        ->and($html)->not->toContain('display:none');

    ftPreview($this, 'site-d', ['admin_path' => 'x'])->assertStatus(422);
    ftPreview($this, 'nope')->assertStatus(422);
});

it('frames each page at its own width and scales it with CSS, measuring nothing', function () {
    /*
     * CLAUDE.md rule 4, and two tests forbid the element-measuring APIs by name.
     * A 1280px page in a narrower card is a scale, and the scale is the card's
     * width over 1280 — tan(atan2(100cqw, 1280px)) — worked out by the browser.
     *
     * MUTATION: scale the frame from `frame.getBoundingClientRect().width /
     * 1280` and the first loop is red.
     */
    $screen = (string) file_get_contents(resource_path('views/admin/partials/slim-footer-screen.blade.php'));

    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'clientHeight', 'scrollWidth', 'scrollHeight', 'getComputedStyle', 'setInterval', 'ResizeObserver'] as $api) {
        expect($screen)->not->toContain($api);
    }

    expect($screen)->toContain('transform:scale(tan(atan2(100cqw, calc(var(--fw) * 1px))))')
        ->and($screen)->toContain("'site-d': { w: 1280,")->toContain("'site-m': { w: 390,")
        ->and($screen)->toContain("'bar-d': { w: 1280,")->toContain("'bar-m': { w: 390,")
        // Sandboxed with no scripts, and asked for once per pause.
        ->and($screen)->toContain('sandbox="allow-same-origin"')->not->toContain('allow-scripts')
        ->and($screen)->toContain('preview(350);');
});

/* ═════════════════════════ 4. who may preview ══════════════════════════════ */

it('gives the preview its own capability, and refuses a role without it', function () {
    /*
     * An endpoint that renders a Blade from a POST body, left off the map,
     * would be owner-only at best and open at worst. MUTATION: delete the
     * 'admin-api/slim-footer/preview' rule and forPath() answers null — the
     * exact 'admin-api/slim-footer' line does not reach this path.
     */
    expect(AdminCapabilities::forPath('POST', 'admin-api/slim-footer/preview'))->toBe('footer.preview')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/slim-footer'))->toBe('slimfooter.manage')
        ->and(AdminCapabilities::roleCan('editor', 'footer.preview'))->toBeTrue()
        ->and(AdminCapabilities::roleCan('support', 'footer.preview'))->toBeFalse();

    $listed = [];
    foreach (AdminRoles::SECTIONS as [, , $caps]) {
        $listed += $caps;
    }
    expect($listed)->toHaveKey('footer.preview');

    ftPreview($this, 'site-d', [], 'support')->assertForbidden();
    ftPreview($this, 'site-d', [], 'editor')->assertOk();
});

/* ═════════════════ 5. the new counterpart moves only the phone ═════════════ */

it('ships the phone brand size following the desktop one, so the bar is unchanged', function () {
    /*
     * `m_brand_size` is the one setting this lane adds: the Mobile page had no
     * brand size while the Desktop page did. Applying the package must move no
     * byte on the cart or the checkout (rule 1), so until it has a row it
     * mirrors `brand_size` and prints nothing.
     *
     * MUTATION: emit `--sf-m-bf` whenever mobile_on is on, rowless, and the
     * first assertion is red; drop the mirror in all() and the second is.
     */
    expect(ftBar())->not->toContain('--sf-m-bf')->not->toContain('.sf-msplit .sf-brand b{font-size');

    sf2()->save(['brand_size' => 130]);
    $c = sf2()->all();
    expect($c['m_brand_size'])->toBe(130)
        ->and(ftBar())->toContain('--sf-bf:1.3')->not->toContain('--sf-m-bf');
});

it('sizes only the phone wordmark once the phone brand size is moved', function () {
    /*
     * MUTATION: put the rule outside `@media (max-width:900px)` and the desktop
     * would shrink too — the media assertion is red; drop the `mobile_on`
     * gate and the last block is.
     */
    sf2()->save(['m_brand_size' => 150]);
    $html = ftBar();

    expect($html)->toContain('--sf-m-bf:1.5')
        ->and($html)->toContain('<style>@media (max-width:900px){.kbb-slimfoot.sf-msplit .sf-brand b{font-size:calc(14px * var(--sf-m-bf))}}</style>')
        // The desktop size has not moved: no --sf-bf on the element itself.
        ->and(preg_match('#<footer class="kbb-slimfoot[^"]*"([^>]*)>#', $html, $tag))->toBe(1)
        ->and($tag[1])->not->toContain('--sf-bf');

    sf2()->save(['mobile_on' => false]);
    expect(ftBar())->not->toContain('--sf-m-bf')->not->toContain('var(--sf-m-bf)');
});

function sf2(): SlimFooter
{
    SettingsService::forgetMemo();

    return app(SlimFooter::class);
}

/* ═══════════════════════ 6. search finds the pages ═════════════════════════ */

it('lets the sidebar search land on each page and its sections', function () {
    /*
     * The old index listed the thirteen tab names; a search result for one of
     * them would open the screen and find nothing to click.
     *
     * MUTATION: put SlimFooter back in SCHEMA_SCREENS['slimfooter'] and the
     * tab list gains "Where it shows" etc., which the screen no longer draws.
     */
    [$tabs, $items] = AdminSearchIndex::build()['slimfooter'];

    expect($tabs)->toBe(array_column(FooterPages::pages(), 'label'));

    $m = array_search('Cart & Checkout footer · Mobile', $tabs, true);
    $d = array_search('Site footer · Desktop', $tabs, true);

    expect($items)->toContain([$m, 'Brand size on a phone'])
        ->and($items)->toContain([$m, 'Size & spacing'])
        ->and($items)->toContain([$d, 'Big name'])
        ->and($items)->toContain([$d, 'Space below the big name']);
});

/* ═══════════════ 7. the last row stands still; the big name drifts ═════════ */

it('ships the last row without its shine, and keeps the big name drifting', function () {
    /*
     * THE OWNER, 4 October: "also remove the effect from the very last row of
     * the footer. animation not from the logo." On the shop, the bottom bar
     * (© · Privacy · Terms · payment marks) carried `kft-sheen-bar`: a pink
     * light sweeping across it every five seconds, under the big name's slow
     * colour drift (`kft-motion`), which he wants kept.
     *
     * MUTATION: put the default back to 'bar' and the first assertion is red;
     * drop the migration's update and the stored 'bar' keeps shining.
     */
    $classes = preg_match('#<footer class="(kft[^"]*)"#', $this->get('/')->getContent(), $m) ? explode(' ', $m[1]) : [];

    expect($classes)->toContain('kft-motion')
        ->and(implode(' ', $classes))->not->toContain('kft-sheen');

    // The switch sits in the Bottom bar section of BOTH site pages — one
    // shared setting, tagged "Desktop + mobile" by the screen.
    foreach (['site-d', 'site-m'] as $page) {
        $bottom = collect(FooterPages::sections($page))->firstWhere('title', 'Bottom bar');
        expect($bottom['keys'])->toContain('site_sheen');
    }
    expect(FooterPages::shared())->toContain('site_sheen')
        ->and(SiteFooter::SCHEMA['site_sheen']['label'])->toBe('Effect on the last row (the shine)');

    // A shop that saved the old screen has 'bar' stored: the migration turns
    // exactly that off, and leaves a shine he moved to the big name alone.
    $up = fn () => (require database_path('migrations/2027_08_05_100100_footer_last_row_still.php'))->up();

    app(SettingsService::class)->set('sitefooter_site_sheen', 'bar');
    ob_start(); $up(); ob_end_clean();
    SettingsService::forgetMemo();
    expect(app(SiteFooter::class)->all()['site_sheen'])->toBe('off');

    app(SettingsService::class)->set('sitefooter_site_sheen', 'name');
    ob_start(); $up(); ob_end_clean();
    SettingsService::forgetMemo();
    expect(app(SiteFooter::class)->all()['site_sheen'])->toBe('name');
});
