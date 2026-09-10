<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Newsletter signups.
 *
 * These were being written to the settings row `newsletter_last` with
 * updateOrInsert, so each signup overwrote the one before it and the store only
 * ever held one address. Everything collected before this migration is lost
 * except that final address, which is carried across below so the one surviving
 * signup is not thrown away too.
 *
 * `email` is unique so a repeat signup updates rather than duplicates.
 * `confirmed_at` is unused today and exists so double opt-in can be added later
 * without a second migration against a table that by then has rows in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscribers')) {
            Schema::create('subscribers', function (Blueprint $t) {
                $t->id();
                $t->string('email', 160)->unique();
                $t->string('source', 40)->default('homepage');
                $t->string('status', 20)->default('subscribed');
                $t->timestamp('confirmed_at')->nullable();
                $t->timestamps();

                $t->index('created_at');
            });
        }

        $this->rescueLastSignup();
    }

    /**
     * Carry the surviving address over from the settings row it was kept in.
     */
    private function rescueLastSignup(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $row = DB::table('settings')->where('key', 'newsletter_last')->first();
        $email = is_object($row) ? trim((string) ($row->value ?? '')) : '';

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        DB::table('subscribers')->updateOrInsert(
            ['email' => mb_strtolower($email)],
            ['source' => 'rescued', 'status' => 'subscribed', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('subscribers');
    }
};
