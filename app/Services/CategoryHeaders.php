<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Support\Locale;
use App\Support\RichText;
use App\Support\TitleHeader;

/**
 * A category page's CUSTOM HEADER AREA: the Super Sale page's header (Pages →
 * Page header) and its banner and strip (Pages → Page banners), on one
 * category page, in place of the category's title header. (Lane CH)
 *
 * The owner: "AND in the same edit panel, we will have option to choose this
 * page with custom header area, so in that tab we will see the same features
 * as we have done for super sale page."
 *
 * ── THE SWITCH IS ON THE CATEGORY, THE LOOK IS IN ONE SETTING ───────────────
 *
 * Which header a category page draws is `categories.header_style.mode`: absent
 * is the title header, `custom` is this. The category row is already loaded
 * by the page, so a category that never switched pays NOTHING here -- not a
 * setting read, not a function call past the mode check.
 *
 * The look lives in `category_header` = ['pages' => ['category:<id>' => entry]],
 * read through SettingsService, which every storefront request has already
 * loaded: no query of its own, and the same cost for one custom category or a
 * hundred. An entry is
 *
 *     header     a PageHeaders bag, cleaned by PageHeaders::bag()
 *     banner     a PageBanners banner, cleaned by PageBanners::sanitize()
 *     banner_on  whether the banner shows
 *     banner_at  above | below the header area
 *
 * ── WHY A ROW OF ITS OWN, NOT `page_header` / `page_banners` ────────────────
 *
 * The SHAPES and the CLEANERS are those two classes', called rather than
 * copied, so a field either of them gains is a field this gains. The ROWS are
 * not shared because both screens save their whole map and their strict
 * cleaners refuse any key that is not a custom page: a `category:12` key in
 * `page_header` would make every save on Pages → Page header fail with
 * "Unknown page", and the read path's own pattern would drop it the next time
 * anyone looked. Switching back to the title header deletes nothing here, so
 * switching again brings the same look back.
 *
 * ── RULE 5 ──────────────────────────────────────────────────────────────────
 *
 * Everything is cleaned on save (strict: refused and named) and on read (each
 * bad value becomes its default). Picture and link addresses are checked by
 * SafeUrl inside those cleaners, again at render. The only raw output is the
 * CSS constant, PageHeaders::CSS and PageBanners::CSS / ICON.
 */
final class CategoryHeaders
{
    public const KEY = 'category_header';

    /** The most categories that may carry a custom header area of their own. */
    public const MAX = 400;

    /** Where the banner sits: [value => label]. The first is the default. */
    public const BANNER_AT = ['above' => 'Above the header area', 'below' => 'Below the header area'];

    /**
     * Every byte of CSS the area adds of its own: the room above it, matching
     * the space the shop's breadcrumb row has over a title header, and the
     * banner's bottom margin taken up by the header row's own gap. A constant.
     */
    public const CSS = '.kbb-chc{display:block;padding:14px 0 0}.kbb-chc>.wrap{display:block}'
        .'.kbb-chc>.kbb-pb{margin:0 0 12px}.kbb-chc>.wrap+.kbb-pb{margin:0 0 18px}'
        .'@media (max-width:900px){.kbb-chc{padding-top:8px}.kbb-chc>.kbb-pb{margin:0 0 8px}.kbb-chc>.wrap+.kbb-pb{margin:0 0 12px}}';

    /** @var array<string, mixed>|null */
    private ?array $raw = null;

    public function __construct(private SettingsService $settings, private PageHeaders $headers) {}

    public static function key(int $id): string
    {
        return 'category:'.$id;
    }

    /** The banner a category's area starts with: Super Sale's strip, no picture. */
    public static function blankBanner(int $id, string $name): array
    {
        return PageBanners::blank('category-'.$id, mb_substr($name !== '' ? $name : 'Category', 0, 60));
    }

    /**
     * The entry a category edits, cleaned -- its own when it has one, else the
     * global page-header look with the default banner. `own` says which.
     *
     * @return array{header: array, banner: array, banner_on: bool, banner_at: string, own: bool}
     */
    public function entryFor(int $id, string $name = ''): array
    {
        $raw = $this->stored()[self::key($id)] ?? null;

        if (! is_array($raw)) {
            return [
                'header' => $this->headers->all()['global'],
                'banner' => self::blankBanner($id, $name),
                'banner_on' => true,
                'banner_at' => 'above',
                'own' => false,
            ];
        }

        [$entry] = self::clean($raw, $id, $name, false);

        return $entry + ['own' => true];
    }

    /**
     * What a category page draws, or null for "the page as it is".
     *
     * Null unless the category's own switch says `custom`, so every other
     * category page renders exactly the bytes it rendered before.
     *
     * @return array{id:int, header: array{key:string, kind:string, bag:array, img:?array}, banner:?array, banner_at:string, heading:string, intro:string}|null
     */
    public function forCategory(Category $category, string $title): ?array
    {
        $style = TitleHeader::sanitizeStyle($category->getAttribute('header_style'));

        if (($style['mode'] ?? null) !== 'custom') {
            return null;
        }

        $id = (int) $category->getKey();
        $entry = $this->entryFor($id, (string) $category->getAttribute('name'));
        $english = Locale::segment() === '';

        return [
            'id' => $id,
            'header' => [
                'key' => self::key($id),
                'kind' => 'collection',
                'bag' => $entry['header'],
                'img' => PageHeaders::picture($entry['header']),
            ],
            'banner' => $entry['banner_on'] ? PageBanners::view($entry['banner'], ! $english) : null,
            'banner_at' => $entry['banner_at'],
            'heading' => self::heading($category, $title, $english),
            'intro' => self::intro($category, $english),
        ];
    }

    /** The name the area prints: the header title the owner typed (English), else the page's own. */
    public static function heading(Category $category, string $title, ?bool $english = null): string
    {
        $english ??= Locale::segment() === '';
        $own = $english ? trim(strip_tags((string) $category->getAttribute('header_title'))) : '';

        if ($own === '' || TitleHeader::isSwitchWord($own)) {
            $own = $title;
        }

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($own, ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 160);
    }

    /** The intro line: the header's description as one line of plain text. */
    public static function intro(Category $category, ?bool $english = null): string
    {
        $html = TitleHeader::descriptionOf($category, $english);
        $text = $html === '' ? '' : RichText::toText($html);

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, 300);
    }

    /**
     * Clean one entry. Returns [entry, rejected]; with $strict anything wrong
     * is named and the caller writes nothing.
     *
     * @return array{0: array{header: array, banner: array, banner_on: bool, banner_at: string}, 1: array<string,string>}
     */
    public static function clean(mixed $raw, int $id, string $name, bool $strict): array
    {
        $raw = is_array($raw) ? $raw : [];
        $rejected = [];

        $header = PageHeaders::bag($raw['header'] ?? null, 'header', $rejected);

        $banner = is_array($raw['banner'] ?? null) ? $raw['banner'] : self::blankBanner($id, $name);
        $banner['id'] = 'category-'.$id;
        $banner['name'] = is_string($banner['name'] ?? null) && trim($banner['name']) !== '' ? $banner['name'] : ($name !== '' ? $name : 'Category');
        [$lib, $bannerRejected] = PageBanners::sanitize(['banners' => [$banner], 'assign' => []], $strict);
        foreach ($bannerRejected as $k => $label) {
            $rejected['banner'.substr((string) $k, strlen('banners.0'))] = $label;
        }
        $banner = $lib['banners'][0] ?? self::blankBanner($id, $name);

        $on = filter_var($raw['banner_on'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($on === null) {
            $rejected['banner_on'] = 'Show the banner';
            $on = true;
        }

        $at = is_string($raw['banner_at'] ?? null) ? $raw['banner_at'] : 'above';
        if (! isset(self::BANNER_AT[$at])) {
            $rejected['banner_at'] = 'Where the banner sits';
            $at = 'above';
        }

        return [['header' => $header, 'banner' => $banner, 'banner_on' => $on, 'banner_at' => $at], $strict ? $rejected : []];
    }

    /**
     * Save one category's area. All or nothing.
     *
     * @return array<string,string> what was refused; empty means written
     */
    public function save(int $id, string $name, mixed $raw): array
    {
        [$entry, $rejected] = self::clean($raw, $id, $name, true);

        if ($rejected !== []) {
            return $rejected;
        }

        $all = $this->stored();
        $all[self::key($id)] = $entry;

        if (count($all) > self::MAX) {
            return ['pages' => 'At most '.self::MAX.' categories can have a custom header area'];
        }

        $this->settings->set(self::KEY, ['pages' => $all]);
        $this->raw = null;

        return [];
    }

    public function forget(): void
    {
        $this->raw = null;
    }

    /**
     * The stored entries by key, uncleaned -- each is cleaned when it is
     * read, so a page cleans only its own and never the whole map.
     *
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        if ($this->raw !== null) {
            return $this->raw;
        }

        $value = $this->settings->get(self::KEY, null);
        $pages = is_array($value) && is_array($value['pages'] ?? null) ? $value['pages'] : [];

        return $this->raw = array_filter(
            $pages,
            static fn ($v, $k): bool => is_array($v) && preg_match('/^category:[1-9]\d{0,9}$/', (string) $k) === 1,
            ARRAY_FILTER_USE_BOTH
        );
    }
}
