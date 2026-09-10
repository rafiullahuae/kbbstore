<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Sets the sitewide default meta description, read by App\Support\Seo::render()
 * (wired into the storefront in 2.60.36) for any page that doesn't supply its
 * own — including the homepage, since it falls back to this when
 * seo_home_description is empty. Idempotent: safe to re-run, always sets the
 * same value, and if this is ever run again after someone has deliberately
 * changed it from the SEO & Meta screen, re-running the same migration would
 * overwrite that — this is a one-time seed, not a permanent override.
 */
return new class extends Migration
{
    public function up(): void
    {
        \App\Models\Setting::updateOrCreate(
            ['key' => 'seo_default_description'],
            ['value' => "From Serum, creams and Moisturizers to Beauty Devices. Let's make your skin more glow. Next Day Delivery. 100% original products."]
        );
    }

    public function down(): void
    {
        \App\Models\Setting::where('key', 'seo_default_description')->delete();
    }
};
