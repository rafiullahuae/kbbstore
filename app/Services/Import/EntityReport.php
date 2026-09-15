<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * One entity's tally, plus its refusals and its observations.
 *
 * Rejections are held in full rather than sampled: 200 refused rows out of
 * 40,000 is a manageable list to fix and an unmanageable one to guess at, and
 * the memory cost of a few hundred short strings is not worth trading a
 * complete answer for. The console prints a bounded number of them; the CSV
 * written by `--rejects=` gets all of them.
 */
final class EntityReport
{
    public int $created = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    public int $skipped = 0;

    /** @var list<array{line: int|string, id: string, reason: string}> */
    private array $rejections = [];

    /** @var array<string, int> reason => how many times, so 40,000 rows do not print 40,000 lines */
    private array $notes = [];

    public function __construct(public readonly string $name) {}

    public function created(): void
    {
        $this->created++;
    }

    public function updated(): void
    {
        $this->updated++;
    }

    public function unchanged(): void
    {
        $this->unchanged++;
    }

    public function skipped(int $n = 1): void
    {
        $this->skipped += $n;
    }

    public function reject(int|string $line, string $id, string $reason): void
    {
        $this->rejections[] = ['line' => $line, 'id' => $id, 'reason' => $reason];
    }

    /**
     * An observation that is not an error but changes what the data means.
     *
     * Counted rather than listed, because these are the cases that recur across
     * thousands of rows — "status 'tamara-p-failed' is not one this application
     * defines; kept verbatim" needs to be said once with a count, not once per
     * order.
     */
    public function note(string $note): void
    {
        $this->notes[$note] = ($this->notes[$note] ?? 0) + 1;
    }

    public function rejectedCount(): int
    {
        return count($this->rejections);
    }

    /** @return list<array{line: int|string, id: string, reason: string}> */
    public function rejections(): array
    {
        return $this->rejections;
    }

    /** @return array<string, int> */
    public function notes(): array
    {
        return $this->notes;
    }

    public function touched(): int
    {
        return $this->created + $this->updated + $this->unchanged;
    }

    public function isEmpty(): bool
    {
        return $this->touched() === 0 && $this->skipped === 0 && $this->rejections === [];
    }
}
