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
 *
 * 4. ...BUT ONLY FOUR OF YOAST'S TOKENS RESOLVE, AND THE REST GO SILENTLY.
 *    This is the half rule 3 did not say, and it is the one that damages
 *    titles.
 *
 *    App\Support\Seo::tokens() supplies exactly `title`, `sep`, `sitename`
 *    and `page`. Yoast ships several dozen — `%%primary_category%%`,
 *    `%%ct_product_cat%%`, `%%currentyear%%`, `%%pt_single%%`, `%%excerpt%%`,
 *    `%%cf_<field>%%`, the WooCommerce set — and a real store's SEO templates
 *    use them. TitleTemplate::render() DELETES any token it cannot resolve,
 *    deliberately, so that a misspelled `{sitname}` never reaches a browser
 *    tab as literal braces.
 *
 *    The two behaviours compose badly. Importing verbatim does not publish
 *    percent signs — it publishes a TRUNCATED SENTENCE, which is worse,
 *    because it looks like a title somebody wrote. Measured against this
 *    shop's own renderer:
 *
 *        Best %%ct_product_cat%% Cream        ->  "Best Cream"
 *        Buy %%title%% for %%currentyear%%    ->  "Buy Dokdo Toner for"
 *        %%wc_price%% only                    ->  "only"
 *        %%title%% %%sep%% %%primary_category%%  ->  "Dokdo Toner"
 *
 *    Nothing in the export is wrong and nothing in the renderer is wrong; the
 *    import is what puts one in front of the other. So the value is still
 *    stored verbatim — the alternative is expanding tokens at import time,
 *    which rule 3 rejects for good reason — and the run REPORTS it, through
 *    the adjusted() channel, with the template and what this shop will make of
 *    it. An owner approving an import can then see the four titles that will
 *    come out mangled and fix those four, instead of discovering them in
 *    Search Console a month later.
 *
 *    unresolvableTokens() and afterStripping() below are that report.
 */
final class YoastSeo
{
    /**
     * The `%%…%%` tokens this shop actually resolves.
     *
     * Exactly the keys App\Support\Seo::tokens() builds. Anything else is
     * deleted by TitleTemplate::render() on the way to the page, which is what
     * unresolvableTokens() exists to warn about. Kept as a list here rather
     * than read out of tokens() because that method is private, is built per
     * request out of a page context this class does not have, and is the wrong
     * shape to ask a static question of.
     *
     * If a token is ever added there, add it here — TokenParityTest fails
     * otherwise, so the two cannot drift silently.
     *
     * @var list<string>
     */
    public const RESOLVED_TOKENS = ['title', 'sep', 'sitename', 'page'];

    /** The `%%token%%` syntax, matching TitleTemplate's own definition. */
    private const TOKEN_RE = '/%%([A-Za-z][A-Za-z0-9_\-]*)%%/';

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
     * The unmapped Yoast keys this row carried, WITH the value each carried.
     *
     * skipped() answers which fields were present; this answers what was in
     * them. The report needs the value, not just the name: "a focus keyphrase
     * was dropped" is a fact the owner can do nothing with, and
     * "_yoast_wpseo_focuskw carried 'snail mucin serum' and was dropped" is one
     * they can paste somewhere. It is also what the discarded() channel is
     * specified to carry — before and after, both — and a `before` of the field
     * name repeated is the shape of a report that looks complete and says
     * nothing.
     *
     * @param  array<string, string|null>  $cells
     * @return array<string, string>  meta key => the value it held
     */
    public static function skippedWithValues(array $cells): array
    {
        $out = [];

        foreach (self::UNMAPPED as $meta) {
            $value = self::pick($cells, $meta);

            if ($value !== null) {
                $out[$meta] = $value;
            }
        }

        return $out;
    }

    /**
     * The `%%…%%` tokens in this value that this shop will DELETE rather than
     * resolve.
     *
     * Names are returned bare and de-duplicated, in the order they appear, so a
     * report line reads `%%ct_product_cat%%` once however many times the
     * template repeats it.
     *
     * @return list<string>
     */
    public static function unresolvableTokens(string $value): array
    {
        if (preg_match_all(self::TOKEN_RE, $value, $m) === 0) {
            return [];
        }

        $out = [];

        foreach ($m[1] as $name) {
            // Case-insensitively, because TitleTemplate::render() registers
            // both spellings of every token it knows and an operator's
            // %%SITENAME%% therefore resolves perfectly well.
            if (in_array(strtolower($name), self::RESOLVED_TOKENS, true)) {
                continue;
            }

            if (! in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * What is left of a template once the unresolvable tokens are removed.
     *
     * The `after` half of the adjusted() report. Deliberately NOT the fully
     * rendered title: rendering needs this store's name, its separator and the
     * product's own head, and a report line that has baked those in describes
     * one product rather than the template every product using it shares. The
     * resolvable tokens are therefore left standing, so the line shows exactly
     * what the import cost and nothing else —
     *
     *     before  Buy %%title%% for %%currentyear%%
     *     after   Buy %%title%% for
     *
     * — which is the dangling preposition the page will publish, with the part
     * that still works still visible.
     *
     * Whitespace is tidied the way TitleTemplate::tidy() tidies it, so the
     * `after` matches what the page does rather than leaving a double space
     * where the token was.
     */
    public static function afterStripping(string $value): string
    {
        $stripped = (string) preg_replace_callback(
            self::TOKEN_RE,
            static fn (array $m): string => in_array(strtolower($m[1]), self::RESOLVED_TOKENS, true) ? $m[0] : '',
            $value
        );

        return \App\Services\Seo\TitleTemplate::tidy($stripped);
    }

    /**
     * A column's value, or null when it is absent or blank (rule 2).
     *
     * @param  array<string, string|null>  $cells
     */
    private static function pick(array $cells, string $meta): ?string
    {
        /*
         * ── THE HYPHEN, WHICH COST THE OG IMAGE AND THE NOINDEX FLAG ────────
         *
         * Two of the five keys this class maps carry a HYPHEN --
         * `_yoast_wpseo_opengraph-image` and `_yoast_wpseo_meta-robots-noindex`
         * -- and CsvRowSource::normaliseHeader() rewrites every run of
         * non-alphanumerics in a header to a single underscore. So the column
         * this class was handed is `yoast_wpseo_opengraph_image` and the three
         * candidates it compared against all still had the hyphen in them. None
         * matched, on every row of every export, and the failure was invisible
         * from both ends: the fragment simply came back without those keys, and
         * the runner's ignored-column line named them among a dozen others.
         *
         * Measured on Lane GE's real export: `products.seo` came out as
         * {"title": …, "desc": …} with the og image in the file and nowhere
         * else. The noindex one is the expensive half -- a product the owner
         * had deliberately hidden from Google in Yoast would be published by
         * this shop, and nothing would have said so.
         *
         * The underscored spellings are ADDED to the candidate list rather than
         * replacing it, so every spelling that matched before still matches --
         * and the list moved into spellings() below because YoastTiers had the
         * identical blind spot in its own copy of it.
         */
        foreach (self::spellings($meta) as $candidate) {
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

    /**
     * Every column spelling a meta key may have arrived under.
     *
     * @return list<string>
     */
    private static function spellings(string $meta): array
    {
        $bare = preg_replace('/^_?yoast_wpseo_/', '', $meta) ?? $meta;
        $underscored = str_replace('-', '_', $meta);

        return array_values(array_unique([
            $meta,
            ltrim($meta, '_'),
            $bare,
            $underscored,
            ltrim($underscored, '_'),
            str_replace('-', '_', $bare),
        ]));
    }

    /**
     * The COLUMNS of this row that one of $metaKeys resolves to.
     *
     * ── WHY AN IMPORTER NEEDS THIS AND WHAT IT COST NOT TO HAVE IT ──────────
     *
     * SeoImporter reads its row with Row::all() rather than field by field,
     * because the keys are discovered from the file and not listed in advance.
     * Row only counts a column as read when something ASKS for it by name, so
     * every Yoast column looked unread to the runner — and the runner's
     * consolidated discard line therefore told the owner, in the one list
     * Phase 13 says he APPROVES, that his meta descriptions, his SEO titles and
     * his barcodes were "in the file and will not be in the database" while all
     * three were being written to `products`.
     *
     * A discard list with false entries in it is worse than a shorter one: the
     * owner cannot tell which of the seven names is the one that matters, so he
     * stops reading all seven. This hands the importer the columns a known key
     * actually resolved to, so it can say it read them.
     *
     * NOT "every column that looks like Yoast". A wpseo-looking column no table
     * in this application has ever heard of is genuinely unread, and it stays
     * in the consolidated line, which is the only place it is named at all.
     *
     * @param  array<string, string|null>  $cells
     * @param  list<string>  $metaKeys
     * @return list<string>
     */
    public static function recognisedColumns(array $cells, array $metaKeys): array
    {
        $out = [];

        foreach ($metaKeys as $meta) {
            foreach (self::spellings($meta) as $candidate) {
                foreach (array_keys($cells) as $column) {
                    if (strcasecmp(trim((string) $column), $candidate) === 0 && ! in_array($column, $out, true)) {
                        $out[] = (string) $column;
                    }
                }
            }
        }

        return $out;
    }
}
