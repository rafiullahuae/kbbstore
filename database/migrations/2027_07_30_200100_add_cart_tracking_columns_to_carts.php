<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cart Tracking, part one: what the list shows lives ON the cart.   (Lane CT)
 *
 * Growth & Marketing → Cart Tracking. One row per cart in that list, sorted by
 * cart number, last update or value, filtered by period, bot, country and
 * purchased — so every column it sorts or filters on is a column of `carts`,
 * indexed, rather than an aggregate computed per page.
 *
 * WHY ON `carts` AND NOT A SIDE TABLE. CartService already saves the cart row
 * on every add, remove and quantity change (`last_activity_at`). The tracker
 * fills these columns on that same model before that same save, so the summary
 * costs NO extra query per event — the one new write per event is the event
 * row itself (cart_events). A side table would be a second UPDATE on every
 * add-to-cart, for ever.
 *
 * Every column is nullable or defaulted, so the 99,000 existing carts need no
 * backfill and the ALTER is metadata-only on MySQL 8. Idempotent: each column
 * and index is added only when it is missing.
 *
 *   ct_ip / ct_net      the shopper's address and its /24 (/64) at first add
 *   ct_country          ISO code from ShopperCountry (null when it only guessed
 *                       the store's own country), or the order's billing country
 *   ct_ua               user agent, truncated to 255
 *   ct_bot_flags/score  BotSignals' bitmask and total (0–100)
 *   ct_speed_ms         fastest "ms since page opened" the cart script reported
 *   ct_value            current lines total in fils
 *   ct_added/ct_removed how many add / remove events
 *   ct_first_at/last_at first and latest tracked event (the list's clock)
 *   ct_order_id         the order this cart became
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('carts')) {
            return;
        }

        $columns = [
            'ct_ip' => fn (Blueprint $t) => $t->string('ct_ip', 45)->nullable(),
            'ct_net' => fn (Blueprint $t) => $t->string('ct_net', 49)->nullable(),
            'ct_country' => fn (Blueprint $t) => $t->string('ct_country', 2)->nullable(),
            'ct_ua' => fn (Blueprint $t) => $t->string('ct_ua', 255)->nullable(),
            'ct_bot_flags' => fn (Blueprint $t) => $t->unsignedInteger('ct_bot_flags')->default(0),
            'ct_bot_score' => fn (Blueprint $t) => $t->unsignedTinyInteger('ct_bot_score')->default(0),
            'ct_speed_ms' => fn (Blueprint $t) => $t->unsignedInteger('ct_speed_ms')->nullable(),
            'ct_value' => fn (Blueprint $t) => $t->integer('ct_value')->default(0),
            'ct_added' => fn (Blueprint $t) => $t->unsignedInteger('ct_added')->default(0),
            'ct_removed' => fn (Blueprint $t) => $t->unsignedInteger('ct_removed')->default(0),
            'ct_first_at' => fn (Blueprint $t) => $t->timestamp('ct_first_at')->nullable(),
            'ct_last_at' => fn (Blueprint $t) => $t->timestamp('ct_last_at')->nullable(),
            'ct_order_id' => fn (Blueprint $t) => $t->unsignedBigInteger('ct_order_id')->nullable(),
        ];

        foreach ($columns as $name => $add) {
            if (! Schema::hasColumn('carts', $name)) {
                Schema::table('carts', function (Blueprint $t) use ($add) {
                    $add($t);
                });
            }
        }

        $indexes = [
            'carts_ct_last_at_index' => ['ct_last_at'],
            'carts_ct_value_index' => ['ct_value'],
            'carts_ct_order_id_index' => ['ct_order_id'],
            'carts_ct_ip_index' => ['ct_ip'],
            'carts_ct_net_first_index' => ['ct_net', 'ct_first_at'],
            'carts_ct_country_last_index' => ['ct_country', 'ct_last_at'],
            'carts_ct_bot_last_index' => ['ct_bot_score', 'ct_last_at'],
        ];

        $existing = $this->indexNames();

        foreach ($indexes as $name => $cols) {
            if (! in_array($name, $existing, true)) {
                Schema::table('carts', function (Blueprint $t) use ($name, $cols) {
                    $t->index($cols, $name);
                });
            }
        }
    }

    public function down(): void
    {
        // Additive and inert. Dropping these would discard tracking history
        // on a rollback of an unrelated package.
    }

    /** @return list<string> */
    private function indexNames(): array
    {
        try {
            return array_map(fn ($i) => (string) $i['name'], Schema::getIndexes('carts'));
        } catch (\Throwable) {
            return [];
        }
    }
};
