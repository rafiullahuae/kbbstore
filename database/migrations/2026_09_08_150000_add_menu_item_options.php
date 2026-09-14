<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three fields the Mega Menu screen didn't have a way to set anything for:
 * a highlight colour for calling out an item (Sale, a seasonal push),
 * visibility tied to whether a shopper is signed in (so "My Account" and
 * "Sign In" can be the same menu slot instead of both showing at once),
 * and whether a link opens in a new tab (useful for anything leaving the
 * site — a WhatsApp link, an external brand partner page).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $t) {
            if (! Schema::hasColumn('menu_items', 'highlight_color')) {
                $t->string('highlight_color', 9)->nullable()->after('badge');
            }
            if (! Schema::hasColumn('menu_items', 'visibility')) {
                $t->string('visibility', 10)->default('always')->after('highlight_color');
            }
            if (! Schema::hasColumn('menu_items', 'new_tab')) {
                $t->boolean('new_tab')->default(false)->after('visibility');
            }
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $t) {
            $t->dropColumn(['highlight_color', 'visibility', 'new_tab']);
        });
    }
};
