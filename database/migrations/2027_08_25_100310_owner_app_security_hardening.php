<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The owner app's security review (Lane SEC).
 *
 *   owner_app_members.lock_level           how many times the PIN pad has locked
 *                                          this member since their last good
 *                                          PIN: 1 = 15 min, 2 = 1 h, 3 = 24 h,
 *                                          4 = until a Full Admin unlocks.
 *   owner_app_members.enrol_*              the SAME ladder, kept apart, for
 *                                          email + PIN on a phone that is not
 *                                          enrolled. A stranger guessing at the
 *                                          sign-in form moves only these, so he
 *                                          can never lock the owner out of the
 *                                          phones already in his hand.
 *   owner_app_throttle                     the per-connection limiter, in the
 *                                          database. The shop's cache is the
 *                                          FILE store, whose increment() reads,
 *                                          adds and writes back with no lock —
 *                                          fifty parallel guesses would count as
 *                                          one. A single UPDATE ... WHERE hits <
 *                                          max is atomic on MySQL and SQLite.
 *
 * And the PIN is now 6 to 8 digits. Nothing with a shorter PIN has shipped,
 * but a member who somehow has one is switched off with no PIN, so the
 * screen says "No PIN" and a Full Admin sets a new one; their sessions end.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'lock_level' => fn (Blueprint $t) => $t->unsignedTinyInteger('lock_level')->default(0),
            'enrol_failed_count' => fn (Blueprint $t) => $t->unsignedSmallInteger('enrol_failed_count')->default(0),
            'enrol_locked_until' => fn (Blueprint $t) => $t->timestamp('enrol_locked_until')->nullable(),
            'enrol_lock_level' => fn (Blueprint $t) => $t->unsignedTinyInteger('enrol_lock_level')->default(0),
        ];

        foreach ($columns as $name => $add) {
            if (! Schema::hasColumn('owner_app_members', $name)) {
                Schema::table('owner_app_members', $add);
            }
        }

        if (! Schema::hasTable('owner_app_throttle')) {
            Schema::create('owner_app_throttle', function (Blueprint $t) {
                $t->string('bucket', 100)->primary();
                $t->unsignedInteger('hits')->default(0);
                $t->timestamp('reset_at')->nullable()->index();
            });
        }

        $short = DB::table('owner_app_members')->whereNotNull('pin_hash')
            ->where(fn ($q) => $q->whereNull('pin_length')->orWhere('pin_length', '<', 6))
            ->pluck('id');

        foreach ($short as $id) {
            DB::table('owner_app_members')->where('id', $id)->update([
                'pin_hash' => null, 'pin_length' => null, 'enabled' => false, 'updated_at' => now(),
            ]);
            DB::table('owner_app_devices')->where('member_id', $id)->update(['session_hash' => null, 'session_seen_at' => null]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_app_throttle');

        foreach (['lock_level', 'enrol_failed_count', 'enrol_locked_until', 'enrol_lock_level'] as $name) {
            if (Schema::hasColumn('owner_app_members', $name)) {
                Schema::table('owner_app_members', fn (Blueprint $t) => $t->dropColumn($name));
            }
        }
    }
};
