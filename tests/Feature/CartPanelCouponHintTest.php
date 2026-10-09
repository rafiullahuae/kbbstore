<?php

declare(strict_types=1);

/*
 * Appearance → Cart panel → Coupon hint (Lane QK3).
 *
 * The owner, on a screenshot of the cart panel with a red line above
 * "Subtotal": "I also need a small text line, Need Discount? Use coupon code
 * {coupon-code} on checkout. can be editable and coupon can be selectable by me
 * on backend. keep this in cart panel settings. also same for mobile." And
 * then: "by default, coupon: glow should be there."
 *
 * The panel is on every shop page, so the line is read from a settings
 * snapshot of the chosen coupon and never from the coupons table: a page with
 * the line costs exactly the queries a page without it costs.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Product;
use App\Services\CartPanel;
use App\Services\CartService;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ArabicShop;

beforeEach(function () {
    SettingsService::forgetMemo();
});

function qkOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'QK '.$role, 'email' => 'qk-'.$role.'-'.Str::random(8).'@example.test',
        'password' => bcrypt('secret'), 'role' => $role,
    ]);
}

function qkCoupon(array $attrs = []): Coupon
{
    return Coupon::create(array_merge(['code' => 'GLOW', 'type' => 'percent', 'amount' => 1000], $attrs));
}

function qkCart(): Cart
{
    $product = Product::create([
        'slug' => 'qk-'.uniqid(), 'name' => 'Hint Serum', 'status' => 'publish',
        'is_visible' => true, 'price' => 5000, 'stock_status' => 'instock',
    ]);

    $cart = Cart::create(['token' => bin2hex(random_bytes(16)), 'status' => 'active', 'currency' => 'AED', 'shipping_country' => 'AE']);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 5000]);

    return $cart;
}

/** A shop page with something in the bag, so the panel draws its footer. */
function qkPage(Cart $cart, string $path = '/'): string
{
    SettingsService::forgetMemo();

    return (string) test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get($path)->assertOk()->getContent();
}

function qkChoose(Coupon|int $coupon): void
{
    app(CartPanel::class)->save(['coupon_id' => (string) ($coupon instanceof Coupon ? $coupon->id : $coupon)]);
    SettingsService::forgetMemo();
}

it('ships on, with his sentence, and with no coupon chosen draws nothing', function () {
    /*
     * MUTATION: `coupon_on` default -> false and the first expectation is red.
     * Remove the `(int) $c['coupon_id'] <= 0` guard in couponLine() and the
     * page carries an empty-coded line — red on the last one.
     */
    $c = app(CartPanel::class)->all();

    expect($c['coupon_on'])->toBeTrue()
        ->and($c['coupon_id'])->toBe('0')
        ->and($c['txt_coupon'])->toBe('Need Discount? Use coupon code {coupon-code} on checkout');

    qkCoupon(); // exists, but nobody chose it

    expect(qkPage(qkCart()))->not->toContain('kc-cch')->not->toContain('data-kccopy');
});

it('prints the line above Subtotal with the code as a tap-to-copy pill', function () {
    $coupon = qkCoupon();
    qkChoose($coupon);

    $html = qkPage(qkCart());

    expect($html)->toContain('<div class="kc-cch">Need Discount? Use coupon code <button type="button" class="kc-cc" data-kccopy="GLOW" data-done="Copied">GLOW</button> on checkout</div>');

    // In the footer, immediately before the Subtotal row — the spot he marked.
    $line = strpos($html, 'class="kc-cch"');
    $sub = strpos($html, '<div class="sumrow tot">');
    expect($line)->toBeLessThan($sub)
        ->and(substr_count($html, 'class="kc-cch"'))->toBe(1)
        ->and(substr($html, $line, $sub - $line))->toContain('</div>')
        ->and(preg_match('/kc-cch.*?<\/div><div class="sumrow tot">/s', $html))->toBe(1);
});

it('hides when switched off', function () {
    // MUTATION: drop `! $c['coupon_on'] ||` from couponLine() and this is red.
    qkChoose(qkCoupon());
    app(CartPanel::class)->save(['coupon_on' => false]);

    expect(qkPage(qkCart()))->not->toContain('kc-cch');
});

it('hides when the coupon is expired, not started, used up or deleted — snapshot refreshed on coupon save', function () {
    /*
     * The panel never reads the coupons table, so each of these is only true
     * because Coupon::booted() re-takes the snapshot on save/delete.
     * MUTATION, RUN: remove the `static::saved` hook and the expiry case is red —
     * the snapshot still says "no expiry" and the line keeps advertising a
     * code the checkout refuses.
     */
    $coupon = qkCoupon();
    qkChoose($coupon);
    $cart = qkCart();

    expect(qkPage($cart))->toContain('data-kccopy="GLOW"');

    $coupon->update(['expires_at' => now()->subDay()]);
    expect(qkPage($cart))->not->toContain('kc-cch');

    $coupon->update(['expires_at' => null, 'starts_at' => now()->addDay()]);
    expect(qkPage($cart))->not->toContain('kc-cch');

    $coupon->update(['starts_at' => null, 'usage_limit' => 3, 'usage_count' => 3]);
    expect(qkPage($cart))->not->toContain('kc-cch');

    $coupon->update(['usage_limit' => null]);
    expect(qkPage($cart))->toContain('data-kccopy="GLOW"');

    // The clock alone: a snapshot whose expiry has since passed hides the line
    // with no write at all.
    $this->travel(2)->days();
    $coupon->update(['expires_at' => now()->addDay()]);
    expect(qkPage($cart))->toContain('data-kccopy="GLOW"');
    $this->travel(2)->days();
    expect(qkPage($cart))->not->toContain('kc-cch');
    $this->travelBack();

    $coupon->delete();
    expect(qkPage($cart))->not->toContain('kc-cch');
});

it('hides once a redemption uses the coupon up, through the query-builder count', function () {
    /*
     * CouponService moves usage_count with ->increment(), which fires no model
     * event. MUTATION, RUN: remove `$this->usageMoved(...)` after the increment in
     * recordRedemption() and this is red — the line keeps offering a code that
     * has just been fully redeemed.
     */
    $coupon = qkCoupon(['usage_limit' => 1]);
    qkChoose($coupon);
    $cart = qkCart();

    expect(qkPage($cart))->toContain('data-kccopy="GLOW"');

    DB::transaction(fn () => app(App\Services\CouponService::class)->recordRedemption($coupon, 100, null, null, 'a@example.test'));

    expect(qkPage($cart))->not->toContain('kc-cch');
});

it('replaces the token and escapes both the wording and the code', function () {
    /*
     * MUTATION, RUN (the attribute half): print `$code` instead of `e($code)` into the pill and the raw
     * <script> reaches the page; print `$text` instead of `e($text)` and the
     * wording's tag does.
     */
    $coupon = qkCoupon(['code' => 'X<script>alert(1)</script>']);
    qkChoose($coupon);
    app(CartPanel::class)->save(['txt_coupon' => '<b>Hi</b> {coupon-code} now']);

    $html = qkPage(qkCart());

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->not->toContain('<b>Hi</b>')
        ->and($html)->toContain('&lt;b&gt;Hi&lt;/b&gt; <button type="button" class="kc-cc" data-kccopy="X&lt;script&gt;alert(1)&lt;/script&gt;"');
});

it('appends the code when the wording has no token, and caps the wording at 120 characters', function () {
    // MUTATION: drop the `str_contains(... COUPON_TOKEN)` branch and the code
    // never appears in a sentence that does not name it.
    qkChoose(qkCoupon());
    app(CartPanel::class)->save(['txt_coupon' => 'Save today with']);

    expect(qkPage(qkCart()))->toContain('<div class="kc-cch">Save today with <button type="button" class="kc-cc" data-kccopy="GLOW"');

    app(CartPanel::class)->save(['txt_coupon' => str_repeat('a', 300)]);
    SettingsService::forgetMemo();
    expect(mb_strlen(app(CartPanel::class)->all()['txt_coupon']))->toBe(120);
});

it('says it in Arabic on /ar/', function () {
    // MUTATION, RUN: drop the `$ar ? $c['txt_coupon_ar']` branch and /ar/ prints
    // the English sentence.
    ArabicShop::on();
    qkChoose(qkCoupon());

    $html = qkPage(qkCart(), '/ar/');

    expect($html)->toContain('<div class="kc-cch">تحتاج خصمًا؟ استخدم كود الخصم <button type="button" class="kc-cc" data-kccopy="GLOW"')
        ->and($html)->not->toContain('Need Discount?');

    // An emptied Arabic box falls back to the English wording.
    app(CartPanel::class)->save(['txt_coupon_ar' => '']);
    expect(qkPage(qkCart(), '/ar/'))->toContain('Need Discount? Use coupon code <button');
});

it('stores only a real coupon id, and lists the shop coupons usable first', function () {
    /*
     * MUTATION, RUN: remove the `exists()` check in CartPanel::save() and the bogus
     * id is stored as chosen.
     */
    $old = qkCoupon(['code' => 'AAOLD', 'expires_at' => now()->subMonth()]);
    $glow = qkCoupon(['code' => 'GLOW']);

    test()->actingAs(qkOwner(), 'admin')
        ->postJson('/admin-api/cart-panel', ['settings' => ['coupon_id' => '999999']])->assertOk();
    SettingsService::forgetMemo();
    expect(app(CartPanel::class)->all()['coupon_id'])->toBe('0');

    test()->postJson('/admin-api/cart-panel', ['settings' => ['coupon_id' => '"><script>']])->assertOk();
    SettingsService::forgetMemo();
    expect(app(CartPanel::class)->all()['coupon_id'])->toBe('0');

    test()->postJson('/admin-api/cart-panel', ['settings' => ['coupon_id' => (string) $glow->id]])->assertOk();
    SettingsService::forgetMemo();
    expect(app(CartPanel::class)->all()['coupon_id'])->toBe((string) $glow->id);

    $body = test()->getJson('/admin-api/cart-panel')->assertOk()->json();

    // Usable first, even though AAOLD sorts first by code.
    expect(array_column($body['coupons'], 'code'))->toBe(['GLOW', 'AAOLD'])
        ->and($body['coupons'][0]['label'])->toBe('GLOW · 10% off · no expiry')
        ->and($body['coupons'][0]['usable'])->toBeTrue()
        ->and($body['coupons'][1]['usable'])->toBeFalse()
        ->and($body['coupons'][1]['label'])->toStartWith('AAOLD · 10% off · expired ');

    $tab = collect($body['tabs'])->firstWhere('key', 'coupon');
    expect(array_column($tab['fields'], 'key'))->toBe(['coupon_on', 'coupon_id', 'txt_coupon', 'txt_coupon_ar']);
});

it('keeps the setting behind the cart panel capability', function () {
    // The coupon controls ride the existing endpoint; a support account can
    // neither read the list nor choose a coupon.
    expect(collect(AdminCapabilities::RULES)->contains(['*', 'admin-api/cart-panel', 'content.manage']))->toBeTrue();

    $glow = qkCoupon();

    test()->postJson('/admin-api/cart-panel', ['settings' => ['coupon_id' => (string) $glow->id]])->assertStatus(401);

    test()->actingAs(qkOwner('support'), 'admin')
        ->postJson('/admin-api/cart-panel', ['settings' => ['coupon_id' => (string) $glow->id]])->assertForbidden();
    test()->getJson('/admin-api/cart-panel')->assertForbidden();

    SettingsService::forgetMemo();
    expect(app(CartPanel::class)->all()['coupon_id'])->toBe('0');
});

it('costs no query on a shop page: with the line and without it, the count is the same', function () {
    /*
     * MUTATION: read the coupon in couponLine() (Coupon::find($id)) instead of
     * the snapshot and the page with the line is one query dearer — red.
     */
    $cart = qkCart();
    $glow = qkCoupon();
    qkPage($cart); // warm the compiled views

    $queries = [];
    DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });

    $count = function () use ($cart, &$queries) {
        qkPage($cart); // the first render after a settings write re-reads the cold cache
        $queries = [];
        $html = qkPage($cart);

        return [count($queries), str_contains($html, 'kc-cch'), $queries];
    };

    [$without, $shown, $before] = $count();
    expect($shown)->toBeFalse();

    qkChoose($glow);
    [$with, $shown, $after] = $count();
    expect($shown)->toBeTrue()->and($with)->toBe($without)->and($after)->toBe($before)
        // and not one of them touches the coupons table
        ->and(collect($after)->filter(fn ($sql) => str_contains($sql, 'coupons'))->all())->toBe([]);
});

it('chooses GLOW by default when the shop has one, matched case-insensitively', function () {
    /*
     * The data migration, run against a shop that has a lower-case `glow`
     * (WooCommerce stores codes lower-case). It prints that stored code.
     * MUTATION: match `'GLOW'` with a case-sensitive where() and this is red.
     */
    DB::table('settings')->where('key', 'like', 'cartpanel_coupon%')->delete();
    $glow = qkCoupon(['code' => 'glow']);

    (require database_path('migrations/2027_10_15_170000_cart_panel_coupon_hint_glow.php'))->up();
    SettingsService::forgetMemo();

    expect(app(CartPanel::class)->all()['coupon_id'])->toBe((string) $glow->id)
        ->and(qkPage(qkCart()))->toContain('Use coupon code <button type="button" class="kc-cc" data-kccopy="glow" data-done="Copied">glow</button> on checkout');

    // A choice already made — including "none" — is left alone on a re-run.
    app(CartPanel::class)->save(['coupon_id' => '0']);
    (require database_path('migrations/2027_10_15_170000_cart_panel_coupon_hint_glow.php'))->up();
    SettingsService::forgetMemo();
    expect(app(CartPanel::class)->all()['coupon_id'])->toBe('0');
});

it('leaves the choice unset when the shop has no GLOW coupon', function () {
    DB::table('settings')->where('key', 'like', 'cartpanel_coupon%')->delete();
    qkCoupon(['code' => 'OTHER']);

    (require database_path('migrations/2027_10_15_170000_cart_panel_coupon_hint_glow.php'))->up();

    expect(DB::table('settings')->where('key', 'cartpanel_coupon_id')->exists())->toBeFalse();
    expect(qkPage(qkCart()))->not->toContain('kc-cch');
});

it('copies in cart.js inside the click, with a selection fallback and no layout reads', function () {
    $js = (string) file_get_contents(resource_path('js/kbb/cart.js'));

    expect($js)->toContain("event.target.closest('[data-kccopy]')")
        ->and($js)->toContain('navigator.clipboard.writeText(cc.dataset.kccopy)')
        ->and($js)->toContain('range.selectNodeContents(cc)')
        ->and($js)->not->toContain('getBoundingClientRect');

    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    // The "Copied" note is positioned out of flow, so copying moves nothing.
    expect($css)->toContain('.kc-cc.is-done::after{content:attr(data-done);position:absolute;');
});
