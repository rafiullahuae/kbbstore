<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What put an address on the "Not sending to" list (Lane EB): the status code,
 * the receiving server's words and the campaign, so the Bounced tab can show
 * them without re-reading every report. All nullable: the rows written before
 * this (unsubscribes, Lane MK's bounces) simply have none.
 *
 * No ->after(): MigrationConventionTest, and a column's position is not
 * information anything here reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_suppressions')) {
            return;
        }

        Schema::table('email_suppressions', function (Blueprint $t) {
            if (! Schema::hasColumn('email_suppressions', 'code')) {
                $t->string('code', 12)->nullable();
            }

            if (! Schema::hasColumn('email_suppressions', 'detail')) {
                $t->string('detail', 255)->nullable();
            }

            if (! Schema::hasColumn('email_suppressions', 'campaign_id')) {
                $t->unsignedBigInteger('campaign_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (['code', 'detail', 'campaign_id'] as $column) {
            if (Schema::hasColumn('email_suppressions', $column)) {
                Schema::table('email_suppressions', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
