<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Growth & Marketing -> Ads Autopilot (Lane AP-API).
 *
 *   autopilot_tokens        connection keys for the owner's KBB Ads Autopilot
 *                           Mac app. Only the SHA-256 of a key is stored; the
 *                           key itself is shown once, when it is created.
 *   autopilot_api_log       the last few hundred calls (key, path, HTTP
 *                           status, rows) -- no query values, no answer body.
 *   marketing_capi_retries  a Meta Conversions API event that failed for a
 *                           reason worth retrying (no answer, 429, 5xx, a
 *                           refused token), kept ENCRYPTED until the scheduler
 *                           re-sends it or 47 hours pass (Meta merges a
 *                           browser and server copy only within 48 h).
 *   products.cost_price     optional, fils. What the shop pays for a product,
 *                           read only by the Autopilot API for margins; no
 *                           page of the shop reads it.
 *
 * Idempotent, no ->after(): see 2026_09_17_000000 for why.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('autopilot_tokens')) {
            Schema::create('autopilot_tokens', function (Blueprint $t) {
                $t->id();
                $t->string('name', 80);
                $t->char('token_hash', 64)->unique();
                $t->string('hint', 16);
                $t->string('scopes', 120);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamp('last_used_at')->nullable();
                $t->timestamp('revoked_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('autopilot_api_log')) {
            Schema::create('autopilot_api_log', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('token_id')->nullable()->index();
                $t->string('path', 80);
                $t->unsignedSmallInteger('status');
                $t->unsignedInteger('rows')->default(0);
                $t->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('marketing_capi_retries')) {
            Schema::create('marketing_capi_retries', function (Blueprint $t) {
                $t->id();
                $t->string('event', 32);
                $t->string('event_id', 80);
                $t->text('payload');
                $t->unsignedTinyInteger('attempts')->default(0);
                $t->timestamp('next_at')->index();
                $t->timestamp('first_at');
                $t->string('last_error', 300)->nullable();
                $t->unique(['event', 'event_id'], 'mcr_event');
            });
        }

        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'cost_price')) {
            Schema::table('products', function (Blueprint $t) {
                $t->integer('cost_price')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('autopilot_tokens');
        Schema::dropIfExists('autopilot_api_log');
        Schema::dropIfExists('marketing_capi_retries');

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'cost_price')) {
            Schema::table('products', function (Blueprint $t) {
                $t->dropColumn('cost_price');
            });
        }
    }
};
