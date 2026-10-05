<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The owner app (Lane MAC): a PIN-unlocked phone app for the owner and the
 * staff he names, at a secret address. Five tables, none of them read by the
 * storefront.
 *
 *   owner_app_members              who may use the app: one row per admin
 *                                  account given access, with the PIN's HASH
 *                                  (Hash::make) and the member's lockout state.
 *   owner_app_devices              a phone or tablet that has been enrolled.
 *                                  The device token and the session token are
 *                                  stored as SHA-256 hashes; the tokens
 *                                  themselves exist only in HttpOnly cookies.
 *   owner_app_logins               every unlock and enrol attempt, good or bad.
 *   owner_app_push_subscriptions   one Web Push subscription per device.
 *   owner_app_events               what happened in the shop, for the in-app
 *                                  list, the live "changes since" cursor and
 *                                  the push notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('owner_app_members')) {
            Schema::create('owner_app_members', function (Blueprint $t) {
                $t->id();
                $t->foreignId('admin_user_id')->unique()->constrained('admin_users')->cascadeOnDelete();
                $t->boolean('enabled')->default(false);
                $t->string('pin_hash')->nullable();
                $t->timestamp('pin_set_at')->nullable();
                $t->unsignedSmallInteger('failed_count')->default(0);
                $t->timestamp('locked_until')->nullable();
                $t->json('notify')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('owner_app_devices')) {
            Schema::create('owner_app_devices', function (Blueprint $t) {
                $t->id();
                $t->foreignId('member_id')->constrained('owner_app_members')->cascadeOnDelete();
                $t->char('token_hash', 64)->unique();
                $t->string('name', 80);
                $t->string('user_agent', 255)->nullable();
                $t->string('ip', 45)->nullable();
                $t->unsignedSmallInteger('failed_count')->default(0);
                $t->char('session_hash', 64)->nullable()->index();
                $t->timestamp('session_seen_at')->nullable();
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamp('revoked_at')->nullable();
                $t->string('revoked_reason', 40)->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('owner_app_logins')) {
            Schema::create('owner_app_logins', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('member_id')->nullable()->index();
                $t->unsignedBigInteger('device_id')->nullable();
                $t->string('kind', 16);
                $t->boolean('success')->default(false);
                $t->string('reason', 24);
                $t->string('ip', 45)->nullable();
                $t->string('user_agent', 255)->nullable();
                $t->timestamp('created_at')->nullable()->index();
            });
        }

        if (! Schema::hasTable('owner_app_push_subscriptions')) {
            Schema::create('owner_app_push_subscriptions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('device_id')->unique()->constrained('owner_app_devices')->cascadeOnDelete();
                $t->text('endpoint');
                $t->char('endpoint_hash', 64)->unique();
                $t->string('p256dh', 120);
                $t->string('auth', 40);
                $t->unsignedSmallInteger('fail_count')->default(0);
                $t->timestamp('last_sent_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('owner_app_events')) {
            Schema::create('owner_app_events', function (Blueprint $t) {
                $t->id();
                $t->string('type', 24);
                $t->unsignedBigInteger('ref_id')->nullable();
                $t->string('title', 120);
                $t->string('body', 255)->nullable();
                $t->timestamp('created_at')->nullable()->index();
                $t->index(['type', 'ref_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_app_events');
        Schema::dropIfExists('owner_app_push_subscriptions');
        Schema::dropIfExists('owner_app_logins');
        Schema::dropIfExists('owner_app_devices');
        Schema::dropIfExists('owner_app_members');
    }
};
