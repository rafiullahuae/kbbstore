<?php

declare(strict_types=1);

/**
 * Free gifts -- "Gift box look" (Lane FG5), Growth & Marketing -> Free gifts ->
 * Display -> Gift box look.
 *
 * The owner, 22 Oct, with a screenshot of the opened list whose long titles
 * ran right up to FREE: "controls to hide / show the brand names ... a little
 * spacing between the names and the word FREE ... the product title, if long,
 * may fade from the end or go to a 2nd line, but give options to control both
 * ... font size of the titles, sizes of the product thumbnails ... a thin,
 * super light separator line".
 *
 * Each case says what the defect would look like on the shop and carries a
 * mutation note: change the named line back and the case goes red.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Setting;
use App\Services\CartService;
use App\Services\FreeGifts;
use App\Services\SettingsService;
use App\Support\Locale;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const FG5_DEFAULT_FIELDSET = '<fieldset class="fgb fgb-clamp2" style="--fg-ts:12px;--fg-pl:28px;--fg-pr:36px;--fg-fg:16px;--fg-rg:3px"';

beforeEach(function () {
    DB::table('free_gift_ranges')->delete();
    app(FreeGifts::class)->compile();
});

function fg5Set(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
}

function fg5Product(string $name, array $o = []): Product
{
    return Product::create(array_merge([
        'slug' => Str::slug($name ?: 'p') . '-' . Str::random(6), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => 2500, 'stock_status' => 'instock', 'manage_stock' => false,
    ], $o));
}

/** Live, with $look saved (null = no "look" key at all, as every shop has before this package). */
function fg5On(?array $look = null): void
{
    $config = ['mode' => 'live', 'show_value' => false, 'countries' => 'all', 'basis' => 'after_discounts'];
    if ($look !== null) {
        $config['look'] = $look;
    }
    fg5Set(FreeGifts::KEY, $config);
}

/** A range from AED 100 giving each product once; returns a qualifying basket. */
function fg5Basket(array $gifts): Cart
{
    $id = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 10000, 'max_fils' => null, 'label_en' => 'Gifts', 'label_ar' => 'هدايا', 'sort' => 0, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($gifts as $i => $p) {
        DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $p->id, 'quantity' => 1, 'sort' => $i]);
    }
    app(FreeGifts::class)->compile();
    SettingsService::forgetMemo();
    Setting::flushMap();

    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $serum = fg5Product('Serum', ['price' => 15000]);
    $cart->items()->create(['product_id' => $serum->id, 'quantity' => 1, 'unit_price' => 15000]);

    return $cart->fresh();
}

/** The box exactly as the cart page draws it for this basket and the saved look. */
function fg5Box(Cart $cart): string
{
    SettingsService::forgetMemo();
    Setting::flushMap();
    $fg = new FreeGifts(app(SettingsService::class));
    app()->instance(FreeGifts::class, $fg);
    $rows = FreeGifts::rows($fg->forTotals(app(CartService::class)->totals($cart->fresh(), 'AE')));

    return view('partials.free-gifts.box', ['kbbFgRows' => $rows])->render();
}

function fg5Shopper(Cart $cart)
{
    app(CartService::class)->forget();

    return test()->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

/*
|------------------------------------------------------------------------------
| THE SETTINGS
|------------------------------------------------------------------------------
*/

it('falls back to the default for a bad value and clamps every number to its range', function () {
    /*
     * The defect: a select that stores whatever it is sent, or a number taken
     * as given -- "titles":"<b>" in the class list, a 400px photo or a -9px
     * gap on every shop page with a basket.
     * MUTATION: in FreeGifts::lookOf() drop the max($min, min($max, ...)) clamp,
     * or the in_array(... LOOK_TITLES ...) check. RED.
     */
    expect(FreeGifts::lookOf(null))->toBe(FreeGifts::LOOK)
        ->and(FreeGifts::lookOf('nonsense'))->toBe(FreeGifts::LOOK)
        ->and(FreeGifts::LOOK)->toBe(['brand' => true, 'titles' => 'clamp2', 'free_gap' => 16, 'title_px' => 12, 'photo_list' => 28, 'photo_row' => 36, 'separator' => false, 'row_gap' => 3]);

    $bad = FreeGifts::lookOf([
        'brand' => 'maybe', 'separator' => ['x'], 'titles' => 'marquee',
        'free_gap' => 'wide', 'title_px' => ['13'], 'photo_list' => null, 'photo_row' => NAN, 'row_gap' => '1e400',
    ]);
    expect($bad)->toBe(FreeGifts::LOOK);

    $clamped = FreeGifts::lookOf(['free_gap' => 99, 'title_px' => 3, 'photo_list' => 400, 'photo_row' => -5, 'row_gap' => '7.6']);
    expect($clamped['free_gap'])->toBe(40)
        ->and($clamped['title_px'])->toBe(11)
        ->and($clamped['photo_list'])->toBe(48)
        ->and($clamped['photo_row'])->toBe(22)
        ->and($clamped['row_gap'])->toBe(8);

    $good = FreeGifts::lookOf(['brand' => false, 'separator' => '1', 'titles' => 'fade', 'free_gap' => '0', 'title_px' => 16]);
    expect($good['brand'])->toBeFalse()->and($good['separator'])->toBeTrue()->and($good['titles'])->toBe('fade')
        ->and($good['free_gap'])->toBe(0)->and($good['title_px'])->toBe(16);
});

it('saves the look from Display through the same normalising, and keeps it when an older console sends none', function () {
    /*
     * The defect: the admin endpoint writing the posted "look" straight into
     * the settings row -- a raw string the box would then have to trust.
     * MUTATION: in FreeGiftsController::settings() store $data['look'] instead
     * of FreeGifts::lookOf($data['look']). RED.
     */
    $owner = AdminUser::create(['name' => 'FG5', 'email' => 'fg5-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);
    $c = app(FreeGifts::class)->freshConfig();
    $body = ['show_value' => false, 'countries' => 'all', 'basis' => 'after_discounts', 'texts' => $c['texts'], 'home' => $c['home']];

    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/settings', $body + ['look' => [
        'brand' => false, 'titles' => '"><script>x()</script>', 'free_gap' => 500, 'title_px' => '14', 'photo_list' => 'big', 'separator' => true, 'evil' => 'kept?',
    ]])->assertOk();

    $saved = app(SettingsService::class)->get(FreeGifts::KEY)['look'];
    expect($saved)->toBe(['brand' => false, 'titles' => 'clamp2', 'free_gap' => 40, 'title_px' => 14, 'photo_list' => 28, 'photo_row' => 36, 'separator' => true, 'row_gap' => 3]);

    // An older console (no "look" in the body) leaves the saved look alone.
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/settings', $body)->assertOk();
    SettingsService::forgetMemo();
    Setting::flushMap();
    expect(app(SettingsService::class)->get(FreeGifts::KEY)['look'])->toBe($saved);

    // And a role without the screen's capability is refused, as every write here is.
    $support = AdminUser::create(['name' => 'FG5 s', 'email' => 'fg5s-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => 'support']);
    test()->actingAs($support, 'admin')->postJson('/admin-api/free-gifts/settings', $body + ['look' => ['brand' => true]])->assertForbidden();
});

/*
|------------------------------------------------------------------------------
| THE BOX
|------------------------------------------------------------------------------
*/

it('ships today\'s look except the two the owner asked for: 16px before FREE and two lines then …', function () {
    /*
     * The owner's screenshot: long titles running right up to FREE (the row's
     * 8px gap was all there was) and wrapping without limit.
     * MUTATION: set LOOK['free_gap'] back to 8, or LOOK['titles'] to 'wrap'. RED.
     * MUTATION: drop `margin-inline-start:calc(var(--fg-fg,16px) - 8px)` from
     * .fgb-lv in the partial -- the space is a setting nobody can see. RED.
     */
    fg5On();
    $brand = \App\Models\Brand::create(['name' => 'Shiseido', 'slug' => 'shiseido-' . Str::random(6)]);
    $html = fg5Box(fg5Basket([fg5Product('Senka Perfect Whip Fresh Beauty Face Foam Cleanser for Normal to Dry Skin 120g', ['image' => '/storage/media/senka.jpg', 'brand_id' => $brand->id])]));

    expect($html)->toContain(FG5_DEFAULT_FIELDSET)
        ->toContain('.fgb-lv{display:flex;gap:6px;align-items:baseline;font-size:11px;font-weight:800;color:#2e8f62;white-space:nowrap;margin-inline-start:calc(var(--fg-fg,16px) - 8px)}')
        ->toContain('.fgb-lr{display:flex;align-items:center;gap:8px}')
        ->toContain('.fgb-clamp2 .fgb-nm{max-height:2.5em;overflow:hidden;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2}')
        // Today's sizes, as fallbacks and as the values.
        ->toContain('width:var(--fg-pr,36px);height:var(--fg-pr,36px)')
        ->toContain('.fgb-lr .fgb-t{width:var(--fg-pl,28px);height:var(--fg-pl,28px);border-radius:7px}')
        ->toContain('font-size:var(--fg-ts,12px);line-height:1.25')
        ->toContain('gap:var(--fg-rg,3px)')
        ->toContain('width="36" height="36"')->toContain('width="28" height="28"')
        // Brand shown, no separator, no fade: and their CSS is not even printed.
        ->toContain('<small>')
        ->not->toContain('fgb-nobrand')->not->toContain('fgb-sep')->not->toContain('fgb-fade')
        ->not->toContain('mask-image');
});

it('turns each control into its fixed class or integer variable, and nothing else', function () {
    /*
     * The defect: a control that saves but never reaches the shop, which is
     * the shape "built, never wired up" this repository keeps finding.
     * MUTATION: in FreeGifts::lookAttrs() drop any one of the classes or
     * variables, or in the partial drop the `@if (! $kbbFgLookCfg['brand'])`
     * rule. RED.
     */
    $p = [fg5Product('Tea Tree Sheet Mask', ['image' => '/storage/media/mask.jpg'])];
    $cart = fg5Basket($p);

    fg5On(['brand' => false]);
    expect(fg5Box($cart))->toContain('<fieldset class="fgb fgb-nobrand fgb-clamp2"')->toContain('.fgb-nobrand .fgb-ln small{display:none}');

    fg5On(['titles' => 'fade']);
    $fade = fg5Box($cart);
    expect($fade)->toContain('<fieldset class="fgb fgb-fade"')
        ->toContain('.fgb-fade .fgb-nm{white-space:nowrap;overflow:hidden;-webkit-mask-image:linear-gradient(90deg,#000 calc(100% - 28px),transparent);mask-image:linear-gradient(90deg,#000 calc(100% - 28px),transparent)}')
        ->toContain('.fgb-fade .fgb-list{grid-template-columns:minmax(0,1fr)}')
        ->toContain('<span class="fgb-nm" dir="ltr">Tea Tree Sheet Mask</span>')
        ->not->toContain('-webkit-line-clamp');

    fg5On(['titles' => 'wrap']);
    $wrap = fg5Box($cart);
    expect($wrap)->toContain('<fieldset class="fgb" style=')->not->toContain('-webkit-line-clamp')->not->toContain('mask-image')
        ->toContain('<span class="fgb-nm">Tea Tree Sheet Mask</span>');

    fg5On(['separator' => true]);
    expect(fg5Box($cart))->toContain('<fieldset class="fgb fgb-clamp2 fgb-sep"')
        ->toContain('.fgb-sep .fgb-lr+.fgb-lr{border-top:1px solid #e4eee9;padding-top:var(--fg-rg,3px)}');

    fg5On(['free_gap' => 24, 'title_px' => 14, 'photo_list' => 40, 'photo_row' => 44, 'row_gap' => 8]);
    $big = fg5Box($cart);
    expect($big)->toContain('style="--fg-ts:14px;--fg-pl:40px;--fg-pr:44px;--fg-fg:24px;--fg-rg:8px"')
        // The photos ask for the copy that fits the size they are drawn at.
        ->toContain('width="44" height="44"')->toContain('width="40" height="40"');
});

it('prints no string from the settings: classes are constants and the variables are integers', function () {
    /*
     * The defect: a look value echoed into class="" or style="" -- the box is
     * on the checkout, so a stored `"><script>` would run there.
     * MUTATION: in FreeGifts::lookAttrs() drop the `$look = self::lookOf($look);`
     * at its top -- the strings reach the (int) casts unchecked. RED.
     */
    $evil = '"><script>alert(1)</script><b x="';
    fg5Set(FreeGifts::KEY, ['mode' => 'live', 'look' => array_fill_keys(array_keys(FreeGifts::LOOK), $evil)]);
    $html = fg5Box(fg5Basket([fg5Product('Mask')]));

    expect($html)->not->toContain('<script>alert')->not->toContain('alert(1)')
        ->toContain(FG5_DEFAULT_FIELDSET);

    // Straight to the builder, past the reader, and the output is still only constants and digits.
    $attrs = FreeGifts::lookAttrs(['brand' => $evil, 'titles' => $evil, 'separator' => $evil, 'free_gap' => '12px;color:red', 'title_px' => $evil, 'photo_list' => '30', 'photo_row' => 30.4, 'row_gap' => '4']);
    expect($attrs['class'])->toMatch('/^( fgb-(nobrand|clamp2|fade|sep))*$/')
        ->and($attrs['style'])->toMatch('/^--fg-ts:\d+px;--fg-pl:\d+px;--fg-pr:\d+px;--fg-fg:\d+px;--fg-rg:\d+px$/')
        ->and($attrs['style'])->toBe('--fg-ts:12px;--fg-pl:30px;--fg-pr:30px;--fg-fg:16px;--fg-rg:4px');

    // And the newer-CSS landmine: nothing newer than calc() next to var().
    preg_match('/<style>(.*?)<\/style>/s', $html, $m);
    expect($m[1])->not->toMatch('/(round|color-mix|clamp|min|max)\(/');
});

it('costs the cart page no query for the look: the same count with or without one saved', function () {
    /*
     * The defect: the look kept in a settings row of its own, or read per box
     * with Setting::query() -- one more query on every page with a basket
     * (CLAUDE.md, "Speed is frozen"). It lives inside the free_gifts row,
     * which SettingsRequestMemo already read.
     * MUTATION: in the partial, after lookAttrs(), add
     * Setting::query()->where('key', 'free_gifts_look')->value('value'). RED (the box
     * then costs one query; the page counts alone would not see it).
     */
    $cart = fg5Basket([fg5Product('Mask A'), fg5Product('Mask B')]);
    $count = function (?array $look) use ($cart): array {
        fg5On($look);
        fg5Shopper($cart)->get('/cart/')->assertOk(); // warm
        SettingsService::forgetMemo();
        Setting::flushMap();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = fg5Shopper($cart)->get('/cart/')->assertOk()->getContent();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$n, $html];
    };

    [$without, $a] = $count(null);
    [$with, $b] = $count(['brand' => false, 'titles' => 'fade', 'separator' => true, 'free_gap' => 30, 'title_px' => 15, 'photo_list' => 44, 'photo_row' => 44, 'row_gap' => 9]);

    expect($a)->toContain('<fieldset class="fgb fgb-page fgb-clamp2"')
        ->and($b)->toContain('<fieldset class="fgb fgb-page fgb-nobrand fgb-fade fgb-sep" style="--fg-ts:15px;--fg-pl:44px;--fg-pr:44px;--fg-fg:30px;--fg-rg:9px" data-fgs="')
        ->and($with)->toBe($without);

    // And the box itself, drawn once the settings row has been read (as the
    // memo has it on every shop page), asks the database nothing at all --
    // a query both runs above paid would not show in their difference.
    $fg = new FreeGifts(app(SettingsService::class));
    app()->instance(FreeGifts::class, $fg);
    $rows = FreeGifts::rows($fg->forTotals(app(CartService::class)->totals($cart->fresh(), 'AE')));
    $fg->config();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $box = view('partials.free-gifts.box', ['kbbFgRows' => $rows])->render();
    expect($box)->toContain('fgb-fade')->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});

it('fades a title at its own end on an Arabic page: Arabic names on the left, English names end in … on the right', function () {
    /*
     * Measured in Chromium (docs/lane-fg5/3-one-line-fade-ar-390.png): with the
     * page's direction alone, "Black Rice Hyaluronic Toner …" on /ar/ was cut at
     * its START and read "yaluronic Toner for Sensitive Skin"; and a fade on the
     * right of a right-aligned English name faded every short one.
     * MUTATION: drop the dir="…" on .fgb-nm in fade mode, or the
     * `.fgb-fade .fgb-nm[dir=rtl]` 270deg rule. RED.
     */
    fg5Set('language_ar_enabled', '1');
    fg5Set(Locale::SETTING_RTL, '1');
    fg5On(['titles' => 'fade']);
    $cart = fg5Basket([fg5Product('مجموعة التوهج للعناية بالبشرة مع سيروم فيتامين سي'), fg5Product('Black Rice Hyaluronic Toner for Sensitive Skin')]);

    $page = fg5Shopper($cart)->get('/ar/cart/')->assertOk()->getContent();

    expect($page)->toContain('dir="rtl"')
        ->toContain('<span class="fgb-nm" dir="rtl">مجموعة التوهج للعناية بالبشرة مع سيروم فيتامين سي</span>')
        ->toContain('<span class="fgb-nm" dir="ltr">Black Rice Hyaluronic Toner for Sensitive Skin</span>')
        // Arabic name on an Arabic page: the fade is on the LEFT (270deg = towards the left).
        ->toContain('.fgb-fade .fgb-nm[dir=rtl]{-webkit-mask-image:linear-gradient(270deg,#000 calc(100% - 28px),transparent);mask-image:linear-gradient(270deg,#000 calc(100% - 28px),transparent)}')
        // English name on an Arabic page: no fade, right-aligned, "…" at its own end only when too long.
        ->toContain('[dir=rtl] .fgb-fade .fgb-nm[dir=ltr]{-webkit-mask-image:none;mask-image:none;text-overflow:ellipsis;text-align:right}')
        ->toContain('[dir=ltr] .fgb-fade .fgb-nm[dir=rtl]{-webkit-mask-image:none;mask-image:none;text-overflow:ellipsis;text-align:left}');

    expect(FreeGifts::textDir('مجموعة'))->toBe('rtl')
        ->and(FreeGifts::textDir('12 × مجموعة'))->toBe('rtl')
        ->and(FreeGifts::textDir('Senka مجموعة'))->toBe('ltr')
        ->and(FreeGifts::textDir(''))->toBe('ltr');
});

/*
|------------------------------------------------------------------------------
| THE ADMIN
|------------------------------------------------------------------------------
*/

it('draws every control on Display and keeps the preview\'s copy of the defaults equal to the server\'s', function () {
    /*
     * The defect: a preview that shows a look the save would not keep, because
     * its defaults or ranges drifted from FreeGifts::LOOK (the script is
     * @verbatim, so they are written out there by hand).
     * MUTATION: change one number in `var LOOK = ...` in the screen. RED.
     */
    $screen = file_get_contents(resource_path('views/admin/partials/free-gifts-screen.blade.php'));

    expect($screen)->toContain('  var LOOK = ' . json_encode(FreeGifts::LOOK) . ', LOOK_TITLES = ' . json_encode(FreeGifts::LOOK_TITLES) . ', LOOK_RANGES = ' . json_encode(FreeGifts::LOOK_RANGES) . ';')
        ->and(substr_count($screen, 'lookCard(),'))->toBe(1)
        ->and($screen)->toContain("card('Gift box look'")
        ->toContain("'Show brand names'")->toContain("'Separator line between gifts'")
        ->toContain("['clamp2', 'Up to 2 lines, then …'], ['fade', 'One line, fades at the end'], ['wrap', 'Wrap fully']")
        ->toContain("num('free_gap', 'Space before FREE'")->toContain("num('title_px', 'Title font size'")
        ->toContain("num('row_gap', 'Space between gifts'")->toContain("num('photo_list', 'Photo size, opened list'")
        ->toContain("num('photo_row', 'Photo size, closed row'")
        // Saved, discarded and compared with the rest of Display.
        ->toContain('look: lookOf(c.look) };')
        ->toContain('c.texts, lookOf(c.look)]); }')
        ->toContain('st.cfg.look = clone(lookOf(o.look));')
        // The preview draws the same classes and integer variables as the shop.
        ->toContain("' fgp-nobrand'")->toContain("({ clamp2: ' fgp-clamp2', fade: ' fgp-fade', wrap: '' })[lk.titles]")->toContain("' fgp-sep'")
        ->toContain("'--fg-ts:' + lk.title_px + 'px;--fg-pl:' + lk.photo_list + 'px;--fg-pr:' + lk.photo_row + 'px;--fg-fg:' + lk.free_gap + 'px;--fg-rg:' + lk.row_gap + 'px'");
});
