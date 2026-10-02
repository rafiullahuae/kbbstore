<?php

declare(strict_types=1);

/*
 * "Buy these together": the four-card carousel and its peek, the price line
 * that stays in its box, the struck total and the savings line, and the bundle
 * discount through the cart, the drawer, the checkout, the order and the
 * payment providers.                                               (Lane RE)
 *
 * The owner, 2 October, from a phone at 390px:
 *
 *   "also the text is going out the boxes. you can adjust the 4 products on
 *    the screen, and 5th one can be hidden, and this will be as carousel. and
 *    upon scroll this section must slightly animate and display the half of
 *    the 5th product. [...] the button has little light bottom shadow [...]
 *    give option to give discount upon 5 products purchse, 4 products and 3.
 *    so the price will change upon user number of selections. and if any
 *    product removed from the cart, the other products prices will become
 *    normal without buy together discount. also above button Total: should be
 *    on right side beside the value. and also mention cut price as total
 *    calculation. and mention you're saving 'AED amount' [...]"
 *
 * and later: "and on top of it, the coupon can be apply. also giveo ption to
 * include exclude the coupon apply on the buy together products."
 *
 * THE BASKET USED THROUGHOUT, tiers 5 / 10 / 15 %:
 *
 *   Relief Sun       AED  69            10% →  AED 62.10 → AED 62   off  7
 *   Moist Best       AED 108 (was 153)  10% →  AED 97.20 → AED 97   off 11
 *   Toner Best       AED  80            10% →  AED 72               off  8
 *   Oil Best         AED  95            10% →  AED 85.50 → AED 85   off 10
 *                    ───────                                        ──────
 *   subtotal         AED 352            bundle                      AED 36
 *
 * MUTATIONS, each made against the file named, RUN, red, and reverted byte
 * for byte (storage/re-logs/mutate.py, a scratch harness, not committed):
 *
 *   M1  BuyTogetherPricing::forCart() — drop the `count($lines) !== $size`
 *       check → "dissolves the bundle when one is removed" red: a line deleted
 *       past CartService (SetStockReconciler does) left a group still priced.
 *   M2  CartService::remove() — drop the dissolve() call → same case red on
 *       `bt_group` of the survivors.
 *   M3  CartService::updateQuantity() — drop the dissolve() call → "quantity
 *       0" case red.
 *   M4  BuyTogetherPricing::forCart() — `$sets` = max() instead of min() →
 *       "one bundle per complete set" red (AED 36 becomes AED 50).
 *   M5  CartController::groupTogether() — drop the allowedCompanions() check →
 *       "a product the section could not have offered" red.
 *   M6  BuyTogetherPricing::forCart() — drop `->visible()` → "a hidden member"
 *       red.
 *   M7  BuyTogetherPricing::couponLines() — ignore `$include` → the
 *       Exclude halves of both coupon cases red.
 *   M8  BuyTogetherPricing::couponLines() — price a bundled unit at its full
 *       price under Include → "stacks the coupon AFTER the bundle" red (AED 42
 *       off, not AED 38), and the order case with it.
 *   M9  CheckoutController::place() — write `discount_total` without the
 *       bundle → "the order records…" red, and the Tabby/Tamara identity red.
 *   M10 BuyTogetherSettings::SCHEMA tier_3 max 50 → 90 → the clamp case red
 *       (80 is stored and served).
 *   M11 kbb-product.css — remove `flex-wrap:wrap` from the `.pr` rule → the CSS
 *       case red (and docs/re-shots measured 9.7px of overflow at 390 again).
 *   M12 fbt.js unitOff() — `Math.ceil` for the whole-dirham step → the node
 *       parity case red (the page says AED 63, the basket AED 62).
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\BuyTogetherPairs;
use App\Services\BuyTogetherPricing;
use App\Services\BuyTogetherSettings;
use App\Services\CartService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

function reFlush(): void
{
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
    Cache::forget(BuyTogetherPairs::CACHE_KEY);
}

/** @param array<string, mixed> $settings */
function reOn(array $settings = []): void
{
    app(BuyTogetherSettings::class)->save(array_merge(['on' => true, 'count' => 4, 'tier_3' => 5, 'tier_4' => 10, 'tier_5' => 15], $settings));
    reFlush();
}

function reCat(string $name): Category
{
    return Category::query()->where('name', $name)->first()
        ?? Category::create(['slug' => Str::slug($name).'-'.Str::lower(Str::random(4)), 'name' => $name]);
}

function reProduct(string $name, array $cats, int $sales, array $extra = []): Product
{
    $p = Product::create(array_merge([
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
        'name' => $name, 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => 5000, 'stock_status' => 'instock', 'total_sales' => $sales,
    ], $extra));

    if ($cats !== []) {
        $p->categories()->sync(array_map(fn (Category $c) => $c->id, $cats));
    }

    return $p;
}

/** A sunscreen and the shelves his sentence pairs it with. */
function reShop(): array
{
    return [
        'sun' => reProduct('Relief Sun', [reCat('Sunscreens')], 10000, ['price' => 6900]),
        'moist' => reProduct('Moist Best', [reCat('Moisturisers')], 900000, ['price' => 15300, 'sale_price' => 10800]),
        'toner' => reProduct('Toner Best', [reCat('Toners')], 700000, ['price' => 8000]),
        'oil' => reProduct('Oil Best', [reCat('Cleansing Oils')], 500000, ['price' => 9500]),
        'mask' => reProduct('Mask Best', [reCat('Masks')], 300000, ['price' => 6000]),
        // On no shelf a sunscreen pairs with, and outsold by the whole demo
        // catalogue: nothing the section could ever have offered.
        'lone' => reProduct('Lone Lip Tint', [], 0, ['price' => 4000]),
    ];
}

function reCart(): Cart
{
    return Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
}

function reAs(Cart $cart)
{
    // CartService is scoped and remembers the cart it resolved (and the
    // controllers hold that same instance); one test here drives several
    // baskets through the same application, so each request starts from the
    // cookie it is handed, as a real one does.
    app(CartService::class)->forget();

    return test()->withCredentials()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

/** @param list<Product> $products */
function reTogether(Cart $cart, array $products, ?Product $main, array $extra = [])
{
    $body = ['items' => array_map(fn (Product $p) => ['product_id' => $p->id], $products)];

    if ($main !== null) {
        $body['main_id'] = $main->id;
    }

    return reAs($cart)->postJson('/api/cart/add-together', array_merge($body, $extra));
}

function reTotals(Cart $cart): array
{
    $fresh = Cart::query()->find($cart->id);

    return app(CartService::class)->totals($fresh, 'AE');
}

function reFour(array $s): array
{
    return [$s['sun'], $s['moist'], $s['toner'], $s['oil']];
}

/* ═══════════════════════════ the settings ═══════════════════════════════ */

it('ships every tier at 0 (off) and coupons included; clamps a tier to 0–50 and stores nothing else', function () {
    /*
     * He asked for the OPTION and named no percentage, so nothing is cheaper
     * until he sets one. What a defect looks like on the shop: every bundle
     * discounted by a number nobody chose the moment the package lands.
     */
    $c = app(BuyTogetherSettings::class)->all();
    expect([$c['tier_3'], $c['tier_4'], $c['tier_5'], $c['coupons']])->toBe([0, 0, 0, true]);
    expect(app(BuyTogetherPricing::class)->tiers())->toBe([3 => 0, 4 => 0, 5 => 0, 6 => 0]);

    app(BuyTogetherSettings::class)->save(['on' => true, 'tier_3' => 80, 'tier_4' => -5, 'tier_5' => 'lots']);
    reFlush();
    $c = app(BuyTogetherSettings::class)->all();
    expect([$c['tier_3'], $c['tier_4'], $c['tier_5']])->toBe([50, 0, 0]);

    // A row written past save() (an import, a hand edit) is held to 50 too.
    app(SettingsService::class)->set('bt_tier_3', 80);
    reFlush();
    expect(app(BuyTogetherSettings::class)->all()['tier_3'])->toBe(50);

    // Through the admin endpoint too: an over-range value is clamped, an
    // unknown option refused with nothing written.
    test()->actingAs(AdminUser::create(['name' => 'O', 'email' => 're-'.Str::random(6).'@example.test', 'password' => Hash::make('secret-secret'), 'role' => 'owner']), 'admin');
    test()->postJson('/admin-api/product-page', ['together' => ['options' => ['tier_4' => 99, 'coupons' => false]]])->assertOk();
    reFlush();
    expect(app(BuyTogetherSettings::class)->all()['tier_4'])->toBe(50)
        ->and(app(BuyTogetherSettings::class)->all()['coupons'])->toBeFalse();
    test()->postJson('/admin-api/product-page', ['together' => ['options' => ['tier_6' => 10]]])->assertStatus(422);

    // 6 takes the 5 tier; under 3 is never a bundle; off is off.
    reOn(['tier_5' => 15]);
    expect(app(BuyTogetherPricing::class)->tiers())->toBe([3 => 5, 4 => 10, 5 => 15, 6 => 15])
        ->and(app(BuyTogetherPricing::class)->percentFor(2))->toBe(0);
    reOn(['on' => false]);
    expect(app(BuyTogetherPricing::class)->percentFor(4))->toBe(0);
});

it('draws the tiers card with a live preview in the admin tab', function () {
    $src = (string) file_get_contents(resource_path('views/admin/partials/product-buy-together-screen.blade.php'));

    expect($src)->toContain("id=\"btpTiers\"")
        ->toContain("['tier_3', 'tier_4', 'tier_5', 'coupons'].map(optRow)")
        ->toContain('id="btpPreview"')
        // the preview redraws as a tier slider moves, before anything is saved
        ->toContain("if (k === 'count' || /^tier_/.test(k)) paintPreview();")
        // and a tier is clamped to ITS bounds, not the count's 3–6
        ->toContain("k === 'count' ? clampCount(t.value) : clampRange(f, t.value)");
});

/* ═══════════════════════════ the product page ═══════════════════════════ */

it('prints four across with the fifth waiting, the struck total and the saving, and the tiers for the script', function () {
    $s = reShop();
    reOn(['count' => 5]);
    $html = (string) test()->get('/product/'.$s['sun']->slug.'/')->assertOk()->getContent();

    expect(preg_match('/<section class="kbb-fbt bt bt-more [^"]*"/', $html))->toBe(1)
        ->and($html)->toContain(';--bt-per:4;--bt-n:5"')
        ->toContain('data-tiers="{&quot;3&quot;:5,&quot;4&quot;:10,&quot;5&quot;:15,&quot;6&quot;:15}"');
    expect(substr_count($html, 'class="bt-card'))->toBe(5);

    // Five ticked at 15%: 69 + 108 + 80 + 95 + 60 = 412, regular 457.
    // Off: 69→58.65→58 (11) 108→91.80→91 (17) 80→68 (12) 95→80.75→80 (15) 60→51 (9) = 64.
    expect($html)->toContain('<span class="bt-total-label">Total:</span>')
        ->toContain('<span class="bt-was-num">457</span>')
        ->toContain('<span class="bt-num">348</span>')
        ->toContain('You&#039;re saving</span> <b>')
        ->toContain('<span class="bt-save-num">109</span>');

    // Four: no carousel class, and nothing peeks.
    reOn(['count' => 4]);
    Cache::flush();
    $four = (string) test()->get('/product/'.$s['sun']->slug.'/')->assertOk()->getContent();
    expect($four)->not->toContain('bt-more')->toContain(';--bt-per:4;--bt-n:4"');

    // Tiers off and nothing on sale: no struck figure, no pill.
    Product::query()->whereKey($s['moist']->id)->update(['sale_price' => null, 'price' => 10800]);
    reOn(['count' => 4, 'tier_3' => 0, 'tier_4' => 0, 'tier_5' => 0]);
    Cache::flush();
    $plain = (string) test()->get('/product/'.$s['sun']->slug.'/')->assertOk()->getContent();
    expect($plain)->toContain('<s class="bt-was" hidden >')->toContain('<p class="bt-save" aria-live="polite" hidden >');
});

it('keeps the price inside its card: scaled to the card, the struck price wrapping, the name clamped', function () {
    /*
     * "the text is going out the boxes": "AED 108 AED 153" was 75px of text in
     * a 60px card at 390 (docs/re-shots/MEASUREMENTS-before.json). The fix is
     * CSS, and these are the declarations it rests on; the browser
     * measurements at seven widths are in docs/re-shots/MEASUREMENTS-after.json.
     */
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));

    expect($css)->toContain('container-type:inline-size;')
        ->toContain('.bt-card .pr{display:flex;flex-wrap:wrap;align-items:baseline;column-gap:.32em;font-size:clamp(8px,15cqi,11.5px)}')
        ->toContain('.bt-card .pr > *{white-space:nowrap;min-width:0}')
        ->toContain('overflow-wrap:anywhere;')
        // four across: a quarter of the rail less three REAL gaps
        ->toContain('flex:0 0 calc((100% - var(--bt-gap) * (var(--bt-per,4) - 1)) / var(--bt-per,4));');
});

it('peeks at the fifth card once, on scroll, without measuring anything — and not at all under reduced motion', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));
    $js = (string) file_get_contents(resource_path('js/kbb/fbt.js'));

    // The slide: half a card and a gap less the rail's padding — the card
    // formula over again, as a share of the rail — run once, only below the
    // laptop layout, only with a fifth card. A logical margin, so the Arabic
    // row slides the other way with no direction rule.
    expect($css)->toContain('34%,60%{margin-inline-start:calc(14px - var(--bt-gap) - (100% - var(--bt-gap) * (var(--bt-per,4) - 1)) / var(--bt-per,4) / 2)}')
        ->toContain('.kbb-fbt.bt.bt-more.is-peek .bt-card:first-child{animation:btpeek 2.1s cubic-bezier(.33,.0,.2,1) .12s 1 both}')
        // reduced motion: no slide, a static half card instead
        ->toContain("@media (max-width:1023.98px) and (prefers-reduced-motion:reduce){\n    .kbb-fbt.bt.bt-more .bt-card{flex-basis:calc((100% - var(--bt-gap) * 3.5) / 4.5)}\n    .kbb-fbt.bt.bt-more.is-peek .bt-card:first-child{animation:none}");

    // The trigger is an IntersectionObserver adding one class; reduced motion
    // adds nothing; a thumb on the row cancels it.
    expect($js)->toContain('new IntersectionObserver(')
        ->toContain("block.classList.add('is-peek');")
        ->toContain("matchMedia('(prefers-reduced-motion: reduce)').matches) return;")
        ->toContain("['pointerdown', 'touchstart', 'wheel']")
        ->not->toMatch('/getBoundingClientRect|offsetWidth|offsetHeight|clientWidth|scrollWidth|ResizeObserver|scrollTo\(/');

    // The button's soft pink shadow, and none when it cannot be pressed.
    expect($css)->toContain('box-shadow:0 7px 16px -9px rgba(224,86,123,.55),0 2px 5px -3px rgba(224,86,123,.22);')
        ->toContain('.bt-buy:disabled{border-color:var(--line);color:var(--muted);cursor:default;box-shadow:none}');
});

it('works the live total out exactly as the basket will, in the shipped script', function () {
    $src = (string) file_get_contents(resource_path('js/kbb/fbt.js'));
    expect(preg_match('/export function unitOff\(unit, pct, exp\) \{.*?\n\}/s', $src, $off))->toBe(1);
    expect(preg_match('/export function bundleTotals\(items, tiers, exp\) \{.*?\n\}/s', $src, $tot))->toBe(1);

    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        test()->markTestSkipped('node is not installed here; the source pins above still ran.');
    }

    $units = [100, 999, 4550, 6900, 9500, 10800, 12345, 15300, 99999, 123456];
    $pcts = [0, 1, 5, 10, 12, 15, 33, 50];
    $js = str_replace('export function', 'function', $off[0]."\n".$tot[0])."\n"
        .'const u='.json_encode($units).',p='.json_encode($pcts).',out=[];'
        .'for(const a of u)for(const b of p)out.push(unitOff(a,b,2));'
        .'const t=bundleTotals([{now:6900,reg:6900},{now:10800,reg:15300},{now:8000,reg:8000},{now:9500,reg:9500}],{3:5,4:10,5:15,6:15},2);'
        .'const two=bundleTotals([{now:6900,reg:6900},{now:10800,reg:15300}],{3:5,4:10,5:15,6:15},2);'
        .'console.log(JSON.stringify({out,t,two}));';
    $file = storage_path('framework/testing/re-bundle-'.getmypid().'.js');
    file_put_contents($file, $js);
    $res = json_decode((string) shell_exec(escapeshellarg($node).' '.escapeshellarg($file)), true);
    @unlink($file);

    $php = [];
    foreach ($units as $a) {
        foreach ($pcts as $b) {
            $php[] = BuyTogetherPricing::unitOff($a, $b);
        }
    }

    expect($res['out'])->toBe($php);
    // Four ticked at 10%: was 397, pay 352 − 36 = 316, saving 81.
    expect($res['t'])->toBe(['was' => 39700, 'pay' => 31600, 'save' => 8100, 'pct' => 10]);
    // Two ticked: no tier, the saving is the sale alone.
    expect($res['two'])->toBe(['was' => 22200, 'pay' => 17700, 'save' => 4500, 'pct' => 0]);
});

/* ═══════════════════════════ the basket ═════════════════════════════════ */

it('groups what the button added, and prices it on the server in the cart, the drawer and the checkout', function () {
    $s = reShop();
    reOn();
    $cart = reCart();

    $res = reTogether($cart, reFour($s), $s['sun'])->assertOk();

    $lines = $cart->items()->orderBy('id')->get();
    expect($lines)->toHaveCount(4)
        ->and($lines->pluck('bt_group')->unique()->count())->toBe(1)
        ->and(strlen((string) $lines[0]->bt_group))->toBe(32)
        ->and($lines->pluck('bt_size')->unique()->all())->toBe([4]);

    $t = reTotals($cart);
    expect($t['subtotal'])->toBe(35200)
        ->and($t['bundle_discount'])->toBe(3600)
        ->and($t['discount'])->toBe(0)
        ->and($t['total'])->toBe(31600)
        ->and(array_column($t['bundle']['lines'], 'off'))->toBe([700, 1100, 800, 1000])
        ->and(array_unique(array_column($t['bundle']['lines'], 'percent')))->toBe([10]);

    // The drawer that came back with the press.
    expect($res->json('drawer'))->toContain('Bought together · 10% off')
        ->toContain('Buy-together discount')
        ->and(substr_count((string) $res->json('drawer'), 'class="kc-bt"'))->toBe(4);
    expect($res->json('total'))->toContain('316');

    $page = (string) reAs($cart)->get('/cart')->assertOk()->getContent();
    expect($page)->toContain('Buy-together discount')
        ->toContain('Bought together · 10% off')
        ->and(substr_count($page, 'class="cbt"'))->toBe(4);

    $co = (string) reAs($cart)->get('/checkout')->assertOk()->getContent();
    expect($co)->toContain('class="sumrow disc co-btrow"><span>Buy-together discount</span>')
        ->and(substr_count($co, 'class="co-bt"'))->toBe(4);
});

it('dissolves the bundle when one is removed, and when one is set to 0 — the rest at their own prices everywhere', function () {
    $s = reShop();
    reOn();
    $cart = reCart();
    reTogether($cart, reFour($s), $s['sun'])->assertOk();
    $lines = $cart->items()->orderBy('id')->get();

    $res = reAs($cart)->postJson('/api/cart/remove', ['item_id' => $lines[1]->id])->assertOk();

    expect($cart->items()->whereNotNull('bt_group')->count())->toBe(0)
        ->and(reTotals($cart)['bundle_discount'])->toBe(0)
        ->and(reTotals($cart)['total'])->toBe(6900 + 8000 + 9500)
        ->and($res->json('drawer'))->not->toContain('Bought together')->not->toContain('Buy-together discount');

    // Quantity 0 is the same as removing.
    $cart2 = reCart();
    reTogether($cart2, reFour($s), $s['sun'])->assertOk();
    $l2 = $cart2->items()->orderBy('id')->get();
    reAs($cart2)->postJson('/api/cart/update', ['item_id' => $l2[2]->id, 'quantity' => 0])->assertOk();
    expect($cart2->items()->whereNotNull('bt_group')->count())->toBe(0)
        ->and(reTotals($cart2)['bundle_discount'])->toBe(0);

    // A line taken out by anything else — SetStockReconciler deletes loose
    // rows directly — leaves an incomplete group, which no pass prices.
    $cart3 = reCart();
    reTogether($cart3, reFour($s), $s['sun'])->assertOk();
    $cart3->items()->where('product_id', $s['oil']->id)->delete();
    expect(reTotals($cart3)['bundle_discount'])->toBe(0);

    // And a product added back by the ordinary button does not complete it.
    reAs($cart2)->postJson('/api/cart/add', ['product_id' => $s['toner']->id])->assertOk();
    expect(reTotals($cart2)['bundle_discount'])->toBe(0);
});

it('takes one bundle per complete set: raising one line never deepens it, buying it twice does', function () {
    $s = reShop();
    reOn();
    $cart = reCart();
    reTogether($cart, reFour($s), $s['sun'])->assertOk();
    $lines = $cart->items()->orderBy('id')->get();

    reAs($cart)->postJson('/api/cart/update', ['item_id' => $lines[3]->id, 'quantity' => 3])->assertOk();
    $t = reTotals($cart);
    /*
     * One set: one unit of each line at the bundle price, the other two oils
     * at the oil's own. The bundle is taken off the price the basket charges
     * for the unit, so a quantity offer on the oil line (Appearance → bundle
     * tiers, if the shop has them on) comes first and the 10% is taken off
     * what is left — read back from the line rather than assumed.
     */
    $oil = (int) $cart->items()->whereKey($lines[3]->id)->value('unit_price');
    expect($t['bundle']['lines'][$lines[3]->id]['sets'])->toBe(1)
        ->and($t['bundle']['lines'][$lines[3]->id]['off'])->toBe(BuyTogetherPricing::unitOff($oil, 10))
        ->and($t['bundle_discount'])->toBe(700 + 1100 + 800 + BuyTogetherPricing::unitOff($oil, 10));

    foreach ($lines as $line) {
        reAs($cart)->postJson('/api/cart/update', ['item_id' => $line->id, 'quantity' => 2])->assertOk();
    }
    $want = 0;
    foreach ($cart->items()->get() as $line) {
        $want += BuyTogetherPricing::unitOff((int) $line->unit_price, 10) * 2;
    }
    expect(reTotals($cart)['bundle_discount'])->toBe($want)
        ->and(collect(reTotals($cart)['bundle']['lines'])->pluck('sets')->unique()->all())->toBe([2]);
});

it('cannot be forged: no group, no deeper percent, nothing for a product the section could not offer, a hidden or sold-out member', function () {
    $s = reShop();
    reOn();

    // 1. A percentage, a group handle and prices in the request are ignored.
    $cart = reCart();
    reTogether($cart, reFour($s), $s['sun'], ['percent' => 50, 'bt_group' => 'x', 'tier' => 5, 'price' => 1])->assertOk();
    expect(reTotals($cart)['bundle_discount'])->toBe(3600);
    expect($cart->items()->pluck('bt_group')->unique()->all())->not->toContain('x');

    // 2. No page named: added, not grouped.
    $cart = reCart();
    reTogether($cart, reFour($s), null)->assertOk();
    expect($cart->items()->count())->toBe(4)->and($cart->items()->whereNotNull('bt_group')->count())->toBe(0);

    // 3. A product the sunscreen's section could never have offered.
    $cart = reCart();
    reTogether($cart, [$s['sun'], $s['moist'], $s['toner'], $s['lone']], $s['sun'])->assertOk();
    expect($cart->items()->count())->toBe(4)->and($cart->items()->whereNotNull('bt_group')->count())->toBe(0);

    // 4. More products than the section shows.
    $cart = reCart();
    reTogether($cart, [...reFour($s), $s['mask']], $s['sun'])->assertOk();
    expect($cart->items()->whereNotNull('bt_group')->count())->toBe(0);

    // 5. Two products are not a bundle.
    $cart = reCart();
    reTogether($cart, [$s['sun'], $s['moist']], $s['sun'])->assertOk();
    expect($cart->items()->whereNotNull('bt_group')->count())->toBe(0);

    // 6. A member hidden, or sold out, after the press: no discount on the
    //    next pass — and back when it is back.
    $cart = reCart();
    reTogether($cart, reFour($s), $s['sun'])->assertOk();
    Product::query()->whereKey($s['oil']->id)->update(['is_visible' => false]);
    expect(reTotals($cart)['bundle_discount'])->toBe(0);
    Product::query()->whereKey($s['oil']->id)->update(['is_visible' => true, 'stock_status' => 'outofstock']);
    expect(reTotals($cart)['bundle_discount'])->toBe(0);
    Product::query()->whereKey($s['oil']->id)->update(['stock_status' => 'instock']);
    expect(reTotals($cart)['bundle_discount'])->toBe(3600);

    // 7. The tier is read from settings on every pass, never stored.
    reOn(['tier_4' => 0]);
    expect(reTotals($cart)['bundle_discount'])->toBe(0);
});

/* ═══════════════════════════ coupons ════════════════════════════════════ */

/** A bundle of four plus one ordinary line (the mask, AED 60), with a code applied. */
function reCouponCart(array $s, string $code): Cart
{
    $cart = reCart();
    reTogether($cart, reFour($s), $s['sun'])->assertOk();
    reAs($cart)->postJson('/api/cart/add', ['product_id' => $s['mask']->id])->assertOk();
    reAs($cart)->postJson('/api/cart/coupon', ['code' => $code])->assertOk();

    return $cart;
}

it('stacks a coupon AFTER the bundle under Include, and skips bundled units under Exclude', function () {
    /*
     * "and on top of it, the coupon can be apply. also giveo ption to include
     * exclude the coupon apply on the buy together products."
     *
     * Basket: the bundle (AED 352, −36 → 316) and the mask (AED 60).
     *   Include, 10%: 10% of 316 + 60 = 37.60 → AED 38 (rounded up, the
     *                 shop's coupon rule). On the full prices it would be 41.20.
     *   Exclude, 10%: 10% of the mask alone = AED 6.
     */
    $s = reShop();
    Coupon::create(['code' => 'GLOW10', 'type' => 'percent', 'amount' => 1000]);

    reOn(['coupons' => true]);
    $t = reTotals(reCouponCart($s, 'GLOW10'));
    expect([$t['subtotal'], $t['bundle_discount'], $t['discount'], $t['total']])->toBe([41200, 3600, 3800, 41200 - 3600 - 3800]);

    reOn(['coupons' => false]);
    $t = reTotals(reCouponCart($s, 'GLOW10'));
    expect([$t['bundle_discount'], $t['discount'], $t['total']])->toBe([3600, 600, 41200 - 3600 - 600]);
});

it('caps a fixed coupon at the non-bundled lines under Exclude, and counts the whole basket for a minimum spend', function () {
    $s = reShop();
    Coupon::create(['code' => 'SAVE20', 'type' => 'fixed_cart', 'amount' => 2000]);
    Coupon::create(['code' => 'BIG80', 'type' => 'fixed_cart', 'amount' => 8000]);
    // Minimum AED 400: the basket is AED 412 at its own prices and AED 376
    // after the bundle. The minimum is read on the basket BEFORE the bundle —
    // as the free-delivery bar is — so the bundle never takes a code away.
    Coupon::create(['code' => 'MIN400', 'type' => 'percent', 'amount' => 500, 'minimum_amount' => 40000]);

    reOn(['coupons' => true]);
    expect(reTotals(reCouponCart($s, 'SAVE20'))['discount'])->toBe(2000);
    // Include: a fixed code may use the bundled lines' reduced value too.
    expect(reTotals(reCouponCart($s, 'BIG80'))['discount'])->toBe(8000);
    $min = reCouponCart($s, 'MIN400');
    expect(Cart::find($min->id)->coupon_id)->not->toBeNull()
        ->and(reTotals($min)['discount'])->toBe(1900); // 5% of 376 = 18.80, rounded up

    reOn(['coupons' => false]);
    expect(reTotals(reCouponCart($s, 'SAVE20'))['discount'])->toBe(2000);
    // Exclude: the code is worth at most what the mask costs.
    expect(reTotals(reCouponCart($s, 'BIG80'))['discount'])->toBe(6000);

    // Exclude, and nothing but the bundle in the basket: the code is refused
    // in so many words rather than applied for AED 0.
    $only = reCart();
    reTogether($only, reFour($s), $s['sun'])->assertOk();
    $res = reAs($only)->postJson('/api/cart/coupon', ['code' => 'SAVE20']);
    expect($res->json('error'))->toBe('That code does not apply to anything in your basket.')
        ->and(Cart::find($only->id)->coupon_id)->toBeNull();
});

it('lets a coupon treat a dissolved bundle like any other lines, whatever the switch says', function () {
    $s = reShop();
    Coupon::create(['code' => 'GLOW10', 'type' => 'percent', 'amount' => 1000]);
    reOn(['coupons' => false]);

    $cart = reCouponCart($s, 'GLOW10');
    $moist = $cart->items()->where('product_id', $s['moist']->id)->first();
    reAs($cart)->postJson('/api/cart/remove', ['item_id' => $moist->id])->assertOk();

    // 69 + 80 + 95 + 60 = 304, all ordinary now: 10% = 30.40 → AED 31.
    $t = reTotals($cart);
    expect([$t['bundle_discount'], $t['discount'], $t['total']])->toBe([0, 3100, 30400 - 3100]);
});

/* ═══════════════════════════ the order and the payment ══════════════════ */

it('records on the order what was charged and why, and the payment providers are sent the order total', function () {
    PaymentProvider::query()->delete();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(SettingsService::class)->set('cod_fee', 0);
    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard', 'cost' => 2000, 'enabled' => true, 'position' => 0]);

    $s = reShop();
    Coupon::create(['code' => 'GLOW10', 'type' => 'percent', 'amount' => 1000]);
    reOn(['coupons' => true]);
    $cart = reCouponCart($s, 'GLOW10');

    reAs($cart)->post('/checkout/place', [
        'billing_email' => 'bundle@example.com', 'billing_phone' => '+971500000000',
        'billing_first_name' => 'Aisha', 'billing_last_name' => 'Khan', 'billing_address_1' => '12 Marina Walk',
        'billing_city' => 'Dubai', 'billing_state' => 'Dubai', 'billing_country' => 'AE', 'payment_method' => 'cod',
    ])->assertRedirect();

    $order = Order::query()->latest('id')->firstOrFail();
    expect([(int) $order->subtotal, (int) $order->bundle_discount, (int) $order->discount_total])->toBe([41200, 3600, 3600 + 3800])
        ->and((int) $order->total)->toBe(41200 - 7400 + (int) $order->shipping_total + (int) $order->fee_total + ((int) $order->tax_total > 0 && $order->tax_basis === 'exclusive' ? (int) $order->tax_total : 0));

    $items = $order->items()->orderBy('id')->get();
    expect($items->pluck('bundle_discount')->all())->toBe([700, 1100, 800, 1000, 0])
        ->and($items->pluck('bundle_percent')->all())->toBe([10, 10, 10, 10, null])
        ->and($items->whereNotNull('bundle_group')->pluck('bundle_group')->unique()->count())->toBe(1);
    // The coupon's redemption spends the coupon alone.
    expect((int) \App\Models\CouponRedemption::where('order_id', $order->id)->value('amount'))->toBe(3800);

    // OrderTax's base is subtotal − discount_total + delivery, the same sum the
    // checkout taxed.
    expect(\App\Support\OrderTax::base($order))->toBe(41200 - 7400 + (int) $order->shipping_total);

    // Tabby: amount = items + shipping + tax − discount, to the fil.
    $tabby = app(\App\Services\Payments\Gateways\TabbyGateway::class);
    $obj = (new ReflectionMethod($tabby, 'paymentObject'))->invoke($tabby, $order->fresh('items'), 'AED');
    $items = array_sum(array_map(fn ($i) => (float) $i['unit_price'] * $i['quantity'], $obj['order']['items']));
    expect(round($items + (float) $obj['order']['shipping_amount'] + (float) $obj['order']['tax_amount'] - (float) $obj['order']['discount_amount'], 2))
        ->toBe(round((float) $obj['amount'], 2))
        ->and(round((float) $obj['amount'] * 100))->toBe((float) $order->total);

    // Tamara: the amounts block balances to the order total.
    $tamara = app(\App\Services\Payments\Gateways\TamaraGateway::class);
    $a = (new ReflectionMethod($tamara, 'amounts'))->invoke($tamara, $order->fresh('items'), (int) $order->total, 'AED');
    expect($a['items_fils'] + $a['shipping'] + $a['tax'] - $a['discount'])->toBe((int) $order->total)
        ->and($a['discount'])->toBe(7400);

    // The receipt the shopper is shown splits the two discounts.
    $html = view('partials.checkout.received-summary', ['order' => $order->fresh('items'), 'settings' => app(SettingsService::class)])->render();
    expect($html)->toContain('<span>Buy-together discount</span><span>&ndash; ')
        ->toContain('Discount (GLOW10)');
});
