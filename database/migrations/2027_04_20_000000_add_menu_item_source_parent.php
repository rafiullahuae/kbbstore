<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `menu_items.source_parent_post_id` — the WordPress id of an imported menu
 * item's parent, kept so the link can be repaired ACROSS REQUESTS.
 *
 * ── WHY A COLUMN AND NOT AN ARRAY IN THE IMPORTER ───────────────────────────
 *
 * `_menu_item_menu_item_parent` is a `nav_menu_item` POST id, so it has to be
 * translated into a local key, and a child very often arrives before its
 * parent. `CategoryImporter` has the same problem and solves it with an
 * instance array resolved in `finalise()` — which works there because a
 * category's parent is always in the same file and `finalise()` runs after the
 * last batch of it.
 *
 * IT DOES NOT WORK HERE, and the reason is the one CLAUDE.md states in general:
 * **a batch is a separate HTTP request.** Store → Import steps the entity a
 * slice at a time, and `ImportRunner::runEntity()` calls `finalise()` on EVERY
 * call, exhausted or not. So a slice holding the child and not the parent
 * resolves nothing, clears its pending array, and the link is lost for good —
 * the later slice that imports the parent has no idea anything was waiting on
 * it.
 *
 * MEASURED, not anticipated: `AdminImportScreenTest > it reaches the same
 * database whether it is stepped in twos or done in one go` went red with one
 * menu item updated, because the sliced run left a child at the top level and
 * the single-pass run put it back under its parent. On the owner's server every
 * import is sliced, so the sliced answer is the one he would have got.
 *
 * With the parent's WordPress id ON THE ROW, `finalise()` repairs from the
 * database instead of from memory: it is a set operation over the whole table,
 * it is idempotent, and it does not care which request wrote which row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $t) {
            if (! Schema::hasColumn('menu_items', 'source_parent_post_id')) {
                $t->unsignedBigInteger('source_parent_post_id')->nullable()->after('source_post_id');
                $t->index('source_parent_post_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $t) {
            $t->dropIndex(['source_parent_post_id']);
            $t->dropColumn('source_parent_post_id');
        });
    }
};
