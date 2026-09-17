<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * The shop's trust claims — one place, owner-editable, and removable.
 *
 * ── THE PROBLEM THIS SOLVES ─────────────────────────────────────────────────
 *
 * The storefront told shoppers several things nobody at this shop has ever
 * verified, and told them as literals inside Blade templates:
 *
 *   store/home.blade.php                    "100% original", with
 *                                           "Direct from brands and trusted
 *                                           suppliers" under it, and
 *                                           "24/7 support"
 *   partials/checkout/order-block.blade.php "100% authentic", printed beside
 *                                           the Place order button
 *   store/product.blade.php                 "100% authentic", the first chip in
 *                                           the trust row under the buy box —
 *                                           left behind by Lane DR because
 *                                           another lane held the file, and
 *                                           brought in by Lane DT
 *   partials/announcement.blade.php         "100% authentic K-beauty"
 *   store/home.blade.php                    "Korean brands, all sourced direct."
 *
 * and one of them as a setting with no admin control, which comes to the same
 * thing:
 *
 *   partials/checkout/reassurance.blade.php `reassure_auth_text`, defaulting to
 *                                           "100% authentic K-beauty" —
 *                                           a key that appears in no admin
 *                                           screen, in no seeder and, until
 *                                           this change, in no
 *                                           AdminController::SETTING_RULES
 *                                           entry, so updateSettings() rejected
 *                                           it outright. The default was the
 *                                           shipped and only value.
 *
 * WHETHER THEY ARE TRUE IS NOT THIS CLASS'S QUESTION and is not a question any
 * lane can answer — sourcing and support hours are facts about the business.
 * What was wrong is that the owner could neither see the claims nor change
 * them: they were in files only a signed zip can edit, on a host with no shell.
 *
 * ── WHAT THIS DOES ──────────────────────────────────────────────────────────
 *
 * Every claim is a setting whose DEFAULT is the exact wording that shipped, so
 * a shop that never opens the screen keeps the page it has always had, and
 * nothing changes for anyone on the day this lands.
 *
 * AN EMPTY VALUE REMOVES THE CLAIM. Not an empty badge, not an icon with
 * nothing beside it — the element is not rendered at all. That is the half the
 * owner actually needs: "I cannot stand behind this" has to be expressible, and
 * the only way to express it in a text box is to clear the box.
 *
 * WHICH IS WHY THIS RETURNS null AND NOT ''. SettingsService::get() hands back
 * its default only when the ROW IS ABSENT; an owner who clears the input stores
 * an empty string, which is a real value and must not fall back to the shipped
 * literal. See App\Support\SupportContact, which carries the same distinction
 * for the shop's phone number, and the `currency_decimals` note in
 * AdminController::SETTING_RULES for the same trap in a money field.
 *
 * ── WHAT IS DELIBERATELY NOT HERE ───────────────────────────────────────────
 *
 * COUNTS ARE NOT CLAIMS AND DO NOT BELONG IN A TEXT BOX. The home page's
 * "N brands", "N products stocked" and "N verified reviews" are already counted
 * from the database, the header's search placeholder already substitutes the
 * real product count into {n}, and none of them is routed through this class —
 * a brand count that an owner can type is a brand count that can drift from the
 * catalogue, which is the defect, not the fix. The only "50+ Korean brands" in
 * the repository is inside store/app.blade.php, the developer preview that
 * PageController::app() now refuses to serve to anyone but an admin.
 *
 * The rating line on the checkout is likewise absent: App\Support\StoreRating
 * computes it from approved reviews and renders nothing when there are too few.
 * A claim that CAN be measured is measured.
 */
final class TrustClaims
{
    /**
     * Every claim key, with the wording that shipped as its default.
     *
     * The array is public so a test can ask what the claims are rather than
     * scraping quoted strings out of Blade files, and so the admin screen has
     * one list to build its inputs from.
     *
     * @var array<string, string>
     */
    public const CLAIMS = [
        // Home page, trust row (store/home.blade.php).
        'trust_authentic_title' => '100% original',
        'trust_authentic_text' => 'Direct from brands and trusted suppliers',
        'trust_support_title' => '24/7 support',

        // Home page, brands section subtitle (store/home.blade.php).
        'home_brands_note' => 'Korean brands, all sourced direct.',

        // Checkout, beside the Place order button
        // (partials/checkout/order-block.blade.php).
        'checkout_authentic_text' => '100% authentic',

        /*
         * Product page, first chip in the trust row (store/product.blade.php).
         *
         * ── WHY THIS IS ITS OWN KEY AND NOT `checkout_authentic_text` ───────
         *
         * The two defaults are the same string, and that is exactly the case
         * where sharing one box looks like the tidy answer. It is not, for one
         * reason that outranks the tidiness: A SHARED BOX MAKES REMOVAL
         * CONTAGIOUS. Clearing a claim is the half of this feature the owner
         * actually needs, and on a shared key "take that off the product page"
         * would also silently strip the chip beside Place order — a second
         * decision he never made, on the page where being wrong costs money.
         * One box must not be able to change two pages the owner is not
         * looking at.
         *
         * IT IS ALSO THE SHAPE THIS CLASS ALREADY HAS. `reassure_auth_text` and
         * `anno_authentic_text` carry byte-identical defaults in two keys
         * already, and the admin tab is organised by PLACE — "On the home
         * page", "At the checkout", "On the announcement strip". One key per
         * placement is the established convention here; reusing one would be
         * the anomaly, not the saving.
         *
         * AND THE MOMENTS ARE GENUINELY DIFFERENT. This chip is read while
         * browsing, beside delivery and returns; the checkout one is the last
         * sentence before payment. An owner may well want to word, keep or drop
         * them separately, and a shared key forbids that outright.
         *
         * THE OBJECTION, ANSWERED. Two boxes for one sentence can drift apart.
         * They cannot drift UNSEEN: the Claims tab prints every claim on one
         * screen with its placement named, which is what that tab is for. This
         * is copy, not an identifier — the "one ID, two places" defect the
         * analytics lane untangled was a single ID that had to be unique, and
         * duplicating it broke reporting. Duplicating a sentence breaks nothing
         * and keeps two pages independently retractable.
         */
        'product_authentic_text' => '100% authentic',

        // Checkout, reassurance block (partials/checkout/reassurance.blade.php).
        // Pre-existing key; the only thing that changes is that the owner can
        // now reach it.
        'reassure_auth_text' => '100% authentic K-beauty',

        // The announcement strip (partials/announcement.blade.php). That file
        // is currently included by no layout and no page — its own header says
        // so — but the claim is one include line away from every page in the
        // shop, and it goes through the same box as the rest.
        'anno_authentic_text' => '100% authentic K-beauty',
    ];

    /**
     * The claim's current wording, or null if the owner has cleared it.
     *
     * An unknown key is a programming error rather than an empty claim, so it
     * is not silently swallowed: the key must be one of CLAIMS.
     */
    public static function text(SettingsService $settings, string $key): ?string
    {
        if (! array_key_exists($key, self::CLAIMS)) {
            throw new \InvalidArgumentException('Unknown trust claim: ' . $key);
        }

        $value = trim((string) $settings->get($key, self::CLAIMS[$key]));

        return $value === '' ? null : $value;
    }

    /**
     * The same question for a template that has no $settings in scope.
     */
    public static function get(string $key): ?string
    {
        return self::text(app(SettingsService::class), $key);
    }
}
