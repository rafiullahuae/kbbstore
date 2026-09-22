<?php

declare(strict_types=1);

/**
 * =============================================================================
 * THE DOCKED CHECKOUT ROW SURVIVES THE PHONE'S URL BAR COMING BACK
 * =============================================================================
 *
 * The owner, with a screenshot, on a basket of ten:
 *
 *     "when i scroll back to up on the cart page, the screen gives weired white
 *      bar type... and it hides the checkouts row. please remove that weird
 *      white bar. it only comes when u have more products on cart page, and you
 *      scrolling back to up side."
 *
 * WHAT IS ACTUALLY HAPPENING. `position:fixed` resolves `bottom` against the
 * LAYOUT viewport, and on a phone the layout viewport is the tall one — the
 * height the page has while the browser's URL bar is retracted. Scrolling back
 * upward puts that URL bar out again. The layout viewport does not change; the
 * visible part of it shrinks, from the top, and its bottom edge now sits that
 * much below the bottom of the screen. So does anything pinned to it.
 *
 * `.cpg-docked` is pinned to it. The address row is near the top of the block
 * and is still on screen; the checkout row is at the bottom of it and has gone
 * past the screen edge; and between the two is the empty upper part of the
 * checkout row, which is this block's own white with nothing in it. That is the
 * white bar in the screenshot, and Proceed to Checkout is underneath the edge
 * of the phone.
 *
 * TEN ITEMS IS NOT THE CAUSE. Two items do not fill a phone, so there is
 * nothing to scroll and the URL bar never goes anywhere. Ten is simply enough
 * page to scroll far enough to see it.
 *
 * THE FIX is a second `bottom` on the same rule:
 *
 *     bottom: calc(100lvh - 100dvh)
 *
 * `100lvh` is the tall layout viewport the block is pinned to, `100dvh` is how
 * much of it is on the screen right now, and the difference is exactly the
 * strip hanging off the bottom. With the URL bar retracted the two are equal,
 * the term is 0px, and the page renders as it does today. A browser that does
 * not parse the units drops the declaration and keeps the `bottom:0` above it,
 * which is where it already was.
 *
 * THIS PREDATES THE WHITE BACKGROUND. `.cpg-docked` has been
 * `position:fixed; bottom:0` since 8c58dbe, the commit that introduced it. What
 * 09ad933 changed was the paint: the rows went from var(--cream) to #fff and the
 * block itself gained a background. Before that the strip showing through was
 * cream and read as page; now it reads as a white bar. The geometry is the same
 * geometry it always was.
 *
 * WHAT THE SUITE CAN AND CANNOT EXECUTE. The structural case below runs
 * everywhere and is what CI keeps. The browser case needs Chromium and skips
 * without it — and even with it, headless Chromium has no URL bar, so `100dvh`
 * can never differ from `100lvh` in it; tests/browser/cart-docked-viewport.mjs
 * says at length what it substitutes and why.
 */

use App\Models\Cart;
use App\Models\Product;
use App\Services\CartPage;
use App\Services\CartService;
use Illuminate\Support\Str;
use Tests\Support\PreviewPort;

/** Node, playwright, Chromium and the script. */
function dvPrereqs(): array
{
    $chrome = env('KBB_BROWSER_CHROME', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');

    $missing = [];

    if (! env('KBB_BROWSER_TESTS')) {
        $missing[] = 'KBB_BROWSER_TESTS is not set';
    }

    if (! is_file($chrome)) {
        $missing[] = "no Chromium at {$chrome}";
    }

    if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
        $missing[] = 'node is not on PATH';
    }

    if (! is_file(base_path('tests/browser/cart-docked-viewport.mjs'))) {
        $missing[] = 'tests/browser/cart-docked-viewport.mjs is missing';
    }

    return ['chrome' => $chrome, 'missing' => $missing];
}

/** The squeezed cart page, as a shopper with a basket too long for a phone. */
function dvCartHtml(int $items = 10): string
{
    app(CartPage::class)->save(['layout' => 'squeeze']);

    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    for ($i = 1; $i <= $items; $i++) {
        $product = Product::create([
            'slug' => 'docked-'.$i.'-'.Str::random(8),
            'name' => 'Glow Serum Number '.$i,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 12000 + $i * 100,
            'stock_status' => 'instock',
        ]);

        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 12000 + $i * 100,
        ]);
    }

    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/cart')
        ->assertOk()
        ->getContent();
}

/**
 * Serve $html as index.html with the tracked asset bundle beside it.
 *
 * public/build is COPIED and never symlinked, for the reason the other browser
 * helpers in this suite give: the directory is rm -rf'd on the way out, and a
 * symlink would put the tracked bundle inside the reach of that delete.
 *
 * @return array{base:string, dir:string, stop:callable}
 */
function dvServe(string $html): array
{
    $dir = storage_path('framework/testing/lane-dv-docked-'.getmypid().'-'.bin2hex(random_bytes(4)));
    $root = $dir.'/webroot';

    @mkdir($root, 0o777, true);

    // @vite emits absolute URLs against APP_URL, which is not where this server
    // is. Without the rewrite the page loads with no author rules at all and
    // every rectangle measures zero, which is a green test that proves nothing.
    $html = preg_replace('#(href|src)="https?://[^"]*/build/#', '$1="/build/', $html) ?? $html;

    file_put_contents($root.'/index.html', $html);

    exec('cp -r '.escapeshellarg(base_path('public/build')).' '.escapeshellarg($root.'/build'));

    $port = PreviewPort::claim(8620, 8850);

    $process = proc_open(
        'php -S 127.0.0.1:'.$port.' -t '.escapeshellarg($root),
        [0 => ['pipe', 'r'], 1 => ['file', $dir.'/serve.log', 'w'], 2 => ['file', $dir.'/serve.log', 'a']],
        $pipes,
        $root
    );

    if (! is_resource($process)) {
        throw new RuntimeException('could not start the static preview server');
    }

    $base = 'http://127.0.0.1:'.$port;

    $stop = function () use ($process, $pipes, $dir) {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $status = proc_get_status($process);

        if ($status['running'] ?? false) {
            // By PID, never by pattern: three lanes share this machine and
            // pkill -f on a php -S takes the other two down with it.
            exec('pkill -P '.(int) $status['pid'].' 2>/dev/null');
            proc_terminate($process);
        }

        proc_close($process);
        exec('rm -rf '.escapeshellarg($dir));
    };

    $up = false;

    for ($i = 0; $i < 60; $i++) {
        usleep(200_000);
        $ch = curl_init($base.'/index.html');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 200) {
            $up = true;
            break;
        }
    }

    if (! $up) {
        $log = @file_get_contents($dir.'/serve.log') ?: '';
        $stop();

        throw new RuntimeException("static preview never answered on {$base}\n".substr($log, -600));
    }

    return ['base' => $base, 'dir' => $dir, 'stop' => $stop];
}

/** @return array<string, mixed> */
function dvMeasure(string $base, string $chrome, int $width, int $height, int $dvh): array
{
    $env = [
        'KBB_BROWSER_URL' => $base.'/index.html',
        'KBB_BROWSER_CHROME' => $chrome,
        'KBB_BROWSER_WIDTH' => (string) $width,
        'KBB_BROWSER_HEIGHT' => (string) $height,
        'KBB_BROWSER_DVH' => (string) $dvh,
        'NODE_PATH' => (string) env('KBB_BROWSER_NODE_PATH', '/opt/node22/lib/node_modules'),
        'PLAYWRIGHT_BROWSERS_PATH' => (string) env('KBB_BROWSER_PW_PATH', '/opt/pw-browsers'),
    ];

    $prefix = '';

    foreach ($env as $k => $v) {
        $prefix .= $k.'='.escapeshellarg((string) $v).' ';
    }

    $decoded = null;

    // One retry: driving a browser against a local server is not perfectly
    // deterministic, and this must fail for the reason it is about.
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $decoded = json_decode((string) shell_exec(
            $prefix.'node '.escapeshellarg(base_path('tests/browser/cart-docked-viewport.mjs')).' 2>/dev/null'
        ), true);

        if (is_array($decoded) && ($decoded['ok'] ?? false)) {
            return $decoded;
        }
    }

    throw new RuntimeException('the browser measurement did not come back: '.json_encode($decoded));
}

/* --------------------------------------------------------------- structural */

it('lifts the docked rows by however much of the layout viewport is off the screen', function () {
    $css = (string) file_get_contents(resource_path('views/store/cart-squeeze.blade.php'));

    $start = strpos($css, '.kbb-cartpage.cpg-squeeze .cpg-docked{');
    expect($start)->not->toBeFalse('the docked rule is gone, and this file is out of date');

    $rule = substr($css, (int) $start, (int) strpos($css, '}', (int) $start) - (int) $start + 1);

    // The fallback is still first, so a browser with no lvh/dvh keeps exactly
    // the declaration it has today.
    expect($rule)->toContain('position:fixed;inset-inline:0;bottom:0;')
        // and the lift is the LAST bottom in the block, or the fallback wins.
        ->and($rule)->toContain('bottom:calc(100lvh - 100dvh)')
        ->and(strrpos($rule, 'bottom:calc(100lvh - 100dvh)'))
        ->toBeGreaterThan((int) strrpos($rule, 'bottom:0;'));

    /*
     * And the term stays OUT of --cpg-bars. That variable is padding at the end
     * of the document, so a dvh in it would make the page's height change every
     * time the URL bar moved -- and a document that grows and shrinks under a
     * scrolling finger drives the URL bar, which is a worse bug than the one
     * this file is about.
     */
    $bars = substr($css, (int) strpos($css, '--cpg-bars:'), 120);

    expect($bars)->not->toContain('dvh')
        ->and($bars)->not->toContain('lvh')
        ->and($bars)->not->toContain('svh');
});
// MUTATION: delete the `bottom:calc(100lvh - 100dvh)` declaration. RED, all
// three cases in this file. Run and confirmed.
// MUTATION: leave a second `bottom:0` AFTER the lift, so the fallback wins. RED
// here on the strrpos comparison and RED in the browser case on the declared
// rule -- and it is the mutation that matters, because the block still parses,
// still carries both declarations, and still reads correctly. The other case in
// this file stays green, which is the point of having three. Run and confirmed.
// MUTATION: put the same viewport term into --cpg-bars as well. RED here and in
// the case below; the browser case stays green, because a document that changes
// height with the URL bar is a bug no single measurement catches. Run and
// confirmed.

it('never lifts the block on a browser that has no browser chrome to hide', function () {
    /*
     * The guarantee the whole change rests on. This is a live shop, and the
     * rule must be a no-op everywhere the bug is not: on a desktop browser,
     * and on a phone whose URL bar is already retracted, lvh and dvh are the
     * same number and calc(100lvh - 100dvh) is 0px. There is no state in which
     * it is negative, because dvh is defined never to exceed lvh.
     *
     * Held here as the shape of the expression rather than a measurement:
     * subtracting dvh FROM lvh is the whole of it, and the reversed subtraction
     * would push the block DOWN by the height of the browser chrome, which is
     * the same bug twice over.
     */
    $css = (string) file_get_contents(resource_path('views/store/cart-squeeze.blade.php'));

    expect($css)->toContain('bottom:calc(100lvh - 100dvh)')
        ->and($css)->not->toContain('bottom:calc(100dvh - 100lvh)');

    // And nothing else on this page learned to move with the viewport. Counted
    // with the comments stripped, because this file explains itself at length
    // and the prose says `100dvh` more often than the stylesheet does.
    $declarations = (string) preg_replace('#/\\*.*?\\*/#s', '', $css);

    expect(substr_count($declarations, 'dvh'))->toBe(1)
        ->and(substr_count($declarations, 'lvh'))->toBe(1);
});
// MUTATION: reverse the subtraction to calc(100dvh - 100lvh). RED, all three
// cases. Run and confirmed.

/* ------------------------------------------------------------------ browser */

it('keeps Proceed to Checkout on the screen once the URL bar is back', function () {
    ['chrome' => $chrome, 'missing' => $missing] = dvPrereqs();

    if ($missing !== []) {
        test()->markTestSkipped('browser half not run: '.implode('; ', $missing));
    }

    $html = dvCartHtml(10);

    ['base' => $base, 'stop' => $stop] = dvServe($html);

    try {
        // A Pixel-sized phone. 640 is the layout viewport -- the height with the
        // URL bar retracted -- and 584 is what dvh reports with Chrome's 56px
        // Android toolbar back out.
        $m = dvMeasure($base, $chrome, 393, 640, 584);
    } finally {
        $stop();
    }

    expect($m['supportsUnits'] ?? false)->toBeTrue(
        'this Chromium does not parse calc(100lvh - 100dvh), so the measurement below proves nothing'
    );

    $natural = $m['states']['natural'];
    $before = $m['states']['before'];
    $after = $m['states']['after'];

    expect($natural['found'])->toBeTrue('.cpg-docked is not on the page, so nothing here was measured');

    /*
     * THE PAGE'S OWN RULE, read out of the CSSOM before the script injected
     * anything. Without this the two states below would only be measuring a
     * `bottom` the script had written itself, and would stay green with the fix
     * deleted from the stylesheet.
     */
    expect($natural['declaredBottom'])->toBe('calc(100lvh - 100dvh)',
        'the shipped .cpg-docked rule no longer declares the lift, so the before/after below is measuring the script and not the shop'
    );

    // 1. NOTHING MOVED where there is no browser chrome to hide. lvh == dvh, so
    //    the new declaration computes to the same 0px the old one did, and the
    //    block is where it has always been: on the bottom edge.
    expect($natural['computedBottom'])->toBe('0px')
        ->and((float) $natural['docked']['bottom'])->toEqualWithDelta((float) $natural['innerHeight'], 0.5)
        ->and((float) $natural['button']['bottom'])->toBeLessThanOrEqual((float) $natural['innerHeight']);

    // env(safe-area-inset-bottom) is 0 here and on the shop: layouts/store.blade.php
    // does not ask for viewport-fit=cover, so no inset is ever reported. The
    // padding under the rows is the shop's own setting and nothing else, which
    // rules out the block being taller than the room for it.
    expect($natural['computedPaddingBottom'])->toBe('0px');

    // 2. THE BUG, with the URL bar out. Pinned to the layout viewport, the block
    //    hangs past the bottom of what the shopper can see, and the button goes
    //    with it.
    expect((float) $before['button']['bottom'])->toBeGreaterThan(584.0)
        // while the address row above it is still comfortably on screen, which
        // is exactly the screenshot: an address row, white under it, no total
        // and no button.
        ->and((float) $before['addrbar']['bottom'])->toBeLessThan(584.0);

    // 3. THE FIX. The whole block is inside the visible area, button and all.
    expect((float) $after['docked']['bottom'])->toBeLessThanOrEqual(584.0)
        ->and((float) $after['button']['bottom'])->toBeLessThanOrEqual(584.0)
        ->and((float) $after['addrbar']['bottom'])->toBeLessThan((float) $after['cobar']['top'] + 1.0);

    // And it moved by the height of the browser chrome and not by some other
    // number: 640 - 584.
    expect($after['computedBottom'])->toBe('56px')
        ->and((float) $natural['docked']['bottom'] - (float) $after['docked']['bottom'])
        ->toEqualWithDelta(56.0, 0.5);
});
