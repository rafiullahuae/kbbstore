<?php

declare(strict_types=1);

/*
 * 2.60.502 -- the checkout's Sign in window recovers from an expired token.
 *
 * The owner, on the live shop: "Something went wrong. Please try again, or use
 * the full sign-in page. this is coming on the sign-in form and also same for
 * forgot email form ... the actual sign-in page is working fine", and "it's
 * only for my email". Reproduced on the preview shop: a checkout tab opened
 * before the browser signed in to the admin holds a retired token; the window's
 * POST was refused 419 and the window said "Something went wrong". Now it asks
 * GET /checkout/sign-in/token once, adopts the answer and sends again -- 419,
 * then 200, signed in, details filled (and the same for "Forgot password").
 */

it('hands this session its own token, never cached', function () {
    // MUTATION: drop the route or the no-store header. RED.
    $r = $this->get('/checkout/sign-in/token', ['Accept' => 'application/json'])->assertOk();

    expect($r->json('ok'))->toBeTrue()
        ->and($r->json('csrf'))->toBe(session()->token())
        ->and((string) $r->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $r->headers->get('Cache-Control'))->toContain('private')
        // Nothing but the token.
        ->and(array_keys($r->json()))->toBe(['ok', 'csrf']);
});

it('retries a refused (419) sign-in or reset once with a fresh token', function () {
    /*
     * MUTATION: remove the `response.status === 419` block. RED -- and on the
     * shop, "Something went wrong" for anyone whose tab outlived a sign-in.
     */
    $src = (string) file_get_contents(resource_path('views/partials/checkout/sign-in.blade.php'));

    expect($src)->toContain("var TOKEN_URL = @json(\\App\\Support\\Url::to('/checkout/sign-in/token'));")
        ->and($src)->toContain('if (response.status === 419) {')
        ->and($src)->toContain('adopt(fresh.csrf);')
        ->and($src)->toContain('response = await send();')
        // The first send and ONE retry: never a loop.
        ->and(substr_count($src, 'await send()'))->toBe(2)
        ->and($src)->toContain('var response = await send();');

    $routes = (string) file_get_contents(base_path('routes/checkout-sign-in.php'));
    expect($routes)->toContain("Route::get('/checkout/sign-in/token', [CheckoutSignInController::class, 'token'])")
        ->and($routes)->toContain("->middleware('throttle:30,1')");
});
