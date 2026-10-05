<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\PageHeaderApiController;
use App\Models\AdminUser;
use App\Models\Page;
use App\Models\Product;
use App\Services\PageBanners;
use App\Services\PageHeaders;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\StorefrontAdminHint;
use Illuminate\Support\Facades\DB;
use Tests\Support\PageHeaderRoutes;
use Tests\Support\StorefrontAdminRoutes;

/**
 * Pages → Page header, the storefront "Edit header" panel, and the third line
 * on the Super Sale strip. (Lane PH)
 *
 * The owner, of /super-sale/ (his screenshot: a dot, "Super Sale", a
 * "117 products" pill and an ALL PRODUCTS button): "i want full control of
 * super sale page header, to hide title, and i needed an image too, also
 * control of image height etc. and hide the all products button, count etc.
 * make this global option for all pages. desktop and mobile seeperate. and i
 * must be able to edit the inner page header from the front-end" — "also to
 * change the position of the elements" — "also include in the strip for
 * desktop only 'Free skincare consultation', in mobile two lines are fine."
 */
function phAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'PH '.$role, 'email' => 'ph-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => $role,
    ]);
}

/** The configured header block a page printed, or ''. */
function phBlock(string $html): string
{
    return preg_match('#<style id="kbb-ph-css">.*?\n</div>\n#s', $html, $m) ? $m[0] : '';
}

function phStore(array $value): void
{
    app(SettingsService::class)->set(PageHeaders::KEY, $value);
    SettingsService::forgetMemo();
}

function phProducts(int $n, string $tag = 'x'): void
{
    for ($i = 1; $i <= $n; $i++) {
        Product::create(['slug' => "ph-{$tag}-{$i}", 'name' => "PH {$i}", 'type' => 'simple', 'status' => 'publish',
            'is_visible' => true, 'price' => 2000, 'sale_price' => 1000, 'stock_status' => 'instock']);
    }
}

function phAbout(): void
{
    Page::firstOrCreate(['slug' => 'about'], ['title' => 'About us', 'content' => '<p>About.</p>', 'status' => 'published']);
}

beforeEach(function () {
    PageHeaderRoutes::wire($this->app);
    SettingsService::forgetMemo();
});

/* ═════════════════════════════════════ what ships, because he asked ═══ */

it('ships /super-sale/ with no dot, no count and no All products button, and the title still shown', function () {
    /*
     * DEFECT THIS CATCHES: shipping the control with the page unchanged (he
     * applies the package and still sees the dot, "117 products" and the
     * button), or reading "to hide title" as "hide the title".
     * MUTATION: drop the 'collection:super-sale' bag from defaults() -> no
     * configured block at all, red on the first line.
     */
    phProducts(2);
    $block = phBlock($this->get('/super-sale')->assertOk()->getContent());

    expect($block)->not->toBe('')
        ->and($block)->toContain('<div class="sh kbb-ph kbb-ph-nod kbb-ph-nom" ')
        ->and($block)->toContain('<h1 class="kbb-ph-t">Super Sale <span class="cnt kbb-ph-hd kbb-ph-hm">')
        ->and($block)->toContain('<a class="lnk kbb-ph-b kbb-ph-hd kbb-ph-hm" href=')
        // Appearance → Header → Breadcrumbs ships off on both devices, so the
        // trail takes no row and carries the hidden classes.
        ->and($block)->toContain('<nav class="crumb kbb-ph-c kbb-ph-hd kbb-ph-hm">')
        ->and($block)->toContain('--ph-am:&quot;t t&quot;;')
        ->and($block)->toContain('data-kbb-ph="collection:super-sale"');
});

it('leaves every other custom page on its original markup, byte for byte', function () {
    /*
     * DEFECT THIS CATCHES: the configured header leaking onto pages nobody
     * changed. StorefrontEnglishUnchangedTest compares all of them; this names
     * the cause. MUTATION: make forPage() never return null -> red.
     */
    phProducts(2);
    phAbout();

    foreach (['/new-in', '/best-sellers', '/everything-under-54-aed', '/about'] as $path) {
        $html = $this->get($path)->assertOk()->getContent();
        expect(str_contains($html, 'kbb-ph'))->toBeFalse($path);
    }

    expect(app(PageHeaders::class)->forPage('collection:new-in', 'collection'))->toBeNull()
        ->and(app(PageHeaders::class)->forPage('page:about', 'page'))->toBeNull();
});

it('keeps the H1 in the page when the title is hidden, clipped rather than display:none', function () {
    /*
     * The owner asked to be able to hide the title; search engines and screen
     * readers still need the page's heading. MUTATION: give .kbb-ph-vd
     * display:none in the CSS constant, or stop printing the <h1> -> red.
     */
    phProducts(1);
    $bag = PageHeaders::blank();
    $bag['d']['title'] = false;
    phStore(['global' => PageHeaders::blank(), 'pages' => ['collection:super-sale' => $bag]]);

    $block = phBlock($this->get('/super-sale')->getContent());

    expect($block)->toContain('<h1 class="kbb-ph-t kbb-ph-vd">Super Sale ')
        ->and(PageHeaders::CSS)->toContain('.kbb-ph .kbb-ph-vd{position:absolute!important;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0 0 0 0)')
        ->and(PageHeaders::CSS)->not->toMatch('/kbb-ph-v[dm]?\{[^}]*display:none/');

    $both = PageHeaders::blank();
    $both['d']['title'] = $both['m']['title'] = false;
    expect(PageHeaders::compile($both, 'collection', [])['cls']['title'])->toBe('kbb-ph-t kbb-ph-v');
});

it('builds the positions as grid rows, per device, from constant letters', function () {
    /*
     * "also to change the position of the elements". The order and the
     * button's place are one grid-template-areas string per device.
     * MUTATION: ignore button_at in compile() -> the side row is red.
     */
    $bag = PageHeaders::blank();
    $bag['d']['order'] = ['image', 'title', 'intro', 'crumb', 'button'];
    $bag['m']['button_at'] = 'row';
    $bag['m']['align'] = 'center';

    $c = PageHeaders::compile($bag, 'collection', ['intro' => true, 'image' => true]);

    expect($c['style'])->toContain('--ph-ad:"i i" "t b" "p p" "c c";')
        ->and($c['style'])->toContain('--ph-am:"c c" "i i" "t t" "b b";')
        ->and($c['wrap'])->toBe('sh kbb-ph kbb-ph-sd kbb-ph-cm')
        ->and($c['style'])->toContain('--ph-hd:260px;--ph-fd:cover;--ph-rd:14px;--ph-gd:12px;--ph-sd:22px')
        ->and($c['style'])->toContain('--ph-hm:160px;--ph-fm:cover;--ph-rm:10px;--ph-gm:8px;--ph-sm:12px');

    // A hidden title beside a shown button keeps the button's place on the row.
    $bag['d']['title'] = false;
    expect(PageHeaders::compile($bag, 'collection', ['intro' => true])['style'])->toContain('--ph-ad:". b" "p p" "c c";');
});

it('gives a part the page cannot show no grid row, so the header is not taller than its contents', function () {
    /*
     * DEFECT THIS CATCHES, MEASURED: the first build drew /super-sale/'s header
     * 84px tall where the page drew 53. The campaign's intro is empty and
     * Appearance → Header → Breadcrumbs hides the trail, but both kept their
     * rows, and a grid item whose area is not in the template lands on an
     * implicit row -- three row-gaps of nothing. MUTATION: build the hidden
     * classes from the switches instead of from what is present -> the empty
     * intro is laid out, red.
     */
    $c = PageHeaders::compile(PageHeaders::blank(), 'collection', ['intro' => false, 'crumb' => ['d' => false, 'm' => false]]);

    expect($c['style'])->toContain('--ph-ad:"t b";')
        ->and($c['cls']['intro'])->toBe('kbb-ph-p kbb-ph-hd kbb-ph-hm')
        ->and($c['cls']['crumb'])->toBe('crumb kbb-ph-c kbb-ph-hd kbb-ph-hm');
});

it('draws the header picture at the height set per device, desktop and phone pictures apart', function () {
    /*
     * "i needed an image too, also control of image height etc."
     * MUTATION: drop the <source> in the partial -> the phone picture is never
     * drawn, red.
     */
    phProducts(1);
    $bag = PageHeaders::blank();
    $bag['img'] = '/wp-content/uploads/2026/10/sale-wide.webp';
    $bag['img_m'] = '/wp-content/uploads/2026/10/sale-tall.webp';
    $bag['alt'] = 'Super Sale <b>now</b>';
    $bag['d']['img_h'] = 320;
    $bag['m']['img_h'] = 140;
    phStore(['global' => PageHeaders::blank(), 'pages' => ['collection:super-sale' => $bag]]);

    $block = phBlock($this->get('/super-sale')->getContent());

    expect($block)->toContain('<picture class="kbb-ph-i"><source media="(max-width: 900px)" srcset="/wp-content/uploads/2026/10/sale-tall.webp"><img src="/wp-content/uploads/2026/10/sale-wide.webp" alt="Super Sale now" decoding="async"></picture>')
        ->and($block)->toContain('--ph-ad:&quot;i i&quot; &quot;t b&quot;')
        ->and($block)->toContain('--ph-hd:320px')
        ->and($block)->toContain('--ph-hm:140px');
});

it('applies the global look to every custom page, a content page included, unless a page has its own', function () {
    /*
     * "make this global option for all pages". MUTATION: read the global bag
     * only when pages[] is empty -> /new-in/ stays original, red.
     */
    phProducts(1);
    phAbout();
    $global = PageHeaders::blank();
    $global['d']['crumb'] = false;
    $global['m']['crumb'] = false;
    phStore(['global' => $global, 'pages' => []]);

    $newIn = phBlock($this->get('/new-in')->getContent());
    $about = $this->get('/about')->getContent();

    expect($newIn)->toContain('<nav class="crumb kbb-ph-c kbb-ph-hd kbb-ph-hm">')
        ->and($about)->toContain('<div class="kbb-ph kbb-ph-pg" ')
        ->and($about)->toMatch('#<h1 class="kbb-ph-t">About [Uu]s</h1>#')
        ->and(substr_count($about, '<h1'))->toBe(1)
        // The super-sale page no longer has its own bag: the dot is back.
        ->and(phBlock($this->get('/super-sale')->getContent()))->toContain('<div class="sh kbb-ph kbb-ph-sd kbb-ph-sm" ');
});

it('does not move a content page for a part a content page does not have', function () {
    /*
     * A content page has no count, intro, button or dot. Hiding the count on
     * every page must not turn every content page into a configured one.
     * MUTATION: compare whole bags in forPage() instead of project() -> red.
     */
    $global = PageHeaders::blank();
    foreach (['d', 'm'] as $dev) {
        $global[$dev]['count'] = false;
        $global[$dev]['dot'] = false;
        $global[$dev]['img_h'] = 400;   // no picture: draws nothing
    }
    phStore(['global' => $global, 'pages' => []]);

    expect(app(PageHeaders::class)->forPage('page:about', 'page'))->toBeNull()
        ->and(app(PageHeaders::class)->forPage('collection:new-in', 'collection'))->not->toBeNull();
});

/* ═══════════════════════════════════════════════════════ rule 5 ═══ */

it('stores only its own options and refuses the rest, writing nothing', function () {
    /*
     * MUTATION: accept any string for a select in bag() -> the 'align' case
     * saves, red.
     */
    $this->actingAs(phAdmin(), 'admin');
    $ok = PageHeaders::blank();

    $cases = [
        'align' => fn (array $b) => array_replace_recursive($b, ['d' => ['align' => 'right;color:red']]),
        'button_at' => fn (array $b) => array_replace_recursive($b, ['m' => ['button_at' => 'floating']]),
        'fit' => fn (array $b) => array_replace_recursive($b, ['d' => ['fit' => 'fill']]),
        'number' => fn (array $b) => array_replace_recursive($b, ['d' => ['img_h' => 5000]]),
        'switch' => fn (array $b) => array_replace_recursive($b, ['d' => ['title' => 'maybe']]),
        'order' => function (array $b) { $b['d']['order'] = ['title', 'title', 'crumb', 'intro', 'button']; return $b; },
        'picture js' => fn (array $b) => ['img' => 'javascript:alert(1)'] + $b,
        'picture data' => fn (array $b) => ['img' => 'data:image/svg+xml,<svg onload=alert(1)>'] + $b,
        'picture quote' => fn (array $b) => ['img' => '/x.png" onerror="alert(1)'] + $b,
    ];

    foreach ($cases as $name => $make) {
        $this->postJson('/admin-api/page-header', ['global' => $make($ok), 'pages' => []])->assertStatus(422, $name);
        $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:super-sale', 'scope' => 'page', 'bag' => $make($ok)])->assertStatus(422, $name);
    }

    $this->postJson('/admin-api/page-header', ['global' => $ok, 'pages' => ['product:anything' => $ok]])->assertStatus(422);
    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:nope', 'scope' => 'page', 'bag' => $ok])->assertStatus(422);
    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:super-sale', 'scope' => 'everywhere', 'bag' => $ok])->assertStatus(422);

    expect(DB::table('settings')->where('key', PageHeaders::KEY)->count())->toBe(0);
});

it('never prints a picture the screen would have refused, even from a row it did not write', function () {
    /*
     * The read path cleans again. MUTATION: skip SafeUrl in picture() -> the
     * javascript: address reaches an <img src>, red.
     */
    phProducts(1);
    $bag = PageHeaders::blank();
    $bag['img'] = 'javascript:alert(1)';
    $bag['d']['crumb'] = false;
    DB::table('settings')->insert(['key' => PageHeaders::KEY, 'value' => json_encode(['global' => PageHeaders::blank(), 'pages' => ['collection:super-sale' => $bag]]), 'autoload' => true]);
    app(SettingsService::class)->flush();

    $html = $this->get('/super-sale')->getContent();

    expect(phBlock($html))->not->toBe('')
        ->and($html)->not->toContain('javascript:alert')
        ->and(phBlock($html))->not->toContain('<picture');
});

it('maps every verb to its own capability, held by owner, manager and editor, and fails closed for the rest', function () {
    /*
     * MUTATION: delete the two RULES lines -> the paths fall to the owner-only
     * default, and forPath() is null, red.
     */
    foreach ([['GET', 'admin-api/page-header'], ['POST', 'admin-api/page-header'], ['POST', 'admin-api/page-header/apply']] as [$verb, $path]) {
        expect(AdminCapabilities::forPath($verb, $path))->toBe('pageheader.manage');
    }
    expect(AdminCapabilities::CAPABILITIES['pageheader.manage'])->toBe(['owner', 'manager', 'editor']);

    $this->actingAs(phAdmin('support'), 'admin');
    $this->getJson('/admin-api/page-header')->assertStatus(403);
    $this->postJson('/admin-api/page-header', ['global' => PageHeaders::blank(), 'pages' => []])->assertStatus(403);
    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:super-sale', 'scope' => 'page', 'bag' => PageHeaders::blank()])->assertStatus(403);

    expect(DB::table('settings')->where('key', PageHeaders::KEY)->count())->toBe(0);
});

it('sits behind the admin guard and the web group, whose CSRF check every write here passes through', function () {
    $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/page-header'));

    expect($routes)->toHaveCount(3);
    foreach ($routes as $route) {
        $mw = $route->gatherMiddleware();
        expect(in_array('auth:admin', $mw, true))->toBeTrue($route->uri())
            ->and(in_array('web', $mw, true))->toBeTrue($route->uri());
    }

    \Illuminate\Support\Facades\Auth::guard('admin')->logout();
    $this->getJson('/admin-api/page-header')->assertStatus(401);
});

/* ═════════════════════════════════════════════ the front-end panel ═══ */

it('saves one page, every page, or hands a page back to the global look', function () {
    /*
     * MUTATION: in apply('global'), keep the page's own bag -> the page the
     * owner was looking at would not show what he applied, red.
     */
    $this->actingAs(phAdmin('editor'), 'admin');
    $bag = PageHeaders::blank();
    $bag['d']['intro'] = false;

    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:new-in', 'scope' => 'page', 'bag' => $bag])
        ->assertOk()->assertJsonPath('own', true)->assertJsonPath('bag.d.intro', false);

    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:super-sale', 'scope' => 'global', 'bag' => $bag])
        ->assertOk()->assertJsonPath('own', false)->assertJsonPath('global.d.intro', false);

    $headers = app(PageHeaders::class);
    $headers->forget();
    expect($headers->all()['pages'])->toHaveKey('collection:new-in')->not->toHaveKey('collection:super-sale');

    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:new-in', 'scope' => 'inherit'])
        ->assertOk()->assertJsonPath('own', false);
    $headers->forget();
    expect($headers->all()['pages'])->toBe([]);
});

it('gives the storefront context an Edit header panel on a custom page only, for a role that holds it', function () {
    /*
     * MUTATION: drop the CollectionController arm from resolvePage() ->
     * pageheader is null on /super-sale/, red.
     */
    StorefrontAdminRoutes::wire($this->app);

    $this->actingAs(phAdmin('editor'), 'admin');
    $ctx = $this->getJson('/admin-api/storefront/context?path='.rawurlencode('/super-sale/'))->assertOk()->json();

    expect($ctx['bar'])->toBeNull()      // the bar stays owner-only
        ->and($ctx['pageheader']['key'])->toBe('collection:super-sale')
        ->and($ctx['pageheader']['kind'])->toBe('collection')
        ->and($ctx['pageheader']['own'])->toBeTrue()
        ->and($ctx['pageheader']['bag']['d']['button'])->toBeFalse()
        ->and($ctx['pageheader']['spec']['css'])->toBe(PageHeaders::CSS)
        ->and($ctx['pageheader']['endpoints']['apply'])->toEndWith('/admin-api/page-header/apply');

    expect($this->getJson('/admin-api/storefront/context?path=/shop/')->json('pageheader'))->toBeNull()
        ->and(StorefrontAdminHint::wantedBy(phAdmin('editor')))->toBeTrue()
        ->and(StorefrontAdminHint::wantedBy(phAdmin('support')))->toBeFalse();

    $this->actingAs(phAdmin('owner'), 'admin');
    expect($this->getJson('/admin-api/storefront/context?path=/super-sale/')->json('bar.context.label'))->toBe('Page header');

    $this->actingAs(phAdmin('support'), 'admin');
    $this->getJson('/admin-api/storefront/context?path=/super-sale/')->assertStatus(403);
});

it('sends a shopper not one byte of the editor', function () {
    /*
     * The panel is a chunk the admin layer imports on a click. A shopper's
     * page names neither it nor its styles. MUTATION: import the editor at the
     * top of storefront-admin.js or app.js -> red.
     */
    phProducts(1);
    $html = $this->get('/super-sale')->getContent();
    $layer = (string) file_get_contents(resource_path('js/kbb/admin/storefront-admin.js'));
    $app = (string) file_get_contents(resource_path('js/kbb/app.js'));

    expect($html)->not->toContain('page-header-editor')
        ->and($html)->not->toContain('kbb-phe')
        ->and(phBlock($html))->not->toBe('')
        ->and(str_contains(phBlock($html), '<script'))->toBeFalse('the configured header adds no script')
        ->and($layer)->toContain("import('./page-header-editor.js')")
        ->and($layer)->not->toMatch('/^import .*page-header-editor/m')
        ->and($app)->not->toContain('page-header-editor');

    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    expect($manifest['resources/js/kbb/admin/storefront-admin.js']['dynamicImports'] ?? [])->toContain('resources/js/kbb/admin/page-header-editor.js')
        ->and($manifest['resources/js/kbb/app.js']['imports'] ?? [])->not->toContain('resources/js/kbb/admin/page-header-editor.js');
});

it('measures nothing in the browser', function () {
    $files = [
        resource_path('js/kbb/admin/page-header-editor.js'),
        resource_path('js/kbb/admin/page-header-compile.js'),
        resource_path('views/admin/partials/page-header-screen.blade.php'),
        resource_path('views/partials/page-header.blade.php'),
    ];

    foreach ($files as $file) {
        $src = (string) file_get_contents($file);
        foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'clientHeight', 'scrollWidth', 'ResizeObserver', 'setInterval', 'innerHTML ='] as $api) {
            expect(str_contains($src, $api))->toBeFalse(basename($file).' uses '.$api);
        }
    }

    expect(PageHeaders::CSS)->not->toContain('<');
});

it('compiles exactly as the PHP does in the browser panel', function () {
    /*
     * The panel redraws the header with page-header-compile.js; the shop
     * prints PageHeaders::compile(). If they disagree the owner saves one
     * thing and sees another. MUTATION: change a class name or the row order
     * in either file -> red.
     */
    $node = trim((string) shell_exec('command -v node 2>/dev/null')) ?: (is_file('/opt/node22/bin/node') ? '/opt/node22/bin/node' : '');
    if ($node === '') {
        $this->markTestSkipped('node is not installed');
    }

    $a = PageHeaders::blank();
    $b = PageHeaders::defaults()['pages']['collection:super-sale'];
    $c = PageHeaders::blank();
    $c['d'] = array_replace($c['d'], ['order' => ['button', 'image', 'intro', 'title', 'crumb'], 'title' => false, 'align' => 'center', 'img_h' => 333, 'fit' => 'contain']);
    $c['m'] = array_replace($c['m'], ['button_at' => 'row', 'crumb' => false, 'intro' => true, 'dot' => false, 'radius' => 0]);
    $d = PageHeaders::blank();
    $d['m']['title'] = $d['d']['title'] = false;
    $d['d']['button'] = false;

    $cases = [];
    foreach (['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d] as $n => $bag) {
        foreach (['collection', 'page'] as $kind) {
            foreach ([['intro' => true, 'image' => true], ['intro' => false, 'image' => false], ['intro' => true, 'crumb' => ['d' => false, 'm' => true]]] as $i => $has) {
                $cases["$n.$kind.$i"] = ['bag' => $bag, 'kind' => $kind, 'has' => $has];
            }
        }
    }

    $dir = storage_path('framework/testing');
    @mkdir($dir, 0777, true);
    $in = $dir.'/ph-cases-'.getmypid().'.json';
    file_put_contents($in, json_encode($cases));
    $module = 'file://'.resource_path('js/kbb/admin/page-header-compile.js');
    $script = "import {compile} from '{$module}'; import {readFileSync} from 'node:fs';"
        ."const c=JSON.parse(readFileSync(process.argv[1],'utf8')); const o={};"
        .'for (const [k,v] of Object.entries(c)) o[k]=compile(v.bag,v.kind,v.has); process.stdout.write(JSON.stringify(o));';
    $out = shell_exec(escapeshellarg($node).' --input-type=module -e '.escapeshellarg($script).' '.escapeshellarg($in));
    @unlink($in);

    $js = json_decode((string) $out, true);
    expect($js)->toBeArray();

    foreach ($cases as $k => $case) {
        expect($js[$k])->toBe(PageHeaders::compile($case['bag'], $case['kind'], $case['has']), $k);
    }
});

/* ═══════════════════════════════════════════════════════ rule 4 ═══ */

it('costs the page no query at all, with 3 products or 40', function () {
    /*
     * The header is read from the settings map the page has already loaded,
     * and the count it prints is the paginator's. Measured against the same
     * page with the original markup, at two catalogue sizes.
     * MUTATION: look the bag up with a query of its own in forPage() -> on is
     * dearer than off, red.
     */
    $measure = function (int $n, bool $on): int {
        Product::query()->delete();
        phProducts($n, "q{$n}".($on ? 'on' : 'off'));
        phStore($on ? PageHeaders::defaults() : ['global' => PageHeaders::blank(), 'pages' => []]);
        $this->get('/super-sale');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->get('/super-sale')->getContent();
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect(str_contains($html, 'kbb-ph-css'))->toBe($on);

        return $q;
    };

    $off3 = $measure(3, false);
    $on3 = $measure(3, true);
    $on40 = $measure(40, true);

    expect($on3)->toBe($off3)->and($on40)->toBe($on3);
});

/* ═════════════════════════════════════════════════════ the strip ═══ */

it('adds "Free skincare consultation" to the strip for desktop only, and keeps a phone at two lines', function () {
    /*
     * MUTATION: drop the .kbb-pb-d rule from PageBanners::CSS -> the phone
     * draws three lines, red on the CSS line.
     */
    $view = PageBanners::view(PageBanners::defaults()['banners'][0]);

    expect($view['items'])->toBe(['100% Authentic Products', 'Express Delivery all over UAE', 'Free skincare consultation'])
        ->and($view['devs'])->toBe(['both', 'both', 'd'])
        ->and(PageBanners::CSS)->toContain('@media (max-width:900px){.kbb-pb-strip .kbb-pb-d{display:none}}')
        ->and(PageBanners::CSS)->toContain('@media (min-width:901px){.kbb-pb-strip .kbb-pb-m{display:none}}');

    [, $rejected] = PageBanners::sanitize(['banners' => [array_replace(PageBanners::blank('x', 'X'), ['items' => [['en' => 'A', 'ar' => '', 'dev' => 'tablet']]])], 'assign' => []]);
    expect($rejected)->toHaveKey('banners.0.items');

    [$read] = PageBanners::sanitize(['banners' => [array_replace(PageBanners::blank('x', 'X'), ['items' => [['en' => 'Old', 'ar' => '']]])], 'assign' => []], false);
    expect($read['banners'][0]['items'][0]['dev'])->toBe('both');
});

it('gives a saved Super Sale strip the third line once, and touches nothing else', function () {
    /*
     * A shop that saved its banners has its own copy of the strip, which the
     * new default cannot reach. MUTATION: skip the "already there" check in
     * the migration -> the second run adds a second line, red.
     */
    $migration = require database_path('migrations/2027_08_14_100000_clear_caches_page_header.php');
    $two = [['en' => '100% Authentic Products', 'ar' => ''], ['en' => 'Express Delivery all over UAE', 'ar' => '']];
    app(SettingsService::class)->set(PageBanners::KEY, [
        'banners' => [
            array_replace(PageBanners::blank('super-sale', 'Super Sale'), ['items' => $two]),
            array_replace(PageBanners::blank('other', 'Other'), ['items' => $two]),
            array_replace(PageBanners::blank('has-it', 'Has it'), ['items' => [['en' => 'free SKINCARE consultation ', 'ar' => '']]]),
        ],
        'assign' => ['collection:super-sale' => 'super-sale', 'page:about' => 'other'],
    ]);

    ob_start();
    $migration->up();
    $migration->up();
    ob_end_clean();

    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    $saved = app(SettingsService::class)->get(PageBanners::KEY);

    expect($saved['banners'][0]['items'])->toHaveCount(3)
        ->and($saved['banners'][0]['items'][2])->toBe(['en' => 'Free skincare consultation', 'ar' => '', 'dev' => 'd'])
        ->and($saved['banners'][1]['items'])->toHaveCount(2)
        ->and($saved['banners'][2]['items'])->toHaveCount(1);

    // Assigned to a banner that already has the line, in any case: no change.
    app(SettingsService::class)->set(PageBanners::KEY, ['banners' => [$saved['banners'][2]], 'assign' => ['collection:super-sale' => 'has-it']]);
    ob_start();
    $migration->up();
    ob_end_clean();
    app(SettingsService::class)->flush();
    expect(app(SettingsService::class)->get(PageBanners::KEY)['banners'][0]['items'])->toHaveCount(1);
});

it('answers the console with the global look, every page and the controls', function () {
    $this->actingAs(phAdmin('manager'), 'admin');

    $body = $this->getJson('/admin-api/page-header')->assertOk()->json();

    expect($body['global'])->toBe(PageHeaders::blank())
        ->and(array_keys($body['pages']))->toBe(['collection:super-sale'])
        ->and(array_column($body['list'], 'key'))->toContain('collection:super-sale')
        ->and($body['spec']['css'])->toBe(PageHeaders::CSS)
        ->and(array_column($body['spec']['elements'], 'key'))->toBe(array_keys(PageHeaders::ELEMENTS))
        ->and(PageHeaderApiController::spec()['breakpoint'])->toBe(900);
});
