<?php

declare(strict_types=1);

/*
 * The foot of the product page AS IT SHIPS: brand | category TABS, then
 * Continue shopping. (Lane BC)
 *
 * The owner, changing the plan a third time: "keep also the tab, more from
 * anua, and more from toner, both tabs. and remove the second block complete
 * your routine. keep such things by default rest will be off, and also give
 * option to disabl the tabs and show the brand, and then the category block,
 * but by default keep the tabs on, turn off the 2nd block and third will be
 * continue shoping which we have already."
 *
 * The "Two separate blocks" option and the best-seller block are RecsBlocksTest
 * (it switches them on in its beforeEach); this file is the default.
 *
 * MUTATIONS, each run and each red (storage/bc-logs/bc-recs-mut.py):
 *   T1  AlsoLikeSettings — `pair` shipped as 'blocks' → "ships tabs then
 *       continue shopping" red: two blocks, no data-rp-tabs.
 *   T2  AlsoLikeSettings — `best_on` shipped true → "ships tabs then continue
 *       shopping" red: a best-seller block is drawn.
 *   T3  ProductRecs::forProduct() — the brand tab's cards not taken out of the
 *       category tab (`$taken += self::idsOf($one)` dropped, and lists()'s
 *       `$skip += …BRAND`) → "never repeats" red: an Anua toner in both tabs.
 *   T4  ProductRecs::forProduct() — `$seen` taken without `$taken` (viewed
 *       products not checked against the blocks above) → "never repeats" red:
 *       a1, viewed, shows in the brand tab and Continue shopping.
 *   T5  recs-tabs.blade.php — data-rp-hint printed whatever `tab_first` says
 *       → "tab that opens first" red for "Always the brand".
 *   T6  ProductRecs — `array_reverse` for "Always the category" dropped → "tab
 *       that opens first" red: the brand tab is still the open one.
 *   T7  ymal.js — the `data-rp-hint` gate removed → "reads the hint only when
 *       he chose where the shopper came from" red.
 *   T8  ProductRecs::lists() — the viewed ids queried on their own instead
 *       of inside the union (a third statement) → "two statements" red.
 *   T9  recs-tabs.blade.php — the nth-child rule's `+ 1` dropped → "per-device
 *       controls" red.
 *   T10 ProductRecs — picks hosted by Continue shopping only when the
 *       best-seller block is on (host test inverted) → "picks" red.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\AlsoLikeSettings;
use App\Services\ProductRecs;
use App\Services\ProductSections;
use App\Support\AlsoLikePicks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function rtProduct(string $name, ?Brand $brand, array $categories, int $sales, array $extra = []): Product
{
    $p = Product::create(array_merge([
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 5000,
        'stock_status' => 'instock',
        'brand_id' => $brand?->id,
        'total_sales' => $sales,
    ], $extra));

    if ($categories !== []) {
        $p->categories()->sync(array_map(fn ($c) => $c->id, $categories));
    }

    return $p;
}

function rtCategory(string $name): Category
{
    $slug = Str::slug($name).'-'.Str::lower(Str::random(4));

    return Category::create(['slug' => $slug, 'name' => $name, 'depth' => 0, 'position' => -1, 'path' => $slug]);
}

/**
 * Brand and category overlap on purpose: the Anua TONERS are the shop's best
 * sellers, so without the no-repeat rule each would be in both tabs and in
 * Continue shopping.
 *
 * @return array<string, mixed>
 */
function rtShop(): array
{
    $anua = Brand::create(['slug' => 'anua-'.Str::lower(Str::random(4)), 'name' => 'Anua']);
    $other = Brand::create(['slug' => 'other-'.Str::lower(Str::random(4)), 'name' => 'Other']);
    $far = Brand::create(['slug' => 'far-'.Str::lower(Str::random(4)), 'name' => 'Far']);

    $toners = rtCategory('Toners');
    $hair = rtCategory('Hair');

    return [
        'brand' => $anua, 'other' => $other, 'far' => $far, 'toners' => $toners, 'hair' => $hair,
        'self' => rtProduct('Heartleaf Toner', $anua, [$toners], 10),
        'a1' => rtProduct('Anua Toner One', $anua, [$toners], 1000400),
        'a2' => rtProduct('Anua Toner Two', $anua, [$toners], 1000300),
        'a3' => rtProduct('Anua Toner Three', $anua, [$toners], 1000200),
        't1' => rtProduct('Toner One', $other, [$toners], 290),
        't2' => rtProduct('Toner Two', $other, [$toners], 190),
        'x1' => rtProduct('Hair Best', $far, [$hair], 90000),
        'x2' => rtProduct('Hair Next', $far, [$hair], 80000),
        'x3' => rtProduct('Hair Third', $far, [$hair], 70000),
        'v1' => rtProduct('Hair Viewed', $far, [$hair], 3),
        'oos' => rtProduct('Anua Sold Out', $anua, [$toners], 5000000, ['stock_status' => 'outofstock']),
    ];
}

function rtBlock(string $html, string $headingId): string
{
    preg_match('#<section [^>]*aria-labelledby="'.preg_quote($headingId, '#').'".*?</section>#s', $html, $m);

    return $m[0] ?? '';
}

/** One tab's panel: from its opening tag to the next panel or the section's end. */
function rtPanel(string $html, string $key): string
{
    $at = strpos($html, '<div class="rp-panel" id="rp-p-'.$key.'"');
    if ($at === false) {
        return '';
    }
    $ends = array_filter([strpos($html, '<div class="rp-panel"', $at + 1), strpos($html, '</section>', $at)], 'is_int');

    return substr($html, $at, min($ends) - $at);
}

/** @return list<string> */
function rtSlugs(string $fragment): array
{
    preg_match_all('#href="/product/([^/"?]+)/"#', $fragment, $m);

    return array_values(array_unique($m[1]));
}

function rtPage(\Tests\TestCase $test, Product $p, ?string $viewed = null): string
{
    $req = $viewed === null ? $test : $test->withCookie('kbb_viewed', $viewed);

    return $req->get('/product/'.$p->slug.'/')->assertOk()->getContent();
}

/* ══════════════════════════════ the default ═══════════════════════════════ */

it('ships tabs then continue shopping — no routine, no best-seller block', function () {
    $s = rtShop();
    $html = rtPage($this, $s['self']);
    $tabs = rtBlock($html, 'ymal-h');
    $recent = rtBlock($html, 'rp3-h');

    expect($tabs)->toContain('data-rp-tabs data-rp-hint')
        ->toContain('<div class="eyebrow">More like this</div>')
        ->toContain('<h2 id="ymal-h">You may also like</h2>')
        ->toContain('id="rp-t-brand" aria-controls="rp-p-brand" aria-selected="true" data-rp-tab>More from Anua</button>')
        ->toContain('id="rp-t-category" aria-controls="rp-p-category" aria-selected="false" tabindex="-1" data-rp-tab>More Toners</button>')
        ->and($recent)->toContain('<h2 id="rp3-h">Continue shopping</h2>')->toContain('<div class="eyebrow">Best sellers</div>')
        ->toContain('data-ymal-auto="0"')
        ->and(strpos($html, 'id="ymal-h"'))->toBeLessThan(strpos($html, 'id="rp3-h"'));

    // The brand panel open, the category one [hidden] (its pictures wait);
    // both lists are links in the HTML, so both are crawlable.
    preg_match('#<div class="rp-panel" id="rp-p-brand"[^>]*>#', $tabs, $b);
    preg_match('#<div class="rp-panel" id="rp-p-category"[^>]*>#', $tabs, $c);
    expect($b[0])->not->toContain('hidden')->toContain('data-rp-paths="/brands/'.$s['brand']->slug.'/"')
        ->and($c[0])->toContain(' hidden')->toContain('/collections/'.$s['toners']->slug.'/')
        ->and(rtSlugs(rtPanel($tabs, 'brand')))->toBe([$s['a1']->slug, $s['a2']->slug, $s['a3']->slug])
        ->and(rtSlugs(rtPanel($tabs, 'category')))->toBe([$s['t1']->slug, $s['t2']->slug]);

    // Nothing else: no separate brand / category block, no best sellers, no routine.
    expect($html)->not->toContain('id="rp1-h"')->not->toContain('id="rp2-h"')->not->toContain('id="rpf-h"')
        ->not->toContain('Complete your routine')->not->toContain('routine');
});

it('never repeats a product across both tabs and continue shopping, nor shows the product itself', function () {
    $s = rtShop();
    app(\App\Services\BuyTogetherSettings::class)->save(['on' => true]);
    // He viewed a1 (in the brand tab) and v1: only v1 comes back below.
    $html = rtPage($this, $s['self'], implode(',', [$s['self']->id, $s['a1']->id, $s['v1']->id]));

    $brand = rtSlugs(rtPanel($html, 'brand'));
    $cat = rtSlugs(rtPanel($html, 'category'));
    $recent = rtSlugs(rtBlock($html, 'rp3-h'));
    $all = array_merge($brand, $cat, $recent);

    expect($brand)->toContain($s['a1']->slug)
        ->and($cat)->not->toContain($s['a1']->slug)->not->toContain($s['a2']->slug)
        ->and($recent[0])->toBe($s['v1']->slug)
        ->and(rtBlock($html, 'rp3-h'))->toContain('<div class="eyebrow">Recently viewed</div>')
        ->and(count($all))->toBe(count(array_unique($all)))
        ->and($all)->not->toContain($s['self']->slug)->not->toContain($s['oos']->slug);

    preg_match('#<section[^>]*class="[^"]*kbb-fbt.*?</section>#s', $html, $fbt);
    $bt = array_diff(rtSlugs($fbt[0] ?? ''), [$s['self']->slug]);
    expect(array_intersect($bt, array_merge($cat, $recent)))->toBe([]);
});

it('opens the tab he chose — where the shopper came from, always the brand, or always the category', function () {
    $s = rtShop();

    // Default: where the shopper came from. The hint attribute lets ymal.js switch.
    expect(rtBlock(rtPage($this, $s['self']), 'ymal-h'))->toContain('data-rp-tabs data-rp-hint aria-labelledby');

    app(AlsoLikeSettings::class)->save(['tab_first' => 'brand']);
    $one = rtBlock(rtPage($this, $s['self']), 'ymal-h');
    expect($one)->not->toContain('data-rp-hint')
        ->and($one)->toContain('id="rp-t-brand" aria-controls="rp-p-brand" aria-selected="true"');

    app(AlsoLikeSettings::class)->save(['tab_first' => 'category']);
    $one = rtBlock(rtPage($this, $s['self']), 'ymal-h');
    preg_match('#<div class="rp-panel" id="rp-p-brand"[^>]*>#', $one, $b);
    expect($one)->not->toContain('data-rp-hint')
        ->and($one)->toContain('id="rp-t-category" aria-controls="rp-p-category" aria-selected="true"')
        ->and(strpos($one, 'id="rp-p-category"'))->toBeLessThan(strpos($one, 'id="rp-p-brand"'))
        ->and($b[0])->toContain(' hidden');

    // A value 2.60.428 saved under its own `first` key is never read.
    app(AlsoLikeSettings::class)->save(['tab_first' => 'auto']);
    app(\App\Services\SettingsService::class)->set('ymal_first', 'category');
    expect(rtBlock(rtPage($this, $s['self']), 'ymal-h'))->toContain('data-rp-hint')
        ->toContain('id="rp-t-brand" aria-controls="rp-p-brand" aria-selected="true"');
});

it('lists the category\'s parent shelves too, so a click from Skincare opens "More Toners"', function () {
    $s = rtShop();
    $parent = Category::create(['slug' => 'skin-'.Str::lower(Str::random(4)), 'name' => 'Skincare', 'depth' => 0, 'position' => 9]);
    $s['toners']->update(['parent_id' => $parent->id, 'depth' => 1, 'path' => $parent->slug.'/'.$s['toners']->slug]);

    preg_match('#<div class="rp-panel" id="rp-p-category"[^>]*data-rp-paths="([^"]*)"#', rtPage($this, $s['self']), $m);

    expect(explode(' ', $m[1] ?? ''))->toBe([
        '/collections/'.$parent->slug.'/'.$s['toners']->slug.'/',
        '/collections/'.$parent->slug.'/',
    ]);
});

it('reads the hint only when he chose where the shopper came from, only by comparing it, written only from the listing grids', function () {
    $js = (string) file_get_contents(resource_path('js/kbb/ymal.js'));
    $shop = (string) file_get_contents(resource_path('js/kbb/shop.js'));

    expect($js)->toContain("const HINT = '".ProductRecs::HINT_KEY."';")
        ->and($js)->toContain("if (!root.hasAttribute('data-rp-hint')) return;")
        ->and(strpos($js, "hasAttribute('data-rp-hint')"))->toBeLessThan(strpos($js, 'sessionStorage.getItem'))
        ->and($js)->toContain("from.length > 300 || from.charAt(0) !== '/'")
        ->and($js)->toContain(".split(' ').indexOf(from) !== -1")
        ->and($shop)->toContain("sessionStorage.setItem('".ProductRecs::HINT_KEY."', decodeURI(window.location.pathname))")
        ->and($shop)->toContain("document.getElementById('grid') || document.getElementById('brandGrid')");

    foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'getBoundingClientRect', 'offsetWidth', 'fetch(', 'XMLHttpRequest', 'sendBeacon'] as $api) {
        expect(str_contains($js, $api))->toBeFalse("ymal.js reaches for {$api}");
    }
});

it('draws a lone tab as its own block: no brand, or a brand with nothing else', function () {
    $s = rtShop();
    $lone = rtProduct('No Brand Toner', null, [$s['toners']], 5);
    $html = rtPage($this, $lone);

    expect($html)->not->toContain('data-rp-tabs')
        ->and(rtBlock($html, 'rp2-h'))->toContain('<h2 id="rp2-h">More Toners</h2>')
        ->and(rtBlock($html, 'rp3-h'))->not->toBe('');
});

/* ═════════════════════ the "Two separate blocks" option ═══════════════════ */

it('shows the brand block, then the category block, with "Two separate blocks"', function () {
    $s = rtShop();
    app(AlsoLikeSettings::class)->save(['pair' => 'blocks']);
    $html = rtPage($this, $s['self']);

    expect($html)->not->toContain('data-rp-tabs')
        ->and(rtBlock($html, 'rp1-h'))->toContain('<h2 id="rp1-h">More from Anua</h2>')->toContain('data-ymal ')
        ->and(rtBlock($html, 'rp2-h'))->toContain('<h2 id="rp2-h">More Toners</h2>')->toContain('class="sec ymal rp-grid ')
        ->and(strpos($html, 'id="rp1-h"'))->toBeLessThan(strpos($html, 'id="rp2-h"'))
        ->and(strpos($html, 'id="rp2-h"'))->toBeLessThan(strpos($html, 'id="rp3-h"'))
        ->and(rtSlugs(rtBlock($html, 'rp2-h')))->not->toContain($s['a1']->slug)
        ->and($html)->not->toContain('id="rpf-h"');
});

/* ══════════════════════════ picks, per device ═════════════════════════════ */

it('puts his picks after the recently viewed in continue shopping, or first in the best sellers when those are on', function () {
    $s = rtShop();
    $s['self']->update(['also_like' => ['mode' => AlsoLikePicks::MODE_FIRST, 'ids' => [$s['x3']->id, $s['a1']->id]]]);

    // a1 is the brand tab's: picking it does not repeat it.
    $recent = rtSlugs(rtBlock(rtPage($this, $s['self'], (string) $s['v1']->id), 'rp3-h'));
    expect(array_slice($recent, 0, 3))->toBe([$s['v1']->slug, $s['x3']->slug, $s['x1']->slug])
        ->and($recent)->not->toContain($s['a1']->slug);

    // Only my picks: what he viewed, then the picks, and no best-seller top-up.
    $s['self']->update(['also_like' => ['mode' => AlsoLikePicks::MODE_ONLY, 'ids' => [$s['x3']->id, $s['x2']->id]]]);
    expect(rtSlugs(rtBlock(rtPage($this, $s['self'], (string) $s['v1']->id), 'rp3-h')))->toBe([$s['v1']->slug, $s['x3']->slug, $s['x2']->slug]);

    // The best-seller block on: the picks lead it, and Continue shopping goes without them.
    $s['self']->update(['also_like' => ['mode' => AlsoLikePicks::MODE_FIRST, 'ids' => [$s['x3']->id]]]);
    app(AlsoLikeSettings::class)->save(['best_on' => true]);
    $html = rtPage($this, $s['self']);
    expect(rtSlugs(rtBlock($html, 'rpf-h'))[0])->toBe($s['x3']->slug)
        ->and(rtSlugs(rtBlock($html, 'rp3-h')))->not->toContain($s['x3']->slug);
});

it('keeps the per-device controls: slider or grid and how many, on the tabs and on continue shopping', function () {
    $s = rtShop();
    foreach (range(1, 14) as $i) {
        rtProduct('Anua Extra '.$i, $s['brand'], [$s['hair']], 100 + $i);
        rtProduct('Toner Extra '.$i, $s['other'], [$s['toners']], 50 + $i);
    }

    // Shipped: sliders, 12 in each tab, 10 in Continue shopping, nothing hidden.
    $html = rtPage($this, $s['self']);
    expect(substr_count(rtPanel($html, 'brand'), 'class="kbb-card kbb-tile'))->toBe(12)
        ->and(substr_count(rtPanel($html, 'category'), 'class="kbb-card kbb-tile'))->toBe(12)
        ->and(substr_count(rtBlock($html, 'rp3-h'), 'class="kbb-card kbb-tile'))->toBe(10)
        ->and($html)->not->toContain('rp-dg')->not->toContain('rp-mg')->not->toContain(':nth-child(n+');

    app(AlsoLikeSettings::class)->save(['tabs_layout_d' => 'grid', 'tabs_count_m' => '4', 'recent_layout_m' => 'grid']);
    $html = rtPage($this, $s['self']);
    expect(rtBlock($html, 'ymal-h'))->toMatch('/class="sec ymal ymal-tabs rp-dg /')->toContain('data-ymal-n="12 4"')
        ->and($html)->toContain('@media(max-width:900px){#rp-r-brand>:nth-child(n+5),#rp-r-category>:nth-child(n+5){display:none}}')
        ->and(rtBlock($html, 'rp3-h'))->toContain('rp-mg');

    // Grid on both devices: plain grids in the panels, no carousel script.
    app(AlsoLikeSettings::class)->save(['tabs_layout_m' => 'grid']);
    $tabs = rtBlock(rtPage($this, $s['self']), 'ymal-h');
    expect($tabs)->toContain('class="sec ymal ymal-tabs rp-grid rp-dg rp-mg ')->not->toContain('data-ymal')->not->toContain('ymal-nav')
        ->and($tabs)->toContain('data-rp-tab>');
});

/* ═══════════════════════════════ the cost ═════════════════════════════════ */

it('asks two statements for the tabs and continue shopping, cold and warm, with or without a history', function () {
    $s = rtShop();
    $s['self']->load(['brand:id,name,slug', 'categories:id,name,slug,path,parent_id,depth']);
    app(\App\Services\SettingsService::class)->all();
    app(ProductSections::class)->all();
    __('store.product.recs_tab_brand');
    $recs = app(ProductRecs::class);
    $req = Request::create('/', 'GET', [], ['kbb_viewed' => $s['v1']->id.','.$s['x2']->id]);

    $count = function (Request $r) use ($recs, $s): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $out = $recs->forProduct($s['self'], $r);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$n, $out];
    };

    [$cold, $a] = $count($req);
    [$warm, $b] = $count($req);
    [$plain] = $count(Request::create('/'));

    expect($cold)->toBe(2)->and($warm)->toBe(2)->and($plain)->toBe(2)
        ->and($a['recent']['products']->pluck('id')->all())->toBe($b['recent']['products']->pluck('id')->all())
        ->and($a['recent']['seen'])->toBe([(int) $s['v1']->id, (int) $s['x2']->id]);
});

it('costs the same queries with 3 relatives as with 40, with a history or without', function () {
    $s = rtShop();
    $count = function (?string $viewed = null) use ($s): int {
        app()->forgetInstance(\App\Services\SettingsService::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        rtPage($this, $s['self'], $viewed);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $cold = function () use ($s): void {
        ProductRecs::forget((int) $s['self']->id);
        \App\Services\BuyTogether::forget((int) $s['self']->id);
    };

    $count();
    $cold();
    $small = $count();

    foreach (range(1, 37) as $i) {
        rtProduct('More Anua '.$i, $s['brand'], [$s['toners']], 5 + $i);
        rtProduct('More Hair '.$i, $s['far'], [$s['hair']], 50 + $i);
    }

    $cold();
    $big = $count();

    expect($big)->toBe($small)
        ->and($count())->toBeLessThanOrEqual($big)
        ->and($count((string) $s['v1']->id))->toBe($count());
});

it('reads the page\'s shared cards as one entry, and keeps the shopper\'s history out of it', function () {
    $s = rtShop();
    $first = rtPage($this, $s['self'], (string) $s['v1']->id);

    $raw = Cache::get('kbb.cards.en.'.$s['self']->id);
    $bundle = unserialize(gzinflate($raw), ['allowed_classes' => false]);

    expect($bundle)->toHaveKey($s['a1']->id)
        ->and($bundle)->not->toHaveKey($s['v1']->id)
        ->and(Cache::get('kbb.card.en.'.$s['v1']->id))->toBeArray();

    rtPage($this, $s['self']);
    expect(Cache::get('kbb.cards.en.'.$s['self']->id))->toBe($raw);

    $again = rtPage($this, $s['self'], (string) $s['v1']->id);
    $foot = fn (string $h) => rtBlock($h, 'ymal-h').rtBlock($h, 'rp3-h');
    expect($foot($again))->toBe($foot($first));
});

/* ═════════════════════════════ both languages ═════════════════════════════ */

it('prints his Arabic headings on the Arabic page only, and keeps the restored strings translated', function () {
    $s = rtShop();
    app(AlsoLikeSettings::class)->save(['title_ar' => 'اخترنا لك', 'recent_title_ar' => 'تابعي التسوق']);

    $en = rtPage($this, $s['self']);
    expect(rtBlock($en, 'ymal-h'))->toContain('>You may also like</h2>')->not->toContain('اخترنا لك');

    app(\App\Services\SettingsService::class)->set(\App\Support\Locale::SETTING_ENABLED, true);
    $ar = $this->get('/ar/product/'.$s['self']->slug.'/')->assertOk()->getContent();
    expect(rtBlock($ar, 'ymal-h'))->toContain('<h2 id="ymal-h">اخترنا لك</h2>')
        ->and(rtBlock($ar, 'rp3-h'))->toContain('<h2 id="rp3-h">تابعي التسوق</h2>');
    preg_match('#<div class="rp-panel" id="rp-p-category"[^>]*data-rp-paths="([^"]*)"#', $ar, $m);
    expect($m[1] ?? '')->toStartWith('/ar/');

    $drafts = \App\Services\Translation\ArabicInterfaceDrafts::all();
    foreach (['recs_tabs_label', 'recs_more_eyebrow', 'recs_recent_heading', 'recs_recent_eyebrow'] as $k) {
        expect($drafts)->toHaveKey('store.product.'.$k);
    }
    expect($drafts)->not->toHaveKey('store.product.recs_routine_heading');
});
