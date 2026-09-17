<?php

declare(strict_types=1);

/**
 * ONE ORDER, TWO COPIES OF ITS RECEIPT, AND THEY STATE THE SAME MONEY — Lane EZ.
 *
 * ── THE DIVERGENCE THIS FILE EXISTS FOR ─────────────────────────────────────
 *
 * App\Services\Mail\OrderEmailPresenter's header settled the principle for this
 * project in its own words — "on a receipt it is a misstatement… a receipt may
 * not round" — and renders every emailed figure at Money::minorExponent().
 * The customer's OWN copies of that same receipt did not follow it. They called
 * Money::format() with no width, which follows Money::displayDecimals(), and
 * displayDecimals() is 0 on this store.
 *
 * Measured, on one real order — subtotal 9040 fils, discount 60, total 8980:
 *
 *     /my-account/orders/{id}           the emailed receipt
 *       Subtotal   AED 90                 Subtotal          AED 90.40
 *       TINY     – AED 1                  Discount (TINY)   AED -0.60
 *       Total      AED 90                 Delivery          AED 0.00
 *                                         Total             AED 89.80
 *
 * Three untruths in four lines: the subtotal is 40 fils short, 90 − 1 = 90 so
 * the column does not add up, and the total the customer can screenshot is
 * 20 fils HIGHER than the total they were emailed for the same order.
 *
 * ── WHAT IS ASSERTED, AND WHY IT WOULD HAVE CAUGHT IT ───────────────────────
 *
 * Not "the figures are formatted a certain way" — that is the kind of assertion
 * that passes because both sides were changed together and proves nothing. The
 * two surfaces are parsed INDEPENDENTLY, back into integer fils, and then:
 *
 *   1. every figure the two copies both print agrees, label for label;
 *   2. every figure equals the ORDER COLUMN behind it, to the fil;
 *   3. the printed column ADDS UP: the rows above the rule sum to the printed
 *      total, as arithmetic over the parsed figures rather than a constant.
 *
 * (2) and (3) are what make this test independent of the fix. They are stated
 * against `orders.subtotal`, `orders.total` and so on — never against the
 * rendering — so they fail on the code as it stood whichever way the rendering
 * had been changed, and they would fail again if a later lane rounded any of
 * these surfaces back. Verified by reverting the four views and re-running: see
 * the lane report.
 *
 * ── THE ZERO-DELIVERY LINE ──────────────────────────────────────────────────
 *
 * The account page prints "Free" where the email prints AED 0.00. That is not
 * a rounding: zero IS what was charged and the word states it. accountFigures()
 * reads it as 0 fils and holds it to the emailed figure like any other.
 *
 * ── DELIBERATELY OUT OF SCOPE ───────────────────────────────────────────────
 *
 * The cart and the checkout ledger (partials/checkout/order-block,
 * store/checkout, store/cart-inner) carry the same rounding. A live basket is
 * not a receipt for an order already placed, and repainting them changes how
 * every shopping page looks — a question for the owner, asked separately.
 */

use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Mail\OrderEmailPresenter;
use App\Services\SettingsService;
use App\Support\Money;

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
});

/* ------------------------------------------------------------------ fixtures */

function receiptCustomer(string $email = 'receipts@example.com'): Customer
{
    return Customer::create(['name' => 'Ada Shopper', 'email' => $email, 'password' => 'password123']);
}

/**
 * An order whose money columns are exactly what the caller asked for.
 *
 * Nothing is derived: `total` is passed in, so a test that mis-states the sum
 * fails on the arithmetic assertion rather than being quietly made consistent
 * by the fixture.
 */
function receiptOrder(Customer $customer, array $columns, array $lines = []): Order
{
    static $seq = 0;
    $seq++;

    $order = Order::create(array_merge([
        'order_number' => 'RCPT' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
        'customer_id' => $customer->id,
        'email' => $customer->email,
        'status' => 'processing',
        'currency' => 'AED',
        'discount_total' => 0,
        'shipping_total' => 0,
        'fee_total' => 0,
        'gift_fee' => 0,
        'tax_total' => 0,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
        'shipping_address' => [
            'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'line1' => '12 Marina Walk', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE',
        ],
    ], $columns));

    foreach ($lines ?: [['quantity' => 2, 'unit_price' => intdiv((int) $columns['subtotal'], 2), 'total' => (int) $columns['subtotal']]] as $line) {
        $order->items()->create(array_merge([
            'name' => 'Rice Toner',
            'brand' => 'Beauty of Joseon',
            'subtotal' => $line['total'],
        ], $line));
    }

    return $order->fresh();
}

function receiptSignIn(Customer $customer)
{
    return test()->withSession(['login_customer_' . sha1(\Illuminate\Auth\SessionGuard::class) => $customer->id]);
}

/* -------------------------------------------------------------- the parsers */

/**
 * A printed money string, back to the integer fils it claims to be.
 *
 * Deliberately the inverse of the RENDERING, not of Money: it reads the digits
 * off the page. "AED 90.40" -> 9040, "AED 90" -> 9000. That is the whole point
 * — a page printing AED 90 for 9040 fils parses to 9000 and fails, which is the
 * divergence this file is about.
 */
function filsFromPrinted(string $text): int
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\u{2013}", "\u{2212}", ','], ['-', '-', ''], $text);

    if (preg_match('~(-?)\s*(?:AED)?\s*(-?)(\d+)(?:\.(\d+))?~u', $text, $m) !== 1) {
        throw new RuntimeException('No figure in: ' . $text);
    }

    $exp = Money::minorExponent();
    $frac = str_pad(substr((string) ($m[4] ?? ''), 0, $exp), $exp, '0', STR_PAD_RIGHT);
    $value = ((int) $m[3]) * (10 ** $exp) + (int) ($frac === '' ? '0' : $frac);

    return ($m[1] === '-' || $m[2] === '-') ? -$value : $value;
}

/**
 * The account order-detail page's ledger, as label => fils.
 *
 * Read out of the markup, not out of the view's variables, so the assertion is
 * about what the customer is shown.
 *
 * Labels are canonicalised to the ones the emailed receipt uses, because the
 * two copies word three rows differently and this file is about the FIGURES:
 * the page prints the bare coupon code where the email prints "Discount (CODE)",
 * and prints the discount positive behind an en dash where the email prints it
 * negative. Both mean money off, and both must mean the same amount off.
 */
function accountFigures(string $html): array
{
    // The closing </div> of the LAST row is inside the capture on purpose: the
    // block ends "</div></div>", and a capture that stops before the first of
    // the pair silently drops the Total row — the one figure this file is most
    // about.
    if (preg_match('~<div class="kbbod-totals">(.*?</div>)\s*</div>~s', $html, $block) !== 1) {
        throw new RuntimeException('No .kbbod-totals block on the page.');
    }

    // A row contains no nested <div>, so it is safe to cut them out whole and
    // split each into its label span and everything after it. The VALUE is not
    // matched with a non-greedy </span>: Money::format() nests the currency
    // symbol in a span of its own, so that stops at "AED" and silently reads
    // every figure as nothing.
    preg_match_all('~<div class="kbbod-row[^"]*">(.*?)</div>~s', $block[1], $rows);

    $out = [];

    foreach ($rows[1] as $row) {
        if (preg_match('~<span>(.*?)</span>(.*)$~s', $row, $parts) !== 1) {
            throw new RuntimeException('Unreadable totals row: ' . $row);
        }

        $label = trim(html_entity_decode(strip_tags($parts[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = trim($parts[2]);

        // "Free" is a zero delivery line stated in a word. Zero is what was
        // charged, so the word is true and it is read as the figure it is.
        $fils = str_contains($value, 'kbbod-free') ? 0 : filsFromPrinted($value);

        $key = match (true) {
            $label === 'Subtotal' => 'Subtotal',
            str_starts_with($label, 'Delivery') => 'Delivery',
            $label === 'Gift wrapping' => 'Gift wrapping',
            str_starts_with($label, 'VAT at ') => $label,
            $label === 'Total' => 'Total',
            str_ends_with($label, ' fee') => $label,
            // Anything left is the discount row: the coupon code, or the word
            // "Discount" when the order carries no code.
            default => 'Discount',
        };

        // The discount row prints a positive figure behind an en dash. It came
        // OFF the bill, so it is carried as the negative the email prints.
        $out[$key] = $key === 'Discount' ? -abs($fils) : $fils;
    }

    if (preg_match('~<p class="kbbod-vatnote">(.*?):\s*(.*?)</p>~s', $html, $note) === 1) {
        $out['(note) ' . trim(html_entity_decode(strip_tags($note[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))] = filsFromPrinted($note[2]);
    }

    return $out;
}

/** The emailed receipt's ledger, as label => fils, read the same way. */
function emailFigures(Order $order): array
{
    $presented = app(OrderEmailPresenter::class)->present($order);

    $out = [];

    foreach ($presented['totals'] as $row) {
        $label = str_starts_with($row['label'], 'Discount') ? 'Discount' : $row['label'];
        $out[$label] = filsFromPrinted($row['html']);
    }

    if (($presented['vatNote'] ?? null) !== null) {
        $out['(note) ' . $presented['vatNote']['label']] = filsFromPrinted($presented['vatNote']['html']);
    }

    return $out;
}

/** Every line's unit price and line total off the account page, in fils. */
function accountLineFigures(string $html): array
{
    // ...</span></span>, not ...</span>: the outer price span wraps an inner
    // currency-symbol span, and stopping at the first close reads "AED".
    preg_match_all('~<span class="kbbod-meta">.*?×\s*(<span class="woocommerce-Price-amount.*?</span></span>)~s', $html, $units);
    preg_match_all('~<div class="kbbod-linetotal">(.*?)</div>~s', $html, $totals);

    return [
        'units' => array_map('filsFromPrinted', $units[1]),
        'totals' => array_map('filsFromPrinted', $totals[1]),
    ];
}

/**
 * The five baskets this file covers, each named for the figure that breaks the
 * rounded rendering. Every one carries a fils fraction that AED-0-decimals
 * cannot print, except `whole dirhams`, which carries none on purpose.
 */
dataset('receipt baskets', [
    'a discount' => [[
        'subtotal' => 9040, 'discount_total' => 60, 'coupon_code' => 'TINY',
        'shipping_total' => 0, 'total' => 8980,
    ]],
    'delivery and a gift fee' => [[
        'subtotal' => 9040, 'shipping_total' => 1550,
        'fee_total' => 1275, 'gift_fee' => 1275, 'total' => 11865,
    ]],
    'a payment fee' => [[
        'subtotal' => 9040, 'shipping_total' => 1550,
        'fee_total' => 525, 'gift_fee' => 0, 'total' => 11115,
    ]],
    'tax added on top' => [[
        'subtotal' => 9040, 'shipping_total' => 0,
        'tax_total' => 452, 'tax_rate' => 5.0, 'tax_basis' => 'exclusive',
        'total' => 9492,
    ]],
    'tax inside the prices' => [[
        'subtotal' => 9040, 'shipping_total' => 0,
        'tax_total' => 430, 'tax_rate' => 5.0, 'tax_basis' => 'inclusive',
        'total' => 9040,
    ]],
    'whole dirhams' => [[
        'subtotal' => 20000, 'shipping_total' => 2000, 'total' => 22000,
    ]],
]);

/* ------------------------------------------------- 1. the two copies agree */

it('prints the same figure on the account page as in the emailed receipt', function (array $columns) {
    $customer = receiptCustomer();
    $order = receiptOrder($customer, $columns);

    $html = receiptSignIn($customer)->get('/my-account/orders/' . $order->id)->assertOk()->getContent();

    $onPage = accountFigures($html);
    $emailed = emailFigures($order);

    // Neither copy may carry a row the other does not: a figure printed on one
    // and missing from the other is a disagreement about the bill, not a
    // formatting difference. This is what catches the account page's missing
    // VAT row, which left a AED 4.52 hole in the column on an exclusive order.
    expect(array_keys($onPage))->toEqualCanonicalizing(array_keys($emailed));

    foreach ($emailed as $label => $fils) {
        expect($onPage[$label])->toBe($fils, 'the two copies disagree about "' . $label . '"');
    }
})->with('receipt baskets');

/* --------------------------------------- 2. each figure is the column itself */

it('prints each figure as the fils the order actually recorded', function (array $columns) {
    $customer = receiptCustomer();
    $order = receiptOrder($customer, $columns);

    $html = receiptSignIn($customer)->get('/my-account/orders/' . $order->id)->assertOk()->getContent();
    $onPage = accountFigures($html);

    // Asserted against the COLUMNS, never against the rendering. A page that
    // rounds 9040 to AED 90 parses back to 9000 and fails here regardless of
    // what any other surface prints — which is what makes this independent of
    // the change it guards.
    expect($onPage['Subtotal'])->toBe((int) $order->subtotal);
    expect($onPage['Total'])->toBe((int) $order->total);
    expect($onPage['Delivery'])->toBe((int) $order->shipping_total);

    if ((int) $order->discount_total !== 0) {
        expect($onPage['Discount'])->toBe(-abs((int) $order->discount_total));
    }

    if ((int) $order->gift_fee > 0) {
        expect($onPage['Gift wrapping'])->toBe((int) $order->gift_fee);
    }

    $paymentFee = max(0, (int) $order->fee_total - (int) $order->gift_fee);

    if ($paymentFee > 0) {
        expect($onPage[$order->paymentLabel() . ' fee'])->toBe($paymentFee);
    }

    // And the lines, which are the figures a customer checks first.
    $lines = accountLineFigures($html);

    foreach ($order->items as $i => $item) {
        expect($lines['units'][$i])->toBe((int) $item->unit_price);
        expect($lines['totals'][$i])->toBe((int) $item->total);
    }
})->with('receipt baskets');

/* ------------------------------------------------ 3. the column adds up */

it('prints a column of figures that sums to the printed total', function (array $columns) {
    $customer = receiptCustomer();
    $order = receiptOrder($customer, $columns);

    $html = receiptSignIn($customer)->get('/my-account/orders/' . $order->id)->assertOk()->getContent();
    $onPage = accountFigures($html);

    $total = $onPage['Total'];

    // Everything above the rule, whatever the rows happen to be. A note under
    // the total is a portion OF it and is excluded by construction — it is
    // keyed "(note) …" precisely so it can never be summed into the column.
    $sum = 0;

    foreach ($onPage as $label => $fils) {
        if ($label !== 'Total' && ! str_starts_with($label, '(note) ')) {
            $sum += $fils;
        }
    }

    expect($sum)->toBe($total, 'the printed rows do not add up to the printed total');
})->with('receipt baskets');

/* --------------------------------- 4. every other copy of the same receipt */

it('states the same total on the order list, the tracker and the thank-you page', function (array $columns) {
    $customer = receiptCustomer();
    $order = receiptOrder($customer, $columns);
    $total = (int) $order->total;

    $list = receiptSignIn($customer)->get('/my-account/orders')->assertOk()->getContent();
    preg_match('~<span class="kbbol-total">(.*?)</span>\s*<span class="kbbol-pill~s', $list, $m);
    expect(filsFromPrinted($m[1]))->toBe($total, 'the order list rounds the total');

    $track = test()->get('/track-my-order?order=' . $order->order_number . '&email=' . urlencode((string) $order->email))
        ->assertOk()->getContent();
    preg_match('~<span>Order total</span><span>(.*?)</span>\s*</div>~s', $track, $m);
    expect(filsFromPrinted($m[1]))->toBe($total, 'the order tracker rounds the total');

    $success = test()->withSession(['kbb_last_order' => $order->order_number])
        ->get('/checkout/success?order=' . $order->order_number)->assertOk()->getContent();
    preg_match('~<dt>Total (?:paid|to pay)</dt><dd>(.*?)</dd>~s', $success, $m);
    expect(filsFromPrinted($m[1]))->toBe($total, 'the thank-you page rounds the total');
})->with('receipt baskets');

/* ------------------------------------------------------------------------- */
/* 5. THE DELIBERATE BOTH-SIDES REGRESSION GUARD                             */
/* ------------------------------------------------------------------------- */

/**
 * PASSES ON BOTH SIDES OF THIS LANE'S CHANGE, BY CONSTRUCTION.
 *
 * An order whose every figure is a whole number of dirhams has nothing for the
 * rounding to hide, so this lane must not move a fil of it. The guard is stated
 * at the value the customer reads, in fils, because the BYTES cannot be
 * identical and it would be dishonest to claim they are: at
 * displayDecimals() = 0 the old rendering emitted `AED 200`, and at
 * minorExponent() = 2 the new one emits `AED 200.00`. Measured, both.
 *
 * What is guarded is the thing that matters and that DOES hold on both sides:
 * for this order every printed figure is the exact fils of the column behind
 * it, the column adds up, and the two copies of the receipt agree — before the
 * change and after it. Run this file at the parent commit with the four views
 * reverted and this test passes while the four above it fail; that separation
 * is the proof the others are testing the change rather than describing it.
 */
it('does not move a whole-dirham order by a fil — the both-sides guard', function () {
    $customer = receiptCustomer();
    $order = receiptOrder($customer, [
        'subtotal' => 20000, 'shipping_total' => 2000, 'total' => 22000,
    ], [['quantity' => 2, 'unit_price' => 10000, 'total' => 20000]]);

    $html = receiptSignIn($customer)->get('/my-account/orders/' . $order->id)->assertOk()->getContent();
    $onPage = accountFigures($html);
    $lines = accountLineFigures($html);

    expect($onPage['Subtotal'])->toBe(20000)
        ->and($onPage['Delivery'])->toBe(2000)
        ->and($onPage['Total'])->toBe(22000)
        ->and($lines['units'][0])->toBe(10000)
        ->and($lines['totals'][0])->toBe(20000)
        ->and($onPage['Subtotal'] + $onPage['Delivery'])->toBe($onPage['Total'])
        ->and($onPage)->toEqual(emailFigures($order));

    // And no fraction appears where the order has none: whatever width the
    // figure is printed at, a whole-dirham order prints whole dirhams.
    foreach ([$onPage['Subtotal'], $onPage['Delivery'], $onPage['Total']] as $fils) {
        expect($fils % (10 ** Money::minorExponent()))->toBe(0);
    }
});
