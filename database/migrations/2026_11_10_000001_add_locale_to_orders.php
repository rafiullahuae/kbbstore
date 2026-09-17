<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language the customer was shopping in when they placed the order.
 *
 * ── WHY THIS IS NOT OPTIONAL ────────────────────────────────────────────────
 *
 * Every email after checkout is sent later, from a queue or from an admin
 * pressing a button, in a process that has no memory of the request. Without a
 * column, the locale at that moment is whatever the process happens to be set
 * to — English. So an Arabic shopper gets an Arabic checkout and then an
 * English confirmation, an English invoice PDF, and an English "your order has
 * shipped" two weeks later, sent by an admin clicking a status change. Each of
 * those is the shop appearing to forget who it was speaking to, at exactly the
 * points where a customer is deciding whether to trust it.
 *
 * It is on the ORDER and not only on the customer for two reasons. A guest
 * checkout has no customer row. And a customer who switched language for one
 * order should get that order's paperwork in that language, not in whatever
 * they chose most recently — an order is a document with a fixed language, the
 * way it has a fixed currency.
 *
 * ── WHAT THE ADMIN SEES ─────────────────────────────────────────────────────
 *
 * A language badge beside the order number on the order list and the order
 * detail screen, reading "EN" or "AR". It is not editable from the list: the
 * order's language is a fact about what happened, not a setting. The order
 * detail screen gets a control to change it, because the one real case for
 * changing it is a phone order taken in Arabic and keyed in by an
 * English-speaking admin, and re-sending the confirmation in the right language
 * should not need a developer.
 *
 * ── DEFAULT 'en', NOT NULL ──────────────────────────────────────────────────
 *
 * Every order that already exists was placed in English, because English is all
 * this shop has ever spoken. A nullable column would make every reader decide
 * what null means, and one of them would decide wrong. `2` is enough for every
 * code in Locale::LOCALES; 5 would be needed only for a regional variant this
 * shop has no plan for, and a narrow column is a narrower thing to get wrong.
 *
 * No ->after(): the column goes wherever MySQL puts it. Column order is
 * cosmetic and MigrationConventionTest records what insisting on it cost here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || Schema::hasColumn('orders', 'locale')) {
            return;
        }

        Schema::table('orders', function (Blueprint $t) {
            $t->string('locale', 5)->default('en');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'locale')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->dropColumn('locale');
            });
        }
    }
};
