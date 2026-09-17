<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;

/**
 * The one sentence this shop says to every search engine, and what was in it.
 *
 * ── WHERE THIS SENTENCE GOES ────────────────────────────────────────────────
 *
 * `seo_default_description` is not "the fallback for a page nobody got round
 * to". Three controllers in this application pass a description of their own —
 * Store\ShopController, Store\CollectionController and Store\ProductController
 * — and EVERYTHING ELSE gets this string. The home page (unless
 * `seo_home_description` is filled, and it ships empty), every one of the seven
 * routed content pages, the brand index, every brand landing page, the cart,
 * the account area, and any product whose `short_description` is blank. It is
 * emitted three times per page — <meta name="description">, og:description and
 * twitter:description — so it is also the sentence WhatsApp, Facebook, Slack
 * and iMessage print under the link when somebody shares the shop. And
 * Store\SeoFilesController quotes it verbatim into /llms.txt.
 *
 * ── WHAT IT SAID ────────────────────────────────────────────────────────────
 *
 *   "Shop authentic Korean skincare in the UAE — serums, creams, moisturisers
 *    & beauty devices. 100% genuine, next-day delivery, glowing skin
 *    guaranteed."
 *
 * Three claims in one sentence and each fails a different way.
 *
 * "NEXT-DAY DELIVERY" IS NOT WHAT THIS SHOP PROMISES ANYWHERE ELSE.
 * App\Support\DeliveryLine resolves the promise per country from the owner's
 * own wording: `delivery_default_text` is "1–3 days fast delivery all over
 * UAE", and App\Support\CountryPresets carries the figures he supplied for the
 * rest of the Gulf — three to five days. So a shopper who chose this shop out
 * of a result promising next day had been told two different things by one
 * shop before clicking, and a shopper in Riyadh a third. 2.60.183 removed this
 * clause from the category, search, /shop and collection descriptions for
 * exactly that reason; Store\ShopController::seoDescription() records the
 * reasoning in full and names this key as the one it could not reach.
 *
 * A META DESCRIPTION CANNOT BE PER-COUNTRY. There is one string per URL and a
 * crawler is one of its readers, so the per-visitor machinery the storefront
 * uses must never touch it — varying it by a guessed geo header would mean
 * serving search engines something different from shoppers, for a promise.
 * THE ANSWER TO "THE PROMISE VARIES" IS THEREFORE TO NAME NO WINDOW AT ALL,
 * not to name the shortest or to average them, and that is the decision here:
 * delivery is stated per country, on the page, by DeliveryLine, where it can
 * be true. Nothing replaces the clause.
 *
 * "GLOWING SKIN GUARANTEED" HAS NO HONEST SOURCE AND CANNOT ACQUIRE ONE. Every
 * other claim in this shop was fixable by finding the thing that backs it — a
 * count from the catalogue, a rate from the shipping zone, a rating from
 * approved reviews. A guarantee about a cosmetic outcome is not a fact this
 * application could ever hold, and in the UAE, the EU and the UK a guaranteed
 * result from a cosmetic product is a regulated claim. It is removed, and the
 * sentence is rewritten to read as a sentence rather than left truncated.
 *
 * "100% GENUINE" IS A FOURTH SPELLING OF A CLAIM THAT NOW HAS ONE HOME.
 * 2.60.193 gave the storefront's trust claims a single owner-editable place —
 * App\Support\TrustClaims — with "100% original", "100% authentic" and "100%
 * authentic K-beauty" each becoming a setting whose empty box removes it. This
 * sentence was the fifth spelling, in the half of the shop the owner never
 * sees.
 *
 * ── AND THE REPLACEMENT CARRIES NO TRUST CLAIM AT ALL ───────────────────────
 *
 * The first attempt at this migration COMPOSED the new sentence from
 * TrustClaims::get(), on the reasoning that a claim quoted from the one box is
 * not a fifth spelling of it. tests/Feature/TrustClaimsAreTheOwnersTest
 * disagreed, and it was right: it clears `home_brands_note` and then asserts
 * the wording is nowhere on the home page. With the claim copied into this
 * setting the wording was still in the page's own <head>, so EMPTYING THE BOX
 * NO LONGER REMOVED THE CLAIM. A settings row written once by a migration
 * cannot follow the Claims tab afterwards; it is a second home for the claim,
 * which is the defect TrustClaims exists to end, one indirection quieter.
 *
 * So the sentence below says what the shop SELLS and makes no claim about the
 * business at all. A shop's trust claims live in one place, are rendered from
 * it, and are removable from it. An owner who wants one in his search result
 * types it into the Default description box himself, where he can see it and
 * take it out again — which is precisely the control he did not have over the
 * sentence this replaces.
 *
 * ── AND AN OWNER WHO WROTE HIS OWN IS NOT OVERWRITTEN ───────────────────────
 *
 * `seo_default_description` is a real, editable field on Store → SEO & Meta
 * (AdminController::SETTING_RULES). Both migrations that set it before this one
 * used a bare updateOrCreate() and would have flattened anything he typed. This
 * writes ONLY when the stored value is still, byte for byte, one of the two
 * sentences this repository shipped — the 2.60.37 seed or the later rewrite.
 * Anything else, including a blank he cleared on purpose, is his and is left
 * exactly as it is.
 *
 * Idempotent: once rewritten the value matches neither literal, so a re-run is
 * a no-op rather than a second rewrite.
 */
return new class extends Migration
{
    private const KEY = 'seo_default_description';

    /** The two sentences this repository has shipped in that box. */
    private const SHIPPED = [
        // 2026_09_10_010000_seed_seo_default_description.php
        "From Serum, creams and Moisturizers to Beauty Devices. Let's make your skin more glow. Next Day Delivery. 100% original products.",
        // 2026_09_10_020000_improve_seo_default_description.php
        'Shop authentic Korean skincare in the UAE — serums, creams, moisturisers & beauty devices. 100% genuine, next-day delivery, glowing skin guaranteed.',
    ];

    public function up(): void
    {
        $row = Setting::query()->where('key', self::KEY)->first();

        if ($row === null || ! in_array((string) $row->value, self::SHIPPED, true)) {
            return;
        }

        $row->value = self::sentence();
        $row->save();

        self::flushCaches();
    }

    /**
     * What the shop sells, and where it is. Nothing else.
     *
     * Every product group named here was already in both of the sentences this
     * replaces — the 2.60.37 wording was "From Serum, creams and Moisturizers
     * to Beauty Devices" — so no category is invented for a catalogue this file
     * cannot see. "In the UAE" is where the shop is, not a promise about
     * anybody's parcel. "Korean beauty brands" is what the `brands` table
     * holds, not a statement about how they are sourced: that is a claim, it
     * belongs on the Claims tab, and it is deliberately not here.
     *
     * 108 characters, so the whole sentence survives both the ~120 mobile and
     * the ~155 desktop cutoff rather than being cut mid-clause.
     *
     * A method rather than an inline literal so up() writes and down()
     * recognises the same string, with no second copy to fall out of step.
     */
    private static function sentence(): string
    {
        return 'Shop Korean skincare in the UAE — serums, creams, moisturisers and beauty devices from Korean beauty brands.';
    }

    /**
     * Both settings caches, because there are two and they are keyed
     * differently: SettingsService holds `kbb.settings` forever plus a
     * process-level memo, and Setting::map() / Seo\SeoSettings::map() share
     * `kbb.settings.map`. A write that clears one leaves the storefront reading
     * the other, which is the trap CLAUDE.md records against Setting::map().
     */
    private static function flushCaches(): void
    {
        app(SettingsService::class)->flush();
        SettingsService::forgetMemo();
        Setting::flushMap();
    }

    /**
     * Back to the later shipped sentence, and only over a value this migration
     * is the author of — the same restraint as up(). An owner who edited the
     * box after the package landed keeps his words on the way down too.
     */
    public function down(): void
    {
        $row = Setting::query()->where('key', self::KEY)->first();

        if ($row === null || (string) $row->value !== self::sentence()) {
            return;
        }

        $row->value = self::SHIPPED[1];
        $row->save();

        self::flushCaches();
    }
};
