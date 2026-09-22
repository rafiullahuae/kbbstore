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

/** Every scheme on, which is the shop's own default for this row. */
function payMarkAllOn(): void
{
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
