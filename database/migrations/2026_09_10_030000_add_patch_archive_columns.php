<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * archive_path: where a successfully-applied release's original zip was
 * copied on this server, so it can be re-downloaded any time afterward —
 * previously the runner deleted the uploaded zip immediately after applying
 * it, every time, leaving nothing to come back to.
 *
 * superseded_by: set manually (by whichever migration ships the replacement)
 * when a release turned out to be wrong and a later one replaced it — this
 * project has had a few of those. Nothing can detect "this was a mistake"
 * automatically; this just gives the history table a structured place to
 * say so, instead of relying on someone reading through notes to work out
 * which version is the one actually worth keeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('update_releases', function (Blueprint $t) {
            if (! Schema::hasColumn('update_releases', 'archive_path')) {
                $t->string('archive_path')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('update_releases', 'superseded_by')) {
                $t->string('superseded_by')->nullable()->after('archive_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('update_releases', function (Blueprint $t) {
            $t->dropColumn(['archive_path', 'superseded_by']);
        });
    }
};
