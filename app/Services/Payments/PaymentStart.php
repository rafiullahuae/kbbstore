<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * What a gateway wants the browser to do next.
 *
 * Four outcomes, no more: send them somewhere (hosted checkout), let the page
 * itself finish the payment against a provider handle (card fields on our own
 * checkout), the order is placed and nothing more is needed (cash on
 * delivery), or it failed and the shopper is told why.
 */
final class PaymentStart
{
    private function __construct(
        public readonly string $result,      // redirect | confirm | placed | failed
        public readonly ?string $redirectUrl = null,
        public readonly ?string $providerRef = null,
        public readonly ?string $message = null,
        public readonly ?string $clientSecret = null,
    ) {}

    public static function redirect(string $url, ?string $providerRef = null): self
    {
        return new self('redirect', $url, $providerRef);
    }

    /**
     * The page finishes the payment itself.
     *
     * The card fields are Stripe-hosted iframes mounted on our own checkout,
     * so there is no URL to send anybody to: what the browser needs is the
     * handle that authorises it to confirm this one PaymentIntent and nothing
     * else.
     *
     * A PaymentIntent client secret is designed to be published to the buyer's
     * browser — that is the only place it is usable, it names exactly one
     * intent, and it confers no read access to the account. It is still handed
     * out only to the session that just placed the order it belongs to (see
     * Store\CheckoutController::place), because a client secret does let its
     * holder see that one intent's amount and status.
     *
     * $providerRef is the PaymentIntent id, which is what settlement,
     * reconciliation and the webhook all key off. It is written to
     * `orders.transaction_id` by the caller exactly as the redirect path
     * writes its own reference there.
     */
    public static function confirm(string $clientSecret, string $providerRef): self
    {
        return new self('confirm', null, $providerRef, null, $clientSecret);
    }

    /** Order stands on its own — no money moves online. */
    public static function placed(?string $providerRef = null): self
    {
        return new self('placed', null, $providerRef);
    }

    /**
     * $message is shown to the shopper, so it must never carry an API error
     * body, a key, or anything else from the provider's response.
     */
    public static function failed(string $message): self
    {
        return new self('failed', null, null, $message);
    }

    public function ok(): bool
    {
        return $this->result !== 'failed';
    }
}
