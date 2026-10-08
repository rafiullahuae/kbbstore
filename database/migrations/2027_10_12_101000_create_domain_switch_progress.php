<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Platform → Domain switch, as a numbered installer (Lane DW2).
 *
 * domain_switch_progress — one row per installer step (SwitchInstaller::STEPS),
 * keyed by the step's stable name, never its number:
 *
 *   status       done | skipped | null (to do)
 *   level        green | amber | red -- the last Verify's answer
 *   message/fix  that answer in plain words
 *   data         JSON the step's screen draws from (e.g. the server address
 *                the DNS check learned)
 *   verified_at  when Verify last ran
 *
 * A table of its own and not a settings row: Setting::map() is read on every
 * shop page, and the installer's progress has no business in it. Nothing on
 * the shop reads this table. Guarded by hasTable: applying the package twice
 * is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('domain_switch_progress')) {
            return;
        }

        Schema::create('domain_switch_progress', function (Blueprint $t) {
            $t->string('step', 32)->primary();
            $t->string('status', 16)->nullable();
            $t->string('level', 8)->nullable();
            $t->text('message')->nullable();
            $t->text('fix')->nullable();
            $t->text('data')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_switch_progress');
    }
};
