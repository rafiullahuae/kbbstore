<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses marketing must never mail again (Lane MK, plan §2.2).
 *
 *   reason   unsubscribe | bounce | complaint | manual
 *   source   where it came from, e.g. "campaign:12" — never the IP.
 *
 * `email` is unique and lower-cased by the only writers, so an unsubscribe is
 * an idempotent insert-or-ignore: pressing twice, or a mail provider's
 * one-click POST arriving after the page's button, writes one row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_suppressions')) {
            return;
        }

        Schema::create('email_suppressions', function (Blueprint $t) {
            $t->id();
            $t->string('email', 191)->unique();
            $t->string('reason', 12)->default('unsubscribe');
            $t->string('source', 40)->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};
