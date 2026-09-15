<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the SMTP password lives.
 *
 * Deliberately NOT the `settings` table, for the same two reasons
 * GatewayCredentials gives for keeping Stripe keys out of it:
 *
 *   - `settings` is plain text in the database and in every backup of it.
 *     `mail_credentials.config` is cast `encrypted:array` by the model, so the
 *     column is ciphertext at rest.
 *   - `GET /api/settings` is public, and `GET /admin-api/settings` returns
 *     `Setting::map()` wholesale -- the ENTIRE table, with no allowlist at all.
 *     A password stored there would be shipped to the admin bundle on every
 *     load of the settings screen whether or not any mail screen asked for it.
 *     A value that is not in `settings` cannot leak from `settings`, however
 *     either of those controllers is rewritten later.
 *
 * Everything about mail that is NOT a credential -- host, port, encryption,
 * username, from address, and the last test-send record -- stays in `settings`
 * via SettingsService, which is where this project keeps operator-set values.
 *
 * Shaped like `payment_providers` on purpose: a string primary key plus one
 * encrypted json blob, so a second mailer (or a future API-token transport)
 * needs a row, not a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mail_credentials')) {
            return;
        }

        Schema::create('mail_credentials', function (Blueprint $t) {
            $t->string('id')->primary();        // 'smtp'
            $t->json('config')->nullable();     // encrypted in the app layer
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_credentials');
    }
};
