<?php

declare(strict_types=1);

/*
 * Lane DS: the guest basket survives the domain forward.
 *
 * The owner, 8 October 2026: "make sure, no any data or settings should be
 * gone after domain switch." A cookie belongs to the domain that set it, so
 * the day extrabeauty.ae starts forwarding, a shopper with a basket on it
 * landed on kbeautybliss.com with an EMPTY basket -- measured before this
 * change: the forward was a bare 301 and nothing crossed. These pin the
 * handoff that carries it, and every way it must refuse.
 */

use App\Models\Product;
use App\Models\Setting;
use App\Services\CartService;
use App\Services\DomainMove\DomainHandoff;
use App\Services\SettingsService;
use App\Support\SiteHost;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const DS_CHROME = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

/** Forwarding ON: kbeautybliss.com main, extrabeauty.ae old -- step 10 of the wizard done. */
function dsForwarding(bool $on = true): void
{
    config(['app.url' => 'https://kbeautybliss.com']);

    foreach ([SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae',
        SiteHost::KEY_REDIRECT => $on ? '1' : '0', SiteHost::KEY_VISIBILITY => 'public'] as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    app(SettingsService::class)->flush();
    Setting::flushMap();
    SiteHost::forget();
}

/** An active basket with one line; a guest's unless a customer id is given. */
function dsCart(?int $customer = null): array
{
    static $n = 0;
    $n++;
    $product = Product::query()->create(['name' => 'DS toner '.$n, 'slug' => 'ds-toner-'.$n, 'price' => 4500, 'status' => 'published', 'stock_status' => 'instock']);
    $token = (string) Str::uuid();
    $id = (int) DB::table('carts')->insertGetId(['token' => $token, 'customer_id' => $customer, 'status' => 'active',
        'last_activity_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('cart_items')->insert(['cart_id' => $id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 4500, 'created_at' => now(), 'updated_at' => now()]);

    return [$id, $token, $product->id];
}

/** The headers a phone's browser sends when a shopper taps a link. */
function dsBrowser(array $extra = []): array
{
    return $extra + [
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'User-Agent' => DS_CHROME,
        'Sec-Fetch-Mode' => 'navigate',
        'Sec-Fetch-Dest' => 'document',
    ];
}

/** name => decrypted value, from a response's Set-Cookie headers. */
function dsSetCookies(\Illuminate\Testing\TestResponse $r): array
{
    $out = [];

    foreach ($r->baseResponse->headers->getCookies() as $cookie) {
        $out[$cookie->getName()] = CookieValuePrefix::remove(Crypt::decrypt((string) $cookie->getValue(), false));
    }

    return $out;
}

beforeEach(function () {
    Cache::flush();
    dsForwarding();
});

it('carries a guest basket from the old address to the new one, once, and strips the token', function () {
    /* MUTATION: return null at the top of DomainHandoff::forward() -> the
       first request is the bare 301 and there is no token to follow, which
       is the empty basket the shopper used to land on. */
    [, $token] = dsCart();

    $first = $this->withCookie(CartService::COOKIE, $token)
        ->get('https://extrabeauty.ae/collections/toners/?sort=new', dsBrowser());

    $first->assertStatus(302);
    $location = (string) $first->headers->get('Location');
    expect($location)->toStartWith('https://kbeautybliss.com/collections/toners/?sort=new&kbb_handoff=')
        ->and($first->headers->get('Cache-Control'))->toContain('no-store')
        ->and($first->headers->get('Referrer-Policy'))->toBe('no-referrer');

    // The new domain: a fresh browser, no cookies of its own.
    $this->flushHeaders();
    $this->defaultCookies = [];
    $landed = $this->get($location, dsBrowser());

    /* MUTATION: drop the hash_equals on the MAC in verify() -> still passes
       here, but the forgery case below goes red. Return the response before
       cookiesFor() -> no kbb_cart here. */
    $landed->assertStatus(302)->assertHeader('Location', 'https://kbeautybliss.com/collections/toners/?sort=new');
    expect($landed->headers->get('Cache-Control'))->toContain('no-store')
        ->and(dsSetCookies($landed))->toHaveKey(CartService::COOKIE)
        ->and(dsSetCookies($landed)[CartService::COOKIE])->toBe($token);

    // REPLAY: the same URL again -- the same clean redirect, no basket.
    /* MUTATION: drop the Cache::add single-use check -> this restores the
       basket a second time, to whoever holds the URL. */
    $again = $this->get($location, dsBrowser());
    $again->assertStatus(302)->assertHeader('Location', 'https://kbeautybliss.com/collections/toners/?sort=new');
    expect(dsSetCookies($again))->toBe([]);
});

it('gives search engines, assets and cookie-less visitors exactly the 301 they always got', function () {
    /* MUTATION: drop the BOT check or the Accept/Sec-Fetch checks in
       isShopperNavigation() -> one of these answers 302 with a token, which
       would weaken the permanent-move signal and put a one-time URL in a
       crawler's queue. */
    [, $token] = dsCart();

    $cases = [
        'Googlebot with a cookie' => [dsBrowser(['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']), '/product/x/', true],
        'Bingbot' => [dsBrowser(['User-Agent' => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)']), '/product/x/', true],
        'a picture' => [dsBrowser(['Accept' => 'image/avif,image/webp,*/*', 'Sec-Fetch-Dest' => 'image', 'Sec-Fetch-Mode' => 'no-cors']), '/storage/p/serum.jpg', true],
        'a file address' => [dsBrowser(), '/sitemap.xml', true],
        'a prefetch' => [dsBrowser(['Sec-Purpose' => 'prefetch']), '/product/x/', true],
        'a shopper with no basket' => [dsBrowser(), '/product/x/', false],
    ];

    foreach ($cases as $what => [$headers, $path, $withCookie]) {
        $this->flushHeaders();
        $this->defaultCookies = [];
        $request = $withCookie ? $this->withCookie(CartService::COOKIE, $token) : $this;
        $r = $request->get('https://extrabeauty.ae'.$path, $headers);

        expect($r->status())->toBe(301, $what)
            ->and((string) $r->headers->get('Location'))->toBe('https://kbeautybliss.com'.rtrim($path, '/'), $what) // the test client drops a trailing slash
            ->and((string) $r->headers->get('Location'))->not->toContain(DomainHandoff::PARAM);
    }

    // HEAD is a crawler's favourite; never a token.
    $this->flushHeaders();
    $head = $this->withCookie(CartService::COOKIE, $token)->call('HEAD', 'https://extrabeauty.ae/product/x/', [], [], [], $this->transformHeadersToServerVars(dsBrowser()));
    expect($head->getStatusCode())->toBe(301);
});

it('restores nothing from an expired, forged, wrong-host or replayed token', function () {
    [$cart] = dsCart();

    $try = function (string $token, string $host = 'kbeautybliss.com') {
        $this->flushHeaders();
        $this->defaultCookies = [];
        $r = $this->get('https://'.$host.'/cart/?'.DomainHandoff::PARAM.'='.rawurlencode($token), dsBrowser());
        $r->assertStatus(302)->assertHeader('Location', 'https://'.$host.'/cart/');

        return dsSetCookies($r);
    };

    // EXPIRED: issued 200 seconds ago, valid for 120.
    /* MUTATION: drop the `x < now` comparison in verify() -> red. */
    expect($try(DomainHandoff::issue('kbeautybliss.com', $cart, [], [], time() - 200)))->toBe([]);

    // FORGED: someone else's basket id, payload edited, signature kept.
    /* MUTATION: compare the MAC with == on a prefix, or skip it -> red. */
    $good = DomainHandoff::issue('kbeautybliss.com', $cart, [], []);
    [$payload, $mac] = explode('.', $good);
    $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    $data['c'] = $cart + 1;
    $forged = rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=').'.'.$mac;
    expect($try($forged))->toBe([])
        ->and($try('not-a-token'))->toBe([])
        ->and($try($payload.'.'.str_repeat('A', 43)))->toBe([]);

    // SIGNED WITH ANOTHER KEY: a token minted on another install.
    $real = config('app.key');
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    $alien = DomainHandoff::issue('kbeautybliss.com', $cart, [], []);
    config(['app.key' => $real]);
    expect($try($alien))->toBe([]);

    // WRONG HOST: a token for kbeautybliss.com presented on another name.
    /* MUTATION: drop the hash_equals on `h` in verify() -> red. */
    $other = DomainHandoff::issue('kbeautybliss.com', $cart, [], []);
    expect($try($other, 'staging.kbeautybliss.com'))->toBe([]);

    // The same token on the right host still works -- the wrong-host attempt did not spend it.
    expect($try($other))->toHaveKey(CartService::COOKIE);
});

it('never hands a signed-in customer’s basket to an anonymous browser, and never replaces one already here', function () {
    /* MUTATION: drop whereNull('customer_id') in guestCartId() -> the
       customer's basket token is minted into the URL and restored to a guest. */
    $customer = (int) DB::table('customers')->insertGetId(['email' => 'ds-'.uniqid().'@example.test', 'first_name' => 'D', 'last_name' => 'S',
        'password' => bcrypt('secret-secret'), 'created_at' => now(), 'updated_at' => now()]);
    [, $token] = dsCart($customer);

    $r = $this->withCookie(CartService::COOKIE, $token)->get('https://extrabeauty.ae/cart/', dsBrowser());
    expect($r->status())->toBe(301);

    // A guest basket arriving where the shopper already has one: theirs stays.
    /* MUTATION: drop the `cookie(...) === null` guard in cookiesFor() -> the
       basket already started on the new domain is thrown away. */
    [$cart] = dsCart();
    $this->flushHeaders();
    $this->defaultCookies = [];
    $landed = $this->withCookie(CartService::COOKIE, (string) Str::uuid())
        ->get('https://kbeautybliss.com/cart/?'.DomainHandoff::PARAM.'='.DomainHandoff::issue('kbeautybliss.com', $cart, [], []), dsBrowser());
    expect(dsSetCookies($landed))->not->toHaveKey(CartService::COOKIE);
});

it('carries the wishlist and recently viewed, merged with what the new domain already has', function () {
    /* MUTATION: drop the WISHLIST entry from cookiesFor()'s loop -> red. */
    $r = $this->withCookie(DomainHandoff::WISHLIST, '7,9')->withCookie(DomainHandoff::VIEWED, '3')
        ->get('https://extrabeauty.ae/', dsBrowser());
    $r->assertStatus(302);

    $this->flushHeaders();
    $this->defaultCookies = [];
    $landed = $this->withCookie(DomainHandoff::WISHLIST, '5')->get((string) $r->headers->get('Location'), dsBrowser());
    $set = dsSetCookies($landed);

    expect($set[DomainHandoff::WISHLIST])->toBe('5,7,9')
        ->and($set[DomainHandoff::VIEWED])->toBe('3')
        ->and($set)->not->toHaveKey(CartService::COOKIE);
});

it('costs a shop page nothing when no token is present, and does nothing while forwarding is off', function () {
    /* MUTATION: move DomainHandoff::forward() above shouldForward() -> the
       old domain starts redirecting while the owner has forwarding OFF. */
    [, $token] = dsCart();
    dsForwarding(false);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->withCookie(CartService::COOKIE, $token)->get('https://extrabeauty.ae/no-such-page-ds/', dsBrowser());
    $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
    DB::disableQueryLog();

    expect($sql)->not->toContain('"cart_items"."cart_id" = "carts"."id"')
        ->and(DomainHandoff::present(\Illuminate\Http\Request::create('https://kbeautybliss.com/product/x/')))->toBeFalse();
});
