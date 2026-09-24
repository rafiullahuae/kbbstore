<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's answers to the redirect map's questions — Phase 13, item
 * "three-bucket classification: migrate / discard / ask — Rafi approves any
 * discard list".
 *
 * ── WHY THE ANSWERS NEED A TABLE AND THE QUESTIONS DO NOT ───────────────────
 *
 * `RedirectMap` is a pure function of the catalogue. Nothing it proposes is
 * stored, every call re-derives the whole map, and that is exactly right: the
 * map has to change when a category is renamed or a permalink export is
 * re-uploaded, and a cached copy would be a map of a shop that no longer
 * exists.
 *
 * An ANSWER is the opposite kind of thing. "Yes, send /toners/ to the nested
 * path even though this shop answers /toners/ today" is a decision a person
 * made once, and re-deriving it is not possible — there is nothing in the
 * catalogue that records it. Without somewhere to put it the ask bucket is a
 * list that comes back identical every time it is read, which is what it did:
 * the owner could download it and he could not act on it.
 *
 * ── WHY NOT JUST WRITE THE REDIRECT AND BE DONE ─────────────────────────────
 *
 * "Accept" could write a `redirects` row immediately and need no table at all.
 * "Decline" could not, and declining is half the job: an address he has looked
 * at and said no to has to stop being asked about, or the list never shrinks
 * and the hundredth read is as long as the first. One table for both answers
 * keeps them the same kind of thing — and it means an accept can be UNDONE by
 * clearing the answer, without guessing which of the rows in `redirects` this
 * screen put there.
 *
 * ── source IS THE KEY, AND target IS WHY AN ANSWER CAN GO STALE ─────────────
 *
 * One answer per old address, because one address gets one redirect. `target`
 * is stored beside it as the destination that was on the screen when he said
 * yes — NOT as the destination that will be written. `RedirectDecisions::apply()`
 * compares the two on every read, and an answer whose destination has since
 * moved goes back into the ask bucket saying so. Approving
 * "/toners/ → /product-category/skincare/toners/" must never silently become
 * approval of "/toners/ → /somewhere-else/" because somebody re-parented a
 * category afterwards.
 *
 * `question` is the code from `RedirectMap::QUESTIONS` that was being answered,
 * kept so the screen can say "you have answered 312 of the 400 rows asking
 * this" and so a bulk clear can undo one question's worth of answers.
 *
 * ── NO FOREIGN KEY TO redirects ─────────────────────────────────────────────
 *
 * Deliberately, and for `audit_events`' reason: an answer must survive the row
 * it caused. Deleting a redirect by hand on Store → Redirects should not
 * silently un-answer the question that produced it, and it does not.
 *
 * NO ->after() ANYWHERE, for the reason 2026_09_15_020000_repair_order_tables
 * already paid for on this project.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('redirect_decisions')) {
            return;
        }

        Schema::create('redirect_decisions', function (Blueprint $t) {
            $t->id();

            /*
             * The old address being answered about. Unique: one answer per
             * address, and `RedirectDecisions` upserts on it rather than
             * reading-then-writing, so two browser tabs answering at once
             * cannot make two rows.
             *
             * 191 and not 255 because it is indexed and MySQL's utf8mb4 index
             * ceiling is 767 bytes; `redirects.source` is the same width, and
             * an address longer than that could not be stored there either.
             */
            $t->string('source', 191)->unique();

            /** 'accept' or 'reject'. 16 is room for both and nothing else. */
            $t->string('decision', 16);

            /*
             * The destination as it stood when the answer was given. Nullable
             * because the three undecidable questions have none — those can
             * only ever be declined.
             */
            $t->string('target', 191)->nullable();

            /** The RedirectMap::QUESTIONS code that was being answered. */
            $t->string('question', 32)->nullable();

            /** Which rule proposed it, and what it was about, for the screen. */
            $t->string('rule', 32)->nullable();
            $t->string('subject', 191)->nullable();

            /*
             * Who answered and when. The email as it was, not a foreign key —
             * `audit_events` states the reasoning and it is the same here: the
             * answer must outlive the account, and a join per row on a screen
             * whose job is to list rows is an N+1.
             */
            $t->string('decided_by', 191)->nullable();

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirect_decisions');
    }
};
