<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Setting;
use App\Services\FreeGifts;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * THE HOMEPAGE FREE GIFTS SECTION.
 *
 * What it looked like on the shop (owner, 23 October): "i have enabled the
 * homepage free gifts section, but it's not showing anywhere." The switch in
 * Marketing -> Free gifts saved `home.on`, and nothing on the storefront ever
 * read it -- the section had been designed and never built. And: "the section
 * must be dynamic, if we publish any range from the ranges, it should show auto
 * here also".
 *
 * Mutation notes: delete the @include line in store/home.blade.php and the
 * first case is red; make FreeGifts::home() ignore `home.on` and the second
 * is; make it fetch products per range and the cost case is.
 */
beforeEach(function () {
    DB::table('free_gift_ranges')->delete();
});

function fghProduct(string $name, string $kind = 'mask', int $stock = 50): Product
{
    return Product::create([
        'slug' => Str::slug($name) . '-' . Str::random(6), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => 2500, 'stock_status' => $stock === 0 ? 'outofstock' : 'instock', 'manage_stock' => true, 'stock' => $stock, 'gift_kind' => $kind,
        'image' => '/media/' . Str::slug($name) . '.webp',
    ]);
}

function fghRange(int $minAed, ?int $maxAed, array $gifts, ?array $needs, bool $enabled = true): void
{
    $id = DB::table('free_gift_ranges')->insertGetId([
        'min_fils' => $minAed * 100, 'max_fils' => $maxAed === null ? null : $maxAed * 100 + 99, 'label_en' => 'Own words', 'label_ar' => 'كلمات',
        'sort' => 0, 'enabled' => $enabled,
        'need_mask' => $needs['mask'] ?? null, 'need_mini' => $needs['mini'] ?? null, 'need_full' => $needs['full'] ?? null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ($gifts as $i => [$p, $q, $kind]) {
        DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $p->id, 'quantity' => $q, 'kind' => $kind, 'role' => 'primary', 'sort' => $i]);
    }
}

function fghSwitch(bool $homeOn, string $mode = 'live'): void
{
    app(SettingsService::class)->set(FreeGifts::KEY, ['mode' => $mode, 'home' => ['on' => $homeOn]]);
    SettingsService::forgetMemo();
    Setting::flushMap();
    app(FreeGifts::class)->compile();
}

it('shows every published range on the homepage with its wording and gift photos', function () {
    $mask = fghProduct('EGF NAD Firming Gel Mask');
    $lip = fghProduct('Honey Milk Lip Oil', 'full');
    $vt = fghProduct('Cica Reti-A Mask');
    fghRange(194, 399, [[$mask, 1, 'mask'], [$lip, 1, 'full']], ['mask' => 1, 'full' => 1]);
    fghRange(400, null, [[$mask, 1, 'mask'], [$vt, 1, 'mask'], [$lip, 2, 'full']], ['mask' => 2, 'full' => 2]);
    fghSwitch(true);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('class="sec fgh"')
        ->and($html)->toContain('Free gifts with every order')
        ->and($html)->toContain('AED 194 – 399')
        ->and($html)->toContain('AED 400+')
        ->and($html)->toContain('1 Mask + 1 Full Size Product')
        ->and($html)->toContain('2 Masks + 2 Full Size Products')
        ->and($html)->toContain('alt="EGF NAD Firming Gel Mask"')
        ->and($html)->toContain('<b>×2</b>');
});

it('draws nothing while the homepage switch is off, or gifts are off', function () {
    $mask = fghProduct('Gel Mask');
    fghRange(194, null, [[$mask, 1, 'mask']], ['mask' => 1]);

    fghSwitch(false);
    expect($this->get('/')->assertOk()->getContent())->not->toContain('class="sec fgh"');

    fghSwitch(true, 'off');
    expect($this->get('/')->assertOk()->getContent())->not->toContain('class="sec fgh"');
});

it('follows the ranges by itself: a newly published range appears, a switched-off one goes', function () {
    $mask = fghProduct('Gel Mask');
    fghRange(194, 399, [[$mask, 1, 'mask']], ['mask' => 1]);
    fghSwitch(true);
    expect($this->get('/')->getContent())->not->toContain('AED 800+');

    fghRange(800, null, [[$mask, 3, 'mask']], ['mask' => 3]);
    fghSwitch(true);
    expect($this->get('/')->getContent())->toContain('AED 800+')->toContain('3 Masks');

    DB::table('free_gift_ranges')->where('min_fils', 80000)->update(['enabled' => false]);
    fghSwitch(true);
    expect($this->get('/')->getContent())->not->toContain('AED 800+');
});

it('never promises a gift that is out of stock', function () {
    $gone = fghProduct('Sold Out Mask', 'mask', 0);
    $mask = fghProduct('Gel Mask');
    fghRange(194, 399, [[$gone, 1, 'mask']], ['mask' => 1]);
    fghRange(400, null, [[$mask, 1, 'mask']], ['mask' => 1]);
    fghSwitch(true);

    preg_match('#<section class="sec fgh".*?</section>#s', $this->get('/')->getContent(), $m);
    $html = $m[0] ?? '';
    expect($html)->not->toContain('AED 194 – 399')->and($html)->not->toContain('Sold Out Mask')->and($html)->toContain('AED 400+');
});

it('costs the same whether one range is published or five', function () {
    $count = function (): int {
        SettingsService::forgetMemo();
        Setting::flushMap();
        app()->instance('request', \Illuminate\Http\Request::create('/'));
        $gifts = app(FreeGifts::class);
        $gifts->home(request());
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        app()->instance('request', \Illuminate\Http\Request::create('/'));
        expect($gifts->home(request()))->not->toBeNull();

        return $n;
    };

    $a = fghProduct('Mask A');
    fghRange(194, 299, [[$a, 1, 'mask']], ['mask' => 1]);
    fghSwitch(true);
    $one = $count();

    foreach ([300, 500, 800, 1200] as $i => $min) {
        $p = fghProduct('Mask ' . $min);
        fghRange($min, $i === 3 ? null : $min + 199, [[$a, 1, 'mask'], [$p, 1, 'mask']], ['mask' => 2]);
    }
    fghSwitch(true);
    $five = $count();

    expect($five)->toBe($one);
});

/*
 * What it looked like in the preview, 23 October: every row's wording was
 * blank when the gifts had no kind set (sentence() counted nothing and won
 * over the admin's own "2 Masks"), and "Shop now" was dark text on a dark
 * button, because `.kbb-home a` outranks a bare `.fgh-btn`. Put the
 * unconditional sentence() back, or the selector to `.fgh-btn{`, and this is red.
 */
it('keeps the admin\'s words when the gifts carry no kind, and its button text visible', function () {
    $mask = fghProduct('Kindless Mask', '');
    fghRange(194, null, [[$mask, 2, null]], ['mask' => 2]);
    fghSwitch(true);

    $html = $this->get('/')->getContent();

    expect($html)->toContain('<span class="fgh-w">Own words</span>')
        ->and($html)->toContain('.fgh a.fgh-btn{')
        ->and($html)->not->toMatch('/[}>]\.fgh-btn\{/');
});
