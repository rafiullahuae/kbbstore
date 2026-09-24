<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The administrative audit trail — Phase 18, item 6.
 *
 * ── WHAT WAS MISSING ────────────────────────────────────────────────────────
 *
 * This shop records what shoppers do to it and nothing about what its own
 * operators do. `not_found_log` remembers every address a visitor asked for
 * that does not exist; `payment_events` remembers every message a gateway
 * sent. Between them they reconstruct a checkout. Neither of them can answer
 * the first question anybody asks after an incident: WHO CHANGED THIS, from
 * WHERE, and WHAT DID IT SAY BEFORE. A setting that is wrong today was right
 * last week and there is no record anywhere of it moving.
 *
 * ── ONE TABLE, NOT FOUR ─────────────────────────────────────────────────────
 *
 * A sign-in, a setting edit, a package install and a rate-limit trip are one
 * kind of thing — something happened, somebody or something did it, here is
 * the evidence — and the screen that reads them reads them together, newest
 * first, in one query. Four tables would be four queries and four shapes to
 * keep in step for no gain: nothing here joins, nothing here is summed across
 * kinds, and `event` separates them at a fraction of the cost of a table.
 *
 * ── EVERY COLUMN IS DENORMALISED ON PURPOSE ─────────────────────────────────
 *
 * `actor_label` and `actor_role` hold the admin's email and role AS THEY WERE
 * at the moment of the act, not a foreign key to admin_users. Two reasons, and
 * both are the point of an audit trail rather than a convenience:
 *
 *   1. The row must survive the account. An account deleted in a hurry is
 *      exactly the row somebody needs afterwards, and a join to a deleted id
 *      renders as a blank.
 *   2. A join per row is an N+1 on a screen whose whole job is to list rows.
 *      StorefrontQueryBudgetTest is a budget; the Security screen honours the
 *      same rule with no joins at all.
 *
 * `actor_id` is kept beside the label, unindexed and unconstrained — no
 * foreign key, deliberately, so deleting an admin can never delete their
 * trail or fail for referencing it.
 *
 * ── WHAT IS NOT IN IT ───────────────────────────────────────────────────────
 *
 * No password, no hash, no session token, no request body. `before`/`after`
 * carry the two values of ONE named thing, and App\Services\SecurityModule
 * redacts any key whose name says it holds a secret before they are written —
 * see SECRET_HINTS there. The rule `payment_events.payload` already follows.
 *
 * ── hits / last_seen_at ─────────────────────────────────────────────────────
 *
 * A rate-limit trip is the one event here that can arrive thousands of times a
 * minute, because arriving repeatedly is what it IS. Those collapse onto one
 * row per (address, path) per window, the way `not_found_log` collapses a
 * repeated 404, so a flood costs one UPDATE rather than ten thousand INSERTs
 * and the screen still shows the count. Everything else is written once with
 * hits = 1.
 *
 * NO ->after() ANYWHERE, for the reason 2026_09_15_020000_repair_order_tables
 * already paid for on this project.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_events')) {
            return;
        }

        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();

            /*
             * When it happened, and when it last happened. Separate columns
             * rather than created_at/updated_at because a collapsed row's
             * "when" is a range and both ends are evidence: a trip that began
             * at 02:11 and was still arriving at 02:19 is a different story
             * from one that happened once.
             */
            $t->timestamp('occurred_at')->useCurrent()->index();
            $t->timestamp('last_seen_at')->useCurrent();
            $t->unsignedInteger('hits')->default(1);

            // 64, not the 255 default: these are the constants on
            // SecurityModule and the column is indexed.
            $t->string('event', 64)->index();
            $t->string('severity', 16)->default('info');

            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_label', 191)->nullable();
            $t->string('actor_role', 32)->nullable();

            // 45 holds an IPv6 address with an IPv4 tail; indexed because
            // "everything from this address" is the second question asked.
            $t->string('ip', 45)->nullable()->index();

            $t->string('method', 10)->nullable();
            $t->string('path', 191)->nullable();

            // What was acted on: a setting key, a release version, an account
            // email. Free text, because the kinds do not share an id space.
            $t->string('subject', 191)->nullable();

            $t->string('summary', 255);

            $t->text('before')->nullable();
            $t->text('after')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
