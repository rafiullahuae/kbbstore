<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Catalog -> Labels (Lane LBL, the owner's 15 Nov approval of
 * docs/plans/product-labels/). The labels he makes and the RULES that put them
 * on products -- never a resolved product list: "Sun care, including
 * sub-categories" covers next week's sunscreen without anybody editing the
 * label again. App\Services\CustomLabels resolves the rules into one cached
 * map that the shop reads.
 *
 * Guarded with hasTable() so a re-applied package is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_labels')) {
            Schema::create('product_labels', function (Blueprint $t) {
                $t->id();
                $t->string('name', 80);
                $t->string('name_ar', 80)->nullable();
                $t->string('text', 40);
                $t->string('text_ar', 40)->nullable();
                $t->string('bg', 7)->default('#C13E63');
                $t->string('fg', 7)->default('#FFFFFF');
                $t->string('shape', 1)->default('A');          // A pill | B ribbon | C corner sash | D soft tag
                $t->string('placement', 5)->default('both');   // cards | page | both
                $t->string('mode', 10)->default('both');       // products | categories | both
                $t->date('starts_on')->nullable();             // store-time dates, inclusive
                $t->date('ends_on')->nullable();
                $t->unsignedTinyInteger('priority')->default(1);
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('product_label_targets')) {
            Schema::create('product_label_targets', function (Blueprint $t) {
                $t->id();
                $t->foreignId('label_id')->constrained('product_labels')->cascadeOnDelete();
                $t->string('kind', 8);                          // product | category | exclude
                $t->unsignedBigInteger('target_id');
                $t->boolean('with_children')->default(true);    // category rows only
                $t->unique(['label_id', 'kind', 'target_id']);
                $t->index(['kind', 'target_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_label_targets');
        Schema::dropIfExists('product_labels');
    }
};
