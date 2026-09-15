<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront password resets get their own token table.
 *
 * `password_reset_tokens` already exists — Laravel's stock users migration
 * created it — and config/auth.php's `customers` broker pointed at it. That is
 * wrong here in a way that only shows up once both tables have rows.
 *
 * The table is keyed by EMAIL ALONE (`email` is the primary key), not by email
 * plus a provider. This install has two providers whose rows can carry the same
 * address: `users` and `customers`. The owner is a customer of their own shop —
 * the demo seed alone gives them an account — so the collision is not
 * hypothetical. Sharing one table means a customer asking for a reset silently
 * overwrites the pending admin token, and worse, a token minted for the
 * `users` broker verifies against the `customers` broker, because the broker
 * looks the row up by address and nothing on it records which provider it was
 * for. Separate tables make that class of confusion unrepresentable.
 *
 * `customers.email_verified_at` is NOT added here: the column has existed since
 * the baseline schema (0001_01_01_000000_create_kbb_schema). The guard below
 * exists only so this migration is correct on a database built some other way,
 * and is a no-op on every real one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_password_reset_tokens')) {
            Schema::create('customer_password_reset_tokens', function (Blueprint $t) {
                // Laravel's DatabaseTokenRepository upserts on this column, so
                // one address can only ever hold one live token: requesting a
                // second link retires the first.
                $t->string('email')->primary();

                // The HASH of the token, never the token. DatabaseTokenRepository
                // writes Hash::make($token) and checks with Hash::check(), so a
                // stolen database dump does not yield usable reset links.
                $t->string('token');

                $t->timestamp('created_at')->nullable();
            });
        }

        if (Schema::hasTable('customers') && ! Schema::hasColumn('customers', 'email_verified_at')) {
            Schema::table('customers', function (Blueprint $t) {
                $t->timestamp('email_verified_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_password_reset_tokens');
    }
};
