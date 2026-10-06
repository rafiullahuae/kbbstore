<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Growth & Marketing → Push Notifications (Lane PN): the sending side of the
 * shop app's Web Push, built on Lane NT's site_app_push_subscriptions.
 *
 * push_campaigns    one row per campaign the owner writes. `cursor` is the
 *                   last subscription id the audience walk reached, so a
 *                   campaign resumes where it stopped; `lock_until` /
 *                   `lock_token` are a compare-and-swap lease, so two ticks
 *                   (or a tick and the owner's tab) never step one campaign at
 *                   once. The totals are recounted from push_sends, never
 *                   incremented, so they cannot drift.
 *
 * push_sends        one row per message to one phone, campaigns AND the four
 *                   automations. `dedupe` is UNIQUE and is the whole "once"
 *                   guarantee: c:{campaign}:{sub}, o:{order}:{status}:{sub},
 *                   s:{product}:{sub} (back in stock: once EVER), a:{cart}:{sub},
 *                   p:{product}:{sub}:{price}. A second writer's INSERT is
 *                   ignored by the database, not by a read-then-write.
 *                   `emirate` is the phone's emirate at send time, for the
 *                   per-campaign report. title/body/url are what was sent, in
 *                   that phone's language.
 *
 * site_app_push_orders   which phone placed which order (guests have no
 *                   customer_id to join on): order updates reach the device
 *                   that placed the order.
 *
 * site_app_push_wishes   products a subscribed phone hearted. The wishlist
 *                   itself is a cookie (WishlistController), so the server
 *                   only learns of a heart as it is pressed; price-drop reads
 *                   this.
 *
 * push_prices       the price each watched product was last seen at (the
 *                   highest since its last price-drop message), in fils.
 *
 * push_daily        per-day counters the subscription table cannot answer
 *                   once a row is gone: opt-outs and endpoints the push
 *                   service retired.
 *
 * push_geo_ranges   the optional offline IP → emirate table: UAE rows only of
 *                   DB-IP's free "IP to City Lite" (CC BY 4.0). ip_from/ip_to
 *                   are 32 lower-case hex digits of the 128-bit address (IPv4
 *                   as ::ffff:a.b.c.d), so plain string order IS numeric order
 *                   on both SQLite and MySQL and one indexed range lookup
 *                   answers an address.
 *
 * site_app_push_subscriptions.cart_id   the cart this phone started, linked
 *                   when the cart is created on a request carrying the phone's
 *                   kbb_push cookie: the abandoned-cart reminder's device.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('push_campaigns')) {
            Schema::create('push_campaigns', function (Blueprint $t) {
                $t->id();
                $t->string('title', 80);
                $t->string('body', 200)->default('');
                $t->string('url', 300)->nullable();
                $t->string('link_label', 160)->nullable();
                $t->text('audience')->nullable();
                $t->string('status', 12)->default('draft')->index();
                $t->timestamp('scheduled_at')->nullable()->index();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->unsignedBigInteger('cursor')->default(0);
                $t->timestamp('lock_until')->nullable();
                $t->string('lock_token', 32)->nullable();
                $t->unsignedInteger('targeted')->default(0);
                $t->unsignedInteger('delivered')->default(0);
                $t->unsignedInteger('failed')->default(0);
                $t->unsignedInteger('gone')->default(0);
                $t->unsignedInteger('held')->default(0);
                $t->unsignedInteger('clicks')->default(0);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('push_sends')) {
            Schema::create('push_sends', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('campaign_id')->nullable();
                $t->string('kind', 8);
                $t->unsignedBigInteger('ref')->nullable();
                $t->unsignedBigInteger('subscription_id');
                $t->string('emirate', 16)->nullable();
                $t->string('title', 80);
                $t->string('body', 200)->default('');
                $t->string('url', 300)->nullable();
                $t->string('status', 12)->default('queued');
                $t->string('claim', 32)->nullable();
                $t->string('dedupe', 64)->unique();
                $t->timestamp('due_at')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->timestamp('clicked_at')->nullable();
                $t->timestamps();
                $t->index(['campaign_id', 'status']);
                $t->index(['status', 'due_at']);
                $t->index(['subscription_id', 'sent_at']);
                $t->index(['kind', 'ref']);
            });
        }

        if (! Schema::hasTable('site_app_push_orders')) {
            Schema::create('site_app_push_orders', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('order_id')->index();
                $t->foreignId('subscription_id')->constrained('site_app_push_subscriptions')->cascadeOnDelete();
                $t->timestamp('created_at')->nullable();
                $t->unique(['order_id', 'subscription_id']);
            });
        }

        if (! Schema::hasTable('site_app_push_wishes')) {
            Schema::create('site_app_push_wishes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('subscription_id')->constrained('site_app_push_subscriptions')->cascadeOnDelete();
                $t->unsignedBigInteger('product_id')->index();
                $t->timestamp('wished_at');
                $t->unique(['subscription_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('push_prices')) {
            Schema::create('push_prices', function (Blueprint $t) {
                $t->unsignedBigInteger('product_id')->primary();
                $t->integer('price');
                $t->timestamp('seen_at')->nullable();
            });
        }

        if (! Schema::hasTable('push_daily')) {
            Schema::create('push_daily', function (Blueprint $t) {
                $t->date('day');
                $t->string('kind', 10);
                $t->unsignedInteger('n')->default(0);
                $t->primary(['day', 'kind']);
            });
        }

        if (! Schema::hasTable('push_geo_ranges')) {
            Schema::create('push_geo_ranges', function (Blueprint $t) {
                $t->id();
                $t->char('ip_from', 32)->index();
                $t->char('ip_to', 32);
                $t->string('emirate', 16)->nullable();
                $t->string('city', 80)->nullable();
            });
        }

        if (Schema::hasTable('site_app_push_subscriptions') && ! Schema::hasColumn('site_app_push_subscriptions', 'cart_id')) {
            Schema::table('site_app_push_subscriptions', function (Blueprint $t) {
                $t->unsignedBigInteger('cart_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('site_app_push_subscriptions') && Schema::hasColumn('site_app_push_subscriptions', 'cart_id')) {
            Schema::table('site_app_push_subscriptions', function (Blueprint $t) {
                $t->dropIndex(['cart_id']);
                $t->dropColumn('cart_id');
            });
        }
        foreach (['push_geo_ranges', 'push_daily', 'push_prices', 'site_app_push_wishes', 'site_app_push_orders', 'push_sends', 'push_campaigns'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
