<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing Emails → Customer groups (Lane MK, plan §2.2 and §3).
 *
 *   audience   customers | subscribers — two separate lists, never mixed
 *              (the owner's D3).
 *   match      all | any.
 *   rules      a list of {field, op, value} from App\Services\Marketing\
 *              Audience::FIELDS — a closed vocabulary compiled to bound SQL.
 *              Nothing stored here is ever pasted into a statement.
 *   preset     1 for the groups the shop ships (Never ordered, Repeat
 *              buyers, VIP …). Editable: the owner can change the AED figure
 *              on VIP, as the plan says.
 *   key        the stable name of a preset, so seeding twice is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mkt_segments')) {
            return;
        }

        Schema::create('mkt_segments', function (Blueprint $t) {
            $t->id();
            $t->string('key', 60)->nullable()->unique();
            $t->string('name', 120);
            $t->string('audience', 20)->default('customers');
            $t->string('match', 3)->default('all');
            $t->json('rules');
            $t->boolean('preset')->default(false);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mkt_segments');
    }
};
