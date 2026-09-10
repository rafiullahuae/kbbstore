<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('update_releases', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('version');
            // running | applied | rolled_back | failed
            $t->string('status')->default('running')->index();
            $t->string('backup_id')->nullable();
            $t->unsignedInteger('file_count')->default(0);
            $t->text('notes')->nullable();
            $t->longText('migration_output')->nullable();
            $t->text('error')->nullable();
            $t->unsignedBigInteger('applied_by')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('update_releases');
    }
};
