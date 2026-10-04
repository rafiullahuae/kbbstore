<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lane EK — Emails → Customer emails → (an email) → Edit: the template
 * builder's store, docs/EMAILS-PLAN.md §2.1.
 *
 * One row per email per language, and only for an email somebody has changed.
 * No row (or a blank field) means the built-in wording and the built-in order —
 * which is how applying the package changes no email: MailKitParityTest
 * compares all nineteen with golden files written before the editor existed.
 *
 *   key        a key of App\Services\Mail\Kit\KitSections::TEMPLATES (closed list)
 *   locale     en | ar
 *   subject    single line, CRLF refused (a header-injection guard)
 *   preheader, heading   single line
 *   body       plain text with {tags}; never HTML — the kit prints it escaped
 *   blocks     the section order, switches and added blocks (KitSections::
 *              clean()), kept on the `en` row: one order serves both languages
 *
 * No `enabled` column although §2.1 sketches one: every on/off already has a
 * home (the module toggles Store → Modules and OrderStatusMailPolicy read), and
 * a second copy of a setting is how they drift. The integrator's brief:
 * "never a second copy of a setting".
 *
 * Schema::create only, idempotent, no ->after() (MigrationConventionTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_templates')) {
            return;
        }

        Schema::create('email_templates', function (Blueprint $t) {
            $t->id();
            $t->string('key', 60);
            $t->string('locale', 8);
            $t->string('subject', 200)->nullable();
            $t->string('preheader', 200)->nullable();
            $t->string('heading', 200)->nullable();
            $t->text('body')->nullable();
            $t->text('blocks')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();

            $t->unique(['key', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
