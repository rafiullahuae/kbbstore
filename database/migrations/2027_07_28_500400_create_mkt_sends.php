<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per address per campaign (Lane MK, plan §2.2 and §4).
 *
 *   token          40 random hex characters. The click link
 *                  /email/c/{token}/{n} finds the row by it; it is never the
 *                  unsubscribe credential (that is an HMAC, see
 *                  App\Services\Marketing\UnsubscribeToken).
 *   status         pending | claimed | sent | failed | skipped. A row is
 *                  CLAIMED by a conditional UPDATE … WHERE status='pending'
 *                  before its message is handed to the mailer, so two
 *                  concurrent steps can never both send it; a claim older than
 *                  180 seconds belongs to a dead step and goes back to pending.
 *   unique(campaign_id, email)
 *                  the same address can never be on one campaign twice, so
 *                  repeating a list-building slice is harmless.
 *
 * Rows of messages that were actually sent are kept: their unsubscribe link
 * has to keep working for as long as the email sits in an inbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mkt_sends')) {
            return;
        }

        Schema::create('mkt_sends', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('campaign_id');
            $t->string('email', 191);
            $t->string('first_name', 120)->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('subscriber_id')->nullable();
            $t->char('token', 40)->unique();
            $t->string('status', 10)->default('pending');
            $t->timestamp('claimed_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->string('error', 300)->nullable();
            $t->timestamp('first_click_at')->nullable();
            $t->timestamp('unsubscribed_at')->nullable();

            $t->unique(['campaign_id', 'email']);
            $t->index(['campaign_id', 'status']);
            $t->index('email');
            $t->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mkt_sends');
    }
};
