<?php

declare(strict_types=1);

/**
 * The drawer's Browsed tab for a shopper who has not added anything yet.
 *
 * Reported from the live shop, in the owner's words: "the browsed tab is not
 * giving real browsed pages result, it's stucks and dead."
 *
 * ── WHAT IT ACTUALLY WAS ────────────────────────────────────────────────────
 *
 * Not staleness, and not the Varnish layer that was serving 27-hour-old pages
 * at the time. The Browsed list is fed from the `kbb_viewed` cookie, which
 * ProductController::rememberViewed() writes on every product page view and
 * which has nothing whatsoever to do with the cart.
 *
 * CartDrawerComposer::compose() bails out early when there is no cart — Rule
 * 27, "when there is nothing to show, no query runs at all" — and that early
 * return handed the view `'browsed' => collect()`. So on any page load by a
 * visitor with no cart cookie, the browsing history sitting right there on the
 * request was discarded and the tab rendered its empty state.
 *
 * That is the whole of "stuck and dead", and it explains the shape of the
 * complaint rather than just the fact of it: the tab is empty for precisely the
 * people it exists for — anyone still browsing — and the moment they add one
 * product the cart cookie appears, the early return stops being taken, and the
 * entire history materialises at once. Intermittent by account, not by time,
 * which is exactly how a cache fault reads from the outside.
 *
 * ── WHAT RULE 27 STILL GUARANTEES ───────────────────────────────────────────
 *
 * browsed() returns an empty collection without touching the database when the
 * cookie is absent or empty, so a genuine first visit and a crawler — no
 * cookies at all — still run no query. The last case below pins that, because
 * a fix that bought the Browsed tab back at the price of a query on every
 * crawler hit would be a worse bug than the one it replaced.
 *
 * ── MUTATIONS THESE CATCH, EACH ONE RUN ─────────────────────────────────────
 *
 *   - `$this->browsed(null)` in the early return reverted to `collect()` --
 *     the original bug. Three cases red.
 *   - BOTH of browsed()'s `if ($ids === []) return collect();` guards deleted,
 *     which runs a whereIn over an empty set for every crawler. One case red.
 *   - the `sortBy` on the cookie's order dropped, losing most-recent-first.
 *
 * ── AND ONE THAT CAME BACK GREEN, RECORDED RATHER THAN QUIETLY REPLACED ─────
 *
 * Deleting only the FIRST of browsed()'s two `if ($ids === []) return
 * collect();` guards changes nothing any case here can see, and nothing any
 * case could see: with an empty cookie `array_diff([], $inCart)` is still
 * empty, so the SECOND guard returns before the query either way. The first
 * guard saves two array calls, not a query. It is left in place as a statement
 * of intent, but it is not load-bearing and nothing here pretends it is --
 * which is why the mutation above deletes both.
 */

use App\Models\Cart;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Support\Str;

function browsedTabProduct(string $name): Product
{
    return Product::create([
        'slug' => Str::slug($name) . '-' . uniqid(),
        'name' => $name,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 3900,
        'stock_status' => 'instock',
    ]);
}

/** A page load carrying only a browsing history — no cart, ever. */
function browsedTabPage(Tests\TestCase $test, array $ids, string $uri = '/'): string
{
    return $test
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie('kbb_viewed', implode(',', $ids))
        ->get($uri)
        ->assertOk()
        ->getContent();
}

it('lists browsed products for a visitor who has no cart at all', function () {
    $a = browsedTabProduct('Ginseng Eye Cream');
    $b = browsedTabProduct('Propolis Ampoule');

    $html = browsedTabPage($this, [$b->id, $a->id]);

    // The bug: both of these were absent, and the tab showed its empty state.
    expect($html)->toContain('data-brow="' . $b->id . '"')
        ->and($html)->toContain('data-brow="' . $a->id . '"');

    // No cart was conjured to make that work — Rule 27's other half.
    expect(Cart::query()->count())->toBe(0);
});

it('keeps the cookie order, most recently viewed first', function () {
    $first = browsedTabProduct('Snail Repair Cream');
    $second = browsedTabProduct('Rice Toner Deluxe');

    // rememberViewed() prepends, so the cookie reads newest-first.
    $html = browsedTabPage($this, [$second->id, $first->id]);

    expect(strpos($html, 'data-brow="' . $second->id . '"'))
        ->toBeLessThan(strpos($html, 'data-brow="' . $first->id . '"'));
});

it('writes the cookie on a product page and reads it back on the next page', function () {
    // End to end, the journey in the report: browse, then look at the drawer.
    $product = browsedTabProduct('Barrier Repair Serum');

    $viewed = collect($this->get('/product/' . $product->slug . '/')->assertOk()->headers->getCookies())
        ->firstWhere(fn ($c) => $c->getName() === 'kbb_viewed');

    expect($viewed)->not->toBeNull();

    expect(browsedTabPage($this, [$product->id]))->toContain('data-brow="' . $product->id . '"');
});

it('still shows the browsed list once a cart does exist', function () {
    // The path that always worked. Kept so a fix to the cart-less branch that
    // broke the other one cannot pass.
    $browsedOnly = browsedTabProduct('Yuja Vitamin Mask');
    $added = browsedTabProduct('Cica Soothing Gel');

    $this->postJson('/api/cart/add', ['product_id' => $added->id])->assertOk();
    $cart = Cart::query()->where('status', 'active')->firstOrFail();

    $html = $this
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie('kbb_viewed', $browsedOnly->id . ',' . $added->id)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-brow="' . $browsedOnly->id . '"')
        // A product already in the bag drops off the Browsed list.
        ->and($html)->not->toContain('data-brow="' . $added->id . '"');
});

it('runs no query at all for a visitor carrying no cookies', function () {
    /*
     * Rule 27. A crawler must still cost nothing, or the fix above is a
     * regression wearing a feature's clothes.
     *
     * The composer is driven DIRECTLY rather than through $this->get(), and
     * that is not a shortcut. A DB::listen() registered in a test does not see
     * the queries of a request made through the test client -- it reports zero
     * for a page that plainly hits the database -- so a case written that way
     * passes whatever the composer does, including with both guards deleted.
     * That was written, run, and found to be green on the mutation it was
     * supposed to catch. compose() only ever calls offsetExists() and with(),
     * never renders, so calling it here exercises the real code path and the
     * query log is honest.
     */
    browsedTabProduct('Never Browsed');

    $composer = new App\View\Composers\CartDrawerComposer(
        app(CartService::class),
        app(App\Services\SettingsService::class),
        Illuminate\Http\Request::create('/'),   // no cookies whatsoever
    );

    $view = view('partials.cart-drawer');

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $composer->compose($view);

    $ran = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();

    expect($ran)->toBeEmpty()
        ->and($view->getData()['browsed'])->toBeEmpty();
});

it('does query, and lists the products, once that visitor has browsed something', function () {
    // The other half of the case above: proof that the query log it reads is
    // capable of reporting a query, so an empty log means something.
    $product = browsedTabProduct('Actually Browsed');

    $composer = new App\View\Composers\CartDrawerComposer(
        app(CartService::class),
        app(App\Services\SettingsService::class),
        Illuminate\Http\Request::create('/', 'GET', [], ['kbb_viewed' => (string) $product->id]),
    );

    $view = view('partials.cart-drawer');

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $composer->compose($view);

    $ran = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();

    expect($ran)->not->toBeEmpty()
        ->and($view->getData()['browsed']->pluck('id')->all())->toBe([$product->id]);
});
