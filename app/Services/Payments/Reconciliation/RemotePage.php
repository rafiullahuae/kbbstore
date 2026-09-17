<?php

declare(strict_types=1);

namespace App\Services\Payments\Reconciliation;

/**
 * One page of a provider's books, or an honest admission that we could not
 * read them.
 *
 * THE FAILED CASE IS THE WHOLE REASON THIS CLASS EXISTS.
 *
 * A reconciliation draws two conclusions, and they are not symmetrical. "The
 * provider has money we have no record of" is safe to get wrong — it sends
 * somebody to look at a dashboard. "We think we have money the provider does
 * not" is not: it is derived from ABSENCE, and absence is exactly what a
 * failed API call looks like. A 401 from a rotated key, a 429 from a rate
 * limit, a wrong path — every one of them returns an empty list if you squint,
 * and every local payment in the window then becomes a finding that says the
 * provider has never heard of it.
 *
 * That report is not merely noisy. It is the report that makes an owner
 * believe he has been defrauded, and it would be produced by a typo.
 *
 * So a source that cannot answer says `failed()`, the run records
 * `source_unavailable` for that provider and phase, and every conclusion that
 * depends on having seen the whole of the provider's side is SKIPPED rather
 * than drawn from a partial list. Reconciler enforces that; this class is what
 * makes it expressible.
 */
final class RemotePage
{
    /**
     * @param  array<int, RemoteTxn>  $items
     * @param  string|null  $cursor  what to pass back for the next page; null
     *                               means this was the last one
     * @param  string|null  $error   a short machine code, never a body and
     *                               never a header — see Redaction
     */
    private function __construct(
        public readonly bool $ok,
        public readonly array $items,
        public readonly ?string $cursor,
        public readonly ?string $error = null,
        public readonly ?int $httpStatus = null,
    ) {}

    /** @param array<int, RemoteTxn> $items */
    public static function of(array $items, ?string $cursor = null): self
    {
        return new self(true, array_values($items), $cursor);
    }

    public static function failed(string $error, ?int $httpStatus = null): self
    {
        return new self(false, [], null, $error, $httpStatus);
    }

    /**
     * The provider has no list endpoint we can drive, or is not configured.
     *
     * Distinct from failed() so the screen can say "Tabby cannot be listed
     * from here" once, calmly, instead of reporting an outage every run.
     */
    public static function unsupported(string $why): self
    {
        return new self(false, [], null, $why);
    }

    public function hasMore(): bool
    {
        return $this->ok && $this->cursor !== null && $this->cursor !== '';
    }
}
