<?php

declare(strict_types=1);

/*
 * THE CART PANEL MUST NEVER FREEZE ON AN OLD PAGE.
 *
 * The owner: "when user come back to our website after several hours or after
 * a day, then when user try to add to cart, then add to cart panel freeze and
 * continues grey bars loading coming, until user refresh the whole website."
 *
 * What it looked like on the shop: the basket cookie lives 30 days, the CSRF
 * token in window.KBB.csrf lives as long as the session (120 minutes). The first
 * Add after a long absence was refused with a 419; cart.js's send() parsed the
 * refusal, found no drawer in it, and left the loading skeleton on screen until
 * a full reload.
 *
 * The behaviour itself is driven in Chromium by tools/cr-shots.cjs (expired
 * session, a 500, a dropped connection, a long absence). This file pins the two
 * halves that can be pinned without a browser: the token endpoint, and the
 * recovery paths in cart.js, each with the mutation that turns it red.
 */

it('answers a fresh token for this session, never cached, and nothing else', function () {
    /*
     * MUTATION: drop the Cache-Control header from CartTokenController and the
     * no-store assertion is red — a cached token is the stale-token bug again.
     * Return the session id beside the token and the exact-keys assertion is red.
     */
    $response = $this->getJson('/api/cart/token')->assertOk();

    expect(array_keys($response->json()))->toBe(['token'])
        ->and($response->json('token'))->toBe(session()->token())
        ->and($response->json('token'))->toBeString()->not->toBe('')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('mounts the token route once, inside the cart group, on its own throttle counter', function () {
    $web = (string) file_get_contents(base_path('routes/web.php'));

    expect(substr_count($web, "require __DIR__.'/cart-token.php';"))->toBe(1);

    // Inside `Route::prefix('api/cart')`, so it is /api/cart/token on the web
    // (session) stack beside the drawer — not a top-level path.
    $group = substr($web, (int) strpos($web, "Route::prefix('api/cart')"));
    $group = substr($group, 0, (int) strpos($group, '});'));
    expect($group)->toContain("require __DIR__.'/cart-token.php';");

    $route = app('router')->getRoutes()->getByName('cart.token');
    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('api/cart/token')
        ->and($route->gatherMiddleware())->toContain('throttle:30,1,cart-token');
});

it('retries a refused (419) cart request once with a fresh token', function () {
    /*
     * MUTATION: delete the `response.status === 419 && !retried` branch from
     * send() and this is red — that branch IS the fix for the owner's report.
     * Drop `!retried` and a server that keeps refusing would loop for ever.
     */
    $js = (string) file_get_contents(resource_path('js/kbb/cart.js'));

    expect($js)->toContain('response.status === 419 && !retried')
        ->and($js)->toContain('return await send(path, body, true)')
        ->and($js)->toContain('`${window.KBB.routes.cartApi}/token`')
        ->and($js)->toContain("cache: 'no-store'")
        ->and($js)->toContain('window.KBB.csrf = d.token');
});

it('never leaves the loading skeleton on screen, whatever fails', function () {
    /*
     * MUTATION: remove the busy-fragment repaint from send()'s `finally` and
     * every failure that is not a 419 — a 500, an HTML error page, a dropped
     * connection — freezes the panel exactly as the owner saw. Remove the
     * lastFragment fallback from repaint() and an offline shopper is left on
     * the skeleton when the drawer cannot be fetched either.
     */
    $js = (string) file_get_contents(resource_path('js/kbb/cart.js'));

    $send = substr($js, (int) strpos($js, 'const send = async'));
    $finally = substr($send, (int) strpos($send, '    } finally {'));
    $finally = substr($finally, 0, (int) strpos($finally, "\n};"));

    expect($finally)->toContain("if (fragment()?.dataset.busy === '1') repaint();")
        ->and($js)->toContain('lastFragment = frag.outerHTML;')
        ->and($js)->toContain("if (frag?.dataset.busy === '1' && lastFragment) frag.outerHTML = lastFragment;")
        // A request that hangs is a failure, not a wait without end.
        ->and($js)->toContain('setTimeout(() => abort.abort(), TIMEOUT)')
        ->and($js)->toContain('signal: abort.signal')
        // An answer that is not the cart (an error page, a refusal that
        // survived the retry) is a failure, not an empty panel.
        ->and($js)->toContain('(!response.ok && !data.drawer && !data.error)')
        ->and($js)->toContain('window.kbbRefreshCart = repaint;');
});

it('mends the token quietly when a tab comes back after a long absence, with no timer', function () {
    /*
     * MUTATION: remove the visibilitychange listener and the first tap after a
     * long absence still works, but only through a refused request and a
     * retry (one round trip slower). Replace it with a setInterval and the
     * "no timer that never stops" rule (CLAUDE.md) is broken; this is red.
     */
    $js = (string) file_get_contents(resource_path('js/kbb/cart.js'));

    expect($js)->toContain("document.addEventListener('visibilitychange', wake);")
        ->and($js)->toContain('if (event.persisted) wake();')
        ->and($js)->toContain('Date.now() - tokenAt > TOKEN_AGE')
        ->and($js)->not->toContain('setInterval(');
});
