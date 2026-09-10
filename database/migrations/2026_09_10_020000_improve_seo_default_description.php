<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Replaces the sitewide default meta description set in 2.60.37 with one
 * checked against current (2026) meta description guidance rather than
 * shipped as originally typed.
 *
 * Front-loads the core keyword, location, and category range within the
 * first ~120 characters (the mobile truncation point), keeps trust signals
 * (authenticity, delivery speed) before the 155-160 character desktop
 * cutoff, and stays out of keyword-stuffing territory — one natural mention
 * each of "Korean skincare" and "UAE". 148 characters total.
 *
 * A separate, larger change alongside this one (ShopController) gives every
 * category and search page its own distinct description instead of falling
 * back to this same sitewide text — duplicate meta descriptions across a
 * catalogue's category pages is a real quality-signal problem, not just a
 * missed opportunity, so this default now mainly serves the homepage.
 *
 * New migration rather than editing 2.60.37's in place: that file has
 * already run on any server that applied that package, so editing its
 * content wouldn't reach a server already at 2.60.37 — Laravel tracks
 * migrations by filename, not content.
 *
 * Idempotent: safe to re-run, always sets the same value. One-time seed,
 * not a permanent override — editable any time from Store → SEO & Meta.
 */
return new class extends Migration
{
    public function up(): void
    {
        \App\Models\Setting::updateOrCreate(
            ['key' => 'seo_default_description'],
            ['value' => 'Shop authentic Korean skincare in the UAE — serums, creams, moisturisers & beauty devices. 100% genuine, next-day delivery, glowing skin guaranteed.']
        );
    }

    public function down(): void
    {
        // Restores the 2.60.37 value rather than deleting the key outright,
        // since that migration's own down() already covers full removal.
        \App\Models\Setting::updateOrCreate(
            ['key' => 'seo_default_description'],
            ['value' => "From Serum, creams and Moisturizers to Beauty Devices. Let's make your skin more glow. Next Day Delivery. 100% original products."]
        );
    }
};
