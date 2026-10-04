<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Growth & Marketing → Marketing Emails → Templates (Lane MK, plan §2.2).
 *
 * One row per template. `blocks` is the builder's closed list of
 * {type, props} (App\Services\Marketing\Blocks) — never HTML.
 *
 *   key        the stable name of a READY template the shop ships
 *              ("new-arrivals", "we-miss-you", …); NULL for the owner's own.
 *   preset     1 for a ready template. Read-only: the owner presses Use (a
 *              campaign draft is made from a copy) or Duplicate (an editable
 *              copy under "My templates"). Keeping the ready ones read-only
 *              means a later package can improve them without overwriting
 *              anything the owner typed.
 *   thumbnail_media_id   plan §2.2. The library draws a live thumbnail of the
 *              template itself, so this stays NULL unless a later screen lets
 *              the owner pin a picture instead.
 *
 * Schema::create only, no ->after() (MigrationConventionTest); idempotent,
 * because packages are sometimes applied twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mkt_templates')) {
            return;
        }

        Schema::create('mkt_templates', function (Blueprint $t) {
            $t->id();
            $t->string('key', 60)->nullable()->unique();
            $t->string('name', 120);
            $t->string('category', 40)->default('mine');
            $t->string('description', 300)->nullable();
            $t->string('subject', 200)->default('');
            $t->string('preheader', 200)->default('');
            $t->json('blocks');
            $t->boolean('preset')->default(false);
            $t->unsignedInteger('sort')->default(0);
            $t->unsignedBigInteger('thumbnail_media_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();

            $t->index(['preset', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mkt_templates');
    }
};
