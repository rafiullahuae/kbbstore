<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `routines` — the owner's OVERRIDES of the eight routines, not the routines
 * themselves. Lane FM.
 *
 * ── WHY OVERRIDES AND NOT ROWS ──────────────────────────────────────────────
 *
 * The obvious table is "one row per routine, and the shop has none until the
 * owner makes one". That ships a feature whose first screen is empty, on a
 * console where five screens already exist that the owner has never filled in,
 * and the module would be switched on, found blank and switched off again.
 *
 * So the eight routines are CODE — one per concern in
 * App\Support\RoutineConcerns, five steps each from App\Support\RoutineRoles —
 * and this table holds only what the owner has changed about one. A shop that
 * has never opened the screen has no rows here and eight working routines; a
 * shop that renamed one has one row. Every column below is therefore nullable
 * or defaulted, and NULL means "whatever the code says", never "empty".
 *
 * ONE ROUTINE PER CONCERN, enforced by the unique key. Two routines for
 * "Hydration" is a merchandising idea — a starter one and a full one, say — and
 * it is not this lane's: it needs a second identifier in the URL, a way to
 * choose between them on the list page, and an answer to which one the offer
 * strip belongs to. `concern` being unique is the cheap, reversible version of
 * that decision, and widening it later is one migration.
 *
 * ── THE COLUMNS ─────────────────────────────────────────────────────────────
 *
 * concern     the slug from RoutineConcerns::LIST. The routine's identity, its
 *             URL segment, and what products are matched against.
 * title       the owner's name for it, or NULL for the keyed default. English
 *             only and deliberately so: a title typed here is one shop's
 *             wording and the translation console is where its Arabic lives.
 * blurb       the sentence under the title, same rule.
 * steps       an ordered JSON list of role keys, honoured ONLY when the module
 *             setting `steps_mode` is `custom`. See BuildMyRoutine::SCHEMA for
 *             the open question this column exists to keep open: the plan asks
 *             "fixed steps or a free list?" and the answer is a setting rather
 *             than a rewrite because the column is here from the start.
 * coupon_code the code of a REAL row in `coupons` whose terms the offer strip
 *             prints, honoured only when `offer_scope` is `routine`. Not an
 *             amount and not a percentage: a discount this shop has not
 *             actually created is exactly the "15% bundle saving" the previous
 *             lane deleted from the quiz.
 * is_enabled  the owner hiding one routine without hiding the module.
 * position    the order on the list page. Defaults to 0, and the storefront
 *             falls back to the order of RoutineConcerns::LIST, so eight rows
 *             that have never been sorted still come out in a fixed order
 *             rather than in whatever order the database returns them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('routines')) {
            return;
        }

        Schema::create('routines', function (Blueprint $table) {
            $table->id();
            $table->string('concern', 40)->unique();
            $table->string('title')->nullable();
            $table->text('blurb')->nullable();
            $table->text('steps')->nullable();
            $table->string('coupon_code')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->integer('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routines');
    }
};
