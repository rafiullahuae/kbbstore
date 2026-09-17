<?php

declare(strict_types=1);

namespace App\Support;

/**
 * `_yoast_wpseo_*` post-meta from the WordPress export, into `products.seo`.
 *
 * The mapping, in one place and nowhere else, for the same reason
 * App\Support\ProductSeo exists: this store has already shipped a write path
 * that wrote `seo_json` while every reader read `seo`, and a second copy of a
 * key name is how that happens.
 *
 * ── THE TABLE ───────────────────────────────────────────────────────────────
 *
 * Yoast writes about twenty keys per post. FIVE of them have a home in this
 * application. The rest are listed below as explicitly UNMAPPED rather than
 * left unmentioned, because "we did not import it" and "we forgot it exists"
 * look identical in a codebase and only one of them is a decision.
 *
 *   MAPPED
 *   _yoast_wpseo_title                  -> seo.title
 *   _yoast_wpseo_metadesc               -> seo.desc
 *   _yoast_wpseo_canonical              -> seo.canonical
 *   _yoast_wpseo_opengraph-image        -> seo.og_image
 *   _yoast_wpseo_meta-robots-noindex    -> seo.noindex   (see below: '1' only)
 *
 *   NOT MAPPED, AND WHY
 *   _yoast_wpseo_focuskw                Nothing in this application reads a
 *                                       focus keyphrase. Storing it would put a
 *                                       value in the database that no page, no
 *                                       crawler and no screen can observe —
 *                                       precisely the defect this codebase has
 *                                       spent twenty packages removing. It is
 *                                       reported as skipped, not silently lost.
 *   _yoast_wpseo_linkdex                Yoast's own SEO score, 0-100.
 *   _yoast_wpseo_content_score          Yoast's own readability score. Both are
 *                                       artefacts of the plugin's analysis of a
 *                                       page that no longer exists.
 *   _yoast_wpseo_opengraph-title        App\Support\Seo derives og:title and
 *   _yoast_wpseo_opengraph-description  og:description FROM the page's resolved
 *   _yoast_wpseo_twitter-title          title and description, and the Twitter
 *   _yoast_wpseo_twitter-description    card from the Open Graph tags. There is
 *   _yoast_wpseo_twitter-image          no separate field to put these in, and
 *                                       inventing one would mean a control the
 *                                       editor cannot show and the owner cannot
 *                                       correct.
 *   _yoast_wpseo_meta-robots-nofollow   This engine emits no per-page nofollow
 *   _yoast_wpseo_meta-robots-adv        and no noarchive/nosnippet directives.
 *                                       A product page that needs one is a
 *                                       decision for the owner, not a value to
 *                                       carry across silently.
 *   _yoast_wpseo_bctitle                Breadcrumb title. This app builds its
 *                                       BreadcrumbList from the product's own
 *                                       name and category path.
 *   _yoast_wpseo_schema_page_type       Schema type overrides. Seo::render()
 *   _yoast_wpseo_schema_article_type    emits Product for a product page and
 *                                       Article for a post, decided by the page
 *                                       rather than by a stored string.
 *   _yoast_wpseo_primary_product_cat    The primary category. It has a real
 *                                       home — products.category_id — but that
 *                                       column belongs to the product import,
 *                                       which already writes it from the
 *                                       category columns. Two writers for one
 *                                       column is how the last one to run wins.
 *   _yoast_wpseo_estimated-reading-time App\Support\ReadingTime computes it.
 *   _yoast_wpseo_wordproof_timestamp    Not a thing this store does.
 *
 * ── THE THREE RULES THAT ARE NOT OBVIOUS ────────────────────────────────────
 *
 * 1. NOINDEX IS TRISTATE IN YOAST AND BOOLEAN HERE. `meta-robots-noindex` is
 *    '1' for noindex, '2' for "index, overriding the site default", and '' or
 *    absent for "use the site default". Only '1' writes anything. '2' and ''
 *    must NOT write `noindex: false`, because ProductSeo::normalise() drops a
 *    false anyway and, more to the point, an explicit "index" is not a value
 *    this schema has — it is the absence of one. Getting this backwards
 *    deindexes a catalogue.
 *
 * 2. AN EMPTY STRING IS NOT A VALUE. Yoast writes `_yoast_wpseo_metadesc` as ''
 *    for a product the operator never touched, on most rows in a real export.
 *    Treating that as a description would write '' into `seo.desc`, and on this
 *    storefront an empty description does NOT fall through to the sitewide
 *    default — App\Support\Seo emits no description tag at all. So a blank
 *    Yoast field would silently strip the search snippet off every product it
 *    touched. Blank is absent, here and in the importer.
 *
 * 3. TOKENS ARE CARRIED ACROSS VERBATIM. A Yoast title is frequently
 *    `%%title%% %%page%% %%sep%% %%sitename%%`. Those resolve —
 *    App\Services\Seo\TitleTemplate handles the `%%…%%` syntax precisely
 *    because the per-product panel emits it — so they are stored as written
 *    rather than expanded at import time. Expanding them would freeze this
 *    store's name into 671 rows.
 */
final class YoastSeo
{
    /** Yoast post-meta key => the key `products.seo` is read by. */
    public const MAPPED = [
        '_yoast_wpseo_title' => 'title',
        '_yoast_wpseo_metadesc' => 'desc',
        '_yoast_wpseo_canonical' => 'canonical',
        '_yoast_wpseo_opengraph-image' => 'og_image',
        '_yoast_wpseo_meta-robots-noindex' => 'noindex',
    ];

    /**
     * Yoast keys this application deliberately has no home for. Named so the
     * importer can report "seen and skipped" instead of saying nothing.
     *
     * @var list<string>
     */
    public const UNMAPPED = [
        '_yoast_wpseo_focuskw',
        '_yoast_wpseo_linkdex',
        '_yoast_wpseo_content_score',
        '_yoast_wpseo_opengraph-title',
        '_yoast_wpseo_opengraph-description',
        '_yoast_wpseo_opengraph-image-id',
        '_yoast_wpseo_twitter-title',
        '_yoast_wpseo_twitter-description',
        '_yoast_wpseo_twitter-image',
        '_yoast_wpseo_twitter-image-id',
        '_yoast_wpseo_meta-robots-nofollow',
        '_yoast_wpseo_meta-robots-adv',
        '_yoast_wpseo_bctitle',
        '_yoast_wpseo_schema_page_type',
        '_yoast_wpseo_schema_article_type',
        '_yoast_wpseo_primary_product_cat',
        '_yoast_wpseo_estimated-reading-time-minutes',
        '_yoast_wpseo_wordproof_timestamp',
        '_yoast_wpseo_is_cornerstone',
    ];

    /**
     * The `seo` keys this importer is allowed to write. `og_image` and the rest
     * of ProductSeo::PUBLISHED_KEYS minus nothing — listed separately only so a
     * reader can see at a glance that the importer writes no key the storefront
     * does not read.
     *
     * @var list<string>
     */
    public const WRITES = ['title', 'desc', 'canonical', 'og_image', 'noindex'];

    /**
     * One export row's Yoast columns, as the `seo` fragment they mean.
     *
     * A key is present in the result ONLY when the source really carried a
     * usable value for it. Absent column, empty column and a noindex of '2' all
     * produce no key at all, so a caller merging this over a stored blob cannot
     * blank something the source never spoke about.
     *
     * Accepts the meta keys with or without the `_yoast_wpseo_` prefix, because
     * WordPress exporters disagree about whether to keep it: a `wp db export`
     * of `postmeta` keeps the full key, while the CSV exporters most stores
     * actually use emit a column headed `yoast_wpseo_metadesc` or even
     * `metadesc`. Matching all three costs nothing and is the difference
     * between an import that works on the owner's file and one that reports 671
     * rows with nothing in them.
     *
     * @param  array<string, string|null>  $cells  the row, keyed by column name
     * @return array<string, mixed>  a fragment of the `seo` column
     */
    public static function fragment(array $cells): array
    {
        $out = [];

        foreach (self::MAPPED as $meta => $key) {
            $value = self::pick($cells, $meta);

            if ($value === null) {
                continue;
            }

            if ($key === 'noindex') {
                // Rule 1. Only an explicit '1' means anything; '2' is Yoast's
                // "index this", which is the absence of a value here.
                if ($value === '1') {
                    $out['noindex'] = true;
                }

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Which unmapped Yoast keys this row actually carried a value for.
     *
     * The importer reports these per run so the owner can see that a focus
     * keyphrase was present and deliberately not kept, rather than wondering
     * later whether the import missed it.
     *
     * @param  array<string, string|null>  $cells
     * @return list<string>
     */
    public static function skipped(array $cells): array
    {
        $out = [];

        foreach (self::UNMAPPED as $meta) {
            if (self::pick($cells, $meta) !== null) {
                $out[] = $meta;
            }
        }

        return $out;
    }

    /**
     * Whether this row carries any Yoast column at all, mapped or not.
     *
     * It has to accept the SAME three spellings fragment() reads, or the
     * importer refuses the very files this class advertises: a `metadesc`
     * column carries no "yoast" substring, so a substring test alone would
     * reject the bare-prefix export while fragment() would have read it
     * perfectly. Checked against the known key list instead.
     *
     * It answers false for a row of nothing but an id, which is the "wrong file
     * entirely" case the importer rejects on. A REAL all-blank row is not that:
     * a CSV row carries every header the file declares, so a product whose SEO
     * tab was never opened still arrives with `_yoast_wpseo_metadesc => ''` and
     * is recognised here, then recorded as unchanged.
     */
    public static function looksLikeYoast(array $cells): bool
    {
        foreach (array_keys($cells) as $column) {
            $column = strtolower(trim((string) $column));

            if (str_contains($column, 'yoast_wpseo')) {
                return true;
            }

            foreach ([...array_keys(self::MAPPED), ...self::UNMAPPED] as $meta) {
                if ($column === (preg_replace('/^_?yoast_wpseo_/', '', $meta) ?? $meta)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Merge a fragment onto what is already stored.
     *
     * DEFAULT: THE OWNER'S TYPING WINS. A value already in `products.seo` was
     * put there by someone using this admin, after the WordPress export was
     * taken — the export is the older document by definition. Overwriting it
     * would silently undo work, and an importer that can undo work is one the
     * owner will not run twice.
     *
     * $overwrite reverses that for the case where the export IS the newer
     * document: a store still being edited in WordPress while this port is
     * built. It is a per-run choice, and the report says which way it ran.
     *
     * Returned through ProductSeo::normalise() so the stored shape is the same
     * shape the editor writes — bools really bool, blanks dropped, and null
     * rather than an empty array, which is what every reader's
     * `is_array($product->seo)` test expects.
     *
     * @param  array<string, mixed>  $fragment
     * @return array<string, mixed>|null
     */
    public static function merge(mixed $stored, array $fragment, bool $overwrite = false): ?array
    {
        $current = is_array($stored) ? $stored : [];

        $merged = $overwrite
            ? array_merge($current, $fragment)
            // Not array_merge the other way round: a stored key whose value is
            // null or '' must still count as "the owner has been here", and
            // + only fills keys that are genuinely absent.
            : $current + $fragment;

        return ProductSeo::normalise($merged);
    }

    /**
     * A column's value, or null when it is absent or blank (rule 2).
     *
     * @param  array<string, string|null>  $cells
     */
    private static function pick(array $cells, string $meta): ?string
    {
        $bare = preg_replace('/^_?yoast_wpseo_/', '', $meta) ?? $meta;

        foreach ([$meta, ltrim($meta, '_'), $bare] as $candidate) {
            foreach ($cells as $column => $value) {
                if (strcasecmp(trim((string) $column), $candidate) !== 0) {
                    continue;
                }

                $value = trim((string) $value);

                return $value === '' ? null : $value;
            }
        }

        return null;
    }
}
