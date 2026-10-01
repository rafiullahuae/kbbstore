<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Setting;
use App\Services\HeaderSettings;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * APPEARANCE → HEADER → BREADCRUMBS.                             (Lane PI-B)
 *
 * The owner, about the line at the top of a product page — "Home / Super Sale
 * / Medicube – PDRN Glow Booster Set (Pink Edition)": control of its spacing,
 * above and below; on and off; SEPARATELY for mobile and desktop; on product
 * pages "etc"; and "by default keep it off".
 *
 * What each case below would have caught, on the shop:
 *
 *   - a default left ON, so applying the package moved nothing he asked for;
 *   - a page that draws a trail but never received the stylesheet, so the
 *     trail stayed on there while the screen said off (the journal article is
 *     the one that does not extend the layout and is the one to forget);
 *   - one switch driving both widths, so "phone off, desktop on" was
 *     impossible to save;
 *   - hiding that took the BreadcrumbList structured data with it;
 *   - a slider that accepted 99999 or a string and printed it into CSS;
 *   - an endpoint a support account could write to.
 */

function bcOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'BC '.$role, 'email' => 'bc-'.$role.'-'.Str::random(8).'@example.com',
        'password' => bcrypt('secret'), 'role' => $role,
    ]);
}

function bcSet(array $values): void
{
    app(HeaderSettings::class)->save($values);
    SettingsService::forgetMemo();
}

/** One `<style id="kbb-crumbs">…</style>` from a document, or null. */
function bcStyle(string $html): ?string
{
    return preg_match('#<style id="kbb-crumbs">(.*?)</style>#s', $html, $m) === 1 ? $m[1] : null;
}

/**
 * A small catalogue with every kind of page that draws a trail, and the path
 * of each. Keyed by what the owner would call the page.
 *
 * @return array<string, string>
 */
function bcPages(): array
{
    $brand = Brand::create(['name' => 'Medicube', 'slug' => 'medicube-bc', 'position' => 1]);
    $category = Category::create(['name' => 'Super Sale', 'slug' => 'super-sale-bc']);

    $product = Product::create([
        'slug' => 'pdrn-glow-booster-bc', 'name' => 'Medicube – PDRN Glow Booster Set (Pink Edition)',
        'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => 9900, 'stock_status' => 'instock',
        'brand_id' => $brand->id, 'category_id' => $category->id,
    ]);
    $product->categories()->syncWithoutDetaching([$category->id]);

    $post = Post::create([
        'slug' => 'bc-article', 'title' => 'Double cleansing, without the sermon',
        'body' => '<p>Two steps.</p>', 'status' => 'published', 'published_at' => now()->subDay(),
    ]);

    // Seeded by the migrations on a fresh shop; made sure of, not assumed.
    Page::updateOrCreate(['slug' => 'privacy-policy'], [
        'title' => 'Privacy Policy', 'content' => '<p>We keep what we need.</p>', 'status' => 'published',
    ]);

    return [
        'product' => \App\Support\UrlScheme::product($product->slug),
        'category' => $category->url(),
        'shop' => \App\Support\UrlScheme::shop(),
        'brand index' => \App\Support\UrlScheme::brandIndex(),
        'brand page' => \App\Support\UrlScheme::brand($brand->slug),
        'article' => \App\Support\UrlScheme::article($post->slug),
        'wishlist' => '/my-wishlist',
        'content page' => '/privacy-policy',
    ];
}

function bcPath(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH) ?: '/';

    return '/'.ltrim($path, '/');
}

beforeEach(function () {
    SettingsService::forgetMemo();
});

/* ══════════════════ 1. what ships ══════════════════ */

it('ships OFF on phones and on desktop, because he said "by default keep it off"', function () {
    /*
     * CLAUDE.md's 30-September reversal: what he asked for is the shop's new
     * state, not a switch to go and find. The four sliders ship at the product
     * page's own numbers (kbb-product.css `.crumb{padding:18px 0 0}`), so a
     * device switched back on returns the page he named to how it looked —
     * measured in Chromium, 84.8px header-to-title at 1280 before and after.
     *
     * MUTATION, RUN: `bc_desktop` default -> true in HeaderSettings::SCHEMA and
     * this is red on "the breadcrumb is still on for desktop", and the
     * (min-width:901px) block prints padding instead of display:none.
     */
    Setting::query()->where('key', 'header_settings')->delete();
    app(SettingsService::class)->flush();

    $c = app(HeaderSettings::class)->all();

    expect($c['bc_mobile'])->toBeFalse('the breadcrumb is still on for phones')
        ->and($c['bc_desktop'])->toBeFalse('the breadcrumb is still on for desktop')
        ->and($c['bc_above_mobile'])->toBe(18)
        ->and($c['bc_below_mobile'])->toBe(0)
        ->and($c['bc_above'])->toBe(18)
        ->and($c['bc_below'])->toBe(0);

    $sel = ':root body :is(.crumb,.brw-crumb,.rtn-crumb)';

    expect(app(HeaderSettings::class)->breadcrumbCss())->toBe(
        '@media (max-width:900px){'.$sel.'{display:none}}'
        .'@media (min-width:901px){'.$sel.'{display:none}}'
    );
});

it('hides the trail on every storefront page that draws one, the journal article included', function () {
    /*
     * The trail is printed by eight views under three class names, and one of
     * them — store/post.blade.php — carries its own <html> and does not extend
     * the layout, so a stylesheet added to the layout alone never reaches it.
     * Each page must carry the trail's markup (so the CSS has something to
     * hide, and the walk below is not passing on an empty page) AND exactly
     * one copy of the rule.
     *
     * MUTATION, RUN: delete the include from store/post.blade.php and this is
     * red on "article: the trail is drawn but nothing hides it". Delete it
     * from layouts/store.blade.php instead and seven pages are red.
     */
    foreach (bcPages() as $name => $url) {
        $html = test()->get(bcPath($url))->assertOk()->getContent();

        expect((bool) preg_match('#class="(crumb|brw-crumb|rtn-crumb)"#', $html))
            ->toBeTrue("{$name}: no breadcrumb trail on the page at all");

        expect(substr_count($html, '<style id="kbb-crumbs">'))
            ->toBe(1, "{$name}: the trail is drawn but nothing hides it (or it is hidden twice)");

        expect(bcStyle($html))->toContain('{display:none}');
    }
});

it('keeps the BreadcrumbList structured data while the visible trail is hidden', function () {
    /*
     * The trail is hidden with CSS; it is not left out of the document. The
     * BreadcrumbList JSON-LD is printed by App\Support\Seo from each
     * controller, so the structured data Google reads is unaffected by the
     * switch — which is the whole reason this is display:none and not an @if.
     *
     * MUTATION, RUN: delete the `$nodes[] = [... 'BreadcrumbList' ...]` line in
     * App\Support\Seo — what a "the trail is off, so drop its schema too"
     * shortcut would amount to — and this is red at the product page.
     */
    $pages = bcPages();

    foreach (['product', 'category', 'shop', 'brand page'] as $name) {
        $html = test()->get(bcPath($pages[$name]))->assertOk()->getContent();

        expect(str_contains((string) bcStyle($html), '{display:none}'))->toBeTrue("{$name}: the trail is not hidden");
        expect(str_contains($html, '"@type":"BreadcrumbList"'))->toBeTrue("{$name}: hiding the trail took the structured data with it");
    }
});

/* ══════════════════ 2. the two devices are two switches ══════════════════ */

it('switches phones and desktop independently, with each width its own spacing', function () {
    /*
     * Phone off, desktop on at 30px above and 12px below — and the phone's own
     * sliders moved too (6/6), which must change nothing while phones are off.
     * Then the mirror image. Measured in Chromium on the preview: at 1280 the
     * trail is 60.8px tall with padding 30/12; at 390 it is display:none.
     *
     * MUTATION, RUN: make breadcrumbCss() read `bc_desktop` for both media
     * queries and this is red at the first expectation — the phone block
     * prints padding.
     */
    bcSet(['bc_mobile' => false, 'bc_desktop' => true,
        'bc_above' => 30, 'bc_below' => 12, 'bc_above_mobile' => 6, 'bc_below_mobile' => 6]);

    $css = app(HeaderSettings::class)->breadcrumbCss();
    $sel = ':root body :is(.crumb,.brw-crumb,.rtn-crumb)';

    expect($css)->toBe(
        '@media (max-width:900px){'.$sel.'{display:none}}'
        .'@media (min-width:901px){'.$sel.'{margin-top:0;margin-bottom:0;padding-top:30px;padding-bottom:12px}}'
    );

    bcSet(['bc_mobile' => true, 'bc_desktop' => false]);

    expect(app(HeaderSettings::class)->breadcrumbCss())->toBe(
        '@media (max-width:900px){'.$sel.'{margin-top:0;margin-bottom:0;padding-top:6px;padding-bottom:6px}}'
        .'@media (min-width:901px){'.$sel.'{display:none}}'
    );

    // And the shop prints what the method says.
    $html = test()->get('/shop/')->assertOk()->getContent();

    expect(bcStyle($html))->toBe(app(HeaderSettings::class)->breadcrumbCss());
});

it('outranks every page sheet that spaces the trail, without !important', function () {
    /*
     * The page sheets declare the trail's spacing at (0,1,0) — kbb-product.css
     * `.crumb{padding:18px 0 0}`, kbb-shop.css `.crumb{padding:14px 0 6px}` —
     * and kbb.css at (0,2,0), `.kbb-home .crumb{margin-bottom:16px}`. The rule
     * here is `:root body :is(…)`, (0,2,1), so the owner's numbers win wherever
     * the <style> sits. Measured: with both on at 18/0 the category page's
     * trail computed 18px/0px at 390 and 1280, not its own 14/6 or 18/4.
     *
     * MUTATION, RUN: drop `:root body ` from the selector and this is red.
     */
    $css = app(HeaderSettings::class)->breadcrumbCss();

    expect($css)->toContain(':root body :is(.crumb,.brw-crumb,.rtn-crumb)')
        ->and($css)->not->toContain('!important');

    foreach (['kbb-product.css', 'kbb-shop.css', 'kbb.css'] as $sheet) {
        $src = (string) file_get_contents(resource_path('css/kbb/'.$sheet));

        expect($src)->not->toMatch('/:root\s+body\s+:is\(\.crumb/', "{$sheet} competes with the breadcrumb rule");
    }
});

it('covers every breadcrumb class a storefront view draws', function () {
    /*
     * The trail is drawn under three names. A fourth page that invents a
     * fourth would be a trail the switches cannot reach. store/app.blade.php is
     * the admin-only App Preview (PageController::app() 404s for anyone else)
     * and is not part of the shop.
     *
     * MUTATION, RUN: drop '.brw-crumb' from HeaderSettings::CRUMB_SELECTORS
     * and this is red naming brands.blade.php.
     */
    foreach (glob(resource_path('views/store/*.blade.php')) as $file) {
        if (basename($file) === 'app.blade.php') {
            continue;
        }

        preg_match_all('#class="([a-z-]*crumb)"#', (string) file_get_contents($file), $m);

        foreach (array_unique($m[1]) as $class) {
            expect(in_array('.'.$class, HeaderSettings::CRUMB_SELECTORS, true))
                ->toBeTrue(basename($file)." draws .{$class}, which the breadcrumb switches do not reach");
        }
    }
});

/* ══════════════════ 3. the screen, its validation and its door ══════════════════ */

it('draws the six controls on Appearance → Header → Breadcrumbs', function () {
    /*
     * MUTATION, RUN: remove the 'crumbs' entry from HeaderSettings::TABS and
     * this is red — the keys are in SCHEMA, saved and read, and the owner has
     * no way to reach them.
     */
    test()->actingAs(bcOwner(), 'admin');

    $tabs = collect(test()->getJson('/admin-api/header')->assertOk()->json('tabs'))->keyBy('key');

    expect($tabs->has('crumbs'))->toBeTrue()
        ->and($tabs['crumbs']['label'])->toBe('Breadcrumbs');

    $fields = collect($tabs['crumbs']['fields'])->keyBy('key');

    expect($fields->keys()->all())->toBe(['bc_mobile', 'bc_desktop', 'bc_above_mobile', 'bc_below_mobile', 'bc_above', 'bc_below'])
        ->and($fields['bc_mobile']['type'])->toBe('bool')
        ->and($fields['bc_mobile']['value'])->toBeFalse()
        ->and($fields['bc_desktop']['value'])->toBeFalse()
        ->and($fields['bc_above']['options'])->toMatchArray(['min' => 0, 'max' => 48, 'step' => 1, 'unit' => 'px']);
});

it('clamps every spacing into 0–48 and stores the switches as booleans', function () {
    /*
     * A slider cannot send 99999, but a POST can, and these numbers are
     * printed into a stylesheet on every page. Each one is clamped by
     * ModuleSchema::cast() on the way in and cast to int again on the way out,
     * so a string carrying CSS cannot survive either half.
     *
     * MUTATION, RUN: change POLICY 'clamp' to false in HeaderSettings and
     * this is red at 99999.
     */
    test()->actingAs(bcOwner(), 'admin')
        ->postJson('/admin-api/header', ['settings' => [
            'bc_mobile' => 1,
            'bc_desktop' => true,
            'bc_above' => 99999,
            'bc_below' => -10,
            'bc_above_mobile' => '10px}body{display:none',
            'bc_below_mobile' => 'abc',
        ]])
        ->assertOk();

    SettingsService::forgetMemo();
    $stored = app(SettingsService::class)->get('header_settings');

    expect($stored['bc_mobile'])->toBeTrue()->toBeBool()
        ->and($stored['bc_desktop'])->toBeTrue()->toBeBool()
        ->and($stored['bc_above'])->toBe(48)
        ->and($stored['bc_below'])->toBe(0)
        ->and($stored['bc_above_mobile'])->toBe(10)
        ->and($stored['bc_below_mobile'])->toBe(0);

    $css = app(HeaderSettings::class)->breadcrumbCss();

    expect($css)->not->toContain('body{display:none')
        ->and($css)->toContain('padding-top:48px')
        ->and($css)->toContain('padding-top:10px;padding-bottom:0px');
});

it('refuses a key the schema does not know, and saves nothing', function () {
    test()->actingAs(bcOwner(), 'admin')
        ->postJson('/admin-api/header', ['settings' => ['bc_desktop' => true, 'bc_selector' => 'body']])
        ->assertStatus(422);

    SettingsService::forgetMemo();

    expect(app(HeaderSettings::class)->get('bc_desktop'))->toBeFalse();
});

it('keeps a support account and a guest out, and fails closed', function () {
    /*
     * No new endpoint: the controls ride the existing /admin-api/header, which
     * is `content.manage` (owner, manager, editor). Support holds no such
     * capability and is refused; a guest never reaches the controller.
     *
     * MUTATION, RUN: add 'support' to content.manage in AdminCapabilities and
     * this is red at assertForbidden().
     */
    expect(collect(AdminCapabilities::RULES)->contains(['*', 'admin-api/header', 'content.manage']))->toBeTrue();

    test()->postJson('/admin-api/header', ['settings' => ['bc_desktop' => true]])
        ->assertStatus(401);

    test()->actingAs(bcOwner('support'), 'admin')
        ->postJson('/admin-api/header', ['settings' => ['bc_desktop' => true]])
        ->assertForbidden();

    SettingsService::forgetMemo();

    expect(app(HeaderSettings::class)->get('bc_desktop'))->toBeFalse('a refused request still moved the setting');
});

/* ══════════════════ 4. how it is printed ══════════════════ */

it('prints the rule escaped, byte-identical to the method, and runs no script', function () {
    /*
     * The partial uses {{ }}: nothing in the rule is one of the characters
     * escaping rewrites, so escaping costs nothing and "a setting printed raw"
     * is impossible here. If somebody adds a `>` combinator to the selector,
     * this goes red rather than the shop silently printing `&gt;`.
     *
     * MUTATION, RUN: write the selector as `:root>body :is(…)` and this is red
     * — the page prints `:root&gt;body`.
     */
    bcSet(['bc_desktop' => true, 'bc_above' => 22, 'bc_below' => 8]);

    $html = test()->get('/shop/')->assertOk()->getContent();

    expect(bcStyle($html))->toBe(app(HeaderSettings::class)->breadcrumbCss())
        ->and(bcStyle($html))->not->toContain('&');

    // Its own header comment names the raw-echo directive to say why it is
    // not used; the code is what is checked.
    $partial = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(resource_path('views/partials/breadcrumb-css.blade.php')));

    expect($partial)->not->toContain('{!!')
        ->and($partial)->not->toContain('<script')
        ->and($partial)->not->toMatch('/getBoundingClientRect|offsetHeight|offsetWidth|ResizeObserver/');
});

it('costs no query of its own', function () {
    /*
     * The six keys live in the one `header_settings` row the header already
     * read on this request, so the stylesheet is free. StorefrontQueryBudgetTest
     * is a budget.
     *
     * MUTATION, RUN: give breadcrumbCss() a settings read of its own
     * (`Setting::query()->where('key', 'breadcrumbs')->value('value')`, the
     * shape a separate "breadcrumbs" module would have) and this is red at 1.
     */
    app(HeaderSettings::class)->all();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    app(HeaderSettings::class)->breadcrumbCss();

    expect($queries)->toBe(0);
});
