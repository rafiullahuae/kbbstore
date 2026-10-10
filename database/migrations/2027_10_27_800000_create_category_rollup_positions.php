<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A PARENT CATEGORY'S OWN ORDER FOR THE PRODUCTS ITS PAGE ROLLS UP. (Lane RL)
 *
 * The owner, 10 October: on Catalog -> Reorder, "i have selected the skincare
 * parent category, but i can see only 19 products which are actually added to
 * the parent category ... i want to see all the products, even from the sub
 * categories too ... same like i have on front-end ... and that re-order will
 * only apply on parent category on front-end."
 *
 * A parent whose page lists its sub-categories' products (CategoryRollup,
 * Lane SC) could only order the products filed directly under it: its order
 * lives on category_product rows, and a product filed only in a child has no
 * row under the parent. Writing one would FILE the product in the parent --
 * ticked in its editor, counted, listed when the parent is switched to "only
 * its own products" -- which is not what reordering a page means. And the
 * child's own rows hold the CHILD's order, which must not move.
 *
 * So the parent's page order gets its own rows: one per (parent, product),
 * read by ScopeOrder::inCategory() only for a page that rolls up, written
 * only by Catalog -> Reorder. Empty until the owner reorders a parent, so
 * applying this changes no page: the order falls back to exactly what it was.
 * Both keys cascade, so a deleted category or product takes its rows with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('category_rollup_positions')) {
            Schema::create('category_rollup_positions', function (Blueprint $t) {
                $t->foreignId('category_id')->constrained()->cascadeOnDelete();
                $t->foreignId('product_id')->constrained()->cascadeOnDelete();
                $t->unsignedInteger('position');
                $t->primary(['category_id', 'product_id']);
            });
        }

        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/events.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('category_rollup_positions');
    }
};
