<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which payment method an order used, in the words an email says it.
 *                                                                (2.60.376)
 *
 * The owner, 4 October: "in failed order, the reason should be there, if it
 * is failed through tabby, then mention clearly, if with tamara, Card payment
 * vice versa" — and then "also for refund."
 *
 * WHAT THE SHOP CAN SAY, AND WHAT IT CANNOT. Tabby, Tamara and the card
 * provider send the shopper back without a reason (see the long note on
 * CheckoutReturnController::reason()): a decline and a change of mind arrive
 * the same way. So the emails name the METHOD, which is certain, and say the
 * payment was "not approved or not finished", which is always true — never
 * "your card was declined", which may be an accusation.
 *
 * A closed list: `orders.payment_method` is the gateway id (stripe, tabby,
 * tamara, cod). Anything else answers null and the email keeps its general
 * wording, so a method added later can never print a raw id at a customer.
 */
final class PaymentMethodWords
{
    /** 'card' | 'tabby' | 'tamara', or null when the email should stay general. */
    public static function key(?string $paymentMethod): ?string
    {
        return match (strtolower(trim((string) $paymentMethod))) {
            'stripe', 'card' => 'card',
            'tabby' => 'tabby',
            'tamara' => 'tamara',
            default => null,
        };
    }

    /** The method's name inside a sentence: "card", "Tabby", "Tamara". */
    public static function name(string $key): string
    {
        return match ($key) {
            'tabby' => __('email.payment_method.tabby'),
            'tamara' => __('email.payment_method.tamara'),
            default => __('email.payment_method.card'),
        };
    }
}
