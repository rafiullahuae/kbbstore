<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciliation — checking the provider's books against ours.
 *
 * WHAT WAS MISSING. Every gateway in this build can take money, capture it,
 * refund it and be told about it by webhook. Not one of them could be ASKED
 * what it holds. A payment taken at Stripe whose webhook never arrived — a
 * dropped delivery, a signing secret rotated a day early, an endpoint the
 * owner pasted with a typo — left the customer charged and this database
 * unaware, and the only way to find it was to open the provider's dashboard
 * and read it against the orders list by hand. Nothing shipped, and nobody
 * knew.
 *
 * FOUR TABLES, AND THE REASON EACH IS SEPARATE.
 *
 * `reconciliation_runs` — one row per (window, providers). The key is
 *   DERIVED from those two, not random, so pressing Run twice over the same
 *   window continues the same run rather than starting a second one beside it.
 *   That is what makes the whole thing idempotent from the owner's side: the
 *   second press reports what the first found, not a duplicate of it.
 *
 * `reconciliation_checkpoints` — one row per (run, provider, phase), holding
 *   the provider's own opaque cursor. Modelled on `import_checkpoints` and on
 *   the same rule: the cursor is advanced INSIDE the transaction that writes
 *   the findings it describes, so a cursor can never point past work that was
 *   rolled back. A run interrupted by the host's request timeout resumes at
 *   the page it was on.
 *
 * `reconciliation_sightings` — every remote transaction the run has seen, by
 *   the provider's own id. This exists because the second question ("we think
 *   we have money the provider does not") cannot be answered until the whole
 *   of the provider's side has been read, and on this host the whole of the
 *   provider's side is read across a dozen separate HTTP requests from a
 *   browser. There is nowhere to hold it in memory between them. Unique on
 *   (run, provider, kind, remote_key) so a page re-fetched after an
 *   interruption records nothing twice.
 *
 * `reconciliation_findings` — what the run concluded. Unique on
 *   (run_id, fingerprint), which is the write-side half of idempotency: a
 *   re-fetched page produces the same fingerprints and inserts nothing.
 *
 * WHAT IS NOT IN ANY OF THEM: a buyer. No name, no email, no phone, no
 * address. These tables are read on a screen and dumped in every backup, and
 * everything an owner needs to find a transaction — the order number, the
 * provider's reference, the amount — is already impersonal. The same rule
 * `payment_events.payload` follows.
 *
 * NOTHING HERE IS WRITTEN TO BY THE RECONCILER EXCEPT THESE FOUR TABLES. No
 * order is marked paid, no payment row is created, no refund is recorded. See
 * Reconciler's class comment for why that is a design decision and not an
 * unfinished feature.
 *
 * No ->after() anywhere, for the reason
 * 2026_09_15_020000_repair_order_tables already paid for on this project.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reconciliation_runs')) {
            Schema::create('reconciliation_runs', function (Blueprint $t) {
                $t->id();
                // 191 rather than the 255 default: unique index, and 255
                // utf8mb4 characters overflow the 767-byte limit on the older
                // MySQL this host could still be running. The keys are 40
                // hex characters.
                $t->string('run_key', 191)->unique();
                $t->date('window_from');
                $t->date('window_to');
                $t->string('providers');                       // comma separated, sorted
                $t->string('status')->default('running');      // running | complete | failed
                $t->unsignedInteger('remote_seen')->default(0);
                $t->unsignedInteger('local_checked')->default(0);
                $t->unsignedInteger('findings_count')->default(0);
                $t->string('started_by')->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('reconciliation_checkpoints')) {
            Schema::create('reconciliation_checkpoints', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('run_id')->index();
                $t->string('provider', 40);
                $t->string('phase', 40);
                // The provider's own opaque cursor, stored verbatim. Text
                // rather than string: a Stripe starting_after is 30-odd
                // characters, but nothing stops a provider handing back a
                // signed page token, and truncating one silently would make
                // the next page the first page forever.
                $t->text('cursor')->nullable();
                $t->unsignedInteger('processed')->default(0);
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();

                $t->unique(['run_id', 'provider', 'phase'], 'recon_checkpoint_unique');
            });
        }

        if (! Schema::hasTable('reconciliation_sightings')) {
            Schema::create('reconciliation_sightings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('run_id');
                $t->string('provider', 32);
                $t->string('kind', 12);                        // payment | refund
                $t->string('remote_key', 120);
                $t->integer('amount')->default(0);             // fils
                $t->string('currency', 3)->nullable();
                $t->string('state', 24)->nullable();           // settled | authorised | dead

                /*
                 * EVERY WIDTH HERE IS CHOSEN BY THE INDEX, not by the data.
                 *
                 * This unique key is four columns wide, and on the older MySQL
                 * this host could still be running an InnoDB index is capped at
                 * 767 BYTES — which is 191 utf8mb4 characters, not 191 bytes.
                 * At the 255 default these three strings would come to 3068
                 * bytes and the migration would fail on the live database while
                 * passing on SQLite, which has no such limit. That is the exact
                 * shape of failure this project has already paid for once.
                 *
                 * 8 (bigint) + (32 + 12 + 120) × 4 = 664 bytes. Under the cap
                 * with room to spare, and 120 characters is three times the
                 * longest id any of these three providers issues (a Stripe
                 * `ch_…` is about 30, a Tabby or Tamara UUID 36).
                 *
                 * Reconciler::KEY_LENGTH truncates to match. The two numbers
                 * have to agree: a key longer than the column is truncated by
                 * MySQL in non-strict mode and REJECTED in strict mode, and the
                 * suite runs strict precisely so that the second one is what
                 * happens.
                 */
                $t->unique(['run_id', 'provider', 'kind', 'remote_key'], 'recon_sighting_unique');
            });
        }

        if (! Schema::hasTable('reconciliation_findings')) {
            Schema::create('reconciliation_findings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('run_id')->index();
                $t->string('provider', 32)->index();
                $t->string('kind', 48)->index();
                $t->string('severity', 16)->default('warn');   // alarm | warn | note
                $t->unsignedBigInteger('order_id')->nullable()->index();
                $t->string('order_number')->nullable();
                $t->string('remote_ref', 120)->nullable();
                $t->string('local_ref', 120)->nullable();
                $t->integer('amount_remote')->nullable();      // fils
                $t->integer('amount_local')->nullable();       // fils
                $t->string('currency', 8)->nullable();
                $t->text('summary');
                $t->json('detail')->nullable();
                $t->string('fingerprint', 100);

                // Looked at by a human. NOT a repair: acknowledging a finding
                // moves no money and changes no order. It records that the
                // owner has read it, so the next run over the same window
                // stops shouting about something already dealt with.
                $t->timestamp('acknowledged_at')->nullable();
                $t->string('acknowledged_by')->nullable();
                $t->text('acknowledged_note')->nullable();

                $t->timestamps();

                $t->unique(['run_id', 'fingerprint'], 'recon_finding_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_findings');
        Schema::dropIfExists('reconciliation_sightings');
        Schema::dropIfExists('reconciliation_checkpoints');
        Schema::dropIfExists('reconciliation_runs');
    }
};
