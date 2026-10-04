<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing Emails → Campaigns (Lane MK, plan §2.2 and §4).
 *
 *   blocks            the draft the builder edits.
 *   blocks_snapshot   FROZEN when the send starts: the draft's blocks with
 *                     every product block's ids resolved, so editing the
 *                     draft mid-send cannot change what the rest of the list
 *                     receives.
 *   rules_snapshot    the group's audience, match and rules, frozen at the
 *                     same moment for the same reason.
 *   status            draft | scheduled | sending | paused | sent |
 *                     cancelled | failed.
 *   audience_cursor / audience_built
 *                     the list is written into mkt_sends a slice of 1,000 at a
 *                     time (one step each, resumable), never as one long
 *                     request; the cursor is the last id written.
 *   recipients … revenue_fils
 *                     counters for the Campaigns table and the report;
 *                     unsigned, money in fils.
 *   html_bytes        the size of the last render, for the 95 KB guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mkt_campaigns')) {
            return;
        }

        Schema::create('mkt_campaigns', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->unsignedBigInteger('template_id')->nullable();
            $t->json('blocks');
            $t->json('blocks_snapshot')->nullable();
            $t->string('subject', 200)->default('');
            $t->string('preheader', 200)->default('');
            $t->string('from_name', 120)->nullable();
            $t->string('audience', 20)->default('customers');
            $t->unsignedBigInteger('segment_id')->nullable();
            $t->json('rules_snapshot')->nullable();
            $t->string('status', 12)->default('draft');
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->unsignedBigInteger('audience_cursor')->default(0);
            $t->boolean('audience_built')->default(false);
            $t->unsignedInteger('recipients')->default(0);
            $t->unsignedInteger('sent')->default(0);
            $t->unsignedInteger('failed')->default(0);
            $t->unsignedInteger('skipped')->default(0);
            $t->unsignedInteger('clicks')->default(0);
            $t->unsignedInteger('unsubscribes')->default(0);
            $t->unsignedInteger('orders')->default(0);
            $t->unsignedBigInteger('revenue_fils')->default(0);
            $t->unsignedInteger('html_bytes')->default(0);
            $t->timestamp('test_sent_at')->nullable();
            $t->string('test_sent_to', 191)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('sent_by')->nullable();
            $t->timestamps();

            $t->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mkt_campaigns');
    }
};
