<?php

declare(strict_types=1);

/**
 * Lane QK12, chunk B -- the owner's cart layout asks, 9 October, on phone
 * screenshots:
 *
 *   3. "in the cart page : the order total should come immeditately after your
 *      bag section and the recommended for you should come below the order
 *      total" -- the phone read Your Bag, Recommended for you, Order Total.
 *      Appearance -> Cart page -> Summary & trust -> "Phone: Order total
 *      straight after Your Bag", ON. Desktop already holds the total in its
 *      right-hand column and is left exactly as it was.
 *   6. "give space below the buttons, approx 50px" -- the cart panel's
 *      Cart / Checkout sat flush on the bottom edge of a phone. Appearance ->
 *      Cart panel -> Mobile -> "Space below the buttons", 50.
 *   7. "on cart floating Proceed to checkout row also give space below around
 *      50px" -- Appearance -> Cart page -> Docked rows -> "Space under the
 *      checkout row", 50 (was 0), with the page's end moving down by the same.
 *
 * Measured in Chromium at 390 (docs/lane-qk12-shots/B*): the order reads
 * bag@253 > total@365 > rec@545 (was rec@365 > total@560); the panel's buttons
 * end 50px above the screen's bottom (was 12); the docked bar carries 50px
 * under it and .wrap's bottom padding went 86 -> 136px, so the last card still
 * clears the bar by the same 70px. At 1280 all three read as before.
 */

use App\Models\Product;
use App\Services\CartPage;
use App\Services\CartPanel;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;

function qk12bFresh(): void
{
    Cache::flush();
    SettingsService::forgetMemo();
}

/** The squeezed cart page with one line in the basket. */
function qk12bCart(): string
{
    app(CartPage::class)->save(['layout' => 'squeeze']);
    qk12bFresh();

    $p = Product::create([
        'name' => 'Glow Serum', 'slug' => 'qk12b-glow-serum', 'status' => 'publish',
        'is_visible' => true, 'price' => 12000, 'stock_status' => 'instock',
    ]);
    test()->postJson('/api/cart/add', ['product_id' => $p->id, 'quantity' => 1])->assertOk();

    return (string) test()->get('/cart/')->assertOk()->getContent();
}

/* ─────────────────────── 3. the cart page's order ──────────────────────── */

it('puts the Order Total straight after Your Bag on a phone, and the rail below it', function () {
    // THE DEFECT: the phone's grid read basket, rail, summary -- in document
    // order, with .cpg-side display:contents so the summary sat last.
    // Mutation: sum_first's default -> false, or drop either rule -> red.
    expect(CartPage::SCHEMA['sum_first'][2])->toBeTrue();

    $html = qk12bCart();

    expect($html)->toMatch('#<div class="kbb-cartpage[^"]* cpg-squeeze[^"]* cpg-sumfirst#')
        // One column at every width when the two-column layout is off ...
        ->and($html)->toContain('.kbb-cartpage.cpg-squeeze.cpg-sumfirst:not(.cpg-d) .grid > :is(.cpg-rec,.coupon){order:1}')
        // ... and with it on (the default), ONLY under the exact complement of
        // the desktop query, so the right-hand column's page cannot move.
        ->and($html)->toContain("@media not all and (min-width: 1024px){\n  .kbb-cartpage.cpg-squeeze.cpg-sumfirst.cpg-d .grid > :is(.cpg-rec,.coupon){order:1}\n}")
        ->and($html)->toContain('@media (min-width: 1024px){');

    // The rail and the summary are still each drawn once, in the markup order
    // cart.js repaints -- the move is CSS only.
    expect(substr_count($html, '<section class="cpg-rec">'))->toBeLessThanOrEqual(1)
        ->and(substr_count($html, '<aside class="sum">'))->toBe(1);
});

it('draws the old order again with the switch off', function () {
    app(CartPage::class)->save(['sum_first' => false]);

    expect(qk12bCart())->not->toMatch('#class="kbb-cartpage[^"]*cpg-sumfirst#');
});

/* ─────────────────── 6. space below the panel's buttons ────────────────── */

it('leaves 50px under the cart panel buttons on a phone, max()ed with the home bar', function () {
    // THE DEFECT: `.dfoot{padding:12px 11px}` in the 680px block -- the
    // buttons sat 12px off the bottom of a phone. Mutation: the default back
    // to 12, or `+` for max() -> red.
    expect(CartPanel::SCHEMA['foot_gap_m'][2])->toBe(50)
        ->and(app(CartPanel::class)->cssVariables())->toEndWith(';--cp-footgap-m:50px');

    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    expect($css)->toContain('.dfoot{padding:12px 11px max(var(--cp-footgap-m,12px),env(safe-area-inset-bottom,0px))}')
        // the laptop's foot is untouched
        ->and($css)->toContain('.dfoot{border-top:1px solid var(--line-2);padding:16px 20px;flex-shrink:0}');

    // Inside the panel's phone block (max-width:680px), not outside it.
    $phone = strpos($css, '@media(max-width:680px){');
    $rule = strpos($css, '.dfoot{padding:12px 11px max(');
    expect($phone)->not->toBeFalse()->and($rule)->toBeGreaterThan($phone);

    app(CartPanel::class)->save(['foot_gap_m' => 20]);
    qk12bFresh();
    expect(app(CartPanel::class)->cssVariables())->toContain('--cp-footgap-m:20px');
});

/* ───────────────── 7. space below the cart page's docked bar ───────────── */

it('puts 50px under the docked Proceed to Checkout bar and moves the page end with it', function () {
    // THE DEFECT: bar_pad shipped at 0, so the bar sat on the bottom edge.
    // Mutation: the default back to 0 -> red; drop bar_pad from --cpg-bars ->
    // the last card slides under the taller bar, red on the reservation line.
    expect(CartPage::SCHEMA['bar_pad'][2])->toBe(50)
        ->and(CartPage::SCHEMA['bar_pad'][4]['max'])->toBe(80);

    $html = qk12bCart();
    expect($html)->toContain('--cpg-bar-pad:50px')
        ->and($html)->toContain('--cpg-bars:calc(var(--cpg-addr-h) + var(--cpg-co-h) + var(--cpg-bar-pad));')
        ->and($html)->toContain('.kbb-cartpage.cpg-squeeze .wrap{padding-bottom:calc(var(--cpg-bars) + 24px)}')
        ->and($html)->toContain('padding-bottom:max(var(--cpg-bar-pad), env(safe-area-inset-bottom, 0px));');
});

it('stores 50 over a saved 0 when the package is applied', function () {
    app(CartPage::class)->save(['bar_pad' => 0]);
    qk12bFresh();
    expect(app(CartPage::class)->get('bar_pad'))->toBe(0);

    (require database_path('migrations/2027_10_19_110000_qk12b_cart_spacing.php'))->up();
    qk12bFresh();

    expect(app(CartPage::class)->get('bar_pad'))->toBe(50);
});
