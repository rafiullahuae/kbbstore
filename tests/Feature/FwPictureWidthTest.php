<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\PageHeaderApiController;
use App\Models\AdminUser;
use App\Models\Page;
use App\Models\Product;
use App\Services\PageHeaders;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Tests\Support\PageHeaderRoutes;

/*
 * Page → Edit header → Sizes · desktop / Sizes · phone → Picture width. (Lane FW)
 *
 * THE OWNER, 5 October, with a phone screenshot of /super-sale/ and a red
 * arrow at each edge of the header picture: "the pages header are has left
 * and right 12px spacing, i want it full width option for desktop and mobile
 * in the edit panel. by default keep full width in mobile, and on desktop
 * normal width."
 *
 * Measured in Chromium before this (docs/fw-shots/numbers.txt): the picture
 * began 12px from each edge of a 390px phone, and 37.6px in on a 1280 laptop.
 */
function fwAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'FW owner', 'email' => 'fw-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);
}

function fwStore(array $value): void
{
    app(SettingsService::class)->set(PageHeaders::KEY, $value);
    SettingsService::forgetMemo();
}

/** The setting as shipped, with /super-sale/ given a header picture. */
function fwSaleWithPicture(array $d = [], array $m = []): array
{
    $all = PageHeaders::defaults();
    $all['pages']['collection:super-sale']['img'] = '/uploads/ph/header-wide.png';
    $all['pages']['collection:super-sale']['d'] = array_replace($all['pages']['collection:super-sale']['d'], $d);
    $all['pages']['collection:super-sale']['m'] = array_replace($all['pages']['collection:super-sale']['m'], $m);

    return $all;
}

/** Just the full-width classes of a compiled header, in order. */
function fwW(array $c): string
{
    return implode(' ', preg_grep('/^kbb-ph-w[dm]$/', explode(' ', $c['wrap'])));
}

function fwWrapClass(string $html): string
{
    return preg_match('#<div class="([^"]*)" style="[^"]*" data-kbb-ph=#', $html, $m) ? $m[1] : '';
}

beforeEach(function () {
    PageHeaderRoutes::wire($this->app);
    SettingsService::forgetMemo();
    for ($i = 1; $i <= 2; $i++) {
        Product::create(['slug' => "fw-{$i}", 'name' => "FW {$i}", 'type' => 'simple', 'status' => 'publish',
            'is_visible' => true, 'price' => 2000, 'sale_price' => 1000, 'stock_status' => 'instock']);
    }
});

it('ships the picture at Full width on a phone and Normal on desktop, because he asked', function () {
    /*
     * DEFECT THIS CATCHES: the control shipped with both devices at Normal, so
     * he applies the package and his phone still shows the 12px gutters he
     * screenshotted -- or the desktop default moved too, which he did not ask.
     * MUTATION: drop the ['d' => 'normal', 'm' => 'full'] entry from
     * SELECTS['width'] -> both read 'normal', red on the first line.
     */
    expect(PageHeaders::blank()['m']['width'])->toBe('full')
        ->and(PageHeaders::blank()['d']['width'])->toBe('normal')
        ->and(PageHeaders::defaults()['pages']['collection:super-sale']['m']['width'])->toBe('full')
        // A bag saved before the control existed has no `width`: it reads as
        // the new default, so the live /super-sale/ moves with the package.
        ->and(PageHeaders::sanitize(['global' => ['d' => [], 'm' => []]], false)[0]['global']['m']['width'])->toBe('full');

    // Every other select still defaults to its first option on both devices.
    foreach (['align' => 'start', 'button_at' => 'side', 'fit' => 'cover'] as $k => $first) {
        expect(PageHeaders::device('d')[$k])->toBe($first)->and(PageHeaders::device('m')[$k])->toBe($first);
    }

    // The panel is told about it, with both options and its label.
    $spec = collect(PageHeaderApiController::spec()['selects'])->firstWhere('key', 'width');
    expect($spec['label'])->toBe('Picture width')
        ->and(array_column($spec['options'], 'value'))->toBe(['normal', 'full'])
        ->and(array_column($spec['options'], 'label'))->toBe(['Normal', 'Full width']);

    // And the shop: /super-sale/ with a picture, as the owner's page has.
    fwStore(fwSaleWithPicture());
    $wrap = fwWrapClass($this->get('/super-sale')->assertOk()->getContent());
    expect($wrap)->toContain(' kbb-ph-wm')->and($wrap)->not->toContain('kbb-ph-wd');
});

it('stores one of its own options or refuses, writing nothing', function () {
    /*
     * MUTATION: accept any string for a select in bag() -> 'edge' saves, red.
     */
    $this->actingAs(fwAdmin(), 'admin');

    foreach (['edge', 'FULL', '100vw', ''] as $bad) {
        $bag = PageHeaders::blank();
        $bag['m']['width'] = $bad;
        $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:super-sale', 'scope' => 'page', 'bag' => $bag])
            ->assertStatus(422)->assertJson(['rejected' => ['bag.m.width'], 'error' => 'Not saved — check: Picture width']);
    }
    $bag = PageHeaders::blank();
    $bag['d']['width'] = ['full'];
    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:super-sale', 'scope' => 'page', 'bag' => $bag])->assertStatus(422);

    expect(DB::table('settings')->where('key', PageHeaders::KEY)->count())->toBe(0);

    // A damaged stored row reads as the device's default, never as itself.
    [$clean] = PageHeaders::sanitize(['global' => ['d' => ['width' => 'x'], 'm' => ['width' => 'y']]], false);
    expect($clean['global']['d']['width'])->toBe('normal')->and($clean['global']['m']['width'])->toBe('full');

    // And a good one round-trips.
    $bag = PageHeaders::blank();
    $bag['d']['width'] = 'full';
    $bag['m']['width'] = 'normal';
    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:super-sale', 'scope' => 'page', 'bag' => $bag])->assertOk();
    SettingsService::forgetMemo();
    app(PageHeaders::class)->forget();
    $saved = app(PageHeaders::class)->bagFor('collection:super-sale');
    expect($saved['d']['width'])->toBe('full')->and($saved['m']['width'])->toBe('normal');
});

it('marks full width per device, and only where a picture is drawn', function () {
    /*
     * DEFECT THIS CATCHES: the class on a header with no picture (every
     * configured page's markup changes for nothing), or on a device whose
     * picture is hidden.
     * MUTATION: drop `$present['image'] &&` from compile() -> the no-picture
     * case carries kbb-ph-wm, red.
     */
    $bag = PageHeaders::blank();
    $with = ['intro' => true, 'image' => true];

    expect(PageHeaders::compile($bag, 'page', $with)['wrap'])->toBe('kbb-ph kbb-ph-pg kbb-ph-wm')
        ->and(PageHeaders::compile($bag, 'page', ['image' => false])['wrap'])->toBe('kbb-ph kbb-ph-pg')
        ->and(fwW(PageHeaders::compile($bag, 'collection', $with)))->toBe('kbb-ph-wm')
        ->and(fwW(PageHeaders::compile($bag, 'collection', ['intro' => true, 'image' => false])))->toBe('');

    $bag['d']['width'] = 'full';
    expect(fwW(PageHeaders::compile($bag, 'collection', $with)))->toBe('kbb-ph-wd kbb-ph-wm');

    $bag['m']['width'] = 'normal';
    expect(fwW(PageHeaders::compile($bag, 'collection', $with)))->toBe('kbb-ph-wd');

    $bag['d']['image'] = false;
    expect(fwW(PageHeaders::compile($bag, 'collection', $with)))->toBe('');

    // The style attribute carries no width: the class is the whole setting.
    expect(PageHeaders::compile($bag, 'collection', $with)['style'])->not->toContain('width');
});

it('compiles the full width class exactly as the PHP does in the browser panel', function () {
    /*
     * The panel redraws the header with page-header-compile.js while he
     * clicks. MUTATION: drop the `kbb-ph-w${dev}` line from the JS -> the
     * bags with a picture differ, red.
     */
    $node = trim((string) shell_exec('command -v node 2>/dev/null')) ?: (is_file('/opt/node22/bin/node') ? '/opt/node22/bin/node' : '');
    if ($node === '') {
        $this->markTestSkipped('node is not installed');
    }

    $cases = [];
    foreach ([['normal', 'normal'], ['full', 'normal'], ['normal', 'full'], ['full', 'full']] as [$d, $m]) {
        foreach ([true, false] as $shown) {
            $bag = PageHeaders::blank();
            $bag['d']['width'] = $d;
            $bag['m']['width'] = $m;
            $bag['m']['image'] = $shown;
            foreach (['collection', 'page'] as $kind) {
                foreach ([['image' => true], ['image' => false]] as $i => $has) {
                    $cases["$d.$m.".(int) $shown.".$kind.$i"] = ['bag' => $bag, 'kind' => $kind, 'has' => $has];
                }
            }
        }
    }

    $dir = storage_path('framework/testing');
    @mkdir($dir, 0777, true);
    $in = $dir.'/fw-cases-'.getmypid().'.json';
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
    expect(fwW($js['full.full.1.collection.0']))->toBe('kbb-ph-wd kbb-ph-wm')
        ->and(fwW($js['full.full.0.collection.0']))->toBe('kbb-ph-wd')
        ->and(fwW($js['full.full.1.collection.1']))->toBe('');
});

it('does not change which pages print the configured header', function () {
    /*
     * DEFECT THIS CATCHES: the phone default of Full width counted as "the
     * page differs from the page as it was", so every custom page nobody
     * touched left its original markup. MUTATION: drop `$h['width']` from
     * project()'s unset -> blank() and a bag at Normal differ, red; and with
     * a stored global bag at Normal, /new-in/ prints kbb-ph.
     */
    foreach (['collection', 'page'] as $kind) {
        $normal = PageHeaders::blank();
        $normal['m']['width'] = $normal['d']['width'] = 'normal';
        $full = PageHeaders::blank();
        $full['m']['width'] = $full['d']['width'] = 'full';

        expect(PageHeaders::project(PageHeaders::blank(), $kind))->toBe(PageHeaders::project($normal, $kind))
            ->and(PageHeaders::project($full, $kind))->toBe(PageHeaders::project($normal, $kind))
            ->and(PageHeaders::project(PageHeaders::blank(), $kind)['m'])->not->toHaveKey('width');
    }

    // A setting saved before the control (no width key anywhere) and one with
    // the global look at Normal on both devices: no page leaves its markup.
    $all = PageHeaders::defaults();
    $all['global']['m']['width'] = 'normal';
    fwStore($all);
    Page::firstOrCreate(['slug' => 'about'], ['title' => 'About us', 'content' => '<p>About.</p>', 'status' => 'published']);

    foreach (['/new-in', '/best-sellers', '/everything-under-54-aed', '/about'] as $path) {
        expect(str_contains($this->get($path)->assertOk()->getContent(), 'kbb-ph'))->toBeFalse($path);
    }

    // /super-sale/ draws its configured header with no picture: no width class.
    $wrap = fwWrapClass($this->get('/super-sale')->assertOk()->getContent());
    expect($wrap)->toBe('sh kbb-ph kbb-ph-nod kbb-ph-nom');
});

it('bleeds the picture to the page edges with CSS alone, never on 100vw, and squares its corners', function () {
    /*
     * DEFECT THIS CATCHES: a bleed built on 100vw. On a laptop 100vw counts
     * the scrollbar, so the page scrolls sideways by its width -- measured in
     * Chromium with a real scrollbar, innerWidth 1280 against a clientWidth
     * of 1265. calc(50% - 50cqi) against the top area, which is the page's
     * own width, leaves scrollWidth at 1265 (docs/fw-shots/numbers.txt).
     * MUTATION: delete the container-type rule -> cqi falls back to the
     * viewport and the desktop shot scrolls; this test is red on its first
     * line. Put 100vw back -> red on the not->toContain.
     */
    $css = PageHeaders::CSS;
    $phone = substr($css, strpos($css, '@media (max-width:900px){'));
    $phone = substr($phone, 0, strpos($phone, '@media (min-width:901px){'));
    $desk = substr($css, strpos($css, '@media (min-width:901px){'));

    expect($css)->toContain('.kbb-home .kbb-pt,.kbb-home.kbb-chc{container-type:inline-size}')
        ->and($css)->not->toContain('100vw')
        ->and($css)->not->toContain('vw)')
        ->and($phone)->toContain('.kbb-ph-wm>.kbb-ph-i{margin-inline:calc(50% - 50cqi)}')
        ->and($phone)->toContain('.kbb-ph-wm>.kbb-ph-i img{border-radius:0}')
        ->and($phone)->not->toContain('kbb-ph-wd')
        ->and($desk)->toContain('.kbb-ph-wd>.kbb-ph-i{margin-inline:calc(50% - 50cqi)}')
        ->and($desk)->toContain('.kbb-ph-wd>.kbb-ph-i img{border-radius:0}')
        ->and($desk)->not->toContain('kbb-ph-wm')
        // The radius rule it overrides comes first, so the zero wins at equal weight.
        ->and(strpos($phone, 'border-radius:var(--ph-rm)'))->toBeLessThan(strpos($phone, 'border-radius:0'))
        ->and(strpos($desk, 'border-radius:var(--ph-rd)'))->toBeLessThan(strpos($desk, 'border-radius:0'));

    // Only the picture bleeds: the title, crumb, intro and button rules carry no margin-inline.
    expect(substr_count($css, 'margin-inline:calc'))->toBe(2);
});

it('puts Picture width in the panel beside the picture sizes, per device, not under Position', function () {
    /*
     * MUTATION: drop `s.key !== 'width' &&` from the Position filter -> the
     * control is drawn twice, once under Position; red.
     */
    $js = (string) file_get_contents(resource_path('js/kbb/admin/page-header-editor.js'));
    $sizes = substr($js, strpos($js, "text: dev === 'd' ? 'Sizes · desktop' : 'Sizes · phone'") - 600, 1500);

    expect($js)->toContain("spec.selects.filter((s) => s.key !== 'fit' && s.key !== 'width' && (s.key !== 'button_at'")
        ->and($js)->toContain("const width = spec.selects.find((s) => s.key === 'width');")
        ->and($sizes)->toContain("'aria-pressed': String(half.width === o.value)")
        ->and($sizes)->toContain('onclick: () => { half.width = o.value; changed(true); }');
});

it('keeps the console Page header screen able to call the panel module by name', function () {
    /*
     * DEFECT THIS CATCHES, found while shooting this control in the console:
     * Pages → Page header stopped at "TypeError: mod.controls is not a
     * function". The console imports page-header-editor.js by its manifest
     * address and calls mod.controls / mod.stage / mod.asDevice; once the
     * category panel imported the same module it became a shared chunk, and
     * Vite's default (preserveEntrySignatures: false) let Rollup rename its
     * exports to `ae as c`. Measured after the fix: the console draws the
     * panel and its preview (docs/fw-shots/console-full-1280.png).
     * MUTATION: remove preserveEntrySignatures from vite.config.js and
     * rebuild -> the entry file exports `o as c`, not `controls`; red.
     */
    expect((string) file_get_contents(base_path('vite.config.js')))->toContain("preserveEntrySignatures: 'exports-only'");

    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $entry = $manifest['resources/js/kbb/admin/page-header-editor.js']['file'] ?? '';
    expect($entry)->not->toBe('');

    $js = (string) file_get_contents(public_path('build/'.$entry));
    expect(preg_match('/export\{([^}]*)\}/', $js, $m))->toBe(1);
    $names = array_map(static fn (string $e): string => trim((string) preg_replace('/^.* as /', '', $e)), explode(',', $m[1]));

    expect($names)->toContain('controls')->toContain('stage')->toContain('asDevice');

    // The screen's own calls are the names it needs.
    $screen = (string) file_get_contents(resource_path('views/admin/partials/page-header-screen.blade.php'));
    foreach (['controls', 'stage', 'asDevice'] as $fn) {
        expect($screen)->toContain('mod.'.$fn.'(');
    }
});
