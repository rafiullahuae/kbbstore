<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;
use App\Services\Import\Row;

/**
 * One kind of thing being imported.
 *
 * The runner owns batching, transactions, the checkpoint and the report;
 * implementations own the mapping and the validation and nothing else. That
 * split is what makes the resume behaviour a property of the system rather than
 * something each entity has to remember to get right — there is exactly one
 * place where a batch commits alongside its progress, and it is not here.
 *
 * import() either returns, having written whatever the row means, or throws
 * RowRejected with a reason the owner can act on. It must not catch its own
 * errors and carry on: a mapping that half-writes a row and reports success is
 * the thing this whole design is arranged to prevent.
 */
abstract class EntityImporter
{
    /** The entity name, used for --only, the checkpoint and the report. */
    abstract public function name(): string;

    /** The file this entity is read from when --dir is used. */
    abstract public function conventionalFile(): string;

    /**
     * Import one row.
     *
     * @throws \App\Services\Import\RowRejected
     */
    abstract public function import(Row $row, ImportContext $context): void;

    /**
     * Whatever has to happen once the entity's rows are all in.
     *
     * Only categories use it, to recompute the cached `depth` and `path`
     * columns after every parent link exists. It runs in its own transaction
     * after the last batch commits, and it is idempotent, so an interrupted run
     * that resumes and finishes still gets it.
     */
    public function finalise(ImportContext $context): void {}

    /**
     * A short identifier for this row, for the rejection report.
     *
     * Best effort by definition — the row may be rejected precisely because its
     * id is unreadable — so it must never throw.
     */
    public function identify(Row $row): string
    {
        foreach (['id', 'order_id', 'user_id', 'term_id', 'item_id', 'sku', 'slug', 'email'] as $key) {
            $value = $row->raw($key);

            if ($value !== null && $value !== '') {
                return $key.'='.$value;
            }
        }

        return 'line '.$row->line;
    }
}
