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

    /**
     * COUNT-BASED VERIFICATION, which Phase 13 asks for by name: "count-based
     * verification after each bucket". Until this existed the report could say
     * what it did to the rows it looked at and could not say whether it had
     * looked at all of them.
     *
     * THE HOLE IT CLOSES. Every other number here is produced by the importer
     * describing its own work. `created` is incremented by the code that
     * created the row; `rejected` by the code that refused it. A row that fell
     * through both — read from the file, neither written nor refused, because
     * some mapping returned early on a case nobody anticipated — increments
     * NOTHING, and is therefore invisible in a report whose columns are all
     * self-reported. The totals still look plausible. That is the exact shape
     * of the failure this whole import is arranged to prevent, and it was the
     * one thing the report could not see.
     *
     * So two independent checks, neither of which asks the importer how it
     * thinks it did:
     *
     *   ROWS READ vs ROWS ACCOUNTED FOR. The runner counts rows as it pulls
     *   them off the source, and separately watches each row move this
     *   entity's own tally by at least one. A row that moves nothing and
     *   throws nothing is recorded in `unaccounted` with its line and its id.
     *   That is the "named discrepancy" — not a total that is one short, but
     *   the row, by name.
     *
     *   ROWS IN THE DATABASE. After the bucket, one COUNT against the table
     *   the entity writes to, restricted to rows carrying an external id. It
     *   is the only number in this report that comes from the database rather
     *   than from the importer, which is precisely why it is worth having.
     *
     * WHY `inDatabase` MAY LEGITIMATELY EXCEED `read - rejected`, and why that
     * is a NOTE and not a discrepancy: a delta import of six new orders runs
     * against a table holding four thousand from last week. Fewer rows than
     * expected is an alarm; more is ordinary. Saying both out loud is the
     * point — an owner who is told "4,159 in, 4,159 out" has been given
     * something to check, and an owner who is told nothing has been given a
     * total to trust.
     */
    public int $rowsRead = 0;

    /** Rows this entity's own tally recorded an outcome for. */
    public int $rowsAccounted = 0;

    /**
     * Rows read from the file that produced neither an outcome nor a refusal.
     *
     * @var list<array{line: int|string, id: string}>
     */
    private array $unaccounted = [];

    /**
     * Rows an earlier run committed and this one did not re-read.
     *
     * THEY ARE COUNTED AS READ AND AS ACCOUNTED FOR, because they are rows of
     * this file whose outcome is recorded in `import_checkpoints` -- the batch
     * that wrote them advanced the offset in the same transaction. What this
     * run cannot know is how many of THOSE were refusals, and a refused row is
     * one that was read and is NOT in the database. So while this is non-zero
     * the arithmetic check still runs and the DATABASE COUNT IS REPORTED
     * WITHOUT A VERDICT: `read - refused` is not the number of rows the table
     * should hold when some of the refusals happened in a process that has
     * exited. Claiming a shortfall on that arithmetic would raise an alarm on
     * every resumed import that refused anything, which on shared hosting is
     * most of them, and an alarm that cries wolf is one nobody reads.
     */
    public int $resumedRows = 0;

    /** Rows in the table this entity writes to that carry an external id, or null when it has no such table. */
    public ?int $inDatabase = null;

    /** False while the bucket is part-way through — a slice, not the whole file. */
    public bool $verificationComplete = false;

    /**
     * How the count-verification note begins.
     *
     * A constant because two other classes match on it: the console prints it
     * and the admin screen replaces the previous one with it rather than
     * stacking a fresh sentence per slice. A literal in three files is a
     * literal that will be edited in two of them.
     */
    public const VERIFICATION_NOTE_PREFIX = 'verification — ';

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

    /* --------------------------------------------------- count verification */

    public function read(int $n = 1): void
    {
        $this->rowsRead += $n;
    }

    public function accounted(int $n = 1): void
    {
        $this->rowsAccounted += $n;
    }

    /**
     * A row that was read and then neither written nor refused.
     *
     * Named individually and never sampled. There should be none of these
     * ever; if there are, each one is a row of the owner's shop that is not in
     * the new shop and that nothing else in this report mentions.
     */
    public function unaccountedFor(int|string $line, string $id): void
    {
        $this->unaccounted[] = ['line' => $line, 'id' => $id];
    }

    /** @return list<array{line: int|string, id: string}> */
    public function unaccountedRows(): array
    {
        return $this->unaccounted;
    }

    public function unaccountedCount(): int
    {
        return count($this->unaccounted);
    }

    /**
     * What the bucket's own arithmetic says, in one line the owner can read.
     *
     * @return array{verdict: 'verified'|'discrepancy'|'partial'|'counted'|'unverifiable', read: int, accounted: int, rejected: int, unaccounted: int, in_database: int|null, expected_in_database: int|null, sentence: string}
     */
    public function verification(): array
    {
        $read = $this->rowsRead;
        $rejected = $this->rejectedCount();
        $unaccounted = $this->unaccountedCount();
        $expected = $this->verificationComplete && $this->resumedRows === 0
            ? max(0, $read - $rejected)
            : null;

        $short = $this->inDatabase !== null && $expected !== null && $this->inDatabase < $expected;
        $verdict = match (true) {
            $unaccounted > 0 || $short => 'discrepancy',
            ! $this->verificationComplete => 'partial',
            $this->inDatabase === null => 'unverifiable',
            $expected === null => 'counted',
            default => 'verified',
        };

        $counted = number_format($read).' read, '.number_format($this->rowsAccounted).' accounted for, '
            .number_format($rejected).' refused'
            .($this->resumedRows > 0
                ? ' ('.number_format($this->resumedRows).' of them committed by an earlier run and not re-read)'
                : '');

        $sentence = match ($verdict) {
            'discrepancy' => $unaccounted > 0
                ? $counted.' — '.number_format($unaccounted).' row'.($unaccounted === 1 ? ' was' : 's were')
                    .' read and then neither imported nor refused, and '.($unaccounted === 1 ? 'it is' : 'they are')
                    .' named below. DO NOT TREAT THIS IMPORT AS COMPLETE.'
                : $counted.' — but '.$this->name.' holds '.number_format((int) $this->inDatabase)
                    .' rows carrying an external id, and '.number_format((int) $expected).' were expected. '
                    .number_format((int) $expected - (int) $this->inDatabase).' row'
                    .((int) $expected - (int) $this->inDatabase === 1 ? ' is' : 's are')
                    .' missing from the database. DO NOT TREAT THIS IMPORT AS COMPLETE.',
            'partial' => $counted.' so far — this bucket is part-way through, so these are a slice and not the file.',
            'counted' => $counted.', and '.$this->name.' holds '.number_format((int) $this->inDatabase)
                .' rows carrying an external id. This run resumed, so it cannot say how many of the rows it '
                .'did not re-read were refusals, and the two numbers are reported side by side rather than '
                .'compared. Re-run this entity from the first row for a verdict.',
            'unverifiable' => $counted.' — this entity writes onto rows another entity owns, so there is no table '
                .'of its own to count. The arithmetic above is the whole check.',
            default => $counted.', '.number_format((int) $this->inDatabase).' in the database'
                .($this->inDatabase > (int) $expected
                    ? ' (more than this file supplied — the table also holds rows from an earlier import or another source)'
                    : '').'.',
        };

        return [
            'verdict' => $verdict,
            'read' => $read,
            'accounted' => $this->rowsAccounted,
            'rejected' => $rejected,
            'unaccounted' => $unaccounted,
            'in_database' => $this->inDatabase,
            'expected_in_database' => $expected,
            'sentence' => $sentence,
        ];
    }

    public function touched(): int
    {
        return $this->created + $this->updated + $this->unchanged;
    }

    public function isEmpty(): bool
    {
        return $this->touched() === 0 && $this->skipped === 0 && $this->rejections === []
            && $this->adjustments === [] && $this->discards === [] && $this->unaccounted === [];
    }
}
