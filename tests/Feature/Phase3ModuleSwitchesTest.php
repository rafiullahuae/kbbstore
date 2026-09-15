<?php

/**
 * The two Phase 3 switches that were advertised as working and were not.
 *
 * `seo_engine` and `product_sorting` were both marked `live` in
 * ModuleRegistry — a status that file defines as "something on the storefront
 * reads moduleEnabled() for this key" — while nothing anywhere read either.
 * Both features were genuinely built; only the switch was missing. That is
 * this repo's signature defect (`single_name` saved and nothing read it; quick
 * view had a button, a modal, CSS, a route and a controller and no listener),
 * so these tests assert the whole path: flip the control, ask for the page,
 * look at what a browser would actually receive.
 *
 * Each module is pinned three ways, because two of them are not enough:
 * on gives the new behaviour, off gives the OLD behaviour back, and the
 * default is what a store that has touched nothing gets. A switch that only
 * ever adds is not a switch.
 */

use App\Models\Product;
use App\Services\ModuleRegistry;
use App\Services\SettingsService;
use App\Support\Facets;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('category_product')->delete();
    DB::table('products')->delete();

    Cache::flush();
    Facets::reset();
});

function moduleSwitch(string $key, bool $on): void
{
    app(SettingsService::class)->setModule($key, $on);
}

function moduleIsOn(string $key): bool
{
    // Read through the registry, so this cannot pass against a default the
    // admin screen does not use. Built fresh rather than resolved: all() caches
    // its answer for the life of the instance, which is right for a request and
    // wrong for a test that flips a switch and asks again.
    return (new ModuleRegistry(app(SettingsService::class)))->on($key);
}

/* ─────────────────────────── product_sorting ─────────────────────────── */

/**
 * Two products whose curated order is the exact reverse of their alphabetical
 * order, so the two possible sorts can never be confused for each other.
 */
function curatedPair(): void
{
    $n = uniqid();

    Product::create([
        'slug' => 'zz-'.$n, 'name' => 'ZZ Curated First '.$n,
        'status' => 'publish', 'is_visible' => true,
        'price' => 10000, 'stock_status' => 'instock', 'position' => 1,
    ]);

    Product::create([
        'slug' => 'aa-'.$n, 'name' => 'AA Curated Second '.$n,
        'status' => 'publish', 'is_visible' => true,
        'price' => 10000, 'stock_status' => 'instock', 'position' => 2,
    ]);
}

/** The curated pair's names, in the order the shop grid actually renders them. */
function curatedOrderOn(string $url): array
{
    Facets::reset();

    $html = test()->get($url)->assertOk()->getContent();

    preg_match_all('/<a class="cname" href="[^"]*">([^<]*)<\/a>/', $html, $m);

    return array_values(array_filter(
        array_map('html_entity_decode', $m[1]),
        fn ($name) => str_starts_with($name, 'ZZ Curated') || str_starts_with($name, 'AA Curated')
    ));
}

it('keeps the plugin default for product_sorting on a genuinely fresh install', function () {
    // Off, as the plugin ships it and the plan records it. This is the value a
    // store with no module_toggles row at all gets, which is what "default"
    // means here — existing stores are handled separately, below.
    expect(ModuleRegistry::REGISTRY['product_sorting'][3])->toBeFalse();

    DB::table('module_toggles')->where('module', 'product_sorting')->delete();
    Cache::forget('kbb.modules');

    expect(moduleIsOn('product_sorting'))->toBeFalse();
});

it('does not silently reorder an existing catalogue when the switch first becomes real', function () {
    // "Featured" has had `position` in its ORDER BY all along, so every shop and
    // category page already shows the curated order. The alignment migration
    // turns the module on for stores that already have that behaviour, rather
    // than letting a package that adds a switch also change what the shop looks
    // like. The stored value it overwrites was never read by anything, so it
    // cannot have carried a decision.
    expect(moduleIsOn('product_sorting'))->toBeTrue();
    expect(moduleIsOn('seo_engine'))->toBeTrue();
});

it('uses the curated order on the shop page only while product_sorting is on', function () {
    curatedPair();

    $alphabetical = fn (array $n) => str_starts_with($n[0] ?? '', 'AA Curated');
    $curated = fn (array $n) => str_starts_with($n[0] ?? '', 'ZZ Curated');

    // Off: the position column is not consulted at all, which is exactly what
    // this query did before a curated order existed.
    moduleSwitch('product_sorting', false);
    $off = curatedOrderOn('/shop');
    expect($off)->toHaveCount(2);
    expect($alphabetical($off))->toBeTrue();

    // On: the owner's drag order from Store → Catalog → Reorder wins.
    moduleSwitch('product_sorting', true);
    $on = curatedOrderOn('/shop');
    expect($on)->toHaveCount(2);
    expect($curated($on))->toBeTrue();

    // And off again puts the previous behaviour back, rather than leaving the
    // storefront in the module's state until something else resets it.
    moduleSwitch('product_sorting', false);
    expect($alphabetical(curatedOrderOn('/shop')))->toBeTrue();
});

it('gates only the default sort, never an explicitly requested one', function () {
    curatedPair();

    // Name A–Z is the shopper's own choice from the sort control. The module
    // governs what "Featured" means, not whether the other six options work —
    // gating those would be a different bug wearing this one's clothes.
    foreach ([false, true] as $on) {
        moduleSwitch('product_sorting', $on);

        $names = curatedOrderOn('/shop?orderby=name');

        expect($names)->toHaveCount(2);
        expect(str_starts_with($names[0], 'AA Curated'))->toBeTrue();
    }
});

/* ───────────────────────────── seo_engine ───────────────────────────── */

it('has seo_engine on by default, because nothing else in this app writes a head', function () {
    // The one deliberate divergence from the plugin's own defaults, recorded in
    // full on the ModuleRegistry row: the plugin can ship this off because
    // WordPress still writes a <head> without it, and here nothing does.
    expect(ModuleRegistry::REGISTRY['seo_engine'][3])->toBeTrue();

    DB::table('module_toggles')->where('module', 'seo_engine')->delete();
    Cache::forget('kbb.modules');

    expect(moduleIsOn('seo_engine'))->toBeTrue();
});

it('emits the full head while seo_engine is on and a bare title while it is off', function () {
    moduleSwitch('seo_engine', true);

    $on = $this->get('/')->assertOk()->getContent();

    expect($on)->toContain('<title>')
        ->toContain('<meta name="robots"')
        ->toContain('<link rel="canonical"')
        ->toContain('<meta property="og:title"')
        ->toContain('application/ld+json');

    // Off is the head this layout had before the engine was ever connected to
    // a page: a title and nothing else. Not a blank title — that is never an
    // acceptable output, and is what an empty template field used to produce.
    moduleSwitch('seo_engine', false);

    $off = $this->get('/')->assertOk()->getContent();

    expect($off)->toContain('<title>')
        ->not->toContain('<title></title>')
        ->not->toContain('<meta name="robots"')
        ->not->toContain('<link rel="canonical"')
        ->not->toContain('<meta property="og:')
        ->not->toContain('<meta name="twitter:')
        ->not->toContain('application/ld+json');

    // Back on restores it, rather than needing a cache clear or a redeploy.
    moduleSwitch('seo_engine', true);

    expect($this->get('/')->assertOk()->getContent())
        ->toContain('<link rel="canonical"');
});

it('still titles a listing page with seo_engine off', function () {
    moduleSwitch('seo_engine', false);

    $html = $this->get('/shop')->assertOk()->getContent();

    expect($html)->toMatch('/<title>[^<]+<\/title>/')
        ->not->toContain('<meta property="og:title"');
});

/* ─────────────────────── the guard against a repeat ─────────────────────── */

it('has a real storefront reader for every module the registry calls live', function () {
    // The whole reason both of these shipped broken: `live` is documented in
    // ModuleRegistry as "something on the storefront reads moduleEnabled() for
    // this key", it is maintained by hand, and nothing checked it. 2.60.91
    // "corrected" both of these rows to `live` against features that were built
    // but ungated, which is how a wrong status survived a deliberate review.
    $roots = [base_path('app'), base_path('resources/views')];
    $haystack = '';

    foreach ($roots as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['php'], true)) {
                // The registry itself lists every key and would match everything.
                if ($file->getPathname() === base_path('app/Services/ModuleRegistry.php')) {
                    continue;
                }

                $haystack .= file_get_contents($file->getPathname());
            }
        }
    }

    $unread = [];

    foreach (ModuleRegistry::REGISTRY as $key => $row) {
        if ($row[9] !== 'live') {
            continue;
        }

        if (! str_contains($haystack, "moduleEnabled('{$key}'") && ! str_contains($haystack, "on('{$key}')")) {
            $unread[] = $key;
        }
    }

    expect($unread)->toBe([], 'Registry says these modules are live, but nothing reads their switch: '.implode(', ', $unread));
});
