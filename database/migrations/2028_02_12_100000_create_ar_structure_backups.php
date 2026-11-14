<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Lane ARDX (14 November 2026): the backup every Arabic spacing repair writes
 * BEFORE it changes a row (php artisan kbb:ar-structure --apply, or Catalog ->
 * Translate & categorise -> Arabic spacing -> Apply). One row per changed
 * translation, grouped by batch; --undo=<batch> puts back exactly the rows that
 * still hold what the repair wrote. Nothing else moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ar_structure_backups')) {
            return;
        }

        Schema::create('ar_structure_backups', function (Blueprint $t) {
            $t->id();
            $t->string('batch', 40)->index();
            $t->unsignedBigInteger('translation_id');
            $t->string('group', 32);
            $t->unsignedBigInteger('item_id');
            $t->string('field', 64);
            $t->longText('old_value');
            $t->longText('new_value');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('undone_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ar_structure_backups');
    }
};
