<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A marketing email's LOOK and LANGUAGE (Lane EC), on campaigns and on
 * templates, so a campaign made from a template inherits both:
 *
 *   theme   standard | playful  (App\Services\Marketing\EmailTheme) — design C,
 *           "Playful K-beauty", the look the owner picked on 10 October
 *   locale  en | ar             — ar renders the email right to left
 *
 * Every row already there is a standard English email, which is exactly what
 * the defaults say: applying this changes no campaign and no template.
 *
 * Idempotent (packages are sometimes applied twice); no ->after()
 * (MigrationConventionTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['mkt_campaigns', 'mkt_templates'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'theme')) {
                    $t->string('theme', 20)->default('standard');
                }

                if (! Schema::hasColumn($table, 'locale')) {
                    $t->string('locale', 5)->default('en');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['mkt_campaigns', 'mkt_templates'] as $table) {
            foreach (['theme', 'locale'] as $column) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
    }
};
