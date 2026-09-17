<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every message this store hands to a transport, and what became of it.
 *
 * WHY A TABLE AND NOT THE LARAVEL LOG. The owner has no shell on this host.
 * `storage/logs/laravel.log` is, from where he sits, write-only: OrderMailer,
 * OrderMailObserver and PasswordResetController all swallow a transport failure
 * and write a line there, which is exactly right for keeping a failed email from
 * turning into a failed order -- and exactly useless as a record, because the
 * only person who could read it would need SSH or a file manager and the right
 * guess about which of a dozen rotated files to open. A swallowed failure nobody
 * can see is the same shape as the defect this whole subject exists to close:
 * the send that looks like it worked.
 *
 * WHAT IS STORED, AND WHAT IS DELIBERATELY NOT.
 *
 *   kind        which email it was -- 'order.placed', 'password.reset'. Never
 *               free text from a request.
 *   recipient   the address. This is the point of the record: "did the customer
 *               get it" cannot be answered without it. Admin-only, and this
 *               table is reachable from exactly one authenticated endpoint.
 *   subject     the rendered subject line.
 *   message_id  the transport's own Message-ID, where one came back. This is
 *               what the owner quotes to a host's support desk, and the one
 *               field that distinguishes "a real server accepted this" from
 *               "nothing threw".
 *   error       the transport's message, with the SMTP password stripped.
 *
 * NO BODY, EVER. The body of a password reset carries a live token, the body of
 * a verification mail carries a live signed link, and a newsletter confirmation
 * carries both a confirm and an unsubscribe token. A log that rendered any of
 * them would be a second, permanent, searchable copy of a credential that the
 * notification classes go out of their way never to write down -- see
 * CustomerPasswordReset's header, which records that not even the URL is logged.
 * The subject line of every mailable in this shop is static wording; none
 * carries a token, and MailLog redacts rather than trusting that.
 *
 * `status` is one of 'sending', 'sent', 'failed'. A row is opened as 'sending'
 * before the transport is called and closed afterwards, so a message that took
 * the process down with it still leaves a row saying it was attempted. That is
 * the difference between this and a log written only on the way out.
 *
 * No ->after() anywhere: this creates a table rather than altering one. See
 * tests/Feature/MigrationConventionTest.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mail_deliveries')) {
            return;
        }

        Schema::create('mail_deliveries', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 40)->default('unknown');
            $t->string('recipient', 191)->default('');
            $t->string('subject', 255)->default('');
            $t->string('transport', 20)->default('');
            $t->string('status', 10)->default('sending');
            $t->string('message_id', 191)->nullable();
            $t->text('error')->nullable();
            $t->timestamps();

            /*
             * The screen reads "newest first" and nothing else, and the pruner
             * deletes by id. `created_at` is indexed because the pruner's
             * age-based half filters on it; `status` because the screen's
             * "failures only" filter is the one an owner actually reaches for.
             */
            $t->index('created_at');
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_deliveries');
    }
};
