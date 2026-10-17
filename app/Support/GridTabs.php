<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\GridSection;

/**
 * "Product tabs" — a Grid section shown as tabs.                   (Lane HTB)
 *
 * The owner: "i need Tabs section on homepage like different tabs in one
 * section, like Best Sellers, Trending K-Beauty, New Arrivals etc. We need to
 * be able to add new tabs with title and products selection query via
 * categories, brands etc and also manual section, and also the sorting inside
 * that section. also i need option as carousel or grid." — "Options A and B
 * are final. give us also the align on left or center." — "need also for
 * arabic too with proper translation option available while creating".
 *
 * A MODE OF A GRID SECTION, NOT A NEW SECTION TYPE (docs/lane-ht/PLAN.md §2).
 * A row of `grid_sections` whose `tabs` column holds at least one tab draws
 * as tabs; NULL draws the ordinary grid it always drew. Layout (grid or
 * carousel per device, columns, cards in view, arrows, counts), the card
 * template, the order on Appearance → Homepage and the Laptop/Phone switches
 * are the grid section's own and are not repeated here.
 *
 * THIS CLASS IS THE ONE STATEMENT OF WHAT A TAB MAY HOLD. clean() is run on
 * every write (with the ids checked against their tables) and again, without
 * a query, on every read — so a row edited by hand in the database is held to
 * the same allowlist as one the console saved. Every select stores one of its
 * own options or the default; titles are plain text, ≤ 40 characters, and are
 * printed escaped; a link is scheme-checked or dropped.
 */
final class GridTabs
{
    /** The most tabs one section may hold. */
    public const MAX = 6;

    /** A tab title, in either language. */
    public const TITLE_MAX = 40;

    /** The section's eyebrow, heading and sub-heading in Arabic. */
    public const TEXT_MAX = 190;

    /** The two designs the owner picked from the previews: "Options A and B are final." */
    public const STYLES = [
        'a' => 'A — underlined words',
        'b' => 'B — pill buttons',
    ];

    /** "give us also the align on left or center". Start is the right-hand side on /ar. */
    public const ALIGN = [
        'center' => 'Centre',
        'left' => 'Left (right on the Arabic shop)',
    ];

    /** Where one tab's products come from — GridSection::SOURCES, worded for a tab. */
    public const SOURCES = [
        'bestsellers' => 'Best sellers',
        'trending' => 'Trending this week',
        'newest' => 'New arrivals — newest first',
        'onsale' => 'On sale',
        'featured' => 'Featured',
        'brand' => 'One brand',
        'category' => 'One category',
        'query' => 'Brands and categories, mixed',
        'manual' => 'Products I pick myself',
    ];

    /** The sources whose name IS their order: no sort control. */
    public const FIXED_ORDER = ['bestsellers', 'trending', 'newest', 'onsale', 'featured'];

    /** A hand-picked list keeps the owner's own order unless he picks a sort. */
    public const MANUAL_ORDER = 'manual';

    /** @return array<string, string> the sort options a tab of this source offers */
    public static function sortsFor(string $source): array
    {
        if (in_array($source, self::FIXED_ORDER, true)) {
            return [];
        }

        return $source === 'manual'
            ? [self::MANUAL_ORDER => 'My own order'] + ProductSource::SORTS
            : ProductSource::SORTS;
    }

    /**
     * The `tabs` column, cleaned — or null for "not a tabs section".
     *
     * `$check` is true on a write: ids are kept only when they name a row
     * (ProductSource::existing(), one SELECT per table, none for an empty
     * list). False on a read, which must cost no query: an id that names no
     * row there simply returns no products.
     *
     * @return array{eyebrow: string, eyebrow_ar: string, heading_ar: string, sub_ar: string, view_all_ar: string, align: string, list: list<array<string, mixed>>}|null
     */
    public static function clean(mixed $raw, bool $check = false): ?array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return null;
        }

        $list = [];

        foreach (array_values(is_array($raw['list'] ?? null) ? $raw['list'] : []) as $one) {
            $tab = is_array($one) ? self::cleanTab($one, $check) : null;

            if ($tab !== null) {
                $list[] = $tab;
            }

            if (count($list) >= self::MAX) {
                break;
            }
        }

        if ($list === []) {
            return null;
        }

        $align = (string) ($raw['align'] ?? '');

        return self::cleanWords($raw) + [
            'align' => isset(self::ALIGN[$align]) ? $align : 'center',
            'list' => $list,
        ];
    }

    /**
     * Lane GAR — the section's own words, tabs ON OR OFF.
     *
     * The owner, with a screenshot of the Arabic homepage: "this heading on the
     * homepage is not translated, please do". The section was an ordinary grid
     * (tabs off), and the Arabic twins of its heading lived only inside tabs
     * mode: clean() answers null for a section with no tab, so the words went
     * with it. They are the same five keys in the same column either way;
     * this reads them without asking for a tab.
     *
     * @return array{eyebrow: string, eyebrow_ar: string, heading_ar: string, sub_ar: string, view_all_ar: string}
     */
    public static function cleanWords(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        $raw = is_array($raw) ? $raw : [];

        return [
            'eyebrow' => self::text($raw['eyebrow'] ?? '', self::TEXT_MAX),
            'eyebrow_ar' => self::text($raw['eyebrow_ar'] ?? '', self::TEXT_MAX),
            'heading_ar' => self::text($raw['heading_ar'] ?? '', self::TEXT_MAX),
            'sub_ar' => self::text($raw['sub_ar'] ?? '', 255),
            'view_all_ar' => self::text($raw['view_all_ar'] ?? '', 120),
        ];
    }

    /** The section's words as the shop reads them: cleaned, no query, tabs or not. */
    public static function words(GridSection $section): array
    {
        return self::cleanWords($section->getAttribute('tabs'));
    }

    /**
     * What the `tabs` column stores on a write. A section with tabs stores
     * clean(). One with NO tab but words typed stores the words with an EMPTY
     * list -- which of() still reads as "not a tabs section", so the ordinary
     * grid stays the ordinary grid and keeps its Arabic. Nothing at all is NULL.
     */
    public static function stored(mixed $raw, bool $check = false): ?array
    {
        $tabs = self::clean($raw, $check);

        if ($tabs !== null) {
            return $tabs;
        }

        $words = self::cleanWords($raw);

        return implode('', $words) === '' ? null : $words + ['align' => 'center', 'list' => []];
    }

    /** One of STYLES, or the default. */
    public static function style(mixed $raw): string
    {
        $raw = is_string($raw) ? $raw : '';

        return isset(self::STYLES[$raw]) ? $raw : 'a';
    }

    /** @return array<string, mixed>|null a tab with no title in English is not a tab */
    private static function cleanTab(array $t, bool $check): ?array
    {
        $title = self::text($t['title'] ?? '', self::TITLE_MAX);

        if ($title === '') {
            return null;
        }

        $source = (string) ($t['source'] ?? '');
        $source = isset(self::SOURCES[$source]) ? $source : 'bestsellers';

        $sorts = self::sortsFor($source);
        $sort = (string) ($t['sort'] ?? '');
        $sort = $sorts === [] ? '' : (isset($sorts[$sort]) ? $sort : array_key_first($sorts));

        $brand = $source === 'brand' ? max(0, (int) (is_scalar($t['brand_id'] ?? null) ? $t['brand_id'] : 0)) : 0;
        $cat = $source === 'category' ? max(0, (int) (is_scalar($t['category_id'] ?? null) ? $t['category_id'] : 0)) : 0;
        $q = $source === 'query' ? ProductSource::clean(is_array($t['query'] ?? null) ? $t['query'] : []) : ['brands' => [], 'cats' => [], 'stock' => false];
        $manual = $source === 'manual' ? ProductSource::ids($t['manual_ids'] ?? [], ProductSource::CAP_PICKS) : [];

        if ($check) {
            $brand = $brand > 0 ? (ProductSource::existing('brands', [$brand])[0] ?? 0) : 0;
            $cat = $cat > 0 ? (ProductSource::existing('categories', [$cat])[0] ?? 0) : 0;
            $q['brands'] = ProductSource::existing('brands', $q['brands']);
            $q['cats'] = ProductSource::existing('categories', $q['cats']);
            $manual = ProductSource::existing('products', $manual);
        }

        $url = mb_substr(trim(is_string($t['url'] ?? null) ? $t['url'] : ''), 0, 500);

        return [
            'title' => $title,
            'title_ar' => self::text($t['title_ar'] ?? '', self::TITLE_MAX),
            'source' => $source,
            'brand_id' => $brand,
            'category_id' => $cat,
            'children' => in_array($source, ['category', 'query'], true) && filter_var($t['children'] ?? false, FILTER_VALIDATE_BOOL),
            'query' => ['brands' => $q['brands'], 'cats' => $q['cats'], 'stock' => (bool) $q['stock']],
            'manual_ids' => $manual,
            'sort' => $sort,
            // Refused => stored empty => no button. SafeUrl::href() with '' as
            // the refusal, the rule GridSections::viewAllHref() states.
            'url' => $url !== '' ? SafeUrl::href($url, '') : '',
        ];
    }

    /**
     * The spec string GridSections fetches and caches a tab's pool under — the
     * SAME format GridSections::pool() builds, so a tab and an ordinary grid
     * asking for the same selection share one query.
     */
    public static function spec(array $tab): string
    {
        $src = (string) $tab['source'];
        $sort = (string) $tab['sort'];
        $kids = ! empty($tab['children']) ? '1' : '0';

        return match ($src) {
            'brand' => $sort === 'bestselling'
                ? 'brand|'.(int) $tab['brand_id'].'|0|0|'
                : 'query|0|0|0||'.ProductSource::spec(['brands' => [(int) $tab['brand_id']], 'sort' => $sort]),
            'category' => $sort === 'bestselling'
                ? 'category|0|'.(int) $tab['category_id'].'|'.$kids.'|'
                : 'query|0|0|'.$kids.'||'.ProductSource::spec(['cats' => [(int) $tab['category_id']], 'sort' => $sort]),
            'query' => 'query|0|0|'.$kids.'||'.ProductSource::spec([
                'brands' => $tab['query']['brands'] ?? [], 'cats' => $tab['query']['cats'] ?? [],
                'sort' => $sort, 'stock' => ! empty($tab['query']['stock']),
            ]),
            // A sorted hand-picked list carries a sixth part; the owner's own
            // order is the five-part spec an ordinary manual grid already uses.
            'manual' => 'manual|0|0|0|'.implode(',', array_map('intval', (array) $tab['manual_ids']))
                .($sort !== self::MANUAL_ORDER ? '|'.ProductSource::spec(['sort' => $sort]) : ''),
            default => $src.'|0|0|0|',
        };
    }

    /** The section's tabs as the shop reads them: cleaned, no query. */
    public static function of(GridSection $section): ?array
    {
        return self::clean($section->getAttribute('tabs'));
    }

    /**
     * Arabic when the page is Arabic and the owner typed one; the English
     * otherwise — never a blank tab or heading.
     */
    public static function pick(string $english, string $arabic): string
    {
        return $arabic !== '' && Locale::current() === 'ar' ? $arabic : $english;
    }

    /**
     * A SECTION word (eyebrow, heading, sub-heading, "View all") as the page
     * prints it, the same in both modes. English page: the English, untouched.
     * /ar: the owner's Arabic twin when he typed one, else the shop's own
     * Arabic for a shipped English phrase (ArabicWording::of -- "Recommended
     * for you" is wording.cpage_00), else his English as typed.
     */
    public static function say(string $english, string $arabic): string
    {
        if (Locale::current() !== 'ar') {
            return $english;
        }

        return $arabic !== '' ? $arabic : ArabicWording::of($english);
    }

    /**
     * A tab's "View all" address for the page being drawn: the stored, already
     * scheme-checked address, re-checked, then given the /ar prefix on the
     * Arabic shop (Url::to leaves another site's address alone).
     */
    public static function href(string $stored): string
    {
        $safe = SafeUrl::href($stored, '');

        return $safe === '' ? '' : Url::to($safe);
    }

    /** Plain text: no tags, no control characters, one line, at most $max. */
    private static function text(mixed $raw, int $max): string
    {
        $s = is_scalar($raw) ? (string) $raw : '';
        $s = strip_tags($s);
        $s = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);

        return mb_substr(trim($s), 0, $max);
    }
}
