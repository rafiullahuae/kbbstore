<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Quiqup deliveries, P1 (docs/plans/quiqup/QUIQUP-PLAN.md §4.1).
 *
 * quiqup_config   one row per environment ('sandbox' | 'live'). The API key and
 *                 both webhook secrets live in `secrets` (encrypted:array, the
 *                 MailCredential precedent) and NEVER in `settings`, so the
 *                 shop's settings map, SettingsRequestMemo and /api/settings
 *                 are untouched by this module.
 * shipments       one row per courier booking. `request_uuid` is the
 *                 idempotency key sent to Quiqup; `active_key` (= order_id
 *                 while the booking is live, NULL otherwise) is UNIQUE, so the
 *                 database itself refuses a second live booking for an order.
 * shipment_events every state Quiqup told us about, keyed UNIQUE on the
 *                 documented dedupe triple (order, state, state_updated_at).
 *                 The raw webhook body is never stored, only its SHA-256.
 *
 * Guarded with hasTable so a package applied twice is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quiqup_config')) {
            Schema::create('quiqup_config', function (Blueprint $t) {
                $t->string('id', 16)->primary();
                $t->boolean('active')->default(false);
                $t->text('secrets')->nullable();
                $t->json('options')->nullable();
                $t->timestamp('last_webhook_at')->nullable();
                $t->string('last_webhook_state', 32)->nullable();
                $t->timestamp('last_poll_at')->nullable();
                $t->string('last_poll_error', 300)->nullable();
                // When this environment's key last passed Test connection;
                // reset when the key is replaced. One of the Go-Live checks.
                $t->timestamp('verified_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('shipments')) {
            Schema::create('shipments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('order_id')->index();
                $t->string('provider', 16)->default('quiqup');
                $t->string('env', 8);
                $t->unsignedSmallInteger('generation')->default(0);
                // Booked while Store -> Quiqup was in Internal testing (staging
                // host, test orders only). Never changes after the booking.
                $t->boolean('test')->default(false);
                $t->uuid('request_uuid')->unique();
                $t->unsignedBigInteger('active_key')->nullable()->unique();
                // Unique PER ENVIRONMENT: staging and production number independently.
                $t->unsignedBigInteger('qq_id')->nullable();
                $t->uuid('qq_uuid')->nullable()->index();
                $t->string('tracking_url', 255)->nullable();
                $t->string('service_kind', 24);
                $t->string('payment_mode', 20);
                $t->integer('cod_fils')->nullable();
                $t->unsignedTinyInteger('parcels')->default(1);
                $t->string('job', 12)->default('queued')->index();
                $t->string('state', 32)->nullable();
                $t->timestamp('state_at', 6)->nullable();
                $t->timestamp('collection_after')->nullable();
                $t->timestamp('collection_before')->nullable();
                $t->timestamp('delivery_after')->nullable();
                $t->timestamp('delivery_before')->nullable();
                $t->timestamp('delivered_at')->nullable();
                $t->unsignedTinyInteger('attempts')->default(0);
                $t->string('last_reason', 300)->nullable();
                $t->string('last_error', 500)->nullable();
                $t->timestamp('last_error_at')->nullable();
                $t->unsignedTinyInteger('tries')->default(0);
                $t->timestamp('claimed_at')->nullable();
                // The next time the minute cron should touch this row: the next
                // retry of a queued create, or the next poll of a booked one.
                $t->timestamp('next_poll_at')->nullable()->index();
                // The exact create body (encrypted), kept only until Quiqup has
                // the order -- a retry must resend it with the same uuid -- and
                // then nulled.
                $t->text('draft')->nullable();
                $t->string('created_by', 100)->nullable();
                $t->timestamps();
                $t->index(['state', 'next_poll_at']);
                $t->unique(['env', 'qq_id'], 'shipments_env_qq_id_unique');
            });
        }

        if (! Schema::hasTable('shipment_events')) {
            Schema::create('shipment_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('shipment_id')->index();
                $t->string('state', 32);
                $t->timestamp('state_at', 6)->nullable();
                $t->string('source', 8);
                $t->unsignedTinyInteger('attempts')->nullable();
                $t->string('reason', 300)->nullable();
                $t->char('body_sha256', 64)->nullable();
                // Non-personal facts the apply needs again if the cron re-runs
                // it: the delivery/collection windows and delivered_at (UTC).
                $t->text('facts')->nullable();
                $t->timestamp('received_at')->nullable();
                $t->timestamp('applied_at')->nullable()->index();
                $t->unique(['shipment_id', 'state', 'state_at'], 'shipment_events_dedupe');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('quiqup_config');
    }
};
