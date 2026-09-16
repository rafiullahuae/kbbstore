<?php

declare(strict_types=1);

use App\Support\MediaUsageWriter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Fill `media_usages` with every association that already exists. (Lane BF)
 *
 * WHY THIS MIGRATION IS THE POINT OF THE TABLE, not an afterthought. The store
 * has been running for months: products, brands and categories carry image
 * URLs that were saved long before anything recorded them. A table that only
 * held associations made FROM NOW ON would be worse than no table at all — the
 * Media Library would show every older image as unused, and the whole reason
 * the library refuses to delete a referenced file is that a broken image on a
 * live product page is a defect a shopper sees.
 *
 * So the table starts out agreeing with what the screen already shows.
 *
 * DERIVED WITH MediaUsage'S OWN LOGIC, not a copy of it. MediaUsageWriter::
 * rebuild() walks MediaUsage::index() — the same catalogue pass the Media
 * Library's filters have always used — and keeps the entries that satisfy
 * MediaUsage::matches(), which is the predicate MediaUsage::verify() applies.
 * There is no second opinion here to disagree with the first. Whatever the
 * screen derived the moment before this ran is what the table holds the moment
 * after.
 *
 * IDEMPOTENT, and it has to be: on this host a package is applied by hand and
 * gets applied twice often enough that CLAUDE.md has a landmine about it.
 * rebuild() computes the full correct set and writes exactly the difference,
 * so a second run inserts nothing, deletes nothing, and the unique index never
 * sees a collision.
 *
 * RE-RUNNABLE LATER, too. `php artisan media:usages-reconcile` is the same
 * call, which is what makes the drift this table can suffer recoverable rather
 * than permanent.
 *
 * No AFTER clause: this migration adds no column at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media_usages') || ! Schema::hasTable('media')) {
            return;
        }

        $result = MediaUsageWriter::rebuild();

        if (app()->runningInConsole()) {
            echo "media_usages: {$result['added']} recorded, {$result['removed']} stale removed, {$result['kept']} already correct.\n";
        }
    }

    /**
     * Nothing. The table is dropped by the migration that created it, and
     * emptying it here would make a rollback of THIS migration alone leave a
     * table whose emptiness reads as "no image is used by anything" — which is
     * precisely the false answer the whole lane exists to prevent.
     */
    public function down(): void {}
};
