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

    expect($html)->toContain('class="sec fgh fgh-bg-flow ')
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
    expect($this->get('/')->assertOk()->getContent())->not->toContain('class="sec fgh ');

    fghSwitch(true, 'off');
    expect($this->get('/')->assertOk()->getContent())->not->toContain('class="sec fgh ');
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

    preg_match('#<section class="sec fgh .*?</section>#s', $this->get('/')->getContent(), $m);
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
 * button, because `.kbb-home a` outranks a bare `.fgh-btn` (since 2.60.588 the
 * button is the shop's pill). Put the unconditional sentence() back and this is red.
 */
it('keeps the admin\'s words when the gifts carry no kind, and its button text visible', function () {
    $mask = fghProduct('Kindless Mask', '');
    fghRange(194, null, [[$mask, 2, null]], ['mask' => 2]);
    fghSwitch(true);

    $html = $this->get('/')->getContent();

    expect($html)->toContain('<span class="fgh-w">Own words</span>')
        // The button is now the shop's own pill (.bndl-all), whose colours kbb.css sets.
        ->and($html)->toContain('<a class="bndl-all hs-btn hs-btn-top"');
});

/*
 * Design 20, as the owner picked it (24 October): the One card rows with a
 * FREE coupon, each row opening a fixed-size popup of that range's gifts that
 * swipes between ranges. Mutation notes: drop the data-fgo on a row and the
 * popup can never open (red); give the popup grid a fixed column count and the
 * "more gifts, smaller photos" vars are gone (red).
 */
it('draws the coupon rows and one popup slide per published range, sized by its gift count', function () {
    $ps = array_map(static fn ($i) => fghProduct('Popup Gift ' . $i), range(1, 8));
    fghRange(194, 399, [[$ps[0], 1, 'mask'], [$ps[1], 1, 'full']], ['mask' => 1, 'full' => 1]);
    fghRange(400, null, array_map(static fn ($p) => [$p, 1, 'mask'], $ps), ['mask' => 8]);
    fghSwitch(true);

    $html = $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, 'class="fgh-r" data-fgo="'))->toBe(2)
        ->and(substr_count($html, '<span class="fgh-ft">Free</span>'))->toBe(2)
        ->and(substr_count($html, '<div class="fgh-s" data-i="'))->toBe(2)
        ->and($html)->toContain('<dialog class="fgh-pop"')
        // 2 gifts: two columns, one row; 8 gifts: 4x2 on a computer, 3x3 on a phone.
        ->and($html)->toContain('style="--c:2;--r:1;--cm:2;--rm:1"')
        ->and($html)->toContain(' dm" style="--c:4;--r:2;--cm:3;--rm:3"')
        ->and($html)->toContain('<span class="fgh-cn">8 gifts</span>')
        ->and($html)->toContain('data-of="Level :i of :n"')
        // Fixed-size popup: the box never grows with its content.
        ->and($html)->toContain('.fgh-box{position:absolute;left:50%;top:50%;width:min(600px,calc(100% - 32px));height:min(620px,calc(100% - 48px))')
        ->and($html)->not->toMatch('/getBoundingClientRect|offsetWidth|clientWidth|setInterval/');
});

/*
 * "translate properly everything, all 1 to 10 numbers incase whatever we
 * change it will be pre-translated" (owner, 24 October). Arabic counts take a
 * different word at 1, 2, 3-10 and 11+; every one of them is pinned here.
 */
it('words every count from 1 to 11 in proper Arabic', function () {
    $ar = static fn (int $n, string $k) => FreeGifts::criteriaWording([$k => $n])['ar'];

    expect($ar(1, 'mask'))->toBe('قناع واحد')->and($ar(2, 'mask'))->toBe('قناعان')
        ->and($ar(3, 'mask'))->toBe('3 أقنعة')->and($ar(10, 'mask'))->toBe('10 أقنعة')->and($ar(11, 'mask'))->toBe('11 قناعاً')
        ->and($ar(1, 'mini'))->toBe('منتج صغير واحد')->and($ar(2, 'mini'))->toBe('منتجان صغيران')->and($ar(5, 'mini'))->toBe('5 منتجات صغيرة')
        ->and($ar(1, 'full'))->toBe('منتج بالحجم الكامل')->and($ar(2, 'full'))->toBe('منتجان بالحجم الكامل')->and($ar(4, 'full'))->toBe('4 منتجات بالحجم الكامل');

    foreach (range(1, 11) as $n) {
        foreach (['mask', 'mini', 'full'] as $k) {
            expect($ar($n, $k))->not->toBe('')->not->toContain(':n');
        }
    }

    app()->setLocale('ar');
    $g = app(FreeGifts::class);
    $counts = array_map(static fn ($n) => $g->count($n), [1, 2, 3, 10, 11]);
    expect($counts)->each->not->toContain(':count');
});

/*
 * The phone photo strip the owner picked (option 27, 24 October): "max upto 5
 * and put + sign if there's more". A range with 7 gifts shows five photos and
 * "+2" on a phone (the computer shows six and "+1"). Mutation note: drop the
 * phone "+N" span or the nth-child(n+6) rule and this is red.
 */
it('shows at most five photos and a +N on a phone', function () {
    $ps = array_map(static fn ($i) => fghProduct('Strip Gift ' . $i), range(1, 7));
    fghRange(194, null, array_map(static fn ($p) => [$p, 1, 'mask'], $ps), ['mask' => 7]);
    fghSwitch(true);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('<span class="fgh-mo m">+2</span>')
        ->and($html)->toContain('<span class="fgh-mo d">+1</span>')
        ->and($html)->toContain('.fgh-p .fgh-t:nth-child(n+6){display:none}')
        // The page scrolls through the section: the card has no scroller of its own.
        ->and($html)->not->toMatch('/\.fgh-card\{[^}]*(overflow-y|overscroll-behavior|max-height)/')
        ->and($html)->not->toContain('touch-action:none');
});

/*
 * Where it sits and what it is painted with (owner, 24 October): "i need the
 * gifts sections here" -- under Shop by Concern, above Top brands -- "but also
 * give control to move up down on homepage, and also the background colors
 * control, put a nice gradient ... super light ... change continues inside our
 * branding pinkish colors". Mutation notes: drop `freegifts` from FOLLOWS and a
 * saved order puts it last (red); drop homeBg()'s hex check and the bad colour
 * reaches the page (red); remove the reduced-motion rule (red).
 */
it('is a homepage row placed under Shop by Concern, with ↑/↓ like every section', function () {
    expect(\App\Services\HomepageSections::REGISTRY)->toHaveKey('freegifts')
        ->and(\App\Services\HomepageSections::FOLLOWS['freegifts'] ?? null)->toBe('sbc');

    $keys = array_keys(\App\Services\HomepageSections::REGISTRY);
    expect(array_search('freegifts', $keys, true))->toBe(array_search('sbc', $keys, true) + 1);

    // A shop that saved its order before this row existed gets it under Shop by Concern.
    $saved = [];
    foreach (array_reverse($keys) as $i => $k) {
        if ($k !== 'freegifts') {
            $saved[$k] = ['desktop' => true, 'mobile' => true, 'order' => $i];
        }
    }
    app(SettingsService::class)->set('homepage_sections', $saved);
    SettingsService::forgetMemo();
    Setting::flushMap();
    $order = array_keys(app(\App\Services\HomepageSections::class)->all());
    expect(array_search('freegifts', $order, true))->toBe(array_search('sbc', $order, true) + 1);
});

it('paints a light moving pink gradient by default, and keeps every colour a colour', function () {
    $mask = fghProduct('Gel Mask');
    fghRange(194, null, [[$mask, 1, 'mask']], ['mask' => 1]);
    fghSwitch(true);

    $html = $this->get('/')->assertOk()->getContent();
    expect($html)->toContain('class="sec fgh fgh-bg-flow ')
        ->and($html)->toMatch('/style="--fgw:100%;--fg1:#[0-9A-F]{6};--fg2:#[0-9A-F]{6};--fg3:#[0-9A-F]{6}"/')
        ->and($html)->toContain('@keyframes fghflow{to{transform:translateX(-66.6667%)}}')
        ->and($html)->toContain('@media(prefers-reduced-motion:reduce){.fgh-bg-flow::before{animation:none}}');

    $bg = FreeGifts::homeBg(['bg' => 'disco', 'c1' => 'red;}body{display:none', 'c2' => '#123456', 'strength' => 500]);
    expect($bg)->toBe(['bg' => 'flow', 'c1' => FreeGifts::HOME['c1'], 'c2' => '#123456', 'c3' => FreeGifts::HOME['c3'], 'strength' => 60, 'width' => 100]);

    // "super light": at the shipped strength every washed colour is within a few shades of white.
    foreach (FreeGifts::washed(FreeGifts::homeBg([])) as $hex) {
        expect(min(array_map('hexdec', str_split(substr($hex, 1), 2))))->toBeGreaterThan(220);
    }
});

/*
 * "plz match the width of the section to the site width ... give also to
 * choose width as percentage ... by default 100%" (owner, 24 October). The
 * card used to stop at 980px. Mutation note: put max-width:980px back on
 * .fgh-in, or drop the clamp in homeBg(), and this is red.
 */
it('runs the site width by default, and takes a clamped percentage on a computer', function () {
    $mask = fghProduct('Gel Mask');
    fghRange(194, null, [[$mask, 1, 'mask']], ['mask' => 1]);
    fghSwitch(true);

    $html = $this->get('/')->assertOk()->getContent();
    expect($html)->toContain('.fgh-in{width:100%;display:grid;gap:16px}')
        ->and($html)->toContain('@media(min-width:901px){.fgh-in{max-width:var(--fgw,100%)}}')
        ->and($html)->toContain('style="--fgw:100%')
        ->and($html)->not->toContain('max-width:980px');

    expect(FreeGifts::homeBg(['width' => 70])['width'])->toBe(70)
        ->and(FreeGifts::homeBg(['width' => 5])['width'])->toBe(40)
        ->and(FreeGifts::homeBg(['width' => '100%;}body{'])['width'])->toBe(100);
});

/*
 * "the button style i need the same as othe sections, and beside the heading
 * right side, and link it to super-sale page ... give option to change the
 * link" (owner, 24 October). Mutation notes: go back to the old dark button,
 * or drop HomeSections::url() from home(), and this is red.
 */
it('draws the shop pill beside the heading, linked to the super sale, with a checked link', function () {
    $mask = fghProduct('Gel Mask');
    fghRange(194, null, [[$mask, 1, 'mask']], ['mask' => 1]);
    fghSwitch(true);

    $html = $this->get('/')->assertOk()->getContent();
    preg_match('#<section class="sec fgh .*?</section>#s', $html, $m);
    expect($m[0])->toContain('<div class="hs-head hs-split hs-split-c">')
        ->and($m[0])->toMatch('#<a class="bndl-all hs-btn hs-btn-top" href="[^"]*/super-sale/">Shop now<i>#')
        ->and($m[0])->toMatch('#<div class="hs-foot"><a class="bndl-all hs-btn hs-btn-bottom" href="[^"]*/super-sale/">#');

    $set = function (string $url) {
        app(SettingsService::class)->set(FreeGifts::KEY, ['mode' => 'live', 'home' => ['on' => true, 'url' => $url]]);
        SettingsService::forgetMemo();
        Setting::flushMap();
        app(FreeGifts::class)->compile();
        preg_match('#<section class="sec fgh .*?<a class="bndl-all hs-btn hs-btn-top" href="([^"]*)"#s', $this->get('/')->getContent(), $m);

        return $m[1] ?? '';
    };
    expect($set('/new-in/'))->toEndWith('/new-in/')
        ->and($set('https://example.com/x'))->toBe('https://example.com/x')
        ->and($set('javascript:alert(1)'))->toEndWith('/super-sale/');
});

/*
 * "need button to the most right, and increase the button length to 10% more"
 * (owner, 24 October). The shared heading caps itself at 760px and shrank to
 * its content inside this section's grid, so the button stopped mid-page.
 * Mutation note: drop either rule below and this is red (measured: the button
 * ends at the card's right edge, 163px wide against 148).
 */
it('puts the button at the card\'s right edge and makes the pill 10% longer', function () {
    $mask = fghProduct('Gel Mask');
    fghRange(194, null, [[$mask, 1, 'mask']], ['mask' => 1]);
    fghSwitch(true);

    $html = $this->get('/')->assertOk()->getContent();
    expect($html)->toContain('.fgh .hs-head.hs-split{width:100%;max-width:none;margin-inline:0;box-sizing:border-box}')
        ->and($html)->toContain('.fgh .hs-btn{padding-inline-start:35px}');
});

/*
 * "in mobile if any gift we give 2x, then there should mention beautifully in
 * a circle 2x, 3x etc on each product image" (owner, 24 October). The phone
 * strip hid the ×N badge (`.fgh-t b{display:none}`). Mutation note: put that
 * back and this is red.
 */
it('shows the ×N circle on phone photos too', function () {
    $mask = fghProduct('Gel Mask');
    fghRange(194, null, [[$mask, 3, 'mask']], ['mask' => 3]);
    fghSwitch(true);

    $html = $this->get('/')->assertOk()->getContent();
    expect($html)->toContain('<b>×3</b>')
        ->and($html)->not->toContain('.fgh-t b{display:none}')
        ->and($html)->toContain('.fgh-t b{display:grid;top:-5px;inset-inline-start:-5px;');
});

/*
 * "gift icon + New gifts added daily ... give control to change this line for
 * both languages" (owner, 24 October), so shoppers do not read the photos as
 * the final gifts. Mutation notes: drop the line from the partial, or let an
 * emptied field fall back to the default, and this is red.
 */
it('says new gifts are added daily under the gifts, in each language, and hides when emptied', function () {
    $mask = fghProduct('Gel Mask');
    fghRange(194, null, [[$mask, 1, 'mask']], ['mask' => 1]);
    fghSwitch(true);

    expect($this->get('/')->assertOk()->getContent())->toMatch('#<p class="fgh-note"><svg[^>]*>.*?</svg><span>New gifts added daily</span></p>#s');
    expect(FreeGifts::HOME['note_ar'])->toBe('هدايا جديدة تُضاف يومياً');

    app(SettingsService::class)->set(FreeGifts::KEY, ['mode' => 'live', 'home' => ['on' => true, 'note_en' => '']]);
    SettingsService::forgetMemo();
    Setting::flushMap();
    app(FreeGifts::class)->compile();
    expect($this->get('/')->getContent())->not->toContain('class="fgh-note"');
});

/*
 * "for higher ranges. show the full sizes products then mini and mask if
 * space is there. don't start with the masks first" (owner, 24 October).
 * Mutation note: hand home() the lines unsorted and this is red.
 */
it('shows the row photos full size first, then minis, then masks; the popup keeps the admin order', function () {
    $mask = fghProduct('Order Mask');
    $mini = fghProduct('Order Mini', 'mini');
    $full = fghProduct('Order Full', 'full');
    fghRange(194, null, [[$mask, 2, 'mask'], [$mini, 1, 'mini'], [$full, 1, 'full']], ['mask' => 2, 'mini' => 1, 'full' => 1]);
    fghSwitch(true);

    app()->instance('request', \Illuminate\Http\Request::create('/'));
    $row = app(FreeGifts::class)->home(request())['rows'][0];
    // The row's small photos lead with full size; the popup keeps the admin's order.
    expect(array_column($row['thumbs'], 'name'))->toBe(['Order Full', 'Order Mini', 'Order Mask'])
        ->and(array_column($row['gifts'], 'name'))->toBe(['Order Mask', 'Order Mini', 'Order Full']);
});
