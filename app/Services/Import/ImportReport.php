<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * What the import did, per entity, including every row it would not take.
 *
 * THE REJECTION LIST IS THE POINT. Counts alone make an import look finished:
 * "customers: 3,706 imported" reads as success whether the export held 3,706
 * rows or 3,712. The six missing ones are the story, and they are invisible
 * unless each one is named with the reason it was refused. So every refusal
 * records the source line number, the external id, and a sentence the owner can
 * act on — and `--rejects=` writes all of them to a CSV that can be opened next
 * to the export.
 *
 * FIVE OUTCOMES, NOT THREE, because "nothing happened to this row" has three
 * different meanings and conflating them hides bugs:
 *
 *  - created   — the row did not exist and now does.
 *  - updated   — the row existed, and at least one column changed.
 *  - unchanged — the row existed and was byte-identical. On a second full pass
 *                this should be nearly every row; if it is not, something is
 *                being rewritten on every run and the import is not idempotent.
 *                That is the number that proves idempotency at a glance.
 *  - skipped   — deliberately not processed: already past the checkpoint, or
 *                excluded by a flag. Not an error.
 *  - rejected  — refused, with a reason. Always an error the owner should read.
 *
 * `notes` carries the observations that are neither: an order status this
 * schema has never seen (kept verbatim, because the column is free-form and
 * production really does carry `shipped` and `tamara-p-failed`), a synthesised
 * placeholder address, a guest customer invented. None of those is wrong, and
 * all of them change what the database means afterwards, so they are surfaced
 * rather than buried.
 */
final class ImportReport
{
    /** @var array<string, EntityReport> */
    private array $entities = [];

    private bool $dryRun = false;

    public function __construct(bool $dryRun = false)
    {
        $this->dryRun = $dryRun;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function for(string $entity): EntityReport
    {
        return $this->entities[$entity] ??= new EntityReport($entity);
    }

    /** @return array<string, EntityReport> */
    public function entities(): array
    {
        return $this->entities;
    }

    public function totalRejected(): int
    {
        return array_sum(array_map(static fn (EntityReport $e): int => $e->rejectedCount(), $this->entities));
    }

    public function totalAdjusted(): int
    {
        return array_sum(array_map(static fn (EntityReport $e): int => $e->adjustedCount(), $this->entities));
    }

    public function totalDiscarded(): int
    {
        return array_sum(array_map(static fn (EntityReport $e): int => $e->discardedCount(), $this->entities));
    }

    /**
     * Rows read and then neither imported nor refused, across every bucket.
     *
     * Zero on every run this importer has ever made, and the number worth
     * printing anyway: it is the only one that can go wrong without any other
     * number in the report changing.
     */
    public function totalUnaccounted(): int
    {
        return array_sum(array_map(
            static fn (EntityReport $e): int => $e->unaccountedCount(),
            $this->entities,
        ));
    }

    /** Did any bucket fail its own count check? */
    public function hasDiscrepancy(): bool
    {
        foreach ($this->entities as $entity) {
            if ($entity->verification()['verdict'] === 'discrepancy') {
                return true;
            }
        }

        return false;
    }

    public function totalWritten(): int
    {
        return array_sum(array_map(
            static fn (EntityReport $e): int => $e->created + $e->updated,
            $this->entities,
        ));
    }

    /**
     * Every rejection across every entity, flattened for the CSV.
     *
     * @return list<array{entity: string, line: int|string, id: string, reason: string}>
     */
    public function allRejections(): array
    {
        $out = [];

        foreach ($this->entities as $entity) {
            foreach ($entity->rejections() as $rejection) {
                $out[] = [
                    'entity' => $entity->name,
                    'line' => $rejection['line'],
                    'id' => $rejection['id'],
                    'reason' => $rejection['reason'],
                ];
            }
        }

        return $out;
    }
}
