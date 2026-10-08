<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Store → Security → Firewall (Lane FW).
 *
 * firewall_rules  the owner's choices: scalar settings, country rules, good-bot
 *                 switches and the always-allow list. NOT the `settings` table:
 *                 every row there is unserialised on every shop page
 *                 (SettingsRequestMemo), and an allow list of a few hundred
 *                 ranges does not belong in that map. Nothing reads this table
 *                 on a request: it is compiled into the block-list file
 *                 (IpBlockList::rebuild()) on every write.
 *
 * firewall_log    the live view, AGGREGATED: one row per address, reason and
 *                 five-minute bucket with a hit count, written in batches from
 *                 cache counters by FirewallLog::flush(). Never one INSERT per
 *                 request.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('firewall_rules')) {
            Schema::create('firewall_rules', function (Blueprint $t) {
                $t->id();
                $t->string('kind', 16);
                $t->string('subject', 64);
                $t->text('value')->nullable();
                $t->string('note', 190)->nullable();
                $t->string('created_by', 120)->nullable();
                $t->timestamps();
                $t->unique(['kind', 'subject']);
            });
        }

        if (! Schema::hasTable('firewall_log')) {
            Schema::create('firewall_log', function (Blueprint $t) {
                $t->id();
                $t->timestamp('bucket_at');
                $t->string('reason', 16);
                $t->string('ip', 45)->nullable();
                $t->string('net', 49)->nullable();
                $t->string('country', 2)->nullable();
                $t->boolean('enforced')->default(false);
                $t->unsignedInteger('hits')->default(0);
                $t->index('bucket_at');
                $t->index(['reason', 'bucket_at']);
            });
        }

        try {
            \App\Services\Security\IpBlockList::rebuild();
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('firewall_log');
        Schema::dropIfExists('firewall_rules');
    }
};
