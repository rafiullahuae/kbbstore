<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-admin panel arrangement for a back-office screen.  (Lane AS)
 *
 * WHY THE DATABASE AND NOT localStorage.
 *
 * localStorage is cheaper and needs none of this, and it was the wrong answer
 * here for three separate reasons:
 *
 *   1. The owner reviews this console on a phone and builds products at a
 *      desk. localStorage is scoped to one origin in one browser profile, so
 *      an arrangement made on the laptop is simply absent on the phone. The
 *      request was for an arrangement "as per the user comfort", and a comfort
 *      that evaporates when you pick up a different device is not one.
 *
 *   2. admin_users.role is already owner|manager|staff, so this console
 *      genuinely has more than one operator. A layout is a PERSONAL
 *      preference, and the only place it can be personal is against a user id.
 *      Keyed per admin_user_id, one operator rearranging their editor cannot
 *      be seen by another.
 *
 *   3. The host has no shell. If a preference is wedged, the only repair paths
 *      are the reset button in the UI or a row somebody can reach. A browser
 *      store is neither.
 *
 * WHY A `screen` COLUMN rather than a table named after the product editor.
 * The next screen that wants this wants exactly this table, and the row is
 * three columns wide. The controller pins `screen` to an allowlist so the
 * table cannot become an anonymous key/value dump written by the client.
 *
 * THE PAYLOAD IS A LIST OF NAMES, NOT A RENDERING. `layout` holds column names
 * mapped to arrays of panel keys and nothing else -- no widths, no HTML, no
 * per-panel settings -- so the worst a stale row can do is name panels that do
 * not exist, and both the client and the server drop those rather than leaving
 * a hole. See ProductEditorLayoutTest.
 *
 * NO `AFTER` CLAUSE ANYWHERE. Nine earlier migrations in this repo were silent
 * no-ops on MySQL for positioning a column against one that did not exist yet.
 * This creates a table outright, so there is nothing to position.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_screen_layouts')) {
            return;
        }

        Schema::create('admin_screen_layouts', function (Blueprint $t) {
            $t->id();

            /*
             * Cascade on delete: a layout is meaningless without the operator
             * it belongs to, and leaving orphans behind would let a recycled
             * auto-increment id hand a new admin the previous admin's screen.
             */
            $t->foreignId('admin_user_id')
                ->constrained('admin_users')
                ->cascadeOnDelete();

            $t->string('screen', 64);

            /*
             * `text`, not `json`. Nothing here queries inside the document,
             * SQLite stores either as text, and a json column would buy a
             * driver difference and no behaviour. The cast on the model is
             * what matters.
             */
            $t->text('layout');

            $t->timestamps();

            // One arrangement per operator per screen. This is the constraint
            // that makes the write an upsert rather than an append, and it is
            // also what stops a retried request growing the table.
            $t->unique(['admin_user_id', 'screen']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_screen_layouts');
    }
};
