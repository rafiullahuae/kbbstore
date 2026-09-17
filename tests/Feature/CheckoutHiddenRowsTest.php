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
 * ── AND THE PAGE NOW HIDES ROWS TWO WAYS, NOT THREE — Lane DE ───────────────
 *
 * partials/checkout/order-block.blade.php had grown a third mechanism. It hid
 * the gift row with `hidden`, the tax lane's two VAT rows with an inline
 * `style="display:none"`, and the cash-on-delivery fee row with a CSS `:has()`
 * selector. The middle one existed only because `hidden` did not work here; the
 * page rule above made it work, so the two VAT rows now use the attribute like
 * everything else — and an author `!important` declaration outranks an inline
 * one, so the attribute is the stronger mechanism as well as the clearer one.
 *
 * THE COD ROWS ARE STILL CSS, AND CORRECTLY SO. `.js-fee-row` and the two Total
 * rows are shown by `:has(#payment_method_cod:checked)`: their visibility is a
 * live function of what the shopper has selected, with no JavaScript anywhere in
 * the loop. `hidden` states a fact about one moment and cannot express that, and
 * replacing the selector with script would add JavaScript to the one part of
 * this page that works without any. Both facts are measured below rather than
 * argued: the VAT rows are asserted to compute `none` FROM THE ATTRIBUTE, and
 * the fee row to compute `none` WITHOUT one.
 *
 * ── THE BLADE AND THE BUNDLE HAVE TO SHIP TOGETHER ──────────────────────────
 *
 * resources/js/kbb/checkout.js reveals the VAT rows on a country change, and it
 * now sets `.hidden` rather than `style.display`. The server runs the compiled
 * bundle this repo tracks under public/build, which only the integrator
 * rebuilds — so a package carrying the Blade change without the rebuilt asset
 * would render a row hidden by an attribute that the old bundle never clears,
 * and a shopper switching to an exclusive-tax country would see a Total with no
 * VAT row above it. The last test in this file pins both halves in source so the
 * pair cannot drift; the rebuild itself is the integrator's, and is called out
 * in the lane report.
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
use Tests\Support\PreviewPort;

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
function hrCheckoutHtml(int $codFeeFils = 0): string
{
    /*
     * The fee row is only RENDERED above a zero fee (the admin's own default is
     * zero), so a test that wants to measure it has to ask for one. Every other
     * caller passes nothing and gets the page exactly as before.
     */
    if ($codFeeFils > 0) {
        app(SettingsService::class)->set('cod_fee', $codFeeFils);
        app(SettingsService::class)->flush();
        SettingsService::forgetMemo();
        App\Models\Setting::flushMap();
    }

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

    $port = PreviewPort::claim(8620, 8850);

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

it('computes display:none for the VAT row hidden by the attribute rather than by a style', function () {
    /*
     * THE ROWS THAT USED TO CARRY AN INLINE STYLE — Lane DE.
     *
     * `.vat-add` belongs above the Total and is drawn only for an EXCLUSIVE
     * destination, where the tax was added to the figures above it; `.vat-note`
     * belongs under the Total and is the "of which" line for an inclusive or
     * printed-only basis. Exactly one is ever on screen, and this shop ships in
     * display mode, so the note is the visible one.
     *
     * The measurement that matters is the pair: the hidden one carries the
     * attribute and computes `none`, and the visible one computes `flex` — from
     * the SAME `.kbb-checkout .sumrow{display:flex}` rule that used to defeat
     * the attribute. If the page rule were missing the first assertion would
     * fail; if it were too broad the second would.
     */
    ['chrome' => $chrome, 'missing' => $missing] = hrPrereqs();

    if ($missing !== []) {
        test()->markTestSkipped('Needs real Chromium: ' . implode('; ', $missing) . '.');
    }

    $html = hrCheckoutHtml();

    expect(preg_match('/<div class="sumrow vat js-vat-row vat-add"\s+hidden/', $html))
        ->toBe(1, 'the fixture did not produce a hidden .vat-add row');
    expect(stripos($html, 'js-vat-row vat-add" style="display:none"'))
        ->toBeFalse('the VAT row is still hidden with an inline style');

    $preview = hrServe($html);

    try {
        foreach ([[1280, 900], [390, 844]] as [$width, $height]) {
            $result = hrMeasure($preview['base'], $chrome, $width, $height, ['.js-vat-row.vat-add', '.js-vat-row.vat-note']);

            $add = $result['elements']['.js-vat-row.vat-add'] ?? [];
            $note = $result['elements']['.js-vat-row.vat-note'] ?? [];

            expect($add)->not->toBeEmpty("no .vat-add row on the page at {$width}px");
            expect($note)->not->toBeEmpty("no .vat-note row on the page at {$width}px");

            foreach ($add as $i => $el) {
                expect($el['hiddenAttribute'])->toBeTrue(".vat-add #{$i} is not hidden by the attribute at {$width}px");
                expect($el['display'])->toBe('none', "the exclusive-tax VAT row is on screen at {$width}px on a shop that added no tax");
                expect($el['height'])->toBe(0, "the exclusive-tax VAT row occupies height at {$width}px");
            }

            /*
             * THE CONTROL FOR THIS TEST. The visible twin proves the author
             * display rule really is in force on these very elements, so the
             * `none` above is the attribute winning rather than a page that
             * loaded with no stylesheet at all. Not `each`: the block is
             * rendered twice, desktop and mobile, and the copy for the other
             * viewport is legitimately display:none.
             */
            expect(in_array('flex', array_column($note, 'display'), true))
                ->toBeTrue("the .vat-note row is hidden too at {$width}px, so nothing states the VAT on this checkout");

            foreach ($note as $i => $el) {
                expect($el['hiddenAttribute'])->toBeFalse(".vat-note #{$i} is hidden although this shop prints a VAT note");
            }
        }
    } finally {
        $preview['stop']();
    }
});

it('leaves the cash-on-delivery rows to CSS, which switches them live', function () {
    /*
     * THE ONE MECHANISM THAT IS NOT CONVERTED, MEASURED RATHER THAN ARGUED.
     *
     * kbb-checkout.css hides `.js-fee-row` and `.js-total-row-fee` outright and
     * shows them — swapping the Total for the fee-inclusive Total as it goes —
     * under `.kbb-checkout:has(#payment_method_cod:checked)`. Not one of those
     * three rows carries a `hidden` attribute, and not one of them may: which
     * of them is on screen is decided by the radio the shopper has selected, in
     * the browser, after the page was served. An attribute is a fact about the
     * moment the server rendered, and the server does not know what they will
     * pick.
     *
     * Cash on delivery is the selected option on this fixture, so the fee row
     * and the fee-inclusive Total are the visible pair and the plain Total is
     * the hidden one. That is the selector doing its work on live state, which
     * is exactly the property `hidden` cannot carry — and it is measured here in
     * the same breath as the assertion that none of the three was hidden by the
     * server.
     */
    ['chrome' => $chrome, 'missing' => $missing] = hrPrereqs();

    if ($missing !== []) {
        test()->markTestSkipped('Needs real Chromium: ' . implode('; ', $missing) . '.');
    }

    $html = hrCheckoutHtml(1500);

    expect(str_contains($html, 'class="sumrow js-fee-row"'))
        ->toBeTrue('the fixture did not produce a cash-on-delivery fee row to measure');
    // The selected option, read off the radio itself. Matched on the element
    // rather than on the word "checked", which appears in this document's own
    // inline stylesheet as part of the `:has()` selector under test.
    expect(preg_match('/<input id="payment_method_cod"[^>]*\schecked\b/', $html))
        ->toBe(1, 'cash on delivery is not the selected option on this fixture, so the selector below is measured against nothing');

    $preview = hrServe($html);

    try {
        foreach ([[1280, 900], [390, 844]] as [$width, $height]) {
            $result = hrMeasure($preview['base'], $chrome, $width, $height, ['.js-fee-row', '.js-total-row', '.js-total-row-fee']);

            $fee = $result['elements']['.js-fee-row'] ?? [];
            $plainTotal = $result['elements']['.js-total-row'] ?? [];
            $feeTotal = $result['elements']['.js-total-row-fee'] ?? [];

            expect($fee)->not->toBeEmpty("no .js-fee-row on the page at {$width}px");
            expect($plainTotal)->not->toBeEmpty("no .js-total-row on the page at {$width}px");
            expect($feeTotal)->not->toBeEmpty("no .js-total-row-fee on the page at {$width}px");

            // None of the three was hidden by the server. An attribute on any
            // of them would mean somebody had "unified" a row that must not be.
            foreach (['fee row' => $fee, 'plain Total' => $plainTotal, 'fee Total' => $feeTotal] as $what => $els) {
                foreach ($els as $i => $el) {
                    expect($el['hiddenAttribute'])
                        ->toBeFalse("{$what} #{$i} has grown a hidden attribute; which of these is shown belongs to the payment selection, not to the server");
                }
            }

            // And the selector really is switching them. Not `each`: the block
            // is rendered twice and the copy for the other viewport is
            // legitimately display:none at this width.
            expect(in_array('flex', array_column($fee, 'display'), true))
                ->toBeTrue("the cash-on-delivery fee row is off screen at {$width}px although COD is selected and a fee is charged");
            expect(in_array('flex', array_column($feeTotal, 'display'), true))
                ->toBeTrue("the fee-inclusive Total is off screen at {$width}px although COD is selected");
            /*
             * in_array() inside toBeFalse(), never ->not->toContain($needle,
             * $message). toContain() is VARIADIC: the sentence was a second
             * needle, the positive expectation could never find it, and `not`
             * therefore passed whatever the displays actually were. Measured
             * with 'flex' appended to the list -- the old form stayed green
             * through the very state it exists to catch.
             */
            expect(in_array('flex', array_column($plainTotal, 'display'), true))
                ->toBeFalse("both Totals are on screen at {$width}px, so the checkout shows two different Totals at once");
        }
    } finally {
        $preview['stop']();
    }
});

/**
 * The page hides rows TWO ways, and each row is on the right one — Lane DE.
 *
 * Read off the rendered document rather than off the partial, because what
 * matters is the markup the shopper's browser receives. Matched at the element
 * with preg_match_all and never by a bare class search: this page inlines a
 * stylesheet, and a search for `js-vat-row` would happily match a selector.
 */
it('hides every server-rendered row with the attribute, and only the payment rows with CSS', function () {
    $html = hrCheckoutHtml();

    // 1. Nothing on this page hides itself with an inline display any more.
    preg_match_all('/<div class="sumrow[^"]*"([^>]*)>/', $html, $rows);

    expect($rows[0])->not->toBeEmpty('no summary rows were rendered, so this proves nothing');

    foreach ($rows[1] as $i => $attributes) {
        expect(stripos($attributes, 'display:none'))
            ->toBeFalse("summary row #{$i} still hides itself with an inline style: {$attributes}");
    }

    // 2. Both VAT rows are rendered, and the one that is off belongs to the
    //    attribute. Exactly one of the pair is ever shown.
    preg_match_all('/<div class="sumrow vat js-vat-row (vat-add|vat-note)"([^>]*)>/', $html, $vat, PREG_SET_ORDER);

    expect($vat)->not->toBeEmpty('the VAT rows are omitted rather than hidden; the country-change refresh has nothing to reveal');

    foreach ($vat as $match) {
        // `.vat-add` is the exclusive-tax row and this shop adds no tax, so it
        // is the hidden one; `.vat-note` states the portion and is shown.
        $shouldBeHidden = $match[1] === 'vat-add';

        expect(str_contains($match[2], 'hidden'))
            ->toBe($shouldBeHidden, "the {$match[1]} row is on the wrong side of the Total for this shop's tax mode");
    }

    // 3. And the payment-driven rows carry no attribute at all, because CSS
    //    owns them. An attribute here would be a row frozen at whatever the
    //    server guessed the shopper would pick.
    foreach (['js-total-row', 'js-total-row-fee'] as $class) {
        preg_match_all('/<div class="sumrow tot ' . $class . '"([^>]*)>/', $html, $tot);

        expect($tot[0])->not->toBeEmpty("no .{$class} on the page");

        foreach ($tot[1] as $i => $attributes) {
            expect(str_contains($attributes, 'hidden'))
                ->toBeFalse(".{$class} #{$i} was hidden by the server; which Total is shown is the payment selection's business");
        }
    }
});

/**
 * The Blade and the bundle say the same thing about these rows.
 *
 * THE PAIR THAT MUST SHIP TOGETHER. The server runs the compiled bundle this
 * repo tracks under public/build, and only the integrator rebuilds it — so the
 * Blade rendering `hidden` and the script clearing `style.display` would leave
 * a shopper who switches to an exclusive-tax country looking at a Total with no
 * VAT row above it. Asserted against the SOURCE, which is what a package
 * carries; whether the bundle was rebuilt is the integrator's check and not one
 * a test in this repo can make without running a build it is forbidden to run.
 *
 * T_COMMENT and T_DOC_COMMENT are not stripped here because this is JavaScript
 * and PHP's tokenizer does not read it. Instead the match is anchored to the
 * assignment itself — `row.hidden =` — which cannot appear in prose by accident
 * the way a quoted sentence can.
 */
it('drives the VAT rows from the attribute in checkout.js as well as in the Blade', function () {
    $js = (string) file_get_contents(resource_path('js/kbb/checkout.js'));

    // The block that switches the two rows, found by the selector it queries.
    $at = strpos($js, ".querySelectorAll('.js-vat-row')");

    expect($at)->not->toBeFalse('checkout.js no longer switches the VAT rows at all');

    $block = substr($js, $at, 400);

    expect(str_contains($block, 'row.hidden ='))
        ->toBeTrue('checkout.js does not set the hidden attribute on the VAT rows, which the Blade now relies on');
    expect(preg_match('/row\.style\.display\s*=/', $block))
        ->toBe(0, 'checkout.js still writes an inline display on the VAT rows, which the page rule now overrides');

    // And the Blade half, so the two cannot drift apart in either direction.
    $blade = (string) file_get_contents(resource_path('views/partials/checkout/order-block.blade.php'));

    expect(preg_match('/js-vat-row[^"]*"@if \(.*?\) hidden @endif/', $blade))
        ->toBeGreaterThan(0, 'the order block no longer hides the VAT rows with the attribute checkout.js drives');
});
