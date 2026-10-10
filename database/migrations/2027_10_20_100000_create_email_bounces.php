<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every bounce, delay and complaint the shop has heard about (Lane EB).
 *
 * The owner: "if email bounce back, i need a proper list building of bounced
 * email etc. and those emails will auto removed from the list and sit as
 * seperate list".
 *
 * email_suppressions is the LIST (one row per address, "never mail again");
 * this is the EVIDENCE behind it — one row per report, so "3 soft bounces in
 * 30 days" can be counted and the Bounced tab can say which code and which
 * campaign put an address there.
 *
 *   kind        hard | soft | delay | complaint
 *   source      dsn (read from the bounce mailbox) | smtp (refused at send
 *               time) | arf (a complaint report)
 *   report_id   the Message-ID of the bounce report itself. unique with the
 *               address, so reading the same report twice — a run that died
 *               after recording and before moving the message — writes one row.
 *   cleared_at  set by Restore: the soft count starts again from zero.
 *
 * No ->after() — this creates a table. See MigrationConventionTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_bounces')) {
            return;
        }

        Schema::create('email_bounces', function (Blueprint $t) {
            $t->id();
            $t->string('email', 191)->index();
            $t->string('kind', 10);
            $t->string('code', 12)->nullable();
            $t->string('detail', 255)->nullable();
            $t->unsignedBigInteger('campaign_id')->nullable();
            $t->unsignedBigInteger('send_id')->nullable();
            $t->string('source', 8);
            $t->string('report_id', 191)->nullable();
            $t->timestamp('cleared_at')->nullable();
            $t->timestamp('created_at')->nullable()->index();
            $t->unique(['report_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_bounces');
    }
};
