<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Color;
use App\Support\Locale;
use App\Support\RoutedPages;
use App\Support\SafeUrl;
use App\Support\Url;

/**
 * Pages → Page banners: a promo picture with a thin strip of ticks beneath it,
 * for the shop's CUSTOM pages — the content pages and the curated listings like
 * /super-sale/. (Lane SS)
 *
 * The owner: "i need a custom banner including image and thin strip with
 * content, only image, and beneath, and give facility to update this banner
 * content. such custom banners we will need for custom pages. not for products
 * or category." Category and brand pages already have their own header banner
 * (App\Support\PageBanner); this is deliberately a different thing, offered
 * only to the pages pageKeys() lists.
 *
 * ── ONE SETTING, TWO HALVES ─────────────────────────────────────────────────
 *
 * `page_banners` holds a small LIBRARY of banners and an ASSIGNMENT of page
 * key → banner id. A page shows at most one banner; one banner can sit on any
 * number of pages. It is read through SettingsService, which is already loaded
 * on every storefront request, so drawing a banner costs no query at all — and
 * the cost does not move as the catalogue grows (PageBannersTest measures it).
 *
 * ── WHAT SHIPS ──────────────────────────────────────────────────────────────
 *
 * With nothing stored, DEFAULT applies: one banner, "Super Sale", with the
 * strip the owner described ("100% Authentic Products" / "Express Delivery all
 * over UAE") and NO picture, because nobody has uploaded one. No picture means
 * no <img> at all — never a broken icon.
 *
 * (Lane SP3) AND ON NO PAGE. The owner: "turned off the strip by default on
 * all pages, and allow to turn ON on any page from the edit panel on the
 * front-end". The assignment IS the page's "show the strip" switch — one
 * model, so this screen and the storefront panel cannot disagree — and it
 * ships empty. The banner itself stays in the library, ready to be switched on
 * (setPage()). Migration 2027_08_21_100000 empties a stored assignment the
 * same way and keeps every banner.
 *
 * ── RULE 5 ──────────────────────────────────────────────────────────────────
 *
 * sanitize() trusts nothing: colours must be #RRGGBB, numbers are clamped,
 * links are scheme-checked by SafeUrl, ids and page keys must be ones this
 * class knows. forPage() checks the scheme AGAIN at render, so a row written
 * by anything other than the admin endpoint still cannot become a
 * javascript: href. Text is escaped by the Blade echo; the only unescaped
 * output is the CSS and ICON constants below.
 */
final class PageBanners
{
    public const KEY = 'page_banners';

    public const MAX_BANNERS = 20;

    public const MAX_ITEMS = 6;

    public const MAX_TEXT = 80;

    /** Phone below this width, desktop above it — the storefront's own breakpoint. */
    public const BREAKPOINT = 900;

    /**
     * Number controls: [min, max, default, label].
     *
     * sh = strip height, fs = strip font size, ic = tick size; _d desktop, _m phone.
     */
    public const NUMBERS = [
        'sh_d' => [24, 90, 44, 'Strip height · desktop (px)'],
        'sh_m' => [24, 90, 36, 'Strip height · phone (px)'],
        'fs_d' => [10, 24, 15, 'Text size · desktop (px)'],
        'fs_m' => [9, 20, 12, 'Text size · phone (px)'],
        'ic_d' => [10, 30, 18, 'Tick size · desktop (px)'],
        'ic_m' => [10, 30, 15, 'Tick size · phone (px)'],
    ];

    /** Colour controls: [default, label]. */
    public const COLOURS = [
        'bg' => ['#C8336A', 'Strip colour'],
        'ink' => ['#FFFFFF', 'Text colour'],
        'icon' => ['#FFFFFF', 'Tick colour'],
    ];

    /** The strip's lines as the owner wrote them. */
    public const DEFAULT_ITEMS = ['100% Authentic Products', 'Express Delivery all over UAE', 'Free skincare consultation'];

    /**
     * Which device each default line shows on. The owner: "also include in the
     * strip for desktop only 'Free skincare consultation', in mobile two lines
     * are fine." (Lane PH)
     */
    public const DEFAULT_DEVICES = ['both', 'both', 'd'];

    /** A strip item's device choice: [value => label]. The first is the default. */
    public const DEVICES = ['both' => 'Desktop and phone', 'd' => 'Desktop only', 'm' => 'Phone only'];

    /** The listings a banner can sit on, by CollectionController key. */
    public const COLLECTIONS = [
        'super-sale' => ['Super Sale', '/super-sale/'],
        'new-in' => ['New In', '/new-in/'],
        'best-sellers' => ['Best Sellers', '/best-sellers/'],
        'under-54' => ['Everything under AED 54', '/everything-under-54-aed/'],
    ];

    /** A tick in a circle. currentColor, so the Tick colour control drives it. */
    public const ICON = '<svg class="kbb-pb-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
        .'<circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/>'
        .'<path d="m7.5 12.3 3 3 6-6.4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    /**
     * Every byte of CSS the banner prints. A constant (rule 5), printed once
     * per page that carries a banner and on no other page.
     *
     * The picture keeps its own shape: width 100%, height auto, and the
     * width/height attributes reserve the box before the bytes arrive. The text
     * is IN the owner's picture, so cropping it to a height would cut words.
     * The strip is a flex row spread evenly; if a phone is too narrow for the
     * items they wrap onto a second line rather than overflow — min-height,
     * not height, is what the control sets. No script measures anything.
     *
     * An item for one device only carries .kbb-pb-d or .kbb-pb-m and the last
     * two rules hide it on the other; an item for both carries no class, so a
     * strip of such items prints exactly what it printed before. (Lane PH)
     */
    public const CSS = '.kbb-pb{display:block;margin:0 0 4px}'
        .'.kbb-pb-img{display:block;line-height:0}'
        .'.kbb-pb-img img{display:block;width:100%;height:auto;max-width:100%}'
        .'.kbb-pb-strip{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-evenly;gap:4px 18px;margin:0;padding:4px 12px;list-style:none;'
        .'background:var(--pb-bg);color:var(--pb-ink);min-height:var(--pb-hm);font-size:var(--pb-fm);line-height:1.25;font-weight:600;box-sizing:border-box}'
        .'.kbb-pb-strip li{display:inline-flex;align-items:center;gap:.45em;margin:0;padding:0;min-width:0}'
        .'.kbb-pb-ic{flex:none;width:var(--pb-im);height:var(--pb-im);color:var(--pb-ic)}'
        .'@media (min-width:901px){.kbb-pb-strip{min-height:var(--pb-hd);font-size:var(--pb-fd);gap:4px 40px}.kbb-pb-ic{width:var(--pb-id);height:var(--pb-id)}}'
        .'@media (max-width:900px){.kbb-pb-strip .kbb-pb-d{display:none}}@media (min-width:901px){.kbb-pb-strip .kbb-pb-m{display:none}}'
        // The whole strip off on one device (2.60.396): kbb-pb-xd = not on a
        // laptop, kbb-pb-xm = not on a phone.
        .'@media (min-width:901px){.kbb-pb-strip.kbb-pb-xd{display:none}}@media (max-width:900px){.kbb-pb-strip.kbb-pb-xm{display:none}}';

    private ?array $memo = null;

    public function __construct(private SettingsService $settings) {}

    /** The shape that ships: one banner, on no page until he switches it on. */
    public static function defaults(): array
    {
        return [
            'banners' => [self::blank('super-sale', 'Super Sale')],
            // Off on every page (Lane SP3): he asked for it.
            'assign' => [],
        ];
    }

    /** A new banner with every field at its default. */
    public static function blank(string $id, string $name): array
    {
        $b = [
            'id' => $id,
            'name' => $name,
            'img_d' => '', 'img_m' => '',
            'w_d' => null, 'h_d' => null, 'w_m' => null, 'h_m' => null,
            'alt' => '',
            'link' => '',
            'strip' => true,
            // On laptop / on phone (2.60.396). The owner: "i need the strip
            // display control also, to turn off for desktop / mobile."
            'strip_d' => true, 'strip_m' => true,
            'items' => array_map(static fn (string $t, string $dev): array => ['en' => $t, 'ar' => '', 'dev' => $dev], self::DEFAULT_ITEMS, self::DEFAULT_DEVICES),
        ];

        foreach (self::COLOURS as $k => [$default]) {
            $b[$k] = $default;
        }
        foreach (self::NUMBERS as $k => [, , $default]) {
            $b[$k] = $default;
        }

        return $b;
    }

    /**
     * Every page a banner may sit on: key => [label, path].
     *
     * The four curated listings, then every content page the router actually
     * serves (RoutedPages asks the router, so a row with no route is not
     * offered). Product, category and brand pages are not here, by the
     * owner's word.
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function pageKeys(): array
    {
        $out = [];

        foreach (self::COLLECTIONS as $key => [$label, $path]) {
            $out['collection:'.$key] = [$label, $path];
        }

        foreach (RoutedPages::paths() as $slug => $path) {
            $out['page:'.$slug] = [ucwords(str_replace(['-', '_'], ' ', $slug)), $path];
        }

        return $out;
    }

    /** The stored library and assignment, or the shipped default. */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $raw = $this->settings->get(self::KEY, null);

        if (! is_array($raw)) {
            return $this->memo = self::defaults();
        }

        // Stored by sanitize(), but read defensively all the same: a value is
        // only ever drawn after it has been through the same checks again.
        [$clean] = self::sanitize($raw, false);

        return $this->memo = $clean;
    }

    public function forget(): void
    {
        $this->memo = null;
    }

    /**
     * What a storefront page draws, or null for nothing at all.
     *
     * Null when no banner is assigned, when the assigned id no longer exists,
     * or when the banner has neither a picture nor a strip with words in it —
     * so an empty banner adds not one byte to the page.
     */
    public function forPage(string $pageKey): ?array
    {
        $all = $this->all();
        $id = $all['assign'][$pageKey] ?? '';

        if ($id === '') {
            return null;
        }

        $banner = null;
        foreach ($all['banners'] as $b) {
            if ($b['id'] === $id) {
                $banner = $b;
                break;
            }
        }

        return $banner === null ? null : self::view($banner, Locale::current() === 'ar');
    }

    /** One banner resolved for rendering. Public so the tests can drive it. */
    public static function view(array $b, bool $arabic = false): ?array
    {
        $desk = self::picture((string) $b['img_d']);
        $phone = self::picture((string) $b['img_m']);

        // One picture serves both when only one was given.
        $img = null;
        if ($desk !== '' || $phone !== '') {
            $img = [
                'd' => $desk !== '' ? $desk : $phone,
                'm' => $phone !== '' ? $phone : $desk,
                'wd' => $desk !== '' ? $b['w_d'] : $b['w_m'],
                'hd' => $desk !== '' ? $b['h_d'] : $b['h_m'],
                'wm' => $phone !== '' ? $b['w_m'] : $b['w_d'],
                'hm' => $phone !== '' ? $b['h_m'] : $b['h_d'],
                'alt' => (string) $b['alt'],
                'href' => self::link((string) $b['link']),
            ];
            $img['two'] = $img['m'] !== $img['d'];
        }

        $items = [];
        $devs = [];
        $sd = (bool) ($b['strip_d'] ?? true);
        $sm = (bool) ($b['strip_m'] ?? true);
        if ($b['strip'] && ($sd || $sm)) {
            foreach ($b['items'] as $item) {
                $t = $arabic && $item['ar'] !== '' ? $item['ar'] : $item['en'];
                if ($t !== '') {
                    $items[] = $t;
                    $devs[] = isset(self::DEVICES[$item['dev'] ?? '']) ? $item['dev'] : 'both';
                }
            }
        }

        if ($img === null && $items === []) {
            return null;
        }

        $style = '--pb-bg:'.$b['bg'].';--pb-ink:'.$b['ink'].';--pb-ic:'.$b['icon']
            .';--pb-hd:'.$b['sh_d'].'px;--pb-hm:'.$b['sh_m'].'px'
            .';--pb-fd:'.$b['fs_d'].'px;--pb-fm:'.$b['fs_m'].'px'
            .';--pb-id:'.$b['ic_d'].'px;--pb-im:'.$b['ic_m'].'px';

        return [
            'id' => $b['id'],
            'img' => $img,
            'items' => $items,
            // Parallel to items: 'both', 'd' or 'm'. (Lane PH)
            'devs' => $devs,
            // A strip for one device only: one of two constant classes, or ''
            // so a strip shown on both prints exactly as it always did.
            'strip_cls' => $sd === $sm ? '' : ($sd ? ' kbb-pb-xm' : ' kbb-pb-xd'),
            'style' => $style,
            'media' => '(max-width: '.self::BREAKPOINT.'px)',
        ];
    }

    /** A picture address the page may print, or '' for none. */
    private static function picture(string $raw): string
    {
        // As the Media Library handed it over: Media::url() has already made
        // it the address this server serves the file at.
        return SafeUrl::src($raw);
    }

    /** A link the page may print, or '' for an unlinked picture. */
    private static function link(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        // A path on this shop: through Url::to() so the base path and the
        // shopper's language are applied, exactly as every internal link is.
        if (str_starts_with($raw, '/') && ! str_starts_with($raw, '//')) {
            return Url::to($raw);
        }

        $href = SafeUrl::href($raw, '');

        return preg_match('#^https?://#i', $href) === 1 ? $href : '';
    }

    /** Would link() print this? The save-time half of the same check. */
    public static function linkIsSafe(string $raw): bool
    {
        $raw = trim($raw);

        if ($raw === '') {
            return true;
        }
        if (strlen($raw) > 500 || preg_match('/[\x00-\x20\x7f"\'<>`\\\\]/', $raw) === 1) {
            return false;
        }

        return (str_starts_with($raw, '/') && ! str_starts_with($raw, '//'))
            || (preg_match('#^https?://[^/?\#@\s]#i', $raw) === 1 && SafeUrl::href($raw, '') !== '');
    }

    /**
     * Clean an incoming library + assignment.
     *
     * Returns [clean, rejected]. With $strict, anything wrong is REPORTED
     * (rejected is non-empty and the caller writes nothing — all or nothing,
     * as every other screen saves). Without it — the read path — anything
     * wrong is quietly replaced by its default, so a damaged row can never
     * take a page down.
     *
     * @return array{0: array{banners: list<array>, assign: array<string,string>}, 1: array<string,string>}
     */
    public static function sanitize(mixed $in, bool $strict = true): array
    {
        $rejected = [];
        $in = is_array($in) ? $in : [];
        $banners = [];
        $ids = [];

        $list = is_array($in['banners'] ?? null) ? array_values($in['banners']) : [];
        if (count($list) > self::MAX_BANNERS) {
            $rejected['banners'] = 'At most '.self::MAX_BANNERS.' banners';
            $list = array_slice($list, 0, self::MAX_BANNERS);
        }

        foreach ($list as $n => $row) {
            if (! is_array($row)) {
                $rejected["banners.$n"] = 'Not a banner';
                continue;
            }

            $id = (string) ($row['id'] ?? '');
            if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $id) !== 1 || isset($ids[$id])) {
                $rejected["banners.$n.id"] = 'Banner id';
                continue;
            }
            $ids[$id] = true;

            $name = self::text($row['name'] ?? '', 60);
            $b = self::blank($id, $name !== '' ? $name : 'Banner');

            foreach (['img_d', 'img_m'] as $k) {
                $v = trim((string) ($row[$k] ?? ''));
                if ($v !== '' && (strlen($v) > 500 || SafeUrl::src($v) === '' || preg_match('/[\x00-\x20"\'<>`\\\\]/', $v) === 1)) {
                    $rejected["banners.$n.$k"] = $k === 'img_d' ? 'Desktop picture' : 'Phone picture';
                    $v = '';
                }
                $b[$k] = $v;
            }

            foreach (['w_d', 'h_d', 'w_m', 'h_m'] as $k) {
                $v = $row[$k] ?? null;
                $b[$k] = is_numeric($v) && (int) $v >= 1 && (int) $v <= 10000 ? (int) $v : null;
            }

            $b['alt'] = self::text($row['alt'] ?? '', 160);

            $link = trim((string) ($row['link'] ?? ''));
            if (! self::linkIsSafe($link)) {
                $rejected["banners.$n.link"] = 'Link';
                $link = '';
            }
            $b['link'] = $link;

            $b['strip'] = filter_var($row['strip'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $b['strip_d'] = filter_var($row['strip_d'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $b['strip_m'] = filter_var($row['strip_m'] ?? true, FILTER_VALIDATE_BOOLEAN);

            $items = [];
            foreach (is_array($row['items'] ?? null) ? array_values($row['items']) : [] as $item) {
                $en = self::text(is_array($item) ? ($item['en'] ?? '') : $item, self::MAX_TEXT);
                $ar = self::text(is_array($item) ? ($item['ar'] ?? '') : '', self::MAX_TEXT);
                if ($en === '' && $ar === '') {
                    continue;
                }
                $dev = is_array($item) && is_string($item['dev'] ?? null) ? $item['dev'] : 'both';
                if (! isset(self::DEVICES[$dev])) {
                    if ($strict) {
                        $rejected["banners.$n.items"] = 'Strip item device';
                    }
                    $dev = 'both';
                }
                $items[] = ['en' => $en !== '' ? $en : $ar, 'ar' => $ar, 'dev' => $dev];
            }
            if (count($items) > self::MAX_ITEMS) {
                $rejected["banners.$n.items"] = 'At most '.self::MAX_ITEMS.' strip items';
                $items = array_slice($items, 0, self::MAX_ITEMS);
            }
            $b['items'] = $items;

            foreach (self::COLOURS as $k => [$default, $label]) {
                $v = strtoupper(trim((string) ($row[$k] ?? $default)));
                if (preg_match('/^#[0-9A-F]{6}$/', $v) !== 1 || ! Color::isValidHex($v)) {
                    $rejected["banners.$n.$k"] = $label;
                    $v = $default;
                }
                $b[$k] = $v;
            }

            foreach (self::NUMBERS as $k => [$min, $max, $default, $label]) {
                $v = $row[$k] ?? $default;
                if (! is_numeric($v) || (float) $v < $min || (float) $v > $max) {
                    $rejected["banners.$n.$k"] = $label;
                    $v = $default;
                }
                $b[$k] = (int) round((float) $v);
            }

            $banners[] = $b;
        }

        $assign = [];
        // The router is asked only on SAVE. The read path runs on every
        // storefront request, and walking the route table there would be work
        // a write has already done: it keeps any key of the right shape, and a
        // key no page asks for is simply never looked up.
        $known = $strict ? self::pageKeys() : null;
        foreach (is_array($in['assign'] ?? null) ? $in['assign'] : [] as $page => $id) {
            $page = (string) $page;
            $id = is_scalar($id) ? (string) $id : '';
            $ok = $known !== null ? isset($known[$page]) : preg_match('/^(collection|page):[a-z0-9_-]{1,60}$/', $page) === 1;
            if (! $ok) {
                if ($strict) {
                    $rejected["assign.$page"] = 'Unknown page';
                }
                continue;
            }
            if ($id === '') {
                continue;
            }
            if (! isset($ids[$id])) {
                if ($strict) {
                    $rejected["assign.$page"] = 'Banner for '.($known[$page][0] ?? $page);
                }
                continue;
            }
            $assign[$page] = $id;
        }

        return [['banners' => $banners, 'assign' => $assign], $strict ? $rejected : []];
    }

    /**
     * Save a library + assignment. All or nothing.
     *
     * @return array<string,string> what was refused; empty means written
     */
    public function save(mixed $in): array
    {
        [$clean, $rejected] = self::sanitize($in, true);

        if ($rejected !== []) {
            return $rejected;
        }

        $this->settings->set(self::KEY, $clean);
        $this->memo = null;

        return [];
    }

    /** One line of plain text: no tags, no control characters, clipped. */
    /**
     * (Lane SP3) Switch one page's strip on with a banner, or off with ''.
     * The storefront panel's "Show the strip". Only the assignment of this one
     * page moves; the library and every other page are written back exactly
     * as they were read, through the same all-or-nothing save().
     *
     * @return array<string,string> what was refused; empty means written
     */
    public function setPage(string $pageKey, string $bannerId, ?array $devices = null): array
    {
        if (! isset(self::pageKeys()[$pageKey])) {
            return ['key' => 'Unknown page'];
        }

        $this->memo = null;
        $all = $this->all();

        if ($bannerId === '') {
            unset($all['assign'][$pageKey]);
        } elseif (in_array($bannerId, array_column($all['banners'], 'id'), true)) {
            $all['assign'][$pageKey] = $bannerId;
            // (2.60.396) "On laptop" / "On phone" from the same panel. They
            // belong to the strip, so every page showing it follows.
            if ($devices !== null) {
                foreach ($all['banners'] as $n => $b) {
                    if ($b['id'] === $bannerId) {
                        $all['banners'][$n]['strip_d'] = (bool) $devices['d'];
                        $all['banners'][$n]['strip_m'] = (bool) $devices['m'];
                    }
                }
            }
        } else {
            return ['strip' => 'Which strip'];
        }

        return $this->save($all);
    }

    private static function text(mixed $v, int $max): string
    {
        $v = is_scalar($v) ? (string) $v : '';
        $v = strip_tags($v);
        $v = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);
        $v = trim((string) preg_replace('/\s+/u', ' ', $v));

        return mb_substr($v, 0, $max);
    }
}
