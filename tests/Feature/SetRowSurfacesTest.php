<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Services\CartService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The set row: where it is drawn, and what a basket holding one costs.
 * (Lane SET)
 *
 * ── THE SURFACES ARE PINNED AS A COUNT OF ONE, NOT AS AN ABSENCE ───────────
 *
 * Every assertion below is on the FINISHED state. `substr_count(...) === 1` is
 * green in this worktree the day it is written AND after the integrator wires
 * the screen up; zero is the "built, never wired up" shape this repository
 * keeps finding, and two registers a sidebar entry twice and wraps window.go
 * around its own wrapper. CLAUDE.md names the opposite assertion — "prove my
 * work is NOT mounted yet" — as something that has cost this project three
 * round trips.
 *
 * ── WHAT IS DELIBERATELY NOT ASSERTED ──────────────────────────────────────
 *
 * What the partial DRAWS. The owner picked the fanned stack after this module
 * was built, and a later change to that drawing is a change to one file; a test
 * that pinned the markup would go red for a design change rather than for a
 * defect. What matters is that every surface goes through THAT ONE FILE, which
 * is what these counts prove.
 */
function setSurfaceSource(string $path): string
{
    return (string) file_get_contents(resource_path('views/'.$path));
}

it('draws every set through one partial, included exactly once per surface', function () {
    /*
     * MUTATION NOTE. Delete the @include from any one of these files and that
     * file's count is 0; paste it twice and the count is 2. Either is red. RUN.
     */
    $surfaces = [
        'store/cart-inner.blade.php' => 1,
        'partials/checkout/summary-items.blade.php' => 1,
        'partials/checkout/received-line.blade.php' => 1,
        'store/account/order-detail.blade.php' => 1,
        // The cart panel draws it twice ON PURPOSE: the basket tab and the
        // browsed tab are two different rows in one file.
        'partials/cart-drawer.blade.php' => 2,
    ];

    foreach ($surfaces as $file => $expected) {
        expect(substr_count(setSurfaceSource($file), "@include('partials.set-row'"))
            ->toBe($expected, $file.' should include the set partial '.$expected.' time(s)');
    }
});

it('keeps the popup markup and the design switch in one file', function () {
    /*
     * The owner asked for "What's inside" as a tiny popup, and asked in the
     * same breath that a later change to it be one file. This is that promise
     * as a test: the button, the popup and the fan of member circles exist in
     * partials/set-row.blade.php and in no other Blade file in this
     * application.
     *
     * MUTATION NOTE. Copy the popup markup into cart-inner.blade.php — a second
     * description of a set, which is the thing this module is built to prevent
     * — and this is red. RUN.
     */
    $partial = setSurfaceSource('partials/set-row.blade.php');

    expect($partial)->toContain('data-kset-toggle')
        ->and($partial)->toContain('aria-expanded')
        ->and($partial)->toContain('aria-controls')
        ->and($partial)->toContain('kset-pop')
        ->and($partial)->toContain('kset-fan');

    /*
     * ▲ BLADE COMMENTS ARE STRIPPED FIRST. (Lane SA)
     *
     * This case is about the MARKUP living in one file, and a docblock that
     * names `kset-pop` in prose is not a second description of a set — it is
     * usually the opposite: the note in layouts/store.blade.php says WHY the
     * Appearance -> Set block has to be emitted in the head, and the reason is
     * that the checkout's `.kbb-checkout .kset-pop.is-open` escape hatch must
     * keep winning the tie. Refusing to let a comment explain that would make
     * the next reader move the include and slice the popup back to one line on
     * a phone.
     *
     * The sibling case below strips comments the same way and for the same
     * reason. Stripping does not weaken this one: a real second copy of the
     * popup would be markup, not a comment, and would still be found.
     */
    $strip = static fn (string $s): string => (string) preg_replace('/\{\{--.*?--\}\}/s', '', $s);

    $others = collect(\Illuminate\Support\Facades\File::allFiles(resource_path('views')))
        ->filter(fn ($f) => str_ends_with($f->getFilename(), '.blade.php'))
        ->filter(fn ($f) => $f->getFilename() !== 'set-row.blade.php')
        ->filter(fn ($f) => str_contains($strip((string) file_get_contents($f->getPathname())), 'kset-pop'))
        ->map(fn ($f) => $f->getRelativePathname())
        ->values()
        ->all();

    expect($others)->toBe([]);
});

it('never measures layout from JavaScript', function () {
    /*
     * CLAUDE.md: "No JavaScript that measures layout — this project sizes with
     * calc() for a reason, and two tests forbid the element-measuring APIs by
     * name." The popup opens upward or downward by a CLASS the markup carries,
     * never by a measured rectangle.
     *
     * MUTATION NOTE. Add a getBoundingClientRect() call to the popup script and
     * this is red. RUN.
     */
    $partial = setSurfaceSource('partials/set-row.blade.php');

    // The prose explains the rule by naming the APIs, so the explanation is
    // taken out before the code is scanned — the lesson UgcRailR3Test paid for
    // twice.
    $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $partial);
    $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);

    foreach ([
        'getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'offsetTop', 'offsetLeft',
        'clientWidth', 'clientHeight', 'getComputedStyle', 'scrollWidth', 'scrollHeight',
    ] as $api) {
        // NOT `expect($code)->not->toContain($api, $message)`: toContain() takes
        // NEEDLES, so a second argument is read as a second needle and the
        // expectation cannot fail. ExpectationsThatCannotFailTest catches that,
        // and caught it here.
        expect(str_contains($code, $api))
            ->toBeFalse($api.' measures layout and this project sizes with calc()');
    }
});

it('draws no member label on a thumbnail, which is what the owner asked for', function () {
    /*
     * The owner: "the thumnail we don't need any label on thubmail etc."
     *
     * Gradient::initials() is the label he is asking not to see — it is what
     * the cart row draws into a pictureless thumbnail — and the member circles
     * must not call it even for a member with no picture. Gradient::for(), the
     * colour, is the right fallback and is used.
     *
     * MUTATION NOTE. Put Gradient::initials(...) inside the .kset-c span and
     * this is red. RUN.
     */
    $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', setSurfaceSource('partials/set-row.blade.php'));

    expect($code)->not->toContain('Gradient::initials')
        ->and($code)->toContain('Gradient::for');
});

it('escapes the checkout summary\'s own clipping on a phone', function () {
    /*
     * ── THE DEFECT, FOUND IN THE 390px SHOT ────────────────────────────────
     *
     * Below 760px the checkout collapses its summary:
     * `.kbb-checkout .panels { max-height:148px; overflow:hidden }` in
     * resources/css/kbb/kbb-checkout.css. An absolutely-positioned popup inside
     * that is CLIPPED — the first shot of this screen showed one visible line
     * of a three-line box, which on a phone reads as a broken control rather
     * than a short one.
     *
     * `position:fixed` is the only thing that escapes an overflow:hidden
     * ancestor, and it needs no measurement: the box is pinned to the
     * viewport's own edges with logical insets, so where it lands is decided
     * entirely in CSS. Scoped to `.kbb-checkout` and to the phone, so the cart
     * page (which does not clip) and the desktop summary (which is not
     * collapsed) are untouched — and a shop with no sets has no `.kset-pop` on
     * the page at all.
     *
     * MUTATION NOTE. Delete the `.kbb-checkout .kset-pop.is-open` rule from
     * partials/set-row.blade.php and this is red — and the 390px checkout shot
     * goes back to showing one line of three. RUN.
     */
    $partial = setSurfaceSource('partials/set-row.blade.php');

    expect($partial)->toMatch('/\.kbb-checkout\s+\.kset-pop\.is-open\s*\{[^}]*position:fixed/');
});

/* ══════════════════════════════════════════════════════ the query budget ══ */

it('costs a flat number of queries however many members a basket set has', function () {
    /*
     * ── WHY THIS IS MEASURED AND NOT ASSERTED ──────────────────────────────
     *
     * StorefrontQueryBudgetTest's own argument: a budget alone cannot catch an
     * N+1, because a page doing one query per member passes any ceiling you
     * like on a small enough fixture. A count that does not move when the set
     * triples is the only evidence that the page batches its loads.
     *
     * MUTATION NOTE. Delete the SetEagerLoad::on() call from
     * CartController::loadCart() and this is red: SetContents::fromProduct()
     * lazy-loads `setItems` and then `member` per row, so the three-member
     * basket and the nine-member one differ. RUN.
     */
    $build = function (int $members): Cart {
        $set = Product::create([
            'slug' => 'set-'.Str::random(8), 'name' => 'Glow Set', 'type' => 'set',
            'status' => 'publish', 'is_visible' => true, 'price' => 12000, 'stock_status' => 'instock',
        ]);

        for ($i = 0; $i < $members; $i++) {
            $member = Product::create([
                'slug' => 'm-'.Str::random(8), 'name' => 'Member '.$i, 'type' => 'simple',
                'status' => 'publish', 'is_visible' => true, 'price' => 9000, 'stock_status' => 'instock',
            ]);

            ProductSetItem::create([
                'set_product_id' => $set->id, 'member_product_id' => $member->id,
                'quantity' => 1, 'position' => $i,
            ]);
        }

        $cart = Cart::create([
            'token' => Str::random(32), 'currency' => 'AED', 'status' => 'active',
            'shipping_country' => 'AE', 'last_activity_at' => now(),
        ]);

        $cart->items()->create(['product_id' => $set->id, 'quantity' => 1, 'unit_price' => 12000]);

        return $cart;
    };

    /*
     * ▲ ONE LISTENER FOR THE WHOLE CASE, and the counter reset between
     *   measurements. DB::listen ACCUMULATES rather than replaces, so a
     *   listener registered inside the measuring closure makes the Nth
     *   measurement report N times its real count — the mistake
     *   StorefrontQueryBudgetTest documents at length, which reported that
     *   suite's own /sitemap.xml at 416 queries when it runs 20. Registered
     *   once here, it cannot.
     */
    $queries = 0;
    DB::listen(function () use (&$queries) { $queries++; });

    $measure = function (Cart $cart) use (&$queries): int {
        // Scoped bindings survive between requests in a test process and
        // nothing in Laravel's test client resets them, so a second request
        // through a page reuses the first one's memos and reports a count no
        // real visitor ever gets. Same reset StorefrontQueryBudgetTest does.
        \App\Services\SettingsService::forgetMemo();
        app()->forgetScopedInstances();

        $queries = 0;

        test()->withCredentials()
            ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
            ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
            ->get('/cart')->assertOk();

        return $queries;
    };

    $three = $build(3);
    $nine = $build(9);

    // Warm-up, for the process-level memos. See StorefrontQueryBudgetTest.
    $measure($three);

    $small = $measure($three);
    $large = $measure($nine);

    expect($large)->toBe(
        $small,
        "/cart ran {$large} queries for a nine-member set and {$small} for a three-member one. "
        .'A difference is one query per member, which is the N+1 SetEagerLoad exists to stop.'
    );
});
