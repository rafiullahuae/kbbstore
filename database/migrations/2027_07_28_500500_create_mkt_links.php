<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ONLY addresses a click can redirect to (Lane MK, plan §2.2 and §5).
 *
 * Written once per campaign when its send starts: every href the email
 * carries, numbered n = 1, 2, 3 … /email/c/{token}/{n} looks up row n of the
 * campaign that token belongs to and redirects there and nowhere else, so a
 * forged or edited click URL can never be an open redirect.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mkt_links')) {
            return;
        }

        Schema::create('mkt_links', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('campaign_id');
            $t->unsignedSmallInteger('n');
            $t->string('url', 500);
            $t->string('label', 160)->nullable();

            $t->unique(['campaign_id', 'n']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mkt_links');
    }
};
