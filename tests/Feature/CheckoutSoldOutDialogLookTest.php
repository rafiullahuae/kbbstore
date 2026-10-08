<?php

/**
 * The sold-out dialog, as the owner redesigned it on 8 October (Lane CO2):
 *
 *   "on the popup, the product image should also come, and black color title,
 *    also reduce the font size little bit, and then is soldout in red. also the
 *    text, your bag is updated etc. that should be a nice frosted glass type
 *    long capsule type box, and with right corner green tick icon, half outside
 *    the box."
 *
 * The dialog is built in the browser (resources/js/kbb/checkout.js) from the
 * JSON place() answers, so the server half is pinned here through that answer
 * and the browser half through the BUILT bundle the shop actually runs. The
 * pictures are in docs/lane-co2-shots/.
 */

use App\Models\Cart;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Models\Translation;
use App\Services\CartService;
use App\Services\SettingsService;
use App\Services\StockSetRule;
use App\Services\Translation\TranslationStore;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;

beforeEach(function () {
    PaymentProvider::query()->delete();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);

    app(SettingsService::class)->set(StockSetRule::KEY, StockSetRule::MODE_MEMBERS);
    SettingsService::forgetMemo();

    $GLOBALS['cdlFiles'] = [];
});

afterEach(function () {
    foreach ($GLOBALS['cdlFiles'] ?? [] as $file) {
        @unlink($file);
    }
});

/** A product photograph with (or without) its 200 px img-cache copy on disk. */
function cdlPicture(bool $withCopy): string
{
    $name = 'cdl-' . Illuminate\Support\Str::random(10) . '.jpg';
    $original = public_path('uploads/' . $name);
    @mkdir(dirname($original), 0777, true);
    file_put_contents($original, 'full-size');
    $GLOBALS['cdlFiles'][] = $original;

    if ($withCopy) {
        $copy = public_path('img-cache/200/uploads/' . $name);
        @mkdir(dirname($copy), 0777, true);
        file_put_contents($copy, 'small');
        $GLOBALS['cdlFiles'][] = $copy;
    }

    return '/uploads/' . $name;
}

function cdlProduct(string $name, array $attributes = []): Product
{
    return Product::create(array_merge([
        'slug' => 'cdl-' . uniqid(), 'name' => $name, 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'price' => 9000, 'stock_status' => 'instock',
    ], $attributes));
}

function cdlCart(array $products): Cart
{
    $cart = Cart::create([
        'token' => (string) Illuminate\Support\Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);

    foreach ($products as $product) {
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 9000]);
    }

    return $cart;
}

function cdlPlace(Cart $cart, string $prefix = '')
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->postJson($prefix . '/checkout/place', [
            'billing_email' => 'buyer@example.com', 'billing_phone' => '+971500000000',
            'billing_first_name' => 'Aisha', 'billing_last_name' => 'Khan',
            'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai', 'billing_state' => 'Dubai',
            'billing_country' => 'AE', 'payment_method' => 'cod',
        ]);
}

it('sends each sold-out line as a small picture, the name, and the red words around it', function () {
    /*
     * The owner's popup: picture, product name in black, "is sold out" in red.
     * The server sends the pieces; the script paints `name` in ink and
     * `before`/`after` in the sale red.
     *
     * MUTATION: send `text` alone again (drop name/before/after) → red; send
     * the original photograph when no copy exists → `img` is the full-size
     * /uploads/ path and the second line goes red.
     */
    $jelly = cdlProduct('fwee - Lip&Cheek Glowy Jelly Pot - Compote', ['stock_status' => 'outofstock', 'image' => cdlPicture(true)]);
    $toner = cdlProduct('Heartleaf Toner', ['stock_status' => 'outofstock', 'image' => cdlPicture(false)]);
    $cart = cdlCart([cdlProduct('Glow Serum'), $jelly, $toner]);

    $answer = cdlPlace($cart)->assertStatus(422)->assertJsonPath('code', 'sold_out');
    $lines = collect($answer->json('lines'))->keyBy('name');

    expect($lines->keys()->all())->toBe(['fwee - Lip&Cheek Glowy Jelly Pot - Compote', 'Heartleaf Toner']);

    $first = $lines['fwee - Lip&Cheek Glowy Jelly Pot - Compote'];

    expect($first['before'])->toBe('')
        ->and($first['after'])->toBe(' is sold out')
        ->and($first['text'])->toBe('fwee - Lip&Cheek Glowy Jelly Pot - Compote is sold out')
        ->and($first['img'])->toStartWith('/img-cache/200/uploads/cdl-')
        // A photograph with no small copy is NOT sent at full size: the 44 px
        // box gets a plain tile instead (CLAUDE.md: no bare full-size src).
        ->and($lines['Heartleaf Toner']['img'])->toBeNull();
});

it('names a set component with its set, the way the refusal does', function () {
    $member = cdlProduct('Heartleaf Toner', ['manage_stock' => true, 'stock' => 0, 'image' => cdlPicture(true)]);
    $set = Product::create([
        'slug' => 'cdl-set-' . uniqid(), 'name' => 'Glow Starter Set', 'type' => 'set', 'status' => 'publish',
        'is_visible' => true, 'price' => 19900, 'stock_status' => 'instock', 'manage_stock' => false,
        'image' => cdlPicture(true),
    ]);
    ProductSetItem::create(['set_product_id' => $set->id, 'member_product_id' => $member->id, 'quantity' => 1, 'position' => 0]);

    $answer = cdlPlace(cdlCart([cdlProduct('Glow Serum'), $set]))->assertStatus(422);

    expect($answer->json('lines'))->toHaveCount(1)
        ->and($answer->json('lines.0.name'))->toBe('Heartleaf Toner (in Glow Starter Set)')
        ->and($answer->json('lines.0.after'))->toBe(' is sold out')
        // The basket line's own picture: the SET's, the row the shopper sees.
        ->and($answer->json('lines.0.img'))->toBe(\App\Support\ImageVariants::variantUrl($set->image, 200));
});

it('puts the red words where Arabic puts them, from the one translated sentence', function () {
    /*
     * "نفدت كمية :name" — in Arabic the status comes FIRST. The template is
     * split around its own :name, so `before` carries it and nothing in the
     * script assembles a sentence. MUTATION: hardcode before='' → red.
     */
    ArabicShop::on();
    DB::table('translations')->where('locale', 'ar')->where('group', Translation::GROUP_UI)
        ->where('item_id', 0)->where('field', 'store.checkout.so_line_gone')
        ->update(['status' => Translation::STATUS_PUBLISHED]);
    TranslationStore::flush();

    $cart = cdlCart([cdlProduct('Glow Serum'), cdlProduct('Heartleaf Toner', ['stock_status' => 'outofstock'])]);

    $answer = cdlPlace($cart, '/ar')->assertStatus(422);

    expect($answer->json('lines.0.before'))->toBe('نفدت كمية ')
        ->and($answer->json('lines.0.name'))->toBe('Heartleaf Toner')
        ->and($answer->json('lines.0.after'))->toBe('');
});

it('reads the pictures off the basket it already loaded: no query for them', function () {
    /*
     * soldOutThumb() is handed a line loadCart() has already loaded (product
     * and variant, image columns included) and must not go back to the
     * database for it — a query per sold-out line is the N+1 CLAUDE.md
     * forbids. MUTATION: look the image up with Product::find($item->
     * product_id) → one query per line and this goes red.
     */
    $cart = cdlCart([
        cdlProduct('A', ['image' => cdlPicture(true)]),
        cdlProduct('B', ['image' => cdlPicture(false)]),
        cdlProduct('C'),
    ]);
    $cart->load(['items.product', 'items.variant']);

    $thumb = new ReflectionMethod(\App\Http\Controllers\Store\CheckoutController::class, 'soldOutThumb');
    $controller = app(\App\Http\Controllers\Store\CheckoutController::class);

    $queries = 0;
    DB::listen(function () use (&$queries) { $queries++; });

    $out = $cart->items->map(fn ($item) => $thumb->invoke($controller, $item))->all();

    expect($queries)->toBe(0)
        ->and($out[0])->toStartWith('/img-cache/200/uploads/')
        ->and($out[1])->toBeNull()
        ->and($out[2])->toBeNull();
});

it('paints the dialog the owner asked for, in the bundle the shop runs', function () {
    /*
     * The browser half, read from the BUILT bundle (a source change with a
     * stale public/build never reaches a shopper):
     *   - the picture is an <img> with width and height (no layout shift) and
     *     only from this origin;
     *   - the name in the shop's ink, the status in the sale red;
     *   - the confirmation is the frosted capsule — the footer capsule's glass,
     *     a solid fallback without backdrop-filter — with the green tick half
     *     outside its END corner (logical, so Arabic mirrors it), in a polite
     *     live region; the old dark toast is gone from this path.
     * MUTATION: put window.kbbToast?.(words.done) back → red.
     */
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
    $bundle = file_get_contents(public_path('build/' . $manifest['resources/js/kbb/app.js']['file']));

    expect($bundle)->toContain('.width=44')
        ->toContain('.height=44')
        ->toContain('.kbb-so li b{font-weight:600;color:var(--ink,#2A2228)}')
        ->toContain('.kbb-so-r{color:var(--sale,#E23A4E);font-weight:600}')
        ->toContain('font-size:13px;line-height:1.4')
        ->toContain('border-radius:999px')
        ->toContain('backdrop-filter:blur(12px) saturate(1.4)')
        ->toContain('@supports not ((backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px))){.kbb-so-cap{background:#fff}}')
        ->toContain('.kbb-so-cap i{position:absolute;top:-14px;inset-inline-end:10px;width:26px;height:26px;border-radius:50%;background:var(--green,#2E9E6B)')
        ->toContain('"aria-live","polite"')
        ->toContain('kbbSoNote')
        ->not->toContain('kbbToast?.(words.done)');

    $src = file_get_contents(resource_path('js/kbb/checkout.js'));

    expect($src)->not->toContain('window.kbbToast?.(words.done)')
        ->and($src)->toContain('soNotice(words.done)')
        ->and($src)->not->toMatch('/getBoundingClientRect|offsetWidth|offsetHeight|clientWidth/');
});
