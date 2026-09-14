<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the single exclusive `location` string with three independent
 * flags. The original design assumed a menu could only ever occupy one
 * slot — desktop OR mobile OR footer — modelled as a dropdown. The real
 * want is simpler and more flexible: the same menu can serve the desktop
 * header AND the mobile menu at once, or neither, checked independently.
 * Exclusivity still holds per slot (only one menu can be the desktop
 * header at a time) — it just no longer forces one menu to be only one
 * thing.
 *
 * Backfills from the old column so any assignment already made under the
 * dropdown design survives the change untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Backfill only runs the first time these columns are actually
        // added — if it ran unconditionally on every migrate, and an
        // admin had since deliberately turned a menu's display off again
        // through the real UI, this would silently turn it back on and
        // undo that.
        $isFirstRun = ! Schema::hasColumn('menus', 'show_desktop');

        Schema::table('menus', function (Blueprint $t) {
            if (! Schema::hasColumn('menus', 'show_desktop')) {
                $t->boolean('show_desktop')->default(false)->after('location');
            }
            if (! Schema::hasColumn('menus', 'show_mobile')) {
                $t->boolean('show_mobile')->default(false)->after('show_desktop');
            }
            if (! Schema::hasColumn('menus', 'show_footer')) {
                $t->boolean('show_footer')->default(false)->after('show_mobile');
            }
        });

        if ($isFirstRun) {
            \Illuminate\Support\Facades\DB::table('menus')->where('location', 'primary')->update(['show_desktop' => true]);
            \Illuminate\Support\Facades\DB::table('menus')->where('location', 'mobile')->update(['show_mobile' => true]);
            \Illuminate\Support\Facades\DB::table('menus')->where('location', 'footer')->update(['show_footer' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $t) {
            $t->dropColumn(['show_desktop', 'show_mobile', 'show_footer']);
        });
    }
};
