<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The shop app's Web Push subscriptions, and the out-of-stock products each
 * subscribed phone looked at (Lane NT, App -> Site App).
 *
 * Written by the installed shop app; NOTHING SENDS TO THESE YET. A later lane
 * (PN) builds the sending side on this shape: order updates, campaigns by
 * region or city, and "back in stock" only to a phone that visited the
 * product while it was out. WebPush::send() takes these rows as they are
 * (id, endpoint, p256dh, auth).
 *
 * site_app_push_subscriptions, one row per browser subscription:
 *   endpoint_hash   SHA-256 of the endpoint; a re-sent subscription updates.
 *   cookie_hash     SHA-256 of the random, HttpOnly `kbb_push` cookie the
 *                   subscribe endpoint sets on that phone (no PII in it), so an
 *                   order placed from the phone can find its row.
 *   customer_id     the shopper signed in on that phone: from the SESSION or
 *                   from an order placed on it, never from a request body.
 *                   No foreign key: PN joins on it, and deleting a customer
 *                   must not cascade through a table nobody reads.
 *   locale          the shop language the app was in (enabled list only).
 *   country/region/city, location_source ('order' | 'ip-header' | null),
 *   location_at     where the phone is, WITHOUT asking the shopper anything:
 *                   the latest order's shipping address (region = the emirate
 *                   the checkout stores in `state`), else the proxy's geo
 *                   headers when the host sends them, else nothing.
 *   platform        'ios' | 'android' | 'desktop' | 'other', coarse; the full
 *                   user agent is never stored.
 *   status          'active', or 'gone' once the push service says so (PN).
 *   last_seen_at    the app's last daily check-in.
 *
 * site_app_push_interests: (subscription, product) once each, viewed_at
 * refreshed on a later visit, notified_at for PN's "only once".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_app_push_subscriptions')) {
            Schema::create('site_app_push_subscriptions', function (Blueprint $t) {
                $t->id();
                $t->text('endpoint');
                $t->char('endpoint_hash', 64)->unique();
                $t->string('p256dh', 120);
                $t->string('auth', 40);
                $t->char('cookie_hash', 64)->nullable()->unique();
                $t->unsignedBigInteger('customer_id')->nullable()->index();
                $t->string('locale', 8)->default('en');
                $t->char('country', 2)->nullable();
                $t->string('region', 60)->nullable()->index();
                $t->string('city', 80)->nullable();
                $t->string('location_source', 12)->nullable();
                $t->timestamp('location_at')->nullable();
                $t->string('platform', 10)->nullable();
                $t->string('status', 8)->default('active')->index();
                $t->unsignedSmallInteger('fail_count')->default(0);
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('site_app_push_interests')) {
            Schema::create('site_app_push_interests', function (Blueprint $t) {
                $t->id();
                $t->foreignId('subscription_id')->constrained('site_app_push_subscriptions')->cascadeOnDelete();
                $t->unsignedBigInteger('product_id')->index();
                $t->timestamp('viewed_at');
                $t->timestamp('notified_at')->nullable();
                $t->unique(['subscription_id', 'product_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_app_push_interests');
        Schema::dropIfExists('site_app_push_subscriptions');
    }
};
