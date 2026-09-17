<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The WooCommerce-era flat category addresses the navigation still carried,
 * and the one transformation that is safe to apply to them.
 *
 * ── WHAT WAS BROKEN ─────────────────────────────────────────────────────────
 *
 * kbeautybliss.com served its category archives at the site root — /toners/,
 * /sunscreens/, /cleansing-oils/ — because that is what its WooCommerce
 * permalink settings produced. This application serves them at
 * /product-category/{path}/ (URL Contract U-03), and nothing was ever taught
 * the difference. The menu rows seeded by
 * 2026_09_09_070000_fix_kbeautybliss_menu_structure kept the old shape, so
 * fifteen addresses in the header and the mobile drawer pointed at a URL
 * shape this application does not serve.
 *
 * They did not 404 in any way a reader could follow, either. routes/
 * kbb-brands-blog.php ends in `/{slug}/` — the single-segment catch-all that
 * serves a BLOG POST — so every one of them fell through to
 * PageController@post, which looked for an article called "toners", found
 * none, and 404'd. A shopper clicking "Toners" in the menu got the
 * not-found page; the application spent the request looking in the wrong
 * table entirely.
 *
 * ── WHY THE SLUG IS PRESERVED AND NOT REMAPPED ──────────────────────────────
 *
 * The obvious-looking repair — point /cleansing-oils/ at whichever category
 * the shop actually has today — is wrong here, and the reason is timing.
 *
 * The only categories on a freshly migrated database are the six placeholders
 * from DemoCatalogueSeeder (`cleansers`, `toners`, `serums`, `moisturisers`,
 * `sunscreens`, `masks`), which 2026_08_27_100000_seed_demo_catalogue runs on
 * EVERY install, production included. They are explicitly placeholders: the
 * real taxonomy arrives later, by WordPress import, carrying the live site's
 * own slugs — the very slugs in the list below.
 *
 * So a migration that resolved /cleansing-oils/ against the categories table
 * at apply time would, on a server where the import has not yet run, bake in
 * `cleansers` — and then the import would deliver the real `cleansing-oils`
 * category and the menu would go on pointing at a placeholder, permanently
 * and invisibly. Preserving the slug has the opposite failure mode, and it is
 * the benign one: the link 404s honestly until the category arrives, and
 * starts working by itself the moment it does, with nobody having to
 * remember to come back and fix it.
 *
 * CategoryPath::resolve() makes this better still. A leaf slug whose category
 * is nested under a parent does not 404 — it 301s to the canonical nested
 * path. So /product-category/cleansing-oils/ keeps working even if the
 * imported category turns out to live at skincare/cleansers/cleansing-oils.
 *
 * Categories the shop genuinely does not have are therefore REPORTED rather
 * than guessed at — see the migration, which prints them at apply time.
 */
final class LegacyCategoryUrls
{
    /**
     * Every flat category path the navigation was seeded with.
     *
     * This is the recognition list, and its exactness is what makes the
     * migration safe to re-run: a row whose URL is not one of these has
     * either been repaired already or been edited by the owner in Mega Menu,
     * and either way it is left alone.
     *
     * `/face-cleansers/` appears only in MenuDemo::tree(), as the parent of
     * Cleansing Oils and Face Washes. It is in the list because MenuDemo tops
     * up the mobile drawer when demo content is switched on, so it can reach
     * a real shopper.
     *
     * NOT in this list, and deliberately: /super-sale/,
     * /everything-under-54-aed/, /new-in/ and /best-sellers/ are flat root
     * URLs too, but they are COLLECTIONS with their own registered routes
     * (CollectionController) and they answer 200 today. /korean-skincare-brands/
     * and /skincare-guide/ likewise. Rewriting those would break them.
     *
     * @var list<string>
     */
    public const PATHS = [
        '/skincare/',
        '/face-cleansers/',
        '/cleansing-oils/',
        '/face-washes/',
        '/exfoliators/',
        '/toners/',
        '/face-serums/',
        '/eye-care/',
        '/face-masks/',
        '/moisturizers/',
        '/lip-care/',
        '/sunscreens/',
        '/hair-care/',
        '/skincare-sets/',
        '/beauty-devices/',
    ];

    /** Is this exactly one of the legacy flat category paths? */
    public static function isLegacy(string $url): bool
    {
        return in_array(self::normalise($url), self::PATHS, true);
    }

    /**
     * The address the same category lives at in this application.
     *
     * Slug preserved, shape corrected — see the note above for why that is
     * the whole transformation.
     */
    public static function toCategoryPath(string $url): string
    {
        return '/product-category/' . trim(self::normalise($url), '/') . '/';
    }

    /** The category slug a legacy path names. */
    public static function slugOf(string $url): string
    {
        return trim(self::normalise($url), '/');
    }

    /**
     * Leading and trailing slash, so a row stored as `toners` or `/toners`
     * is recognised as the same address as `/toners/`.
     */
    private static function normalise(string $url): string
    {
        $url = trim($url);

        if ($url === '' || str_contains($url, '://')) {
            return $url;
        }

        // A query string or fragment means this is not a bare category path.
        if (str_contains($url, '?') || str_contains($url, '#')) {
            return $url;
        }

        return '/' . trim($url, '/') . '/';
    }
}
