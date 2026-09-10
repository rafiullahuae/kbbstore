<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What visitors search for, counted per day.
 *
 * Daily rows rather than a log line per search: a busy day is one row per term,
 * not thousands, and "the last seven days" is then a single grouped query.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('search_terms')) {
            return;
        }

        Schema::create('search_terms', function (Blueprint $t) {
            $t->id();
            $t->string('term', 60);
            $t->date('day');
            $t->unsignedInteger('hits')->default(1);
            $t->unsignedInteger('results')->default(0);
            $t->timestamps();

            $t->unique(['term', 'day']);
            $t->index(['day', 'hits']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_terms');
    }
};
