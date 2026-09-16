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
     * Why this entity's writes must not email a customer, or null when they can.
     *
     * AN IMPORT IS NOT AN EVENT IN A CUSTOMER'S LIFE. Re-syncing a status that
     * WooCommerce recorded in 2023 onto the order that already holds it is
     * bookkeeping; the customer was told at the time, by WooCommerce, and
     * telling them again years later is a message about the importer rather than
     * about their parcel. `orders` answers this; nothing else does yet.
     *
     * THE ANSWER IS A SENTENCE, not a boolean, because the runner puts it in the
     * report. An import that quietly stopped sending mail would be its own
     * hazard — the owner would find out by noticing that nobody had been written
     * to — so the suppression says what it is and counts what it held back.
     *
     * WHY THIS IS NOT DONE BY ROUTING THROUGH App\Services\Orders\OrderStatus,
     * which is the funnel every other status write in this application goes
     * through and does have an off-switch. The importer is deliberately NOT on
     * that funnel: it would write a history note per row — thousands of them —
     * and hand back coupon uses WooCommerce has already accounted for. Getting
     * an off-switch by joining the funnel would buy this at the price of both.
     */
    public function suppressesCustomerMailBecause(): ?string
    {
        return null;
    }

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
