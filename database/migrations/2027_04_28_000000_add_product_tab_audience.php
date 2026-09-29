<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A global tab stops being all-or-nothing. (Lane PT, round 2)
 *
 * The owner, with a red arrow on Catalog -> Product tabs -> Global tabs:
 *
 *   "on add product tag, i want to choose specific product, category, brand or
 *    sets products or GLOBAL, so it will show according as per the selection
 *    criteria."
 *
 * ── TWO COLUMNS, AND WHY NOT A PIVOT TABLE ─────────────────────────────────
 *
 * The obvious shape is `product_tab_targets(tab_id, kind, target_id)`. It was
 * rejected for ONE reason, and it is the reason CLAUDE.md puts a query budget
 * on the product page: App\Support\ProductTabs caches every global tab in ONE
 * entry and matches in PHP, so a product page costs the same whether the shop
 * has one global tab or thirty. A pivot means the cached row is no longer the
 * whole answer -- either a second cached table that has to evict in step with
 * the first, or a join, or (the way it actually goes wrong) a lookup per tab on
 * every product page in the shop.
 *
 * A tab's audience is CONFIGURATION, not data: a handful of ids, written by the
 * one person who owns the shop, read on every page. That is the same argument
 * SettingsService makes for reading its whole table in one go, and it is why
 * this is two columns on the row that already travels with the tab.
 *
 * ── `audience` ─────────────────────────────────────────────────────────────
 *
 *   global      every product. THE DEFAULT, AND WHAT EVERY EXISTING ROW GETS,
 *               so applying this package moves nothing at all: a tab written
 *               before today keeps showing exactly where it showed.
 *   products    the ids in `audience_ids`.
 *   categories  those categories AND EVERY CATEGORY UNDER THEM -- see
 *               App\Support\ProductTabs::categoryFamily(), which carries the
 *               argument for inheriting down the tree.
 *   brands      the brand ids in `audience_ids`.
 *   sets        every product whose `type` is 'set'. A TYPE MATCH, not a
 *               picker, so a set created next week is covered without anybody
 *               going back to tick it.
 *
 * 16 characters, which is one more than the longest word above. A column whose
 * width says what it holds, the same choice `source_key` made at 64.
 *
 * ── NO ->after(), DELIBERATELY ─────────────────────────────────────────────
 *
 * The first draft of this file positioned both columns after `source_key` and
 * MigrationConventionTest caught it. That guard exists because of a checkout
 * outage: a chain of migrations each added a column AFTER the column the
 * previous one was supposed to create, and on MySQL `ALTER ... AFTER` a column
 * that does not exist is an error, so one missing anchor took out the rest of
 * the chain. Each was wrapped in a hasColumn guard, so the failure looked like
 * a clean no-op and recorded as run. SQLite ignores AFTER entirely, so the
 * suite stayed green while checkout could not take an order.
 *
 * Column order in a table is cosmetic and is not worth that.
 *
 * ── `audience_ids` ─────────────────────────────────────────────────────────
 *
 * A JSON list of integers, NULL for `global` and `sets`, which have no targets.
 * text rather than a native JSON column because this schema is read on both
 * MySQL and SQLite and the application never queries INTO it -- the whole list
 * is loaded and matched in PHP. ProductTabs normalises whatever is in there to
 * a list of positive ints on the way out, so a hand-edited row cannot put a
 * string where an id belongs.
 *
 * ── ONLY A GLOBAL TAB HAS AN AUDIENCE ──────────────────────────────────────
 *
 * A per-product tab already names its product, and an override row already
 * names the tab it covers. Both keep `audience = 'global'` and neither reads
 * it; ProductTabsApiController never writes anything else on those two shapes,
 * and the read path only consults it for rows with product_id IS NULL.
 *
 * ── NOTHING ON THE SHOP MOVES ──────────────────────────────────────────────
 *
 * Two columns with defaults. No row changes meaning, no setting is added, and
 * the three built-in tabs are not rows here at all, so they are untouched.
 * StorefrontEnglishUnchangedTest is the instrument.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_tabs')) {
            return;
        }

        Schema::table('product_tabs', function (Blueprint $table) {
            if (! Schema::hasColumn('product_tabs', 'audience')) {
                /*
                 * DEFAULT 'global', which is what makes this migration a no-op
                 * for a shop that already has tabs. Not nullable: a NULL
                 * audience would be a third state meaning the same thing as
                 * 'global', and two spellings of one state is how a matcher
                 * grows a branch that is wrong half the time.
                 */
                $table->string('audience', 16)->default('global');
            }

            if (! Schema::hasColumn('product_tabs', 'audience_ids')) {
                $table->text('audience_ids')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_tabs')) {
            return;
        }

        Schema::table('product_tabs', function (Blueprint $table) {
            foreach (['audience_ids', 'audience'] as $column) {
                if (Schema::hasColumn('product_tabs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
