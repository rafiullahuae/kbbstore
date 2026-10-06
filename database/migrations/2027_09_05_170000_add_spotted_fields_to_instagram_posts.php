<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #KBeautyBliss Spotted from Instagram (Lane SG, 2.60.417).
 *
 * The owner, 6 October 2026: "a function to fetch our instagram all posts /
 * videos, and to choose from the list which one need to be shown on the page …
 * likes, comments, shares icons with counts".
 *
 *   spotted_sort   NULL = not on the page; 1, 2, 3 … = on the page, in that
 *                  order. One column is the tick AND the order, so the two can
 *                  never disagree (a "selected" flag beside a separate sort is
 *                  two writes that can half-happen).
 *   share_count    the media insights metric `shares`. NULL means Instagram did
 *   view_count     not tell us (no insights permission yet, or no value), and
 *   insights_at    the card then draws no share count at all -- never a 0.
 *
 * Guarded column by column, so a half-applied package re-runs cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('instagram_posts')) {
            return;
        }

        foreach ([
            'spotted_sort' => fn (Blueprint $t) => $t->unsignedInteger('spotted_sort')->nullable()->index('instagram_posts_spotted_idx'),
            'share_count' => fn (Blueprint $t) => $t->unsignedInteger('share_count')->nullable(),
            'view_count' => fn (Blueprint $t) => $t->unsignedInteger('view_count')->nullable(),
            'insights_at' => fn (Blueprint $t) => $t->timestamp('insights_at')->nullable(),
        ] as $column => $add) {
            if (! Schema::hasColumn('instagram_posts', $column)) {
                Schema::table('instagram_posts', $add);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('instagram_posts')) {
            return;
        }

        foreach (['spotted_sort', 'share_count', 'view_count', 'insights_at'] as $column) {
            if (Schema::hasColumn('instagram_posts', $column)) {
                Schema::table('instagram_posts', function (Blueprint $t) use ($column): void {
                    if ($column === 'spotted_sort') {
                        $t->dropIndex('instagram_posts_spotted_idx');
                    }
                    $t->dropColumn($column);
                });
            }
        }
    }
};
