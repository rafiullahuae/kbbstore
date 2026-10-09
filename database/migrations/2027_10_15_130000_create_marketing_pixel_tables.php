<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing Pixels: server events, their browser context, and custom code
 * history. (Lane MP)
 *
 *   marketing_server_events   one row per server-side event sent (or refused)
 *                             to Meta, Google or TikTok. The "Last events"
 *                             panel reads it. NO payload, NO customer data:
 *                             the platform, the event name, the shared
 *                             event_id, the outcome and the platform's own
 *                             error text. The unique key is what makes a
 *                             Purchase go out once per order and platform.
 *   marketing_event_contexts  the shopper's browser context (IP, user agent,
 *                             the platforms' click cookies) captured when the
 *                             checkout creates the order, ENCRYPTED, so a
 *                             Purchase confirmed later by a payment webhook
 *                             still carries the shopper's signals rather than
 *                             the gateway's. Deleted when the event is sent,
 *                             and anything older than 7 days is pruned.
 *   marketing_custom_code     every saved version of the owner's custom
 *                             head / body / footer code, with who saved it.
 *                             The LIVE copy is the `pixels_custom_code`
 *                             setting (read from the settings map the page
 *                             already loads, so it costs no query); this table
 *                             is the history and the one-click restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_server_events')) {
            Schema::create('marketing_server_events', function (Blueprint $t) {
                $t->id();
                $t->string('platform', 12);
                $t->string('event', 32);
                $t->string('event_id', 80);
                $t->string('status', 12);
                $t->unsignedSmallInteger('http_status')->nullable();
                $t->string('message', 300)->nullable();
                $t->timestamp('created_at')->nullable();
                $t->unique(['platform', 'event', 'event_id'], 'mse_dedup');
            });
        }

        if (! Schema::hasTable('marketing_event_contexts')) {
            Schema::create('marketing_event_contexts', function (Blueprint $t) {
                $t->unsignedBigInteger('order_id')->primary();
                $t->text('payload');
                $t->timestamp('created_at')->nullable()->index();
            });
        }

        if (! Schema::hasTable('marketing_custom_code')) {
            Schema::create('marketing_custom_code', function (Blueprint $t) {
                $t->id();
                $t->text('snapshot');
                $t->string('saved_by', 191)->nullable();
                $t->string('note', 120)->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_custom_code');
        Schema::dropIfExists('marketing_event_contexts');
        Schema::dropIfExists('marketing_server_events');
    }
};
