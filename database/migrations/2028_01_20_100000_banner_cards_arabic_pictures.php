<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 2.60.659 -- the owner, 11 Nov 2026: "why you didn't translate the banner? do
 * it immediately for arabic version and put there."
 *
 * A slider slide can now carry ARABIC pictures (desktop and phone), drawn on
 * /ar in place of the English ones. Empty by default: every slide without them
 * draws exactly as before, in both languages. Banners::load() does the swap.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('banner_cards')) {
            return;
        }

        Schema::table('banner_cards', function (Blueprint $t) {
            if (! Schema::hasColumn('banner_cards', 'image_ar')) {
                $t->string('image_ar', 400)->default('');
            }
            if (! Schema::hasColumn('banner_cards', 'image_ar_w')) {
                $t->unsignedInteger('image_ar_w')->nullable();
            }
            if (! Schema::hasColumn('banner_cards', 'image_ar_h')) {
                $t->unsignedInteger('image_ar_h')->nullable();
            }
            if (! Schema::hasColumn('banner_cards', 'image_m_ar')) {
                $t->string('image_m_ar', 400)->default('');
            }
            if (! Schema::hasColumn('banner_cards', 'image_m_ar_w')) {
                $t->unsignedInteger('image_m_ar_w')->nullable();
            }
            if (! Schema::hasColumn('banner_cards', 'image_m_ar_h')) {
                $t->unsignedInteger('image_m_ar_h')->nullable();
            }
        });
    }

    public function down(): void {}
};
