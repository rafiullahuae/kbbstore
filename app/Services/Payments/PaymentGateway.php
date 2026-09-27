<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;

/**
 * One payment method.
 *
 * Deliberately built around what already exists rather than beside it:
 *
 *  - The `payment_providers` table has been in the schema since LO-03, keyed
 *    `cod | stripe | tabby | tamara` with `enabled`, `mode`, `position` and an
 *    encrypted `config` blob. Enablement and ordering stay there. A gateway
 *    class is the behaviour for a row, not a replacement for it.
 *  - Store\CheckoutController::gateways() already produced the array the
 *    checkout blade iterates (id / title / description / fee_html / fee_fils).
 *    GatewayRegistry produces exactly that shape, so the view is untouched.
 *  - Money is integer fils everywhere (App\Support\Money). Every amount that
 *    crosses this interface is fils. Conversion to whatever decimal shape a
 *    provider wants happens inside that provider's class and nowhere else.
 */
interface PaymentGateway
{
    /** Matches the `payment_providers.id` primary key. */
    public function id(): string;

    /** Shown on the checkout radio list. */
    public function title(): string;

    /**
     * Are the credentials present?
     *
     * Must never throw and must never call out to the network. A gateway the
     * owner has not configured yet reports false and is simply not offered;
     * it does not fatal, and it does not take the checkout page down with it.
     */
    public function configured(): bool;

    /**
     * Can this method be used for an order of this size, to this country?
     *
     * Separate from configured() so the reason can be explained. Callers ask
     * configured() first — an unconfigured gateway is not "unavailable for
     * this basket", it is not set up.
     */
    public function availableFor(int $totalFils, ?string $country = null): bool;

    /** Sentence under the radio option, or null. May contain markup. */
    public function description(int $totalFils): ?string;

    /** Surcharge in fils added to the order total. COD is the only one today. */
    public function feeFils(int $totalFils): int;

    /**
     * Begin payment for an order whose totals are already computed and stored.
     *
     * Implementations read the amount from $order, never from a request.
     */
    public function start(Order $order): PaymentStart;

    /**
     * The config fields the admin screen renders.
     *
     * ── THE FOURTH ELEMENT IS WHICH COLUMN THE FIELD BELONGS IN ─────────────
     *
     * 'keys' is a value the PROVIDER issues and the owner pastes in, plus the
     * plumbing that connects the two shops: the tokens, the merchant code, the
     * webhook secret behind the webhook URL, the registered webhook id.
     * 'settings' is a decision THIS SHOP makes about how the gateway is used:
     * the capture window, basket limits, exclusions, payment type, whether to
     * share order history.
     *
     * It exists because the screen may not name a gateway. PaymentsGatewayTabs-
     * Test forbids it — a console that hardcodes 'tamara' stops following the
     * registry the moment a gateway is added or renamed — so "is this a key or
     * a setting" has to be answered by the gateway that owns the field, in the
     * same place its type and help live. Every entry states it; PaymentsField-
     * GroupsTest fails a schema that leaves one out rather than guessing,
     * because a field that silently picks a column is a field in the wrong one.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     *         key => [type (text|secret|bool|select), label, help, group (keys|settings)]
     */
    public function configSchema(): array;
}
