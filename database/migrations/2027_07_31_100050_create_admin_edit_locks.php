<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Edit presence (Lane RL): who has which record open in the admin, and who
 * took it over. App\Support\EditPresence reads and writes it.
 *
 * One row per open record (unique resource_type + resource_id, which is also
 * the index every beat and every guarded save reads by) and one 'console' row
 * per admin for "online now". heartbeat_at is indexed for the Members tab's
 * one "who beat recently" query and for the prune. Nothing else in the shop
 * reads it; no storefront page touches it.
 *
 * Guarded, so a second run finishes rather than failing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_edit_locks')) {
            return;
        }

        Schema::create('admin_edit_locks', function (Blueprint $t) {
            $t->id();
            $t->string('resource_type', 24);
            $t->string('resource_id', 64);
            $t->unsignedBigInteger('admin_id');
            $t->string('token', 32);
            $t->timestamp('since_at')->nullable();
            $t->timestamp('heartbeat_at')->nullable()->index();
            $t->unsignedBigInteger('taken_over_by')->nullable();
            $t->string('displaced_token', 32)->nullable();
            $t->unique(['resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_edit_locks');
    }
};
