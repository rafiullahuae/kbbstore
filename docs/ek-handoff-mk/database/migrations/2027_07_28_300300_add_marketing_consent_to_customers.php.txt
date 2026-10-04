<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lane EK — WHETHER A CUSTOMER HAS SAID YES TO MARKETING EMAIL.
 *
 * The shop had no such fact. `customers` carries `whatsapp_optin` and nothing
 * for email; the WooCommerce import brought no marketing consent across; the
 * only marketing consent anywhere is the newsletter's double opt-in
 * (`subscribers.confirmed_at`). Buying something is not consent to campaigns.
 *
 * So the column starts EMPTY for every customer, and Email Marketing treats a
 * customer as emailable only when this is set OR their address is a confirmed
 * newsletter subscriber. Nothing in this package sets it: capturing the
 * consent (a tick box at checkout or on My account) is a storefront change the
 * owner has not asked for yet, and is reported rather than slipped in.
 *
 * Nullable timestamps, no ->after() (MigrationConventionTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        if (! Schema::hasColumn('customers', 'marketing_consent_at')) {
            Schema::table('customers', function (Blueprint $t) {
                $t->timestamp('marketing_consent_at')->nullable();
            });
        }

        if (! Schema::hasColumn('customers', 'marketing_consent_source')) {
            Schema::table('customers', function (Blueprint $t) {
                $t->string('marketing_consent_source', 40)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        foreach (['marketing_consent_source', 'marketing_consent_at'] as $column) {
            if (Schema::hasColumn('customers', $column)) {
                Schema::table('customers', function (Blueprint $t) use ($column) {
                    $t->dropColumn($column);
                });
            }
        }
    }
};
