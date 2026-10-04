<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lane EK — Growth & Marketing → Email Marketing.
 *
 *   campaign_groups      a saved audience: which list (customers OR
 *                        subscribers -- the owner keeps them separate) and a
 *                        set of whitelisted rules, stored as data
 *   campaigns            one email: subject, preview line, from-name, the kit
 *                        blocks it is built from, and where it is in its life
 *   campaign_recipients  ONE ROW PER ADDRESS PER CAMPAIGN, written when the
 *                        send starts. The row is claimed (status queued ->
 *                        sending, by an UPDATE that only one process can win)
 *                        BEFORE the message is handed to the mailer, which is
 *                        what makes a message impossible to send twice however
 *                        many requests run the heartbeat at once. It is also
 *                        the record of every send: when, and what the mail
 *                        server said.
 *   campaign_clicks      one row per click on a tracked link, for "Most
 *                        clicked". The link is an INDEX into the campaign's own
 *                        list of URLs, never a URL from the request.
 *   marketing_optouts    "Unsubscribe" from any campaign. Checked at the moment
 *                        of sending as well as when the list is built, so an
 *                        opt-out pressed while a campaign is half sent is
 *                        honoured for the other half.
 *
 * No PII in a URL: a recipient is reached from the pixel, the click and the
 * unsubscribe link through `token_hash`, the SHA-256 of a random token that is
 * only ever in the email itself.
 *
 * No ->after() anywhere -- these create tables (MigrationConventionTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaign_groups')) {
            Schema::create('campaign_groups', function (Blueprint $t) {
                $t->id();
                $t->string('name', 120);
                $t->string('audience', 20)->default('customers');   // customers | subscribers
                $t->string('match', 3)->default('all');              // all | any
                $t->text('rules');                                   // JSON, CampaignAudience::clean()
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('campaigns')) {
            Schema::create('campaigns', function (Blueprint $t) {
                $t->id();
                $t->string('name', 160);
                $t->string('subject', 200)->default('');
                $t->string('preheader', 255)->default('');
                $t->string('from_name', 120)->default('');
                $t->string('audience', 20)->default('customers');
                $t->unsignedBigInteger('group_id')->nullable()->index();
                $t->mediumText('blocks');                            // JSON, KitBlocks::clean()
                $t->string('status', 20)->default('draft')->index(); // draft | scheduled | sending | sent | cancelled
                $t->timestamp('scheduled_at')->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->text('links')->nullable();                       // JSON list of URLs fixed when the send starts
                $t->unsignedInteger('total_recipients')->default(0);
                $t->unsignedInteger('sent_count')->default(0);
                $t->unsignedInteger('failed_count')->default(0);
                $t->timestamp('test_sent_at')->nullable();
                $t->string('test_sent_to', 191)->nullable();
                $t->string('created_by', 191)->nullable();
                $t->string('sent_by', 191)->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('campaign_recipients')) {
            Schema::create('campaign_recipients', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('campaign_id');
                $t->string('email', 191);
                $t->string('first_name', 120)->nullable();
                $t->unsignedBigInteger('customer_id')->nullable();
                $t->unsignedBigInteger('subscriber_id')->nullable();
                $t->char('token_hash', 64)->unique();
                $t->string('status', 10)->default('queued');          // queued | sending | sent | failed | skipped
                $t->timestamp('claimed_at')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->string('error', 255)->nullable();
                $t->timestamp('opened_at')->nullable();
                $t->unsignedInteger('open_count')->default(0);
                $t->timestamp('clicked_at')->nullable();
                $t->unsignedInteger('click_count')->default(0);
                $t->timestamp('unsubscribed_at')->nullable();
                $t->timestamps();

                $t->unique(['campaign_id', 'email']);
                $t->index(['campaign_id', 'status']);
                $t->index('sent_at');
            });
        }

        if (! Schema::hasTable('campaign_clicks')) {
            Schema::create('campaign_clicks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('campaign_id');
                $t->unsignedBigInteger('recipient_id');
                $t->unsignedSmallInteger('link');
                $t->timestamp('created_at')->nullable();

                $t->index(['campaign_id', 'link']);
            });
        }

        if (! Schema::hasTable('marketing_optouts')) {
            Schema::create('marketing_optouts', function (Blueprint $t) {
                $t->id();
                $t->string('email', 191)->unique();
                $t->unsignedBigInteger('campaign_id')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_optouts');
        Schema::dropIfExists('campaign_clicks');
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('campaign_groups');
    }
};
