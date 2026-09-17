<?php

declare(strict_types=1);

namespace App\Support;

/**
 * WHICH YOAST WAS IN USE — answered by the export, not by the owner's memory.
 *
 * ── THE QUESTION THIS CLOSES ────────────────────────────────────────────────
 *
 * Phase 12 leaves the Yoast importer at "[~]" with one thing outstanding, and
 * records it as an owner question rather than a developer one: *which Yoast
 * tier was actually in use — free, Premium, or Premium plus the WooCommerce SEO
 * add-on* — because that decides whether the export carries product-schema data
 * at all.
 *
 * It is not an owner question. Each tier writes post meta the others do not,
 * and the export is a list of exactly those keys. A file that contains
 * `_yoast_wpseo_focuskeywords` was written by a site running Premium, whatever
 * anybody remembers about the subscription; a file that contains
 * `wpseo_global_identifier_values` was written by a site running the
 * WooCommerce SEO add-on. So the answer is in the owner's hands already and has
 * been the whole time — nothing was reading it out.
 *
 * ── WHAT THIS CLASS IS FOR ─────────────────────────────────────────────────
 *
 * Naming every `_yoast_wpseo_*` (and `wpseo_*`) key this research found, which
 * tier writes it, and what this application does with it: imports it, has
 * deliberately no home for it, or — the category that did not exist before —
 * HAS A HOME FOR IT AND IS NOT READING IT.
 *
 * The run then reports one line per key with the number of rows carrying it,
 * which turns "we think it was the free one?" into a paragraph an owner reads
 * once:
 *
 *     671 rows  _yoast_wpseo_metadesc                    free        imported
 *     412 rows  _yoast_wpseo_opengraph-image             free        imported
 *     671 rows  _yoast_wpseo_focuskw                     free        no home here
 *     183 rows  _yoast_wpseo_focuskeywords               PREMIUM     no home here
 *     214 rows  wpseo_global_identifier_values           WOO ADD-ON  HAS A HOME, NOT READ
 *
 * Line four settles the tier. Line five is worth more than the rest put
 * together, and is the subject of the next section.
 *
 * ── THE FINDING: THE GTIN BLOCKER IS AN IMPORT GAP, NOT A DATA GAP ─────────
 *
 * Phase 12's structured-data item ends "Still open: GTIN and variant-level
 * offers — genuinely blocked, no GTIN/barcode column exists anywhere in the
 * schema and there's no existing source of truth for it."
 *
 * Both halves of that have since stopped being true, and the second half is
 * this class's business. The WooCommerce SEO add-on stores a product's global
 * identifiers — GTIN-8, GTIN-12/UPC, GTIN-13/EAN, GTIN-14/ITF-14, ISBN and MPN
 * — in ONE post meta key, `wpseo_global_identifier_values`, holding a map. If
 * the shop ran that add-on and filled those boxes in, the export IS the source
 * of truth the plan says does not exist, and `products.gtin` (added since, by
 * 2026_10_05_add_product_editor_columns) is the column it goes in.
 *
 * TWO TRAPS IN THAT KEY, and they are why it has been invisible.
 *
 *   1. IT DOES NOT START WITH `_yoast_`. It is `wpseo_global_identifier_values`
 *      — no leading underscore, no `yoast`. App\Support\YoastSeo::looksLikeYoast()
 *      tests for the substring `yoast_wpseo`, so this column does not look like
 *      Yoast to the importer at all, and YoastSeo::UNMAPPED does not list it,
 *      so skipped() cannot report it either. An export whose only extra content
 *      is the barcodes therefore imports "successfully" and says nothing.
 *
 *   2. IT IS A MAP, NOT A STRING. WordPress stores it serialized; a CSV
 *      exporter emits either the PHP serialization or JSON. So even a reader
 *      that found the column would get `a:1:{s:6:"gtin13";s:13:"...";}` rather
 *      than a barcode. gtinFrom() below unpicks both shapes.
 *
 * Variations carry their own, under `wpseo_variation_global_identifiers_values`
 * (note the plural `identifiers`, which the product key does not have — that
 * asymmetry is Yoast's, not a typo here).
 *
 * ── WHAT THIS CLASS DELIBERATELY DOES NOT DO ───────────────────────────────
 *
 * IT DOES NOT WRITE. Not to `products.seo`, not to `products.gtin`. The
 * importer's entity classes are another lane's this round, and more to the
 * point a barcode is the field Google MATCHES PRODUCTS ON — a wrong one
 * attaches this shop's price and stock to somebody else's product, which is
 * worse than having none. So this reports what is there and validates what it
 * would be, and the decision to import it stays the owner's. The exact
 * anchor+replacement that turns the report into an import is in
 * docs/FX-YOAST-TIER-CENSUS.md.
 *
 * IT DOES NOT GUESS. A key nobody has documented comes back as tier `unknown`
 * and is reported as unknown. "We did not import it" and "we never heard of
 * it" look identical in a database and only one of them is a decision — the
 * same rule App\Support\YoastSeo's UNMAPPED list is built on, extended to the
 * keys that list does not have.
 *
 * ── SOURCES ────────────────────────────────────────────────────────────────
 *
 * The free tier's key list was read out of Yoast's own `inc/class-wpseo-meta.php`
 * ($meta_prefix `_yoast_wpseo_`, plus the `$social_networks` × `$social_fields`
 * cross-product that builds the opengraph-/twitter- four), not from a blog post.
 * The WooCommerce add-on's two identifier keys were read out of WooCommerce's
 * own Google Listings & Ads integration for it
 * (`src/Integration/YoastWooCommerceSeo.php`), which reads them to populate a
 * Merchant Center feed and is therefore the closest thing to a specification
 * that exists outside the paid plugin.
 */
final class YoastTiers
{
    public const FREE = 'free';

    public const PREMIUM = 'premium';

    public const WOO = 'woocommerce-seo';

    public const UNKNOWN = 'unknown';

    /** Imported into `products.seo` today. */
    public const USE_IMPORTED = 'imported';

    /** Seen, and deliberately given no home. */
    public const USE_NO_HOME = 'no-home';

    /**
     * There IS a column for this and nothing reads it. The category that makes
     * this class worth writing.
     */
    public const USE_UNREAD = 'has-a-home-unread';

    /**
     * Every key this research could attribute, with the tier that writes it and
     * what this application currently does with it.
     *
     * Ordered tier by tier rather than alphabetically, because the reason to
     * read this table is to answer the tier question.
     *
     * @var array<string, array{tier: string, use: string, note: string}>
     */
    public const KEYS = [
        /* ── Yoast SEO, free ────────────────────────────────────────────── */
        '_yoast_wpseo_title' => [
            'tier' => self::FREE, 'use' => self::USE_IMPORTED,
            'note' => 'the SEO title template, imported into seo.title',
        ],
        '_yoast_wpseo_metadesc' => [
            'tier' => self::FREE, 'use' => self::USE_IMPORTED,
            'note' => 'the meta description, imported into seo.desc',
        ],
        '_yoast_wpseo_canonical' => [
            'tier' => self::FREE, 'use' => self::USE_IMPORTED,
            'note' => 'the canonical override, imported into seo.canonical',
        ],
        '_yoast_wpseo_opengraph-image' => [
            'tier' => self::FREE, 'use' => self::USE_IMPORTED,
            'note' => 'the share image, imported into seo.og_image',
        ],
        '_yoast_wpseo_meta-robots-noindex' => [
            'tier' => self::FREE, 'use' => self::USE_IMPORTED,
            'note' => "noindex, imported into seo.noindex — only a literal '1' means anything",
        ],
        '_yoast_wpseo_focuskw' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => 'the focus keyphrase; no page, screen or crawler here can observe one',
        ],
        '_yoast_wpseo_linkdex' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => "Yoast's own SEO score for a page that no longer exists",
        ],
        '_yoast_wpseo_content_score' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => "Yoast's own readability score for a page that no longer exists",
        ],
        /*
         * These three are FREE keys that App\Support\YoastSeo::UNMAPPED does
         * not list — so an export carrying them is currently neither imported
         * nor reported. Not a defect in that class so much as a gap in the
         * table it was built from; naming them here is the whole point of
         * having a second, wider table.
         */
        '_yoast_wpseo_inclusive_language_score' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => "Yoast's inclusive-language score; not in the importer's own table either",
        ],
        '_yoast_wpseo_seo_title_score' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => "Yoast's title-length score; not in the importer's own table either",
        ],
        '_yoast_wpseo_meta_description_score' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => "Yoast's description-length score; not in the importer's own table either",
        ],
        '_yoast_wpseo_is_cornerstone' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => 'cornerstone flag; this engine has no cornerstone concept on a product',
        ],
        '_yoast_wpseo_meta-robots-nofollow' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => 'per-page nofollow; this engine emits none',
        ],
        '_yoast_wpseo_meta-robots-adv' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => 'noarchive/nosnippet/noimageindex; this engine emits none',
        ],
        '_yoast_wpseo_bctitle' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => "breadcrumb title; the trail here is built from the product's own name",
        ],
        '_yoast_wpseo_opengraph-title' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => 'og:title override; derived here from the resolved page title',
        ],
        '_yoast_wpseo_opengraph-description' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => 'og:description override; derived here from the resolved description',
        ],
        '_yoast_wpseo_opengraph-image-id' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => 'the attachment id behind the share image; meaningless without that media library',
        ],
        '_yoast_wpseo_twitter-title' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => 'Twitter card title; derived here from the Open Graph tags',
        ],
        '_yoast_wpseo_twitter-description' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => 'Twitter card description; derived here from the Open Graph tags',
        ],
        '_yoast_wpseo_twitter-image' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => 'Twitter card image; derived here from the Open Graph tags',
        ],
        '_yoast_wpseo_twitter-image-id' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => 'the attachment id behind the Twitter image',
        ],
        '_yoast_wpseo_schema_page_type' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => 'schema type override; decided here by what the page is',
        ],
        '_yoast_wpseo_schema_article_type' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => 'article subtype override; decided here by what the page is',
        ],
        '_yoast_wpseo_estimated-reading-time-minutes' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => 'reading time; App\\Support\\ReadingTime computes it from the text',
        ],
        '_yoast_wpseo_wordproof_timestamp' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => 'WordProof blockchain timestamp; not a thing this store does',
        ],
        '_yoast_wpseo_primary_product_cat' => [
            'tier' => self::FREE, 'use' => self::USE_NO_HOME,
            'note' => 'the primary category; products.category_id has one writer already and two is how the last one wins',
        ],

        /* ── Yoast SEO Premium ──────────────────────────────────────────── */
        /*
         * THE TIER TELLS. Neither key exists on a site running the free
         * plugin — the editor that writes them is the Premium metabox — so one
         * row carrying either is a positive answer to the question Phase 12
         * could not answer. Their absence is weaker evidence than their
         * presence: a Premium site whose operator never added a second
         * keyphrase writes neither.
         */
        '_yoast_wpseo_focuskeywords' => [
            'tier' => self::PREMIUM, 'use' => self::USE_NO_HOME,
            'note' => 'related keyphrases, a Premium-only field, stored as JSON',
        ],
        '_yoast_wpseo_keywordsynonyms' => [
            'tier' => self::PREMIUM, 'use' => self::USE_NO_HOME,
            'note' => 'keyphrase synonyms, a Premium-only field, stored as JSON',
        ],
        /*
         * `redirect` is REGISTERED by the free plugin (it is in the `advanced`
         * group of class-wpseo-meta.php) and WRITTEN by Premium's redirect
         * manager. So it is a Premium hint and not a Premium proof, and is
         * labelled as one rather than being allowed to settle the question on
         * its own.
         */
        '_yoast_wpseo_redirect' => [
            'tier' => self::PREMIUM, 'use' => self::USE_UNREAD,
            'note' => "a slug redirect Premium's redirect manager wrote; this shop HAS a redirects table and an admin for it",
        ],

        /* ── Yoast WooCommerce SEO add-on ───────────────────────────────── */
        'wpseo_global_identifier_values' => [
            'tier' => self::WOO, 'use' => self::USE_UNREAD,
            'note' => 'GTIN-8/12/13/14, ISBN and MPN in one map — products.gtin exists and nothing imports this',
        ],
        'wpseo_variation_global_identifiers_values' => [
            'tier' => self::WOO, 'use' => self::USE_UNREAD,
            'note' => "the same identifiers per variation — product_variants has no gtin column yet",
        ],
    ];

    /**
     * The identifier sub-keys inside a global-identifiers map, in the order
     * a GTIN should be preferred from them.
     *
     * Longest first is NOT the order. WooCommerce's own Google Listings & Ads
     * integration reads isbn, gtin8, gtin12, gtin13, gtin14 and takes the first
     * non-empty; the order barely matters because a product realistically
     * carries one of them, and matching a shipping implementation is worth more
     * than inventing a better one. `mpn` is excluded deliberately: a
     * manufacturer part number is not a GTIN, has no check digit, and putting
     * one in `products.gtin` would publish a confident wrong identifier.
     *
     * @var list<string>
     */
    public const IDENTIFIER_KEYS = ['isbn', 'gtin8', 'gtin12', 'gtin13', 'gtin14'];

    /**
     * The keys whose presence PROVES Premium, as against hinting at it.
     *
     * Only the two the Premium metabox is the sole writer of. See verdict().
     *
     * @var list<string>
     */
    public const PREMIUM_PROOF = ['_yoast_wpseo_focuskeywords', '_yoast_wpseo_keywordsynonyms'];

    /**
     * Which known keys this row carries a value for.
     *
     * @param  array<string, string|null>  $cells
     * @return list<string>  meta keys, in KEYS order
     */
    public static function present(array $cells): array
    {
        $out = [];

        foreach (array_keys(self::KEYS) as $meta) {
            if (self::value($cells, $meta) !== null) {
                $out[] = $meta;
            }
        }

        return $out;
    }

    /**
     * Columns on this row that look like Yoast and that this table cannot name.
     *
     * The honesty half. A key nobody documented is reported as unknown rather
     * than passed over, because a silent drop and a considered one are the same
     * bytes on disk.
     *
     * @param  array<string, string|null>  $cells
     * @return list<string>  the column headings, as the file spells them
     */
    public static function unrecognised(array $cells): array
    {
        $known = [];

        foreach (array_keys(self::KEYS) as $meta) {
            foreach (self::spellings($meta) as $spelling) {
                $known[strtolower($spelling)] = true;
            }
        }

        $out = [];

        foreach (array_keys($cells) as $column) {
            $name = strtolower(trim((string) $column));

            if ($name === '' || isset($known[$name])) {
                continue;
            }

            // `wpseo` catches both families: the `_yoast_wpseo_` post meta and
            // the add-on's unprefixed `wpseo_` keys.
            if (! str_contains($name, 'wpseo')) {
                continue;
            }

            if (self::value($cells, (string) $column) === null) {
                continue;
            }

            if (! in_array((string) $column, $out, true)) {
                $out[] = (string) $column;
            }
        }

        return $out;
    }

    /**
     * The report line for one key — one stable sentence, so EntityReport's
     * note counting turns N rows carrying the key into "N × <this line>".
     *
     * That counting is the whole census and it needs no new report channel and
     * no run-level state: the importer emits this per row, and the run's notes
     * section comes out as a table of key, tier, disposition and row count.
     */
    public static function line(string $meta): string
    {
        $entry = self::KEYS[$meta] ?? null;

        if ($entry === null) {
            return $meta.' — an unrecognised wpseo column: this export carries it and nothing here has '
                .'a name for it. Not imported. Worth saying out loud, because a key nobody has heard of '
                .'and a key somebody decided to drop look identical afterwards.';
        }

        return $meta.' — '.self::tierLabel($entry['tier']).' — '.$entry['note'].' — '
            .self::dispositionLabel($entry['use']);
    }

    /**
     * What the keys seen across a whole export say about the tier.
     *
     * Presence is proof; absence is not. A shop on Premium whose operator never
     * opened the extra boxes writes no Premium key at all, so "no Premium
     * evidence" is reported as exactly that and not as "the free tier" — the
     * difference matters because the consequence of the two is different. If
     * the answer is "no evidence either way" then the export carries nothing
     * those tiers would have added, which is itself the answer to the question
     * Phase 12 actually asked: *does the export carry product-schema data at
     * all.* No is a usable answer. "Probably free" is not.
     *
     * @param  list<string>  $keysSeen  meta keys observed anywhere in the file
     * @return array{free: bool, premium: bool, premium_hint: bool, woocommerce: bool, gtin_source: bool, summary: string}
     */
    public static function verdict(array $keysSeen): array
    {
        $free = false;
        $woo = false;

        foreach ($keysSeen as $meta) {
            $tier = self::KEYS[$meta]['tier'] ?? null;

            if ($tier === self::FREE) {
                $free = true;
            } elseif ($tier === self::WOO) {
                $woo = true;
            }
        }

        /*
         * PROOF, NOT TIER. Both Premium-tier keys in the table are not equally
         * good evidence and treating them as one flag loses the distinction:
         * `focuskeywords` and `keywordsynonyms` can only have been written by
         * the Premium metabox, while `_yoast_wpseo_redirect` is REGISTERED by
         * the free plugin and merely written by Premium's redirect manager. A
         * verdict that says "Premium was in use" on the strength of a key the
         * free plugin also declares is the kind of confident wrong answer this
         * whole class exists to replace.
         */
        $premium = array_intersect($keysSeen, self::PREMIUM_PROOF) !== [];
        $premiumHint = ! $premium && in_array('_yoast_wpseo_redirect', $keysSeen, true);

        $gtinSource = in_array('wpseo_global_identifier_values', $keysSeen, true)
            || in_array('wpseo_variation_global_identifiers_values', $keysSeen, true);

        $parts = [];

        if ($woo) {
            $parts[] = 'the WooCommerce SEO add-on was in use — the export carries product identifiers';
        }

        if ($premium) {
            $parts[] = 'Yoast SEO Premium was in use — the export carries Premium-only fields';
        } elseif ($premiumHint) {
            $parts[] = "a Premium redirect was found, which HINTS at Premium without proving it — the "
                ."free plugin declares that key and Premium's redirect manager is what fills it";
        }

        if ($free && ! $premium && ! $woo) {
            $parts[] = 'only fields the FREE tier writes are present; nothing in this export needs '
                .'Premium or the WooCommerce add-on to explain it';
        }

        if ($parts === []) {
            $parts[] = 'no Yoast key of any tier carried a value — this export has no SEO data in it, '
                .'which is an answer and not a failure';
        }

        if ($gtinSource) {
            $parts[] = 'AND IT CARRIES BARCODES: products.gtin exists and nothing imports them yet';
        }

        return [
            'free' => $free,
            'premium' => $premium,
            'premium_hint' => $premiumHint,
            'woocommerce' => $woo,
            'gtin_source' => $gtinSource,
            'summary' => implode('. ', $parts).'.',
        ];
    }

    /**
     * A valid GTIN out of this row's WooCommerce SEO identifier map, or null.
     *
     * NULL RATHER THAN A BEST GUESS, at every step. The map is absent, or it is
     * not a map, or it holds only an MPN, or the number in it fails its own
     * check digit — all of those come back null, because the failure mode being
     * avoided is not "no barcode", it is "somebody else's barcode". Google
     * matches products on this field: a transposed pair of digits does not make
     * a worse listing, it makes a listing for a different product.
     *
     * TWO ENCODINGS, because WordPress stores the map serialized and CSV
     * exporters disagree about what to do with that. `wp db export` of postmeta
     * keeps PHP's serialization; the exporters most stores actually use emit
     * JSON. Both are read; anything else is null.
     *
     * unserialize() is called with `allowed_classes: false`, so a crafted export
     * cannot instantiate anything — the file is the owner's, but "the owner's
     * file" is exactly the trust level at which object injection happens.
     *
     * @param  array<string, string|null>  $cells
     */
    public static function gtinFrom(array $cells): ?string
    {
        $raw = self::value($cells, 'wpseo_global_identifier_values');

        if ($raw === null) {
            return null;
        }

        $map = json_decode($raw, true);

        if (! is_array($map)) {
            $map = @unserialize($raw, ['allowed_classes' => false]);
        }

        if (! is_array($map)) {
            return null;
        }

        foreach (self::IDENTIFIER_KEYS as $key) {
            foreach ($map as $name => $value) {
                if (strcasecmp(trim((string) $name), $key) !== 0) {
                    continue;
                }

                $candidate = Gtin::normalise(is_scalar($value) ? (string) $value : null);

                if ($candidate !== null && Gtin::isValid($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /** Human wording for a tier, used in the report line. */
    public static function tierLabel(string $tier): string
    {
        return match ($tier) {
            self::FREE => 'Yoast SEO (free)',
            self::PREMIUM => 'Yoast SEO PREMIUM',
            self::WOO => 'Yoast WooCommerce SEO ADD-ON',
            default => 'unattributed',
        };
    }

    /** Human wording for what this application does with a key. */
    public static function dispositionLabel(string $use): string
    {
        return match ($use) {
            self::USE_IMPORTED => 'imported',
            self::USE_NO_HOME => 'NOT imported: this application has no home for it',
            self::USE_UNREAD => 'NOT imported, AND THERE IS SOMEWHERE FOR IT TO GO — a decision, not a gap in the schema',
            default => 'undecided',
        };
    }

    /**
     * The spellings of a meta key a real export might use as a column heading.
     *
     * The `_yoast_wpseo_` family arrives three ways and App\Support\YoastSeo
     * already accepts all three; the add-on's `wpseo_` keys have no prefix to
     * strip, so stripping one anyway would invent a column called
     * `global_identifier_values` that no exporter writes.
     *
     * @return list<string>
     */
    private static function spellings(string $meta): array
    {
        if (! str_starts_with($meta, '_yoast_wpseo_')) {
            return [$meta];
        }

        return [$meta, ltrim($meta, '_'), (string) preg_replace('/^_?yoast_wpseo_/', '', $meta)];
    }

    /**
     * A column's value, or null when it is absent or blank.
     *
     * Blank is absent, the same rule App\Support\YoastSeo works to: Yoast
     * writes '' for a product whose SEO tab was never opened, which is most
     * rows in a real export, and counting those as "present" would report every
     * key on every row and say nothing.
     *
     * @param  array<string, string|null>  $cells
     */
    private static function value(array $cells, string $meta): ?string
    {
        foreach (self::spellings($meta) as $candidate) {
            foreach ($cells as $column => $value) {
                if (strcasecmp(trim((string) $column), $candidate) !== 0) {
                    continue;
                }

                $value = trim((string) $value);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }
}
