<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The contact page's inquiries (Lane CT): one row per message sent from the
 * form on /contact-us/, read at Store → Inquiries.
 *
 * The caps are the form's own (App\Support\ContactPage::MAX), so a row can
 * never hold more than the validator let through. `read_at` drives the
 * unread mark; `mailed_at` records that the alert email went out, and stays
 * empty when the mail failed — the row is the record either way. `ip` is kept
 * for the owner to recognise abuse; nothing on the shop prints it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contact_inquiries')) {
            return;
        }

        Schema::create('contact_inquiries', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('email', 160);
            $t->string('phone', 40)->nullable();
            $t->string('topic', 80)->nullable();
            $t->text('message');
            $t->string('locale', 8)->default('en');
            $t->string('ip', 45)->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('mailed_at')->nullable();
            $t->timestamps();
            $t->index(['read_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_inquiries');
    }
};
