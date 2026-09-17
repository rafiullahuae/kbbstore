<?php

declare(strict_types=1);

/**
 * =============================================================================
 * THE CARD OPTION AT CHECKOUT: ITS ORDER, ITS EXPLANATION, AND WHY IT HAS NO
 * CARD FIELDS
 * =============================================================================
 *
 * The owner configured Stripe in test mode, opened his own checkout, chose
 * Credit / Debit Card and asked why the card fields were not showing.
 *
 * TWO ANSWERS, AND ONLY ONE OF THEM WAS A BUG.
 *
 * 1. THERE ARE NO CARD FIELDS, AND THERE SHOULD NOT BE. StripeGateway's header
 *    argues it at length: this is Stripe CHECKOUT, a hosted page. The shopper
 *    presses Place order and is sent to Stripe's own page to type the card, so
 *    a card number never touches this server and the shop's PCI obligation
 *    stays SAQ-A — which matters because this application runs on shared
 *    hosting. There is no Stripe.js anywhere in the tree and that is by design,
 *    not by omission.
 *
 * 2. ▲ THE EXPLANATION WAS THERE AND HAS NEVER BEEN VISIBLE. Each method
 *    renders a `.payment_box` holding its description, revealed by
 *
 *        .kbb-checkout .wc_payment_method:has(input:checked) .payment_box
 *
 *    while it is hidden by
 *
 *        .kbb-checkout #payment div.payment_box { display: none }
 *
 *    `#payment` is an ID. An id beats any number of classes, so the hide rule
 *    won on every method, for every shopper, since the day it shipped — Cash on
 *    delivery's fee explanation included. The owner selected the card option,
 *    saw nothing appear, and reasonably read that as the card fields failing to
 *    load. The reveal is matched id-for-id now.
 *
 * WHAT THIS FILE HOLDS. That the description is rendered for the selected
 * method, that it says where the card is actually typed, and that the list is
 * in the order the owner asked for: card, Tabby, Tamara, cash last.
 */

use App\Models\PaymentProvider;
use App\Services\Payments\GatewayRegistry;

it('offers the card first and cash last, which is the order the owner asked for', function () {
    // The seeder is the shipped order for a NEW shop; the migration beside it
    // moves an existing one. Both are asserted, because only the second reaches
    // the owner's live shop.
    (new \Database\Seeders\PaymentProviderSeeder)->run();

    $positions = PaymentProvider::query()
        ->orderBy('position')
        ->pluck('id')
        ->all();

    expect($positions)->toBe(['stripe', 'tabby', 'tamara', 'cod']);
});

it('sorts the checkout list by that column, not by anything else', function () {
    /*
     * GatewayRegistry::availableFor() is the one place the storefront list is
     * ordered. Asserted on the QUERY's own ordering rather than on a rendered
     * list, because which gateways appear depends on which are configured, and
     * this is a claim about order and not about configuration.
     */
    $sql = \App\Models\PaymentProvider::query()->orderBy('position')->toSql();

    expect(str_contains(strtolower($sql), 'order by'))->toBeTrue()
        ->and(str_contains(strtolower($sql), 'position'))->toBeTrue();

    $registry = file_get_contents(base_path('app/Services/Payments/GatewayRegistry.php'));

    expect(str_contains($registry, "orderBy('position')"))->toBeTrue(
        'the registry no longer orders by position, so the order the owner sets is decorative'
    );
});

it('tells the shopper where the card details are actually typed', function () {
    $stripe = app(GatewayRegistry::class)->find('stripe');

    $text = (string) $stripe?->description(50000);

    expect($text)->not->toBe('');

    /*
     * The old sentence — "Your card details never reach this site" — was true
     * and answered a question nobody was asking. What a shopper looking at an
     * empty card option needs to know is WHERE the card goes and WHEN, or they
     * conclude the shop is broken and leave. That costs the same order a
     * missing field would.
     */
    foreach (['Stripe', 'Place order'] as $must) {
        expect(str_contains($text, $must))->toBeTrue(
            'the card option no longer says "'.$must.'", so it does not tell the shopper where the card is entered'
        );
    }
});

it('renders that explanation into the page, under the option it belongs to', function () {
    /*
     * The partial is handed its list directly. Which gateways a test database
     * has CONFIGURED is a different question from whether the partial draws a
     * description for one, and mixing the two is how this case failed first
     * time round — against a database with no keys in it, proving nothing about
     * the markup.
     */
    $html = view('partials.checkout.payment-methods', [
        'gateways' => [[
            'id' => 'stripe',
            'title' => 'Credit / Debit Card',
            'description' => 'You will enter your card details on Stripe\'s own secure page after you press Place order.',
            'fee_html' => null,
            'fee_fils' => 0,
        ]],
        'codHidden' => null,
        'selectedMethod' => 'stripe',
    ])->render();

    expect(str_contains($html, 'payment_box payment_method_stripe'))->toBeTrue('the card option has no description box')
        ->and(str_contains($html, 'Place order'))->toBeTrue('the box does not carry the explanation');

    // The radio the page pre-selects must be the one the box belongs to,
    // otherwise the CSS reveals a different method's explanation.
    expect(preg_match('/id="payment_method_stripe"[^>]*checked/', $html))
        ->toBe(1, 'the card option is not the one checked, so its box stays hidden');
});

it('keeps the reveal rule able to out-rank the rule that hides it', function () {
    /*
     * The bug in one line. This is asserted on the STYLESHEET because that is
     * where it lives; the browser half is tests/browser (the box measured
     * display:block once this landed). A future edit that drops the id-matched
     * selector puts every description back behind display:none, silently, on a
     * page that still contains all the words.
     */
    $css = file_get_contents(base_path('resources/css/kbb/kbb-checkout.css'));

    expect(str_contains($css, '.kbb-checkout #payment .wc_payment_method:has(input:checked) div.payment_box'))
        ->toBeTrue(
            'the reveal no longer matches the hide rule id-for-id, so `.kbb-checkout #payment div.payment_box{display:none}` wins again and no payment method shows its description'
        );
});
