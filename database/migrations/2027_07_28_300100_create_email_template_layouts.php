<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lane EK — the order of each email's sections, as the owner arranges it in
 * Emails → Customer emails → (an email) → Edit.
 *
 * The owner, 3 October 2026: "give facility in edit any template, to
 * re-position any section by drag n drop."
 *
 * ONE ROW PER EMAIL, and only for an email somebody has changed. No row means
 * the email renders exactly as the code draws it — which is how nothing in any
 * inbox changes on the day this package is applied (MailKitParityTest compares
 * the nineteen emails byte for byte). `layout` is JSON written only by
 * App\Services\Mail\Kit\KitSections::save(), which keeps section keys from the
 * email's own catalogue and blocks built from the kit's own block types; it is
 * never printed, it is read.
 *
 * The WORDS of an email are not here. They are interface strings, and they
 * already have a home: the `translations` table, where Translation → Strings
 * edits the same rows. Two places to keep one sentence is how they drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_template_layouts')) {
            return;
        }

        Schema::create('email_template_layouts', function (Blueprint $t) {
            $t->id();
            $t->string('template', 40)->unique();
            $t->text('layout');
            $t->string('updated_by', 191)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_template_layouts');
    }
};
