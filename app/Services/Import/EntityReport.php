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

    /**
     * Values this import CHANGED on the way in, and content it DROPPED, each
     * with the before and the after.
     *
     * THIS IS THE CHANNEL PHASE 13 ASKS FOR AND THE REPORT DID NOT HAVE. The
     * plan's line is "three-bucket classification: migrate / discard / ask —
     * Rafi approves any discard list", and until now the report could produce
     * only two of those three. A row that was refused is named. A row that went
     * in untouched is counted. A row that went in ALTERED — a description with
     * its script tag stripped out, a refund line whose quantity of -1 became 0,
     * a unit price truncated by integer division, a slug regenerated from the
     * product name because the export carried none — was counted as "created",
     * exactly like a row that arrived intact, and the difference was invisible.
     *
     * An owner cannot approve a discard list that does not exist. So:
     *
     *   adjusted  — the row was imported and a value is not what the export
     *               said. Before and after, both.
     *   discarded — something in the export is NOT in the database and never
     *               will be: content an allowlist removed, a column no entity
     *               reads, a whole file nothing opens.
     *
     * BOUNDED BY KIND, NOT HELD IN FULL, and that is the one place this differs
     * from the rejection list. A rejection list is a few hundred rows by
     * construction — the run is a failure if it is not. Adjustments are the
     * opposite: "unit price truncated" can be true of forty thousand line items
     * in a perfectly good import, and holding forty thousand before/after pairs
     * in memory on shared hosting to print "and 39,975 more" is a cost with no
     * buyer. Each KIND keeps its full count and the first few examples, which
     * is what the owner reads: how many, and what one of them looks like.
     *
     * @var array<string, array{count: int, samples: list<array{line: int|string, id: string, field: string, before: string, after: string}>}>
     */
    private array $adjustments = [];

    /** @var array<string, array{count: int, samples: list<array{line: int|string, id: string, field: string, before: string, after: string}>}> */
    private array $discards = [];

    /** How many examples of each kind are kept. */
    public const SAMPLES_PER_KIND = 5;

    /**
     * How much of a before/after value is kept.
     *
     * Long enough to hold the whole ignored-column list for a wide
     * WooCommerce export, which is the one sample that is a LIST rather than a
     * value and is useless truncated -- the names at the end are exactly the
     * ones nothing else in the report mentions.
     */
    public const EXCERPT_LENGTH = 600;

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

    /**
     * A value that was imported, but not as the export wrote it.
     *
     * @param  string  $kind  the recurring headline, e.g. 'unit price truncated by integer division'
     * @param  string  $before  what the export said
     * @param  string  $after  what the database now holds
     */
    public function adjusted(string $kind, int|string $line, string $id, string $field, string $before, string $after): void
    {
        $this->collect($this->adjustments, $kind, $line, $id, $field, $before, $after);
    }

    /**
     * Something in the export that is not in the database and will not be.
     */
    public function discarded(string $kind, int|string $line, string $id, string $field, string $before, string $after = '(nothing)'): void
    {
        $this->collect($this->discards, $kind, $line, $id, $field, $before, $after);
    }

    /**
     * @param  array<string, array{count: int, samples: list<array{line: int|string, id: string, field: string, before: string, after: string}>}>  $into
     */
    private function collect(array &$into, string $kind, int|string $line, string $id, string $field, string $before, string $after): void
    {
        $into[$kind] ??= ['count' => 0, 'samples' => []];
        $into[$kind]['count']++;

        if (count($into[$kind]['samples']) < self::SAMPLES_PER_KIND) {
            $into[$kind]['samples'][] = [
                'line' => $line,
                'id' => $id,
                'field' => $field,
                // Truncated: a product description is kilobytes long and the
                // owner is reading a console, not a diff viewer.
                'before' => self::excerpt($before),
                'after' => self::excerpt($after),
            ];
        }
    }

    private static function excerpt(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_strlen($value) > self::EXCERPT_LENGTH
            ? mb_substr($value, 0, self::EXCERPT_LENGTH - 3).'...'
            : $value;
    }

    /** @return array<string, array{count: int, samples: list<array{line: int|string, id: string, field: string, before: string, after: string}>}> */
    public function adjustments(): array
    {
        return $this->adjustments;
    }

    /** @return array<string, array{count: int, samples: list<array{line: int|string, id: string, field: string, before: string, after: string}>}> */
    public function discards(): array
    {
        return $this->discards;
    }

    public function adjustedCount(): int
    {
        return array_sum(array_column($this->adjustments, 'count'));
    }

    public function discardedCount(): int
    {
        return array_sum(array_column($this->discards, 'count'));
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
        return $this->touched() === 0 && $this->skipped === 0 && $this->rejections === []
            && $this->adjustments === [] && $this->discards === [];
    }
}
