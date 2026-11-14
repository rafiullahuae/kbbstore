<?php

declare(strict_types=1);

namespace App\Services\Quiqup;

/**
 * One answer from Quiqup, already decoded (QUIQUP-PLAN §1.7).
 *
 * `status` is the HTTP status, and it is what decisions are made on -- never
 * api_error.http_status_code, which the docs say can disagree with it. 0 means
 * Quiqup did not answer at all (timeout, connection refused, TLS).
 *
 * `error` is Quiqup's OWN text, prefixed with the status, e.g.
 * "HTTP 422 — [destination][address][address1] is missing"; `hint` is our
 * explanation beside it for the documented traps. Both are plain text: every
 * screen that prints them escapes them.
 */
final class QuiqupResult
{
    /**
     * @param  array<string, mixed>|list<mixed>|null  $json
     */
    public function __construct(
        public readonly int $status,
        public readonly ?array $json = null,
        public readonly string $body = '',
        public readonly string $error = '',
        public readonly string $hint = '',
        public readonly bool $projectionPending = false,
    ) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300 && $this->error === '';
    }

    /**
     * Worth trying again with the same uuid: no answer, a timeout, 429 or a
     * 5xx (Quiqup's own retry rule for webhooks, applied to our calls).
     */
    public function retryable(): bool
    {
        return $this->status === 0 || $this->status === 408 || $this->status === 429 || $this->status >= 500;
    }

    /** The order object from an OrderResponse (`{order: {...}}`), or null. */
    public function order(): ?array
    {
        $o = $this->json['order'] ?? null;

        return is_array($o) ? $o : null;
    }

    /** "HTTP 422 — text · our hint", for a row on the sheet. */
    public function message(): string
    {
        return $this->hint === '' ? $this->error : $this->error . ' · ' . $this->hint;
    }
}
