<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Face ID / fingerprint unlock for the owner app (Lane BIO), alongside the PIN.
 *
 *   owner_app_passkeys     one WebAuthn credential per enrolled device: its id
 *                          (base64url), public key (PEM), algorithm (-7 / -257)
 *                          and signature counter. Nothing secret: the private
 *                          key never leaves the phone. Goes with the device.
 *   owner_app_devices      + passkey_challenge (SHA-256 hex of the one live
 *                          challenge), passkey_challenge_kind ('reg' / 'get'),
 *                          passkey_challenge_until (two minutes). Spent by one
 *                          conditional UPDATE, so a challenge answers once.
 *
 * Literal only: no application class is loaded here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('owner_app_passkeys')) {
            Schema::create('owner_app_passkeys', function (Blueprint $t) {
                $t->id();
                $t->foreignId('device_id')->constrained('owner_app_devices')->cascadeOnDelete();
                $t->string('credential_id', 400);
                $t->text('public_key');
                $t->smallInteger('alg');
                $t->unsignedBigInteger('sign_count')->default(0);
                $t->timestamp('last_used_at')->nullable();
                $t->timestamps();
            });
        }

        foreach ([
            'passkey_challenge' => fn (Blueprint $t) => $t->char('passkey_challenge', 64)->nullable(),
            'passkey_challenge_kind' => fn (Blueprint $t) => $t->string('passkey_challenge_kind', 8)->nullable(),
            'passkey_challenge_until' => fn (Blueprint $t) => $t->timestamp('passkey_challenge_until')->nullable(),
        ] as $column => $add) {
            if (! Schema::hasColumn('owner_app_devices', $column)) {
                Schema::table('owner_app_devices', $add);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_app_passkeys');

        foreach (['passkey_challenge', 'passkey_challenge_kind', 'passkey_challenge_until'] as $column) {
            if (Schema::hasColumn('owner_app_devices', $column)) {
                Schema::table('owner_app_devices', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
