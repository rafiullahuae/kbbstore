<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Push Notifications → Devices (Lane PD). Two columns on the shop app's
 * subscriptions so the owner can find his own phone again:
 *
 *   nickname       what the owner calls the phone ("Rafi's iPhone"), plain
 *                  text, at most 40 characters, set from the admin only.
 *   admin_user_id  the admin who marked this phone "mine": "Send a test to my
 *                  phone" in the campaign editor then reaches it even when
 *                  nobody is signed in to the shop on it. No foreign key, as
 *                  customer_id has none: deleting an admin must not cascade
 *                  through a table the shop app writes.
 *
 * Both nullable, so applying this moves nothing on the shop.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_app_push_subscriptions')) {
            return;
        }
        if (! Schema::hasColumn('site_app_push_subscriptions', 'nickname')) {
            Schema::table('site_app_push_subscriptions', function (Blueprint $t) {
                $t->string('nickname', 40)->nullable();
            });
        }
        if (! Schema::hasColumn('site_app_push_subscriptions', 'admin_user_id')) {
            Schema::table('site_app_push_subscriptions', function (Blueprint $t) {
                $t->unsignedBigInteger('admin_user_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['admin_user_id', 'nickname'] as $col) {
            if (Schema::hasColumn('site_app_push_subscriptions', $col)) {
                Schema::table('site_app_push_subscriptions', function (Blueprint $t) use ($col) {
                    if ($col === 'admin_user_id') {
                        $t->dropIndex(['admin_user_id']);
                    }
                    $t->dropColumn($col);
                });
            }
        }
    }
};
