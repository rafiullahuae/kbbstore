<?php

declare(strict_types=1);

/**
 * `hidden` has to actually hide — Lane CX.
 *
 * ── THE BUG, AND WHY IT IS A CLASS OF BUG ───────────────────────────────────
 *
 * The checkout renders two rows that are meant to be on the page and out of
 * sight, so that checkout.js can reveal them without a reload:
 *
 *   partials/checkout/order-block.blade.php    the Gift wrapping row, revealed
 *                                              when the shopper ticks the box
 *   partials/checkout/delivery-line.blade.php  the delivery promise, revealed
 *                                              when the country changes to one
 *                                              that has a line recorded
 *
 * Both say so with the HTML `hidden` attribute, and on this page that attribute
 * did nothing. The browser's own stylesheet carries `[hidden]{display:none}`,
 * and ANY author rule that sets `display` beats it — author origin outranks the
 * user-agent origin before specificity is consulted at all. kbb-checkout.css
 * carries both `.kbb-checkout .sumrow{display:flex}` and
 * `.kbb-checkout .kbb-delivery-line{display:flex}`.
 *
 * So a shopper who had not asked for gift wrapping was shown "Gift wrapping
 * AED 0.00" on their checkout, and a shopper in a country with no recorded
 * delivery window was shown the delivery row — border, truck icon, and no
 * words in it.
 *
 * The gift row was fixed once, alone, with `.kbb-checkout .sumrow[hidden]`.
 * Then the delivery line was changed from "omitted" to "rendered and hidden"
 * and walked into the same rake, because the repair had been made to one
 * selector rather than to the rule. The fix in store/checkout.blade.php is now
 * one rule for the page, and this file measures it.
 *
 * ── WHY THIS NEEDS A BROWSER AND WHY IT SKIPS ───────────────────────────────
 *
 * A cascade claim cannot be settled by reading the stylesheet, and Pest has no
 * layout engine. This asks Chromium for the computed `display` of each element,
 * at 1280 and at 390. CI installs PHP only (.github/workflows/ci.yml), so it
 * skips there; the structural half below runs everywhere and is what CI
 * carries.
 *
 * newContext({ viewport }) and never page.setViewportSize() — the latter does
 * not take in this environment.
 *
 * ── HOW THE PAGE GETS IN FRONT OF THE BROWSER ───────────────────────────────
 *
 * The real document, rendered by the real controller through the test client,
 * written to a throwaway web root beside a copy of public/build, and served by
 * `php -S`. The stylesheet is a <link> emitted by @vite, so the cascade only
 * exists once a browser has fetched it — which is the whole point. Nothing here
 * boots a second application: the page is this suite's own render, so the
 * markup measured is the markup this branch produces.
 */

use App\Models\Cart;
use App\Models\Product;
use App\Services\CartService;
use App\Services\SettingsService;
use Illuminate\Support\Str;

/** Node, playwright, Chromium and the script. */
function hrPrereqs(): array
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

    if (! is_file(base_path('tests/browser/checkout-hidden-rows.mjs'))) {
        $missing[] = 'tests/browser/checkout-hidden-rows.mjs is missing';
    }

    return ['chrome' => $chrome, 'missing' => $missing];
}

/**
 * The checkout document, as a shopper who wants no gift wrapping and is in a
 * country this shop has recorded no delivery window for.
 *
 * `kbb_country` in the session, not a header: ShopperCountry treats a session
 * value as something the shopper SAID, which is not filtered against the
 * served-country list, so the page renders for Saudi Arabia exactly as it does
 * for a shopper who picked it from the selector. DeliveryLine has nothing for
 * SA and returns '', which is what puts `hidden` on the delivery row.
 */
function hrCheckoutHtml(): string
{
    $product = Product::create([
        'slug' => 'hr-' . Str::random(8),
        'name' => 'Rice Probiotics Toner',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 13000,
        'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 13000]);

    return test()
        ->withSession(['kbb_country' => 'SA'])
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/checkout/')
        ->assertOk()
        ->getContent();
}

/**
 * Serve $html as index.html with the tracked asset bundle beside it, and hand
 * back the base URL plus a stop().
 *
 * public/build is COPIED, never symlinked: the directory is rm -rf'd on the way
 * out and this repo tracks public/build as the record of server state. A
 * symlink here would put those files inside the reach of that delete, which is
 * exactly how packages 2.60.102-.106 took the product pages down.
 *
 * @return array{base:string, dir:string, stop:callable}
 */
function hrServe(string $html): array
{
    $dir = storage_path('framework/testing/lane-cx-hidden-' . getmypid() . '-' . bin2hex(random_bytes(4)));
    $root = $dir . '/webroot';

    @mkdir($root, 0o777, true);

    // @vite emits absolute URLs against APP_URL, which is not where this server
    // is. Rewritten to root-relative so the real stylesheet is really fetched;
    // without it the page loads with no author rules at all and every element
    // measures `display: none`, which is a green test that proves nothing.
    $html = preg_replace('#(href|src)="https?://[^"]*/build/#', '$1="/build/', $html) ?? $html;

    file_put_contents($root . '/index.html', $html);

    exec('cp -r ' . escapeshellarg(base_path('public/build')) . ' ' . escapeshellarg($root . '/build'));

    $port = 8600 + random_int(20, 250);

    $process = proc_open(
        'php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root),
        [0 => ['pipe', 'r'], 1 => ['file', $dir . '/serve.log', 'w'], 2 => ['file', $dir . '/serve.log', 'a']],
        $pipes,
        $root
    );

    if (! is_resource($process)) {
        throw new RuntimeException('could not start the static preview server');
    }

    $base = 'http://127.0.0.1:' . $port;

    $stop = function () use ($process, $pipes, $dir) {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $status = proc_get_status($process);

        if ($status['running'] ?? false) {
            exec('pkill -P ' . (int) $status['pid'] . ' 2>/dev/null');
            proc_terminate($process);
        }

        proc_close($process);
        exec('rm -rf ' . escapeshellarg($dir));
    };

    $up = false;

    for ($i = 0; $i < 60; $i++) {
        usleep(200_000);
        $ch = curl_init($base . '/index.html');
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
        $log = @file_get_contents($dir . '/serve.log') ?: '';
        $stop();

        throw new RuntimeException("static preview never answered on {$base}\n" . substr($log, -600));
    }

    return ['base' => $base, 'dir' => $dir, 'stop' => $stop];
}

/** @return array<string, mixed> */
function hrMeasure(string $base, string $chrome, int $width, int $height, array $selectors): array
{
    $env = [
        'KBB_BROWSER_URL' => $base . '/index.html',
        'KBB_BROWSER_CHROME' => $chrome,
        'KBB_BROWSER_WIDTH' => (string) $width,
        'KBB_BROWSER_HEIGHT' => (string) $height,
        'KBB_BROWSER_SELECTORS' => json_encode(array_values($selectors)),
        'NODE_PATH' => (string) env('KBB_BROWSER_NODE_PATH', '/opt/node22/lib/node_modules'),
    ];

    $prefix = '';

    foreach ($env as $k => $v) {
        $prefix .= $k . '=' . escapeshellarg((string) $v) . ' ';
    }

    $decoded = null;

    // One retry: driving a browser against a local server is not perfectly
    // deterministic, and this must fail for the reason it is about.
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $decoded = json_decode((string) shell_exec($prefix . 'node ' . escapeshellarg(base_path('tests/browser/checkout-hidden-rows.mjs')) . ' 2>/dev/null'), true);

        if (is_array($decoded) && ($decoded['ok'] ?? false)) {
            return $decoded;
        }
    }

    throw new RuntimeException('the browser measurement did not come back: ' . json_encode($decoded));
}

/* ------------------------------------------------------------------ browser */

it('computes display:none for every row the checkout marked hidden, at 1280 and at 390', function () {
    ['chrome' => $chrome, 'missing' => $missing] = hrPrereqs();

    if ($missing !== []) {
        test()->markTestSkipped('Needs real Chromium: ' . implode('; ', $missing) . '.');
    }

    $html = hrCheckoutHtml();

    // The fixture is only worth measuring if it really produced the two hidden
    // rows and a visible one to compare them against. A page that stopped
    // rendering the gift row at all would otherwise pass this test for the
    // wrong reason.
    expect(preg_match('/<div class="sumrow js-gift-row"\s+hidden/', $html))
        ->toBe(1, 'the fixture did not produce a hidden gift row');
    expect(preg_match('/<div class="kbb-delivery-line"\s+hidden/', $html))
        ->toBe(1, 'the fixture did not produce a hidden delivery line');

    $preview = hrServe($html);

    try {
        $selectors = ['.js-gift-row', '.kbb-delivery-line', '.sumrow'];

        foreach ([[1280, 900], [390, 844]] as [$width, $height]) {
            $result = hrMeasure($preview['base'], $chrome, $width, $height, $selectors);

            $gift = $result['elements']['.js-gift-row'] ?? [];
            $line = $result['elements']['.kbb-delivery-line'] ?? [];
            $rows = $result['elements']['.sumrow'] ?? [];

            expect($gift)->not->toBeEmpty("no .js-gift-row on the page at {$width}px");
            expect($line)->not->toBeEmpty("no .kbb-delivery-line on the page at {$width}px");

            /*
             * THE CONTROL, and this test is worthless without it. If the
             * stylesheet never reached the browser there would be no author
             * `display` rule to beat the attribute, every assertion below would
             * read `none`, and the test would pass having measured a page with
             * no CSS on it at all. `.kbb-checkout .sumrow{display:flex}` is the
             * very rule the bug is made of, so a visible row computing `flex` is
             * proof that the rule is in force here. Not `each`: the block is
             * rendered twice, desktop and mobile, and the one for the other
             * viewport is legitimately display:none — as is whichever Total row
             * the payment selection has turned off.
             */
            expect(in_array('flex', array_column($rows, 'display'), true))
                ->toBeTrue("kbb-checkout.css did not reach the browser at {$width}px");

            foreach ($gift as $i => $el) {
                expect($el['hiddenAttribute'])->toBeTrue("gift row #{$i} lost its hidden attribute at {$width}px");
                expect($el['display'])->toBe('none', "the Gift wrapping row is on screen at {$width}px for a shopper who did not ask for it");
                expect($el['height'])->toBe(0, "the Gift wrapping row occupies height at {$width}px");
            }

            foreach ($line as $i => $el) {
                expect($el['hiddenAttribute'])->toBeTrue("delivery line #{$i} lost its hidden attribute at {$width}px");
                expect($el['display'])->toBe('none', "the empty delivery line is on screen at {$width}px for a shopper whose country has no recorded window");
                expect($el['height'])->toBe(0, "the empty delivery line occupies height at {$width}px");
            }
        }
    } finally {
        $preview['stop']();
    }
});

/* --------------------------------------------------------------- structural */

/**
 * What CI can carry: the rule exists, and it is the general one.
 *
 * Asserted against the checkout document rather than against a file, because
 * what matters is that the rule reaches the page the shopper loads. It lives in
 * this page's own inline <style> and not in resources/css: the server runs a
 * compiled bundle that this repo tracks and only the integrator rebuilds, so a
 * rule added to kbb-checkout.css would do nothing in production until somebody
 * remembered to build it. An inline rule ships with the Blade file.
 */
it('ships one rule that makes hidden mean hidden for the whole checkout', function () {
    app(SettingsService::class)->flush();

    $html = hrCheckoutHtml();

    expect(str_contains($html, '.kbb-checkout [hidden]{display:none!important}'))
        ->toBeTrue('The checkout has no rule restoring the hidden attribute over its own display rules.');
});

/**
 * The two rows are RENDERED and hidden, never omitted.
 *
 * This is the half the hiding exists for. checkout.js reveals both from the
 * country-change and gift endpoints, and an element that is not on the page
 * cannot be unhidden — a shopper who arrived with no gift wrapping and then
 * ticked the box would watch the Total rise with no line saying why.
 */
it('renders the gift row and the delivery line even when both are hidden', function () {
    $html = hrCheckoutHtml();

    // Anchored at the element. A bare class search would match the stylesheet,
    // which is inlined into this document.
    preg_match_all('/<div class="sumrow js-gift-row"([^>]*)>/', $html, $gift);
    preg_match_all('/<div class="kbb-delivery-line"([^>]*)>/', $html, $line);

    expect($gift[0])->not->toBeEmpty('The gift row is omitted rather than hidden; JavaScript has nothing to reveal.');
    expect($line[0])->not->toBeEmpty('The delivery line is omitted rather than hidden; JavaScript has nothing to reveal.');

    foreach ($gift[1] as $attributes) {
        expect(str_contains($attributes, 'hidden'))->toBeTrue('The gift row is on screen at a zero fee.');
    }

    foreach ($line[1] as $attributes) {
        expect(str_contains($attributes, 'hidden'))->toBeTrue('The empty delivery line is on screen.');
    }
});
