<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Content -> Media Library -> WebP images (Lane WP): one row per JPEG/PNG the
 * converter has looked at.
 *
 * It is the converter's ledger and its memory at once. A file with a row is
 * never looked at again, which is what makes the bulk run resumable and
 * idempotent: a batch killed half way is picked up by the next one, and a
 * second full run does nothing. It is also the log the owner reads — every
 * conversion, the bytes before and after, and how many references moved — and
 * the list "Remove originals" and "Undo" work from.
 *
 *   status   pending   a conversion started and has not finished
 *            converted the WebP exists; the original is still on disk
 *            removed   the original has been deleted (by the owner, or at
 *                      upload time when "keep original" is off)
 *            skipped   left alone on purpose: WebP was not smaller, too many
 *                      pixels, not really a JPEG/PNG — `reason` says which
 *            failed    could not be converted; `reason` says why
 *   refs_done  the references for a converted file have been re-pointed. A
 *              batch that dies between the two is finished by the next one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('webp_conversions')) {
            return;
        }

        Schema::create('webp_conversions', function (Blueprint $t) {
            $t->id();
            $t->string('from_path')->unique();
            $t->string('to_path')->nullable();
            $t->string('origin', 16)->default('bulk');
            $t->string('status', 16)->index();
            $t->string('reason', 40)->nullable();
            $t->unsignedBigInteger('bytes_before')->nullable();
            $t->unsignedBigInteger('bytes_after')->nullable();
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->unsignedInteger('refs')->default(0);
            $t->boolean('refs_done')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webp_conversions');
    }
};
