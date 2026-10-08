<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The ledger of "Fetch missing pictures from the old server" (Lane PX,
 * App\Services\Import\OldServerPictures): one row per referenced
 * /wp-content/uploads/ picture that was missing on disk when Check last ran,
 * and what happened when it was asked for from Hostinger. A run resumes onto
 * the rows still `pending`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('old_picture_fetches')) {
            return;
        }

        Schema::create('old_picture_fetches', function (Blueprint $t) {
            $t->id();
            $t->char('path_hash', 40)->unique();
            $t->text('path');
            $t->string('host', 255)->nullable();
            $t->text('owners')->nullable();
            $t->unsignedInteger('refs')->default(0);
            $t->string('state', 16)->default('pending')->index();
            $t->text('reason')->nullable();
            $t->unsignedSmallInteger('status_code')->nullable();
            $t->unsignedBigInteger('bytes')->default(0);
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->string('seen_scan', 16)->nullable();
            $t->timestamp('attempted_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('old_picture_fetches');
    }
};
