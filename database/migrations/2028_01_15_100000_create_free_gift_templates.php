<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Growth & Marketing -> Free gifts -> Ranges & gifts -> Templates (Lane GT,
 * the owner 8 Nov 2026: "save as template the whole gifts ranges in one single
 * template ... select and live instantly").
 *
 * One row per saved set of ranges. `payload` is plain values only -- every
 * range's columns and its gifts by product id -- never a range id, so a
 * template outlives the rows it was taken from. `auto` marks the "Before ..."
 * copies an apply takes on its own (the last five are kept). `live_rev` is the
 * ranges revision (FreeGifts::revisionOf) right after this template was saved
 * or made live: equal to today's revision, the screen says "Live"; different,
 * "edited since". At most one row carries it.
 *
 * Nothing on the shop reads this table; checkout reads the compiled ranges.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('free_gift_templates')) {
            return;
        }

        Schema::create('free_gift_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60)->unique();
            $t->json('payload');
            $t->boolean('auto')->default(false);
            $t->string('live_rev', 16)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('free_gift_templates');
    }
};
