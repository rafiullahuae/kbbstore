<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store → Customers → "Send account invite". (Lane PQ)
 *
 * The owner's ask: every guest who checked out on the old WooCommerce shop
 * should be able to get into an account on this one, by an email he selects
 * the recipients of, previews and can edit.
 *
 * FIVE COLUMNS ON `customers`:
 *
 *   invited_at          when the most recent invite went out. Also the
 *                       "never twice within ten minutes" gate.
 *   invite_count        how many invites this customer has been sent.
 *   invite_accepted_at  when they used one to set a password ("Activated").
 *   invite_token_hash   sha256 of the one live set-password token, or NULL.
 *                       ONE column, not a table of tokens, and that is the
 *                       "a newer invite invalidates the older one" rule made
 *                       structural: minting a new token overwrites the old
 *                       hash, so there is never a second live link to forget
 *                       to revoke. Never the token itself — a database dump
 *                       must not be a bag of working sign-in links.
 *   invite_expires_at   when that token stops working.
 *
 * TWO TABLES for the send itself, because a shared host will not send 1,000
 * emails in one request and the console has to be able to stop, reload and
 * carry on:
 *
 *   customer_invite_runs   one press of "Send": the subject and body AS SENT
 *                          (so resuming tomorrow sends what was approved, not
 *                          whatever the template says by then), the expiry,
 *                          who pressed it and what was skipped up front.
 *   customer_invite_items  one row per recipient: pending → sending → sent or
 *                          failed (with the transport's reason). The claim from
 *                          pending to sending is a conditional UPDATE, so two
 *                          tabs pressing Resume cannot both send to one person.
 *
 * Guarded with hasTable/hasColumn throughout: a package can be applied twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers')) {
            if (! Schema::hasColumn('customers', 'invited_at')) {
                Schema::table('customers', function (Blueprint $t) {
                    $t->timestamp('invited_at')->nullable();
                });
            }

            if (! Schema::hasColumn('customers', 'invite_count')) {
                Schema::table('customers', function (Blueprint $t) {
                    $t->unsignedInteger('invite_count')->default(0);
                });
            }

            if (! Schema::hasColumn('customers', 'invite_accepted_at')) {
                Schema::table('customers', function (Blueprint $t) {
                    $t->timestamp('invite_accepted_at')->nullable();
                });
            }

            if (! Schema::hasColumn('customers', 'invite_token_hash')) {
                Schema::table('customers', function (Blueprint $t) {
                    $t->char('invite_token_hash', 64)->nullable()->index();
                });
            }

            if (! Schema::hasColumn('customers', 'invite_expires_at')) {
                Schema::table('customers', function (Blueprint $t) {
                    $t->timestamp('invite_expires_at')->nullable();
                });
            }
        }

        if (! Schema::hasTable('customer_invite_runs')) {
            Schema::create('customer_invite_runs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('admin_user_id')->nullable();
                $t->string('subject', 200);
                $t->text('body');
                $t->unsignedTinyInteger('expiry_days')->default(7);
                $t->boolean('include_recent')->default(false);
                $t->string('status', 20)->default('running')->index();
                $t->unsignedInteger('selected')->default(0);
                $t->unsignedInteger('skipped_password')->default(0);
                $t->unsignedInteger('skipped_email')->default(0);
                $t->unsignedInteger('skipped_recent')->default(0);
                $t->unsignedInteger('skipped_missing')->default(0);
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('customer_invite_items')) {
            Schema::create('customer_invite_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('run_id');
                $t->unsignedBigInteger('customer_id');
                $t->string('status', 20)->default('pending');
                $t->string('reason', 300)->nullable();
                $t->timestamp('claimed_at')->nullable();
                $t->timestamp('processed_at')->nullable();
                $t->unique(['run_id', 'customer_id']);
                $t->index(['run_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_invite_items');
        Schema::dropIfExists('customer_invite_runs');

        if (! Schema::hasTable('customers')) {
            return;
        }

        if (Schema::hasColumn('customers', 'invite_token_hash')) {
            Schema::table('customers', function (Blueprint $t) {
                $t->dropIndex(['invite_token_hash']);
            });
        }

        foreach (['invited_at', 'invite_count', 'invite_accepted_at', 'invite_token_hash', 'invite_expires_at'] as $column) {
            if (Schema::hasColumn('customers', $column)) {
                Schema::table('customers', function (Blueprint $t) use ($column) {
                    $t->dropColumn($column);
                });
            }
        }
    }
};
