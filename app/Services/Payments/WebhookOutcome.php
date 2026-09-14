<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * The result of handling one webhook delivery.
 *
 * `message` goes into the HTTP response body, so it is written for the
 * provider's delivery log and contains no order data, no customer data and
 * nothing from our own configuration.
 *
 * Status codes matter to the sender: 2xx stops retries, 4xx is "do not bother
 * retrying", 5xx asks for another attempt. Getting this wrong means either a
 * provider hammering the endpoint forever or a genuinely missed payment.
 */
final class WebhookOutcome
{
    private function __construct(
        public readonly bool $accepted,
        public readonly int $status,
        public readonly string $message,
    ) {}

    /** Applied now. */
    public static function applied(string $message = 'ok'): self
    {
        return new self(true, 200, $message);
    }

    /**
     * Valid, but there was nothing left to do — already paid, or an event type
     * this gateway does not act on. Still a 200: it is not an error, and a
     * retry would only produce the same answer.
     */
    public static function ignored(string $message): self
    {
        return new self(true, 200, $message);
    }

    /** Bad or missing signature. 401 and no retry. */
    public static function rejected(string $message = 'signature verification failed'): self
    {
        return new self(false, 401, $message);
    }

    /** Well-signed but wrong — unknown order, amount mismatch. 422, no retry. */
    public static function refused(string $message): self
    {
        return new self(false, 422, $message);
    }

    /** Our fault, or the provider's API was unreachable. Retry welcome. */
    public static function failed(string $message): self
    {
        return new self(false, 503, $message);
    }
}
