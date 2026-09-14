<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redirects & 404 manager.
 *
 * redirects itself already exists (source/target/code/enabled/hits/
 * last_hit_at, from the original schema migration) — found this only by
 * actually testing against it, after first assuming a from_path/to_url/
 * status_code shape that didn't match anything real. This migration only
 * adds the one genuinely new column, auto_created, plus the separate
 * not_found_log table.
 *
 * A redirect is a rule an admin wrote on purpose; a 404 is something that
 * just happened — kept as two tables rather than one, so "show me my
 * redirects" and "show me what's actually broken" stay two different,
 * simple queries instead of one query with a filter that's easy to get
 * subtly wrong later.
 */
return new class extends Migration
{
    public function up(): void
    {
        // redirects itself already exists — created by the original schema
        // migration (source/target/code/enabled/hits/last_hit_at). Only
        // auto_created is actually new, distinguishing a redirect an admin
        // wrote on purpose from one this feature created automatically on
        // a slug change.
        if (Schema::hasTable('redirects') && !Schema::hasColumn('redirects', 'auto_created')) {
            Schema::table('redirects', function (Blueprint $t) {
                $t->boolean('auto_created')->default(false)->after('code');
            });
        }

        if (!Schema::hasTable('not_found_log')) {
            Schema::create('not_found_log', function (Blueprint $t) {
                $t->id();
                $t->string('path')->unique();
                $t->unsignedInteger('hits')->default(1);
                $t->string('referer')->nullable();
                $t->timestamp('first_seen_at')->useCurrent();
                $t->timestamp('last_seen_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('not_found_log');

        if (Schema::hasColumn('redirects', 'auto_created')) {
            Schema::table('redirects', function (Blueprint $t) {
                $t->dropColumn('auto_created');
            });
        }
    }
};
