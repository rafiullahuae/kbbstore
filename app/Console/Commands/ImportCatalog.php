<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * `kbb:import-catalog` — audited, and it does nothing.
 *
 * WHAT IT ACTUALLY DID. Its description still advertises "Import products from
 * a WooCommerce JSON export (idempotent on wc_id)", and its handle() has
 * returned a one-line notice since Phase 0 without touching the database. So
 * the honest answer to "does the existing importer honour external ids" is
 * that there is no existing importer. Nothing in this repository imports
 * WooCommerce data. The "WordPress Migrator" that the old notice pointed at
 * does not exist here either — no command, no service, no controller.
 *
 * WHAT WAS REMOVED WITH THIS COMMIT, AND WHY IT MATTERED. The file also
 * carried a private handleLegacy() of about sixty lines that nothing called.
 * It read as a complete, working product importer, and it was not:
 *
 *   - It wrote `brand`, `category`, `concerns`, `in_stock`, `reviews`,
 *     `images_json`, `variants_json`, `collections_json`, `labels_json`,
 *     `custom_tabs_json` and `variant_axis`. Not one of those columns exists.
 *     Phase 0 replaced the flat products table: brand and category are real
 *     foreign keys to real tables, images and custom_tabs are json columns
 *     under different names, and reviews are their own table.
 *   - It assigned `$p['price']` straight through from JSON. Prices in this
 *     schema are integer fils (AED x 100). A decimal in the export would have
 *     been silently truncated to whole fils — AED 99.50 landing as 99 fils,
 *     an order of magnitude and a half out, on every product.
 *   - It defaulted `status` to 'active', a value this schema has no concept
 *     of; the column holds publish/draft/private.
 *
 * It did match on wc_id, which is the one thing it got right and the reason it
 * was worth reading before deleting. But dead code that looks like a working
 * importer is a trap set for whoever runs the real migration: it is the
 * obvious thing to re-enable under time pressure, it would have failed on the
 * first row, and if the schema had drifted the other way it would have
 * succeeded and quietly mangled the prices instead.
 *
 * The contract a real importer has to meet — every table, its external id, its
 * uniqueness constraints and the columns it must set — is written down in
 * docs/IMPORT-READINESS.md, and pinned by tests/Feature/ImportContractTest.php.
 *
 * THAT IMPORTER NOW EXISTS. It is `kbb:import`
 * (App\Console\Commands\ImportWooCommerce), with the operating manual in
 * docs/IMPORT-RUNBOOK.md. This command is kept as a signpost rather than
 * deleted: it is the name in the old notes and the obvious thing to reach for,
 * and a command that has silently disappeared sends whoever is running the
 * migration under time pressure looking for a file rather than at the one they
 * want.
 */
class ImportCatalog extends Command
{
    protected $signature = 'kbb:import-catalog';

    protected $description = 'Retired — use kbb:import';

    public function handle(): int
    {
        $this->warn('kbb:import-catalog is retired and imports nothing.');
        $this->line('The WooCommerce importer is:  php artisan kbb:import --dir=<folder> --dry-run');
        $this->line('Operating manual:             docs/IMPORT-RUNBOOK.md');
        $this->line('Schema contract:              docs/IMPORT-READINESS.md');

        return self::SUCCESS;
    }
}
