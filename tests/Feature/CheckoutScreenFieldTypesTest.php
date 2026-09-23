<?php

declare(strict_types=1);

use App\Services\CheckoutPage;
use App\Support\TrustClaims;

/**
 * The four field types the Checkout page screen claims to draw, and the three
 * selects that shipped before it could draw any of them.
 *
 * WHAT THIS EXISTS FOR, IN ONE SENTENCE. `fieldHTML()` handled `bool` and then
 * returned an `<input type="range">` for everything else, so `ph_tone` and
 * `ph_weight` (2.60.252) and `m_float` (2.60.253) each rendered as
 *
 *     <input type="range" min="undefined" max="undefined" value="muted">
 *
 * — a slider with no scale, showing a value it cannot represent — and the input
 * handler then stored `Number(el.value)`, which is NaN. A control that cannot be
 * read and cannot be stored is worse than one that is missing, because the
 * screen says the setting exists.
 *
 * SlimFooter's own header said "the same four the checkout screen already
 * draws". It drew two.
 *
 * MUTATION: delete the `f.type === 'select'` branch from the screen and the
 * first test is red. Delete the `kind === 'select'` arm from the input handler
 * and the second is red.
 */
$screen = fn (): string => (string) file_get_contents(
    resource_path('views/admin/partials/checkout-page-screen.blade.php'),
);

it('draws a select as a select and a text field as a text field', function () use ($screen) {
    $html = $screen();

    expect($html)->toContain("if (f.type === 'select')")
        ->and($html)->toContain('<select class="chp-sel"')
        ->and($html)->toContain("if (f.type === 'text')")
        ->and($html)->toContain('<input class="chp-text" type="text"');
});

it('reads a value by the field type and not by the DOM element type', function () use ($screen) {
    $html = $screen();

    /*
     * The old line was `else values[key] = Number(el.value)`, reached by every
     * non-checkbox. Both halves matter: the branch has to exist, and the
     * numeric one has to be the LAST resort rather than the default.
     */
    expect($html)->toContain("if (kind === 'bool') values[key] = el.checked;")
        ->and($html)->toContain("else if (kind === 'select' || kind === 'text') values[key] = String(el.value);")
        ->and($html)->toContain('else values[key] = Number(el.value);');
});

it('has a rule behind every class the two new field types wear', function () use ($screen) {
    expect($screen())->toContain('.chp-f select.chp-sel,.chp-f input.chp-text{');
});

/* ------------------------------------------------------------------------
 | The reviews line: the wording is the owner's, the figures are not
 |------------------------------------------------------------------------*/

it('refuses a wording template that carries a digit of its own', function () {
    $page = app(CheckoutPage::class);
    $shipped = CheckoutPage::SCHEMA['rating_text'][2];

    /*
     * `reassure_rating_text` was a free-text setting whose shipped default read
     * "4.8 · loved by 2,300+ UAE customers" on a shop with no reviews at all.
     * It was removed for that. Handing the WORDING back without handing the
     * FIGURE back is the only way this control can exist.
     */
    $page->save(['rating_text' => '4.9 · loved by 2,300+ UAE customers']);
    expect(app(CheckoutPage::class)->all()['rating_text'])->toBe($shipped);

    // The tokens are not digits, so a template made only of them is kept.
    $page->save(['rating_text' => '{rating} · loved by {count} UAE customers']);
    expect(app(CheckoutPage::class)->all()['rating_text'])
        ->toBe('{rating} · loved by {count} UAE customers');

    // Empty and over-long both fall back rather than storing.
    $page->save(['rating_text' => '']);
    expect(app(CheckoutPage::class)->all()['rating_text'])->toBe($shipped);

    $page->save(['rating_text' => str_repeat('a', 121)]);
    expect(app(CheckoutPage::class)->all()['rating_text'])->toBe($shipped);
});

it('says nothing at all when there are too few approved reviews', function () {
    // No reviews table rows in this test, so the line is null whatever the
    // wording says. Three "say nothing" cases answer identically on purpose.
    expect(app(CheckoutPage::class)->ratingLine())->toBeNull();
});

/* ------------------------------------------------------------------------
 | The header band, and the notch to the left of the logo
 |------------------------------------------------------------------------*/

it('lines the phone header band up with the page, and centres it only on request', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));

    /*
     * Measured at 390px with the mobile header width at 280: `.in` ran 55…335,
     * the logo started at 75 and the page's own "Back to shop" started at 20 —
     * a 55px notch with nothing on the page matching it, and the badge inside
     * overflowing to 370 so the right side looked flush and the left did not.
     *
     * The default is the page's edges and the CLASS restores the centring, so
     * the default emits no class and `class="kbb-checkout"` is unchanged.
     */
    expect($css)->toContain('.kbb-checkout .co-head .in{max-width:none;margin-inline:0}')
        ->and($css)->toContain('.kbb-checkout.cop-mhead-center .co-head .in{max-width:var(--cop-headmax);margin-inline:auto}')
        ->and($css)->toContain('.kbb-checkout.cop-dhead-page .co-head .in{max-width:none;margin-inline:0}');
});

it('emits no class for either alignment default', function () {
    // The two point opposite ways -- centred is the desktop default, lined up
    // is the phone's -- and both defaults still have to render nothing.
    expect(app(CheckoutPage::class)->bodyClass())->toBe('');

    app(CheckoutPage::class)->save(['m_head_align' => 'center']);
    expect(app(CheckoutPage::class)->bodyClass())->toContain('cop-mhead-center');

    app(CheckoutPage::class)->save(['d_head_align' => 'page']);
    expect(app(CheckoutPage::class)->bodyClass())->toContain('cop-dhead-page');
});

it('keeps the authenticity wording where the rest of the shop keeps its claims', function () {
    // Not moved onto the checkout screen, and the Trust & reviews tab says so
    // in words: one key with two screens writing it is two places for the shop
    // to say something different about itself.
    expect(TrustClaims::CLAIMS)->toHaveKey('reassure_auth_text')
        ->and(CheckoutPage::SCHEMA)->not->toHaveKey('reassure_auth_text')
        ->and(CheckoutPage::TABS['trust'][1])->toContain('Business Details');
});
