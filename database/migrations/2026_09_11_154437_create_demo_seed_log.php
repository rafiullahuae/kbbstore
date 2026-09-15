<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks exactly which records a demo-content import created, so "Remove"
 * can delete precisely those rows and nothing else — never a query that
 * guesses at demo data by name pattern or date range, which could catch
 * real records too. One row per created record, not one row per import
 * batch, so a partial failure during import still leaves an accurate,
 * removable trail rather than an all-or-nothing marker.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('demo_seed_log')) {
            Schema::create('demo_seed_log', function (Blueprint $t) {
                $t->id();
                $t->string('type');
                $t->string('model');
                $t->unsignedBigInteger('record_id');
                $t->timestamp('created_at')->useCurrent();
                $t->index(['type']);
                $t->index(['model', 'record_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_seed_log');
    }
};
