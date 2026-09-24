<?php

declare(strict_types=1);

use App\Services\CheckoutPage;

/**
 * The floating Place order bar only exists once the order can actually be
 * placed.
 *
 * The owner: "The floating Place order row should only appear, if the user
 * enter all the details and ready to place the order... with missing fields, or
 * selection or missing payment selection, the floating place order bar will not
 * show."
 *
 * A bar offering Place order on a form that cannot be placed is a button that
 * exists to be refused — and on a phone it sits over the field that is wrong.
 *
 * DRIVEN IN CHROMIUM AT 390px, because a Pest test has no layout engine and
 * this is a question about what is on screen. The measurement, in order:
 *
 *   1  load, empty form, in-page button off screen   is-on false, hidden
 *   2  name + phone + email filled, no address       is-on false, hidden
 *   3  address chosen                                is-on TRUE,  visible
 *   4  scrolled to the in-page button                is-on false
 *   5  email cleared, scrolled back to the top       is-on false, hidden
 *
 * Step 2 is the one that matters most and the one a `:invalid` check alone
 * fails: the chosen address lands in HIDDEN inputs, and a hidden input is
 * barred from constraint validation, so it is never :invalid however empty it
 * is. What this file pins is the structure that produced those numbers.
 *
 * MUTATION: drop the `&& placeable()` from paint() and step 1 shows the bar.
 */
$view = fn (): string => (string) file_get_contents(
    resource_path('views/store/checkout.blade.php'),
);

it('asks the browser the same question the Place order button asks', function () use ($view) {
    $html = $view();

    /*
     * `:invalid` and not a second list of field names. The [data-place] handler
     * in checkout.js already scrolls to `form.querySelector(':invalid')` before
     * it will submit; using the same test means the bar and the button cannot
     * disagree about what "complete" means, and it reads no geometry.
     */
    expect($html)->toContain("if (form.querySelector(':invalid')) { return false; }")
        ->and($html)->toContain('function placeable()')
        ->and($html)->toContain('bar.classList.toggle(\'is-on\', away && placeable());');
});

it('checks the three things constraint validation cannot see', function () use ($view) {
    $html = $view();

    /*
     * The address is hidden inputs; delivery and payment are radio groups that
     * may not have loaded yet. Each guard is written as "if this control exists
     * and is unanswered", so a shop with no delivery step is not held back by a
     * question it never asked.
     */
    expect($html)->toContain("form.querySelector('#billing_address_1')")
        ->and($html)->toContain("address.value.trim() === ''")
        ->and($html)->toContain("form.querySelector('input[name=\"shipping_method\"]:checked')")
        ->and($html)->toContain("form.querySelector('input[name=\"payment_method\"]:checked')");
});

it('repaints on a click as well, because setting a value from script fires nothing', function () use ($view) {
    /*
     * The address sheet writes the chosen address straight into those hidden
     * inputs. Assigning to .value fires no `input` and no `change` event at
     * all, so without this listener the bar would stay hidden until the
     * shopper happened to type somewhere else.
     */
    expect($view())->toContain("document.addEventListener('click', function () { setTimeout(paint, 0); });")
        ->and($view())->toContain("document.addEventListener('input', paint);")
        ->and($view())->toContain("document.addEventListener('change', paint);");
});

it('reads no geometry to decide any of it', function () use ($view) {
    /*
     * The only thing that knows where the button is, is IntersectionObserver —
     * which is told the element rather than asked for a number. This project
     * does not write layout-measuring JavaScript, and it would be wrong here as
     * well as expensive: the button moves as an address is chosen or a coupon
     * applied, so a remembered position is stale immediately.
     */
    $script = (string) strstr($view(), 'function placeable()');

    foreach (['getBoundingClientRect', 'offsetTop', 'offsetHeight', 'clientHeight', 'scrollY'] as $api) {
        expect($script)->not->toContain($api);
    }

    expect($view())->toContain('new IntersectionObserver(');
});

it('only draws the bar at all in the two modes that ask for it', function () {
    // "Never" renders no bar, so there is nothing for the gate to hold back.
    expect(CheckoutPage::SCHEMA['m_float'][4])->toHaveKeys(['off', 'smart', 'always'])
        ->and(CheckoutPage::SCHEMA['m_float'][2])->toBe('smart');

    app(CheckoutPage::class)->save(['m_float' => 'off']);
    expect(app(CheckoutPage::class)->floatBar())->toBe('off');
});
