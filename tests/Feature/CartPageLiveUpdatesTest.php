<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\Product;
use App\Services\SettingsService;

/**
 * =============================================================================
 * EVERY CART-PAGE WRITE ANSWERED 500 WHILE RENDERING ITS OWN REPLY
 * =============================================================================
 *
 * Reported from the live shop: the rail's + button does not put the product in
 * the list, the quantity buttons do not move the total, remove does not remove
 * — "all those changes implement only on page refresh".
 *
 * That description is the diagnosis, if you read it literally. The basket DID
 * change; only the HTML describing it never arrived. So the write worked and
 * the render failed, which is one bug, not three.
 *
 * `store/cart-inner.blade.php` opens with `$kbbCartPage->squeezed()` and reads
 * that variable four more times. It arrived ONLY as inherited view data:
 * store/cart.blade.php resolves it in an @php block, and @include hands it down
 * to the partial. That works for the full page and only for the full page.
 *
 * `CartController::fragments()` renders the SAME partial standalone on every
 * cart write, with `payload()` and nothing else — and payload() never carried
 * it. Measured before the fix, across both layouts:
 *
 *     [classic] add 500   update 500   coupon 500   remove 200
 *     [squeeze] add 500   update 500   coupon 500   remove 500
 *     [both]    add via the drawer (no with_page) 200
 *
 * The drawer renders a DIFFERENT view, which is why the mini-cart kept working
 * and hid this for as long as it did. And a 500 here is invisible: cart.js
 * catches it, the page sits unchanged, and the shopper sees their item the
 * moment they reload.
 *
 * The fix is one line in payload(), not at the call site, because payload() IS
 * the contract for this view.
 *
 * ── WHY THIS FILE ASSERTS CONTENT AND NOT STATUS ────────────────────────────
 *
 * A 200 would have gone green on a reply whose `page` was null, which is the
 * state that produced the report: cart.js only repaints when `data.page` is
 * there. So every assertion below reads the FRAGMENT and looks for the change
 * the shopper made. That is the thing that was broken.
 *
 * MUTATION: drop 'kbbCartPage' from payload(). Red, eight times.
 * MUTATION: make `page` null unless the layout is squeezed. Red — the classic
 * cart page updates live too, and did before any of this work.
 */
beforeEach(function () {
    Cart::query()->delete();
});

function liveLayout(string $layout): void
{
    app(SettingsService::class)->set('cartpage_layout', $layout);
    SettingsService::forgetMemo();
}

function liveProduct(string $name): Product
{
    return Product::create([
        'slug' => 'live-' . uniqid(),
        'name' => $name,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 200,
        'stock_status' => 'instock',
    ]);
}

/** The cart page's own door: cart.js appends ?with_page=1 when #cartInner exists. */
function livePost(string $path, array $body): array
{
    $response = test()->postJson('/api/cart/' . $path . '?with_page=1', $body);

    expect($response->getStatusCode())->toBe(
        200,
        "POST /api/cart/{$path}?with_page=1 answered {$response->getStatusCode()}. A cart write that "
        .'500s while rendering its reply still changes the basket, so the shopper sees it on reload '
        .'and reports it as "only updates on page refresh"'
    );

    return (array) $response->json();
}

dataset('layouts', ['classic', 'squeeze']);

it('puts the product in the list when the rail plus is pressed', function (string $layout) {
    liveLayout($layout);
    $product = liveProduct('Zebra Serum');

    $data = livePost('add', ['product_id' => $product->id, 'quantity' => 1]);

    expect($data['page'])->not->toBeNull(
        'the reply carried no page fragment, so cart.js has nothing to repaint with'
    );
    expect(str_contains((string) $data['page'], 'Zebra Serum'))->toBeTrue(
        'the basket changed but the fragment describing it does not mention the product just added'
    );
    expect($data['count'])->toBe(1);
})->with('layouts');

it('moves the quantity and the total together', function (string $layout) {
    liveLayout($layout);
    $product = liveProduct('Zebra Serum');

    livePost('add', ['product_id' => $product->id, 'quantity' => 1]);
    $item = Cart::query()->latest('id')->first()?->items()->latest('id')->first();
    expect($item)->not->toBeNull();

    $one = livePost('update', ['item_id' => $item->id, 'quantity' => 1]);
    $three = livePost('update', ['item_id' => $item->id, 'quantity' => 3]);

    expect($three['count'])->toBe(3);

    // The figure the shopper reads has to MOVE, and it is the moving that
    // matters — pinning a formatted currency string here would go red the next
    // time somebody changes how money is printed.
    expect($three['total'])->not->toBe($one['total'], 'the total did not change when the quantity did');

    expect(str_contains((string) $three['page'], '<span>3</span>'))->toBeTrue(
        'the fragment does not show the new quantity, so the stepper appears stuck until reload'
    );
})->with('layouts');

it('takes the product back out again', function (string $layout) {
    liveLayout($layout);
    $product = liveProduct('Zebra Serum');

    livePost('add', ['product_id' => $product->id, 'quantity' => 1]);
    $item = Cart::query()->latest('id')->first()?->items()->latest('id')->first();

    $data = livePost('remove', ['item_id' => $item->id]);

    expect($data['count'])->toBe(0);
    expect(str_contains((string) $data['page'], 'Zebra Serum'))->toBeFalse(
        'the removed product is still in the fragment, so the row stays on screen until reload'
    );
})->with('layouts');

it('answers a coupon attempt without falling over', function (string $layout) {
    liveLayout($layout);
    $product = liveProduct('Zebra Serum');
    livePost('add', ['product_id' => $product->id, 'quantity' => 1]);

    // A code that does not exist. The interesting part is that the RENDER
    // survives it -- this endpoint 500'd on both layouts for the same reason
    // the others did, and a shopper mistyping a code got a dead page.
    $data = livePost('coupon', ['code' => 'NO-SUCH-CODE']);

    expect($data['page'])->not->toBeNull();
})->with('layouts');

it('renders the cart partial from payload() alone, which is the actual rule', function (string $layout) {
    /*
     * The generalisation, and the reason this bug cannot come back in a
     * different endpoint. Whatever `payload()` returns must be enough to render
     * `store.cart-inner` with NO inherited view data at all. Every assertion
     * above goes through HTTP; this one goes at the contract directly, so a
     * future partial that reaches for a sixth variable fails here first.
     */
    liveLayout($layout);
    liveProduct('Zebra Serum');

    $controller = new ReflectionClass(\App\Http\Controllers\Store\CartController::class);
    $payload = $controller->getMethod('payload');
    $payload->setAccessible(true);

    $instance = app(\App\Http\Controllers\Store\CartController::class);
    $data = $payload->invoke($instance, null, request());

    expect(fn () => view('store.cart-inner', $data)->render())->not->toThrow(Throwable::class);
})->with('layouts');

it('gives the rail plus an attribute the cart script actually listens for', function () {
    /*
     * The other way this feature can be dead: a button nothing is bound to.
     * cart.js handles `.kc-badd` and reads `dataset.add`. Both halves are
     * checked, because either one alone is a button that looks right and does
     * nothing -- a category of bug this repo has shipped before.
     */
    $markup = (string) file_get_contents(base_path('resources/views/store/cart-inner.blade.php'));
    $script = (string) file_get_contents(base_path('resources/js/kbb/cart.js'));

    expect(preg_match('/class="kc-badd"[^>]*data-add=/', $markup))->toBe(
        1,
        'the rail plus no longer carries the class and attribute cart.js binds to'
    );

    expect(str_contains($script, "closest('.kc-badd')"))->toBeTrue('cart.js no longer binds the rail plus');
    expect(str_contains($script, 'dataset.add'))->toBeTrue('cart.js no longer reads the product id off it');
});
