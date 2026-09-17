<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two columns "Build my routine" cannot be honest without — Lane FM.
 *
 * routine_role — WHICH STEP THIS PRODUCT FILLS.
 *
 * The finding this implements is the previous lane's, written into
 * resources/views/store/skin-quiz.blade.php after it deleted seventeen invented
 * products from that page: a routine driven by the catalogue "needs a schema
 * change: a `routine_role` on products (cleanser / toner / treatment /
 * moisturiser / spf), an admin field to set it, and an endpoint to read the
 * visible, in-stock row per role."
 *
 * Nothing on `products` says it today. `category_id` is the closest thing and
 * is not close: it is a merchandising tree the owner reorders, "Serums" holds
 * both the acid that goes on before moisturiser and the oil that goes on after,
 * and a product may sit in several. The value here is a single word from
 * App\Support\RoutineRoles::ORDER — see that class for why those five and why
 * their order is code rather than a setting.
 *
 * NULLABLE, AND EVERY EXISTING ROW STAYS NULL. That is the honest starting
 * state of this catalogue: nobody has said. It is also why the admin screen
 * this ships with leads on the untagged count rather than hiding it — a routine
 * engine that silently skips half the shop looks like it is working.
 *
 * Indexed because every storefront read is `where routine_role = ? and status =
 * 'publish' and is_visible = 1 and stock_status = 'instock'`, once per step,
 * five steps to a page.
 *
 * routine_concerns — WHICH CONCERNS IT IS FOR.
 *
 * Without this, "routines by concern" can only vary the SHAPE of the routine
 * and every concern gets the same treatment serum. That is the same class of
 * untruth as the invented catalogue, in a quieter voice: putting a product
 * under the heading "for your acne" is a claim, and nobody in this shop would
 * have made it. With it, the owner makes the claim explicitly or not at all.
 *
 * A JSON list of slugs from App\Support\RoutineConcerns, stored as `text` for
 * the same reason `products.image_alts` is text: this schema ships to both
 * SQLite and MySQL and Eloquent's `array` cast reads either. NULL and `[]` mean
 * the same thing and both mean "suits any routine" — an untargeted cleanser is
 * a cleanser whatever the shopper came in worrying about.
 *
 * NOT a pivot table, deliberately. A `product_routine_concern` table would be
 * the textbook shape and would buy one thing this feature never asks for — a
 * query FROM the concern side that does not also filter by role. Every read
 * here starts at the role, takes at most a few dozen rows, and tests the
 * concern list in PHP; a join table would add a migration, a model and a
 * relation to save nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'routine_role')) {
                $table->string('routine_role', 24)->nullable()->index();
            }

            if (! Schema::hasColumn('products', 'routine_concerns')) {
                $table->text('routine_concerns')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['routine_role', 'routine_concerns'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
