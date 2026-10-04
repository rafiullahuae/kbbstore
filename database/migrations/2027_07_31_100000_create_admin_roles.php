<?php

declare(strict_types=1);

use App\Support\AdminRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Editable roles (Lane RL, plan row 53): Platform → Users & Roles.
 *
 * `admin_roles` holds the eight predefined roles and any the owner makes;
 * `admin_users` gains the role an account is on and that person's own tweaks.
 *
 * NOTHING ANYBODY CAN DO CHANGES WHEN THIS RUNS. Every existing account keeps
 * role_id NULL, which App\Support\AdminRoles reads as "the preset your legacy
 * role maps to" — and every preset is seeded with capabilities NULL, which reads
 * as "the code default", which for owner/manager/support/editor IS
 * AdminCapabilities::CAPABILITIES. AdminRolesTest compares the two for every
 * route rule and every legacy role.
 *
 * Every step is guarded, so a half-applied run (or a second one) finishes
 * rather than failing on a table or column that is already there. No foreign
 * key: deleting a role that is in use is refused by the screen, and a role_id
 * that names no row resolves to no access at all, which is the closed answer.
 * Plain columns behave the same on MySQL and on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_roles')) {
            Schema::create('admin_roles', function (Blueprint $t) {
                $t->id();
                $t->string('slug', 64)->unique();
                $t->string('name', 60);
                $t->string('description', 255)->nullable();
                $t->string('tier', 16)->default('support');
                $t->boolean('is_preset')->default(false);
                $t->text('capabilities')->nullable();
                $t->timestamps();
            });
        }

        $columns = [
            'role_id' => fn (Blueprint $t) => $t->unsignedBigInteger('role_id')->nullable()->index(),
            'role_title' => fn (Blueprint $t) => $t->string('role_title', 60)->nullable(),
            'grants' => fn (Blueprint $t) => $t->text('grants')->nullable(),
            'revokes' => fn (Blueprint $t) => $t->text('revokes')->nullable(),
        ];
        foreach ($columns as $column => $add) {
            if (! Schema::hasColumn('admin_users', $column)) {
                Schema::table('admin_users', $add);
            }
        }

        $now = now();
        foreach (AdminRoles::PRESETS as $slug => [$name, $tier, $description]) {
            if (! DB::table('admin_roles')->where('slug', $slug)->exists()) {
                DB::table('admin_roles')->insert([
                    'slug' => $slug, 'name' => $name, 'description' => $description, 'tier' => $tier,
                    'is_preset' => true, 'capabilities' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        AdminRoles::flush();
    }

    public function down(): void
    {
        if (Schema::hasColumn('admin_users', 'role_id')) {
            Schema::table('admin_users', fn (Blueprint $t) => $t->dropIndex(['role_id']));
        }
        foreach (['role_id', 'role_title', 'grants', 'revokes'] as $column) {
            if (Schema::hasColumn('admin_users', $column)) {
                Schema::table('admin_users', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
        Schema::dropIfExists('admin_roles');
        AdminRoles::flush();
    }
};
