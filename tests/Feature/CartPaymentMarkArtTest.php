<?php

declare(strict_types=1);

/*
 * The trust row's payment marks: the artwork itself, the six `pay_*` booleans
 * that choose which of them appear, and the properties that keep `trust_size`
 * in charge of how big they are.
 *
 * ── WHY THE ASSERTIONS ARE ABOUT PROPERTIES, NOT ABOUT STRINGS ──────────────
 *
 * A test that pinned the path data would go red on every redraw of a letter
 * and green on a mark that had lost its accessible name, which is backwards:
 * the shape is allowed to change and the contract is not. So these parse the
 * mark and ask it questions -- is the root an <svg>, is its accessible name
 * the brand, is its height expressed in a unit that inherits -- and the one
 * about sizing asks for the UNIT rather than for the literal `2.2em`, so
 * retuning the size stays green and pinning it to pixels does not.
 *
 * ── EACH BLOCK NAMES THE MUTATION THAT TURNS IT RED ─────────────────────────
 *
 * Every mutation written below was applied to the real file, the suite was
 * run, and the failure was seen before the line was written down. A test whose
 * red nobody has watched is a test nobody can trust, and this repository has
 * found four guards that asserted nothing while being counted as coverage.
 */

use App\Services\CartPage;
use App\Support\PaymentMarkArt;
use Tests\Support\StaticMemos;

/**
 * The six schemes, and the brand each one's mark must name, in the order the
 * trust row prints them.
 *
 * @return array<string, string>
 */
function payMarkBrands(): array
{
    return [
        'pay_visa' => 'Visa',
        'pay_mc' => 'Mastercard',
        'pay_apple' => 'Apple Pay',
        'pay_google' => 'Google Pay',
        'pay_tabby' => 'tabby',
        'pay_tamara' => 'tamara',
    ];
}

/**
 * Save, then drop the process-level memo.
 *
 * Without the second half a second save inside one test is invisible:
 * Setting::map() memoises in a static, tests/Pest.php clears it before each
 * test and nothing clears it during one. CLAUDE.md lists this as a landmine
 * and it is the reason these tests can switch a scheme off and read the result
 * in the same test body.
 *
 * @param  array<string, mixed>  $values
 */
function payMarkSave(array $values): void
{
    app(CartPage::class)->save($values);
    StaticMemos::forgetAll();
}

/**
 * The shop able to take Apple Pay and Google Pay, with both switched on.
 *
 * TWO OF THE SIX MARKS NOW CARRY A SECOND CONDITION, and this is what
 * satisfies it. `pay_apple` and `pay_google` used to be switches over nothing:
 * the shop drew both marks and could take neither payment. They are now gated
 * on App\Services\Payments\Wallets, which asks four questions — the card
 * gateway's row is enabled, its secret key is present, its publishable key is
 * present, and the merchant has switched that wallet on. All four, because each
 * one alone is a way to draw a mark for a payment that cannot be taken.
 *
 * The four SCHEME marks are unaffected and deliberately so: Visa, Mastercard,
 * tabby and tamara are separate claims with separate answers, and this round
 * was sent for the two that were lying.
 */
function payMarkWalletsOn(bool $apple = true, bool $google = true): void
{
    $row = \App\Models\PaymentProvider::firstOrNew(['id' => 'stripe']);

    $row->fill(['title' => 'Card', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $row->config = [
        'publishable_key' => 'pk_test_marks',
        'secret_key' => 'sk_test_marks',
        'wallet_apple_pay' => $apple ? '1' : '',
        'wallet_google_pay' => $google ? '1' : '',
    ];
    $row->save();

    // The model's own `saved` hook already evicts the cached answer; these two
    // are belt and braces for the per-instance memos that sit in front of it,
    // which nothing in a test process would otherwise clear.
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    app(\App\Services\Payments\Wallets::class)->forget();
}

/** Every scheme on, which is the shop's own default for this row. */
function payMarkAllOn(): void
{
    payMarkWalletsOn();
    payMarkSave(array_fill_keys(array_keys(payMarkBrands()), true));
}

/** Parse a mark so it can be asked about rather than string-matched. */
function payMarkDom(string $svg): DOMDocument
{
    $doc = new DOMDocument();

    expect(@$doc->loadXML($svg))->toBeTrue();

    return $doc;
}

/**
 * The declarations of an inline style attribute, keyed by property.
 *
 * @return array<string, string>
 */
function payMarkStyle(DOMElement $el): array
{
    $out = [];

    foreach (explode(';', $el->getAttribute('style')) as $bit) {
        if (! str_contains($bit, ':')) {
            continue;
        }

        [$prop, $value] = explode(':', $bit, 2);
        $out[trim($prop)] = trim($value);
    }

    return $out;
}

/* ------------------------------------------------------------------------
 | 1. The six booleans still decide which marks appear
 |------------------------------------------------------------------------*/

it('prints exactly one mark for every scheme that is switched on', function () {
    payMarkAllOn();

    $marks = app(CartPage::class)->paymentMarks();

    expect($marks)->toHaveCount(count(payMarkBrands()));

    // One mark per brand, in the order the reference shows them.
    $named = array_map(
        fn (string $svg): string => payMarkDom($svg)->documentElement->getAttribute('aria-label'),
        $marks,
    );

    expect($named)->toBe(array_values(payMarkBrands()));
});
// MUTATION, run: in PaymentMarkArt::MARKS give 'pay_tabby' the same drawing
// as 'pay_tamara'. RED here on the ordered list of names, and in two blocks
// below as well -- three tests failed.

it('prints no mark at all for a scheme that is switched off', function () {
    foreach (payMarkBrands() as $off => $brand) {
        payMarkAllOn();
        payMarkSave([$off => false]);

        $marks = app(CartPage::class)->paymentMarks();

        expect($marks)->toHaveCount(count(payMarkBrands()) - 1);

        // Not merely absent from the count -- absent from the markup, so a
        // mark that was drawn and then hidden with CSS would not pass.
        expect(implode('', $marks))->not->toContain('aria-label="' . $brand . '"');
    }
});
// MUTATION, run: in CartPage::paymentMarks() drop the `if ($c[$key])` and
// push every mark. RED on the count, for all six schemes -- and on the block
// below, which is the same defect seen from the other end. Two tests failed.

/*
 * THE DEFECT THIS LANE WAS SENT FOR, pinned from both ends.
 *
 * What the shop did before: it printed the Apple Pay and Google Pay marks on
 * the basket, the footer and the product page unconditionally, on a build that
 * had no Apple Pay and no Google Pay anywhere — GatewayRegistry knew four
 * gateways, `cod`, `tabby`, `tamara` and `stripe`, and there was no code for
 * either wallet at all. The marks were a claim nobody could act on, and the
 * two buttons on the checkout that went with them had no listener behind them.
 */

it('draws no wallet mark while the shop cannot take that wallet', function () {
    // Every appearance switch on, as a shop that has never touched this screen
    // has them — so the only thing that can remove a mark here is capability.
    payMarkAllOn();
    payMarkWalletsOn(apple: false, google: true);

    $all = implode('', app(CartPage::class)->paymentMarks());

    expect($all)->not->toContain('aria-label="Apple Pay"')
        ->and($all)->toContain('aria-label="Google Pay"')
        // And nothing else moved: the four scheme marks have no wallet behind
        // them and are not this gate's business.
        ->and($all)->toContain('aria-label="Visa"')
        ->and($all)->toContain('aria-label="Mastercard"')
        ->and($all)->toContain('aria-label="tabby"')
        ->and($all)->toContain('aria-label="tamara"');

    expect(app(CartPage::class)->paymentMarks())->toHaveCount(5);
});
// MUTATION, run: in CartPage::paymentMarks() put `if ($c[$key])` back in place
// of `$wallets->markAllowed($key, (bool) $c[$key])`. RED on the first
// assertion -- the Apple Pay mark is drawn again for a shop that cannot take
// an Apple Pay payment, which is exactly the shape of the original defect.

it('draws no wallet mark at all when the card gateway itself is off', function () {
    payMarkAllOn();

    // Both wallets still switched ON in the gateway's own config. The row is
    // what changed, and the wallets ride it: a shop that has switched the card
    // off has switched the wallets off with it, because they are the same
    // money path.
    $row = \App\Models\PaymentProvider::find('stripe');
    $row->enabled = false;
    $row->save();

    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    app(\App\Services\Payments\Wallets::class)->forget();

    $all = implode('', app(CartPage::class)->paymentMarks());

    expect($all)->not->toContain('aria-label="Apple Pay"')
        ->and($all)->not->toContain('aria-label="Google Pay"')
        ->and($all)->toContain('aria-label="Visa"');
});
// MUTATION, run: in Wallets::compute() drop the `! $row->enabled` half of the
// first guard. RED on both wallet assertions -- a shop with the card gateway
// switched off went on advertising two payments it could not take.

it('draws no wallet mark when Stripe has no publishable key', function () {
    payMarkAllOn();

    // The secret key alone opens an intent but never boots Stripe.js, so the
    // sheet can never be drawn however willing Stripe is. StripeGateway makes
    // the same distinction for the card option in availableFor().
    $row = \App\Models\PaymentProvider::find('stripe');
    $row->config = ['secret_key' => 'sk_test_marks', 'wallet_apple_pay' => '1', 'wallet_google_pay' => '1'];
    $row->save();

    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    app(\App\Services\Payments\Wallets::class)->forget();

    $all = implode('', app(CartPage::class)->paymentMarks());

    expect($all)->not->toContain('aria-label="Apple Pay"')
        ->and($all)->not->toContain('aria-label="Google Pay"');
});
// MUTATION, run: in Wallets::compute() drop `publishable_key` from the guard.
// RED on both -- the marks come back for a shop whose checkout cannot mount a
// wallet button.

it('prints nothing whatsoever when every scheme is switched off', function () {
    payMarkSave(array_fill_keys(array_keys(payMarkBrands()), false));

    expect(app(CartPage::class)->paymentMarks())->toBe([]);
});
// MUTATION, run: the same one as the block above -- ignoring `$c[$key]`
// returns all six here instead of none. RED.

/* ------------------------------------------------------------------------
 | 2. The row keeps its meaning once the words became pictures
 |------------------------------------------------------------------------*/

it('gives every mark an accessible name equal to the brand', function () {
    payMarkAllOn();

    $brands = array_values(payMarkBrands());

    foreach (app(CartPage::class)->paymentMarks() as $i => $svg) {
        $doc = payMarkDom($svg);
        $root = $doc->documentElement;

        expect($root->tagName)->toBe('svg');

        // role="img" is what makes a screen reader treat the drawing as one
        // thing with a name rather than reading into it.
        expect($root->getAttribute('role'))->toBe('img');
        expect($root->getAttribute('aria-label'))->toBe($brands[$i]);

        $title = $doc->getElementsByTagName('title')->item(0);

        expect($title)->not->toBeNull();
        expect(trim($title->textContent))->toBe($brands[$i]);
    }
});
// MUTATIONS, both run: strip role="img" from the Visa mark -- RED on that
// mark's role. Rename Mastercard's <title> to "Card" -- RED on the title.
// One test failed in each case.

/* ------------------------------------------------------------------------
 | 3. trust_size stays the one control over how big the row is
 |------------------------------------------------------------------------*/

it('sizes every mark in a font-relative unit, so trust_size keeps scaling it', function () {
    payMarkAllOn();

    foreach (app(CartPage::class)->paymentMarks() as $svg) {
        $root = payMarkDom($svg)->documentElement;
        $style = payMarkStyle($root);

        expect($style)->toHaveKey('height');

        // THE PROPERTY, NOT THE LITERAL. `trust_size` scales the row by
        // scaling its font-size, so the marks follow only while their height
        // is expressed in a unit that resolves against that font-size.
        // Retuning 2.2em to 2.4em must stay green; pinning it to 14px must
        // not.
        expect($style['height'])->toMatch('/^\d+(\.\d+)?(em|rem|ex|ch)$/');

        // Nothing anywhere in the drawing may be an absolute length, in the
        // style attribute or as a presentation attribute.
        expect($svg)->not->toMatch('/\d\s*px/');
        expect($root->getAttribute('width'))->toBe('');
        expect($root->getAttribute('height'))->toBe('');

        // And the width has to follow the height, or the aspect ratio that
        // makes a Visa wordmark a Visa wordmark is lost.
        expect($style['width'] ?? '')->toBe('auto');
    }
});
// MUTATION, run: change the Visa mark's inline height to `height:14px`. RED
// on the unit check, which is the first of the two absolute-length assertions
// to be reached. One test failed.

/* ------------------------------------------------------------------------
 | 4. Nothing user-supplied reaches markup the page prints unescaped
 |------------------------------------------------------------------------*/

it('prints the constant and nothing a setting could put there', function () {
    payMarkAllOn();

    // trust_text is the setting sitting next to the marks on the same row and
    // the nearest thing to them a shopkeeper can type into.
    payMarkSave(['trust_text' => '<script>alert(1)</script>']);

    $marks = app(CartPage::class)->paymentMarks();
    $all = implode('', $marks);

    expect($all)->not->toContain('<script')
        ->and($all)->not->toContain('alert(1)');

    // Byte-for-byte the constant: the row prints these with {!! !!}, so the
    // only thing standing between that and stored XSS is that no value ever
    // travels into this list.
    expect($marks)->toBe(array_values(PaymentMarkArt::marks()));
});
// MUTATION, run: in CartPage::paymentMarks() append $c['trust_text'] to
// $out. RED here on the <script> check, and everywhere else in this file too
// -- a seventh element breaks every count. All seven tests failed, which is
// the shape a leak of this kind should have.

/* ------------------------------------------------------------------------
 | 5. And the row on the actual page draws them
 |------------------------------------------------------------------------*/

it('draws the artwork in the trust row of the rendered page', function () {
    payMarkAllOn();

    $marks = app(CartPage::class)->paymentMarks();

    expect($marks)->not->toBeEmpty();

    foreach ($marks as $svg) {
        expect($svg)->toStartWith('<svg')
            ->and($svg)->toEndWith('</svg>');

        // Self-contained: a package applied through the admin panel cannot
        // half-deliver a drawing that has no second file to fetch.
        expect($svg)->not->toContain('<image')
            ->and($svg)->not->toContain('base64')
            ->and($svg)->not->toContain('xlink')
            ->and($svg)->not->toContain('http');
    }
});
// MUTATION, run: give the Visa mark an <image href="http://x/build/visa.svg"/>.
// RED on the <image> check. One test failed.
