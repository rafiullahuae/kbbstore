<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * What a gateway wants the browser to do next.
 *
 * Three outcomes, no more: send them somewhere (hosted checkout), the order is
 * placed and nothing more is needed (cash on delivery), or it failed and the
 * shopper is told why.
 */
final class PaymentStart
{
    private function __construct(
        public readonly string $result,      // redirect | placed | failed
        public readonly ?string $redirectUrl = null,
        public readonly ?string $providerRef = null,
        public readonly ?string $message = null,
    ) {}

    public static function redirect(string $url, ?string $providerRef = null): self
    {
        return new self('redirect', $url, $providerRef);
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
