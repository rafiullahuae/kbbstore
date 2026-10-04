<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE block list. One, for the whole shop.                         (Lane CT)
 *
 * The Safety screens (App\Services\SecurityModule) record and report but
 * deliberately block nothing — "report before enforce" — and no other block
 * list exists anywhere in the application. This is the one, and anything that
 * blocks an address later belongs in it rather than beside it.
 *
 * ENFORCED WITHOUT A QUERY. App\Services\Security\IpBlockList compiles these
 * rows into a PHP file under storage/framework, rewritten on every change, and
 * App\Http\Middleware\BlockGate reads that file (opcache) on each request.
 *
 *   cidr          canonical "203.0.113.7/32", "203.0.113.0/24", "2001:db8::/64"
 *   family/prefix 4|6 and the prefix length
 *   network       the masked network, hex — the key the compiled file uses
 *   reason        what the owner typed, or "Fake COD order" etc.
 *   source        cart | manual | bulk
 *   cart_id       the cart it was blocked from, when it was
 *   created_by    who (name snapshot) and created_by_id (admin_users.id)
 *   hits          refused requests since, flushed at most once a minute
 *   expires_at    null = until unblocked
 *
 * CREATED LAST of Lane CT's tables on purpose: IpBlockList treats "this table
 * exists" as "every Cart Tracking column exists", which is only true if it is
 * the last thing the migration set creates.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ip_blocks')) {
            Schema::create('ip_blocks', function (Blueprint $t) {
                $t->id();
                $t->string('cidr', 49)->unique();
                $t->unsignedTinyInteger('family');
                $t->unsignedTinyInteger('prefix');
                $t->string('network', 32);
                $t->string('reason', 190)->nullable();
                $t->string('source', 20)->default('manual');
                $t->unsignedBigInteger('cart_id')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->string('created_by', 120)->nullable();
                $t->unsignedInteger('hits')->default(0);
                $t->timestamp('last_hit_at')->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->timestamps();
            });
        }

        // Whatever compiled list a previous run left is now stale.
        try {
            \App\Services\Security\IpBlockList::rebuild();
        } catch (\Throwable) {
            // The first request rebuilds it after the response; a migration
            // must not fail over a cache.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_blocks');
    }
};
