<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Support\Locale;
use App\Support\Money;
use App\Support\StoreTime;
use Illuminate\Support\Facades\DB;

/**
 * Free gifts -- Marketing -> Free gifts (Lane FG).
 *
 * The owner, October 2026: spend AED 100 and get 2 masks, AED 300 and get 3
 * masks and a mini, up to AED 2,000+ and 12 masks, 5 minis and 3 full-size
 * products. One band at a time, never cumulative.
 *
 * ── THE SERVER IS THE ONLY AUTHORITY ────────────────────────────────────────
 *
 * Gifts are NOT cart lines. Nothing a browser posts can add, remove or resize
 * one: every surface (the cart panel, the cart page, the checkout summary)
 * asks forTotals() with the totals CartService::totals() has just computed,
 * and Store\CheckoutController::place() asks the SAME method again with the
 * order's own totals and writes what it answers. A coupon added or removed, a
 * quantity changed, a line removed -- each of those already re-renders the
 * surfaces from fresh totals, so the gifts follow without any code of their own.
 *
 * ── WHAT COUNTS ─────────────────────────────────────────────────────────────
 *
 * basis(): the products' subtotal after the buy-together discount and the
 * coupon ("after_discounts", the shipped default), or before them. Never
 * delivery, never gift wrapping (both are added after totals() returns), never
 * the gifts themselves (they are not lines).
 *
 * A band "100 - 299" covers AED 100.00 up to AED 299.99: a band's maximum is
 * inclusive of its whole last dirham, so a coupon that leaves AED 299.50 does
 * not fall into a gap between two bands the owner wrote as touching.
 *
 * ── COST ───────────────────────────────────────────────────────────────────
 *
 * The configuration is two autoloaded settings rows, read off the settings map
 * SettingsRequestMemo already holds: NO query to know whether there is a gift.
 * Off, or a basket under the first band: nothing at all. A basket in a band:
 * ONE query for its products (their brands on a join), ONCE per request
 * however many surfaces draw the box (memoised on the request), and that one
 * however many gifts the band holds.
 */
final class FreeGifts
{
    /** The owner's settings: switch, display, texts, homepage section. */
    public const KEY = 'free_gifts';

    /** The bands and their gifts, compiled from the two tables on every save. */
    public const COMPILED = 'free_gifts_compiled';

    public const BASES = ['after_discounts', 'before_discounts'];

    public const COUNTRIES = ['all', 'AE'];

    public const MAX_QTY = 50;

    /**
     * Who sees the gifts (Lane FG2; the owner: "make sure it does not go live
     * without our checking completely"). off: nobody. testing: only the IPs on
     * `testers`, in the same autoloaded settings row. live: everyone.
     */
    public const MODES = ['off', 'testing', 'live'];

    /** At most this many IPs on the Testing list. */
    public const MAX_TESTERS = 50;

    /**
     * Gift types (Lane FG4, the owner 22 Oct): INTERNAL ONLY, the boxes on the
     * admin's range editor and the criteria they are counted against. Never
     * compiled, so the shop and the orders never see them. Order is the boxes'.
     */
    public const KINDS = ['mask', 'mini', 'full'];

    /** Each type's box on the admin: [box title, one, many]. */
    public const KIND_NAMES = [
        'mask' => ['Face masks', 'face mask', 'face masks'],
        'mini' => ['Mini products', 'mini product', 'mini products'],
        'full' => ['Full size products', 'full size product', 'full size products'],
    ];

    /** A criterion is 0 to this many units per type. */
    public const MAX_NEED = 50;

    /** Every text the shopper reads, in both languages. Settings, printed escaped. */
    public const TEXTS = [
        'title' => ['Free gifts for you', 'هدايا مجانية لكِ'],
        'free' => ['FREE', 'مجاناً'],
        'view_all' => ['View all', 'عرض الكل'],
        'hide' => ['Hide', 'إخفاء'],
        'line' => ['Free gifts (:count)', 'هدايا مجانية (:count)'],
        'tag' => ['Free gift', 'هدية مجانية'],
        // " · N gifts" after the band's wording. Arabic counts four ways
        // (هدية واحدة / هديتان / 3-10 هدايا / 11+ هدية), English two.
        'count_one' => ['1 gift', 'هدية واحدة'],
        'count_two' => [':count gifts', 'هديتان'],
        'count_few' => [':count gifts', ':count هدايا'],
        'count_many' => [':count gifts', ':count هدية'],
    ];

    /** The homepage section's data. No storefront section yet: the owner is choosing its design. */
    public const HOME = [
        'on' => false,
        'title_en' => 'Free gifts with every order', 'title_ar' => 'هدايا مجانية مع كل طلب',
        'sub_en' => 'The more you shop, the more you get.', 'sub_ar' => 'كلما تسوّقتِ أكثر، حصلتِ على المزيد.',
        'btn_en' => 'Shop now', 'btn_ar' => 'تسوّقي الآن',
        // Where the button goes (owner, 24 October: "link it to super-sale page
        // ... give option to change the link"): a path on this shop or an
        // http(s) address, checked by HomeSections::url() before it is an href.
        'url' => '/super-sale/',
        /*
         * The section's background (owner, 24 October: "a nice gradient color
         * scheme, but super light, and it should change continues inside our
         * branding pinkish colors. but super light opacity. same in mobile").
         * Shipped ON at what he asked for: a slowly moving gradient of three
         * brand pinks, washed to `strength` percent over white. Each colour is
         * a #RRGGBB or its default, `bg` one of BG_STYLES or its default, and
         * `strength` an integer clamped to 5..60 -- homeBg() is the one reader.
         */
        'bg' => 'flow', 'c1' => '#F7A8C4', 'c2' => '#FFB8C9', 'c3' => '#F4C2E0', 'strength' => 22,
        /*
         * The card's width on a computer, as a percentage of the site width
         * (owner, 24 October: "match the width of the section to the site
         * width ... give also to choose width as percentage ... by default
         * 100%"). 40..100; tablets and phones always use the full width.
         */
        'width' => 100,
    ];

    /** Background styles for the homepage section: `token => label`. */
    public const BG_STYLES = [
        'flow' => 'Moving gradient — the three colours drift slowly',
        'still' => 'Still gradient — the same colours, not moving',
        'white' => 'Plain white',
        'none' => 'None — the page’s own background shows through',
    ];

    /**
     * "Gift box look" (Lane FG5, the owner 22 Oct: "controls to hide / show
     * the brand names ... a little spacing between the names and the word
     * FREE ... the product title, if long, may fade from the end or go to a
     * 2nd line ... font size of the titles, sizes of the product thumbnails
     * ... a thin, super light separator line").
     *
     * Every default is today's look EXCEPT the two he asked for: `free_gap`
     * (16px of real space before FREE; it was the row's 8px gap) and
     * `titles` (two lines then "…"; it wrapped without limit).
     */
    public const LOOK = [
        'brand' => true,          // the brand line above each title
        'titles' => 'clamp2',     // LOOK_TITLES
        'free_gap' => 16,         // px between the title column and FREE
        'title_px' => 12,         // the title's font size
        'photo_list' => 28,       // a photo in the opened list
        'photo_row' => 36,        // a photo in the closed row
        'separator' => false,     // a hairline between the opened rows
        'row_gap' => 3,           // px between the opened rows
    ];

    public const LOOK_TITLES = ['clamp2', 'fade', 'wrap'];

    /** Each number's [min, max]. Out of range is clamped; not a number is the default. */
    public const LOOK_RANGES = [
        'free_gap' => [0, 40],
        'title_px' => [11, 16],
        'photo_list' => [22, 48],
        'photo_row' => [22, 48],
        'row_gap' => [0, 16],
    ];

    public const PRODUCT_COLUMNS = [
        'products.id', 'products.name', 'products.slug', 'products.sku', 'products.brand_id', 'products.image', 'products.type',
        'products.status', 'products.stock_status', 'products.manage_stock', 'products.stock',
        'products.price', 'products.sale_price', 'products.sale_starts_at', 'products.sale_ends_at',
        'products.gift_only', 'products.is_visible',
    ];

    private ?array $config = null;

    public function __construct(private SettingsService $settings) {}

    /** The owner's settings, every key present, every select one of its own options. */
    public function config(): array
    {
        return $this->config ??= $this->readConfig();
    }

    /**
     * config() read again from the settings row. The admin's writes use it:
     * a controller instance (and the FreeGifts it was given) can outlive one
     * request -- Laravel keeps it on the route -- so a memo from an earlier
     * request must never decide a later write (Lane FG2).
     */
    public function freshConfig(): array
    {
        $this->config = null;

        return $this->config();
    }

    private function readConfig(): array
    {
        $saved = $this->settings->get(self::KEY);
        $saved = is_array($saved) ? $saved : [];

        $texts = [];
        foreach (self::TEXTS as $key => [$en, $ar]) {
            $texts[$key . '_en'] = self::str($saved['texts'][$key . '_en'] ?? null, $en);
            $texts[$key . '_ar'] = self::str($saved['texts'][$key . '_ar'] ?? null, $ar);
        }

        $home = [];
        foreach (self::HOME as $key => $default) {
            $home[$key] = is_bool($default)
                ? (bool) ($saved['home'][$key] ?? $default)
                : (is_int($default) ? $default : self::str($saved['home'][$key] ?? null, $default));
        }
        $home = array_merge($home, self::homeBg($saved['home'] ?? []));

        $mode = self::modeOf($saved);

        return [
            'mode' => $mode,
            'on' => $mode !== 'off',
            'testers' => self::testersOf($saved),
            'show_value' => (bool) ($saved['show_value'] ?? false),
            'countries' => in_array($saved['countries'] ?? null, self::COUNTRIES, true) ? $saved['countries'] : 'all',
            'basis' => in_array($saved['basis'] ?? null, self::BASES, true) ? $saved['basis'] : 'after_discounts',
            'texts' => $texts,
            'home' => $home,
            'look' => self::lookOf($saved['look'] ?? null),
        ];
    }

    /**
     * A saved "Gift box look", every key present: a switch is a bool, a
     * select one of its own options or the default, a number an integer
     * clamped to its range (not a number at all is the default). The admin
     * saves through this too, so nothing else ever reaches the settings row.
     *
     * @return array{brand:bool,titles:string,free_gap:int,title_px:int,photo_list:int,photo_row:int,separator:bool,row_gap:int}
     */
    public static function lookOf(mixed $saved): array
    {
        $saved = is_array($saved) ? $saved : [];
        $look = self::LOOK;

        foreach (['brand', 'separator'] as $key) {
            if (array_key_exists($key, $saved)) {
                $bool = filter_var($saved[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $look[$key] = is_bool($bool) && ! is_array($saved[$key]) ? $bool : self::LOOK[$key];
            }
        }

        $look['titles'] = in_array($saved['titles'] ?? null, self::LOOK_TITLES, true) ? $saved['titles'] : self::LOOK['titles'];

        foreach (self::LOOK_RANGES as $key => [$min, $max]) {
            $v = $saved[$key] ?? null;
            $num = is_int($v) || is_float($v) || (is_string($v) && is_numeric(trim($v))) ? (float) $v : null;
            $look[$key] = $num !== null && is_finite($num)
                ? max($min, min($max, (int) round($num)))
                : self::LOOK[$key];
        }

        return $look;
    }

    /**
     * "rtl" when a text's first letter is Arabic or Hebrew, else "ltr" -- what
     * dir="auto" would decide, but as one of two constants. The one-line fade
     * needs it: a title is clipped at ITS OWN end. An Arabic name on an
     * Arabic page fades on the left; an English name there sits on the right
     * and ends in "…" when too long (a fade on its end-aligned side would
     * fade every short name). Without it the page's direction decides, and
     * "Black Rice Hyaluronic Toner" on /ar/ read "yaluronic Toner...".
     */
    public static function textDir(string $text): string
    {
        return preg_match('/^[^\p{L}]*[\p{Arabic}\p{Hebrew}]/u', $text) === 1 ? 'rtl' : 'ltr';
    }

    /**
     * The box's look as it reaches the page: fixed class names and integer
     * custom properties, nothing else. Both strings are built only from
     * constants and (int) casts, so no setting can put a character of its own
     * into the markup.
     *
     * @return array{class:string,style:string}
     */
    public static function lookAttrs(array $look): array
    {
        $look = self::lookOf($look);
        $class = ($look['brand'] ? '' : ' fgb-nobrand')
            . ['clamp2' => ' fgb-clamp2', 'fade' => ' fgb-fade', 'wrap' => ''][$look['titles']]
            . ($look['separator'] ? ' fgb-sep' : '');

        return [
            'class' => $class,
            'style' => '--fg-ts:' . (int) $look['title_px'] . 'px;--fg-pl:' . (int) $look['photo_list'] . 'px;--fg-pr:' . (int) $look['photo_row']
                . 'px;--fg-fg:' . (int) $look['free_gap'] . 'px;--fg-rg:' . (int) $look['row_gap'] . 'px',
        ];
    }

    /** Switched on at all (Testing or Live) -- the admin's meaning. Never decides what a shopper sees. */
    public function on(): bool
    {
        return $this->mode() !== 'off';
    }

    public function mode(): string
    {
        $saved = $this->settings->get(self::KEY);

        return self::modeOf(is_array($saved) ? $saved : []);
    }

    /** A saved mode, or the old switch read as one: `on` true is live, anything else off. */
    public static function modeOf(array $saved): string
    {
        if (in_array($saved['mode'] ?? null, self::MODES, true)) {
            return $saved['mode'];
        }

        return ! empty($saved['on']) ? 'live' : 'off';
    }

    /** @return list<array{ip:string,label:string}> valid, normalised, de-duplicated, at most MAX_TESTERS */
    public static function testersOf(array $saved): array
    {
        $out = [];

        foreach (is_array($saved['testers'] ?? null) ? $saved['testers'] : [] as $t) {
            $ip = is_array($t) ? \App\Support\IpRange::normalise(trim((string) ($t['ip'] ?? ''))) : null;

            if ($ip === null || isset($out[$ip]) || count($out) >= self::MAX_TESTERS) {
                continue;
            }

            $out[$ip] = ['ip' => $ip, 'label' => mb_substr(trim((string) ($t['label'] ?? '')), 0, 60)];
        }

        return array_values($out);
    }

    /**
     * THE ONE PLACE that decides whether a request sees free gifts: the box
     * (cart panel, cart page, checkout), the checkout's "Free gifts (N)" line
     * and order placement all reach it through forTotals(). The address is
     * $request->ip(), the firewall's own answer (BlockGate, Firewall::before()):
     * this application trusts no proxy, so X-Forwarded-For is never read.
     * Compared packed (IpRange::pack), so "::1" and "0:0:0:0:0:0:0:1" are one.
     * No query: the settings row is read through SettingsRequestMemo.
     */
    public function visibleTo(?\Illuminate\Http\Request $request): bool
    {
        $saved = $this->settings->get(self::KEY);
        $saved = is_array($saved) ? $saved : [];
        $mode = self::modeOf($saved);

        if ($mode !== 'testing') {
            return $mode === 'live';
        }

        $bin = $request === null ? null : \App\Support\IpRange::pack((string) $request->ip());

        if ($bin === null) {
            return false;
        }

        foreach (is_array($saved['testers'] ?? null) ? $saved['testers'] : [] as $t) {
            if (is_array($t) && \App\Support\IpRange::pack(trim((string) ($t['ip'] ?? ''))) === $bin) {
                return true;
            }
        }

        return false;
    }

    /**
     * THE HOMEPAGE SECTION (Marketing -> Free gifts -> Homepage section). The
     * owner, 23 October: "the section must be dynamic, if we publish any
     * range from the ranges, it should show auto here also ... i need just a
     * simple one section ... super simple for users to understand".
     *
     * One row per range that is published and in date today, in the order the
     * checkout reads them, each with what that range gives right now: the
     * wording ("1 Mask + 1 Full Size Product", or the range's own words when
     * it has no counts) and the photos of the gifts actually in stock. A range
     * whose gifts are all out of stock is left out, so the homepage never
     * promises a gift the bag would not give.
     *
     * null when the section is off, gifts are off (or only on for testers who
     * are not this visitor), or no range has a gift to show. ONE product query
     * for every range together, so the cost does not grow with the ranges.
     *
     * @return array{title:string,sub:string,btn:string,rows:list<array{amount:string,words:string,gifts:list<array>}>}|null
     */
    public function home(?\Illuminate\Http\Request $request): ?array
    {
        $cfg = $this->config();

        if (! $cfg['home']['on'] || ! $this->visibleTo($request)) {
            return null;
        }

        $today = StoreTime::today()->format('Y-m-d');
        $ranges = array_values(array_filter($this->ranges(), static fn (array $r) => (($r['starts'] ?? null) === null || $today >= $r['starts'])
            && (($r['ends'] ?? null) === null || $today <= $r['ends'])));

        if ($ranges === []) {
            return null;
        }

        $products = $this->products(array_merge(...array_map(static fn (array $r) => array_map(static fn (array $g) => (int) $g[0], $r['gifts']), $ranges)));
        $ar = ! Locale::isDefault();
        $rows = [];

        foreach ($ranges as $r) {
            $lines = $this->linesFor($r, $products);

            if ($lines === []) {
                continue;
            }

            $words = $ar ? trim((string) $r['ar']) : trim((string) $r['en']);

            if (($r['needs'] ?? null) !== null || $words === '') {
                // The counted wording ("1 Mask + 1 Full Size Product") when the
                // gifts carry their kinds; the admin's own label when they do not.
                $words = trim(self::sentence($lines)[$ar ? 'ar' : 'en']) ?: $words;
            }

            if ($words === '') {
                $words = $this->count(array_sum(array_map(static fn (array $l) => (int) $l['quantity'], $lines)));
            }

            $min = number_format(intdiv((int) $r['min'], 100));
            $max = $r['max'] === null ? null : number_format(intdiv((int) $r['max'], 100));

            $rows[] = [
                'amount' => $ar
                    ? ($max === null ? $min . '+ د.إ' : $min . ' – ' . $max . ' د.إ')
                    : ($max === null ? 'AED ' . $min . '+' : 'AED ' . $min . ' – ' . $max),
                'words' => $words,
                'gifts' => self::rows($lines),
                // "4 gifts" / "4 هدايا" for the popup, every Arabic number form covered by count().
                'count' => $this->count(array_sum(array_map(static fn (array $l) => (int) $l['quantity'], $lines))),
            ];
        }

        if ($rows === []) {
            return null;
        }

        $h = $cfg['home'];

        return [
            'title' => $ar ? $h['title_ar'] : $h['title_en'],
            'sub' => $ar ? $h['sub_ar'] : $h['sub_en'],
            'btn' => $ar ? $h['btn_ar'] : $h['btn_en'],
            'url' => \App\Support\HomeSections::url((string) $h['url'], \App\Support\Url::to(self::HOME['url'])),
            'rows' => $rows,
            'ar' => $ar,
            'bg' => self::homeBg($h),
        ];
    }

    /** One text in the language being drawn (the order's, inside OrderLocale::render()). */
    public function text(string $key, array $replace = []): string
    {
        $config = $this->config();
        $value = $config['texts'][$key . (Locale::isDefault() ? '_en' : '_ar')] ?? '';

        foreach ($replace as $name => $with) {
            $value = str_replace(':' . $name, (string) $with, $value);
        }

        return $value;
    }

    /** The amount a basket is measured on, in fils. */
    public function basis(array $totals): int
    {
        $subtotal = (int) ($totals['subtotal'] ?? 0);

        if ($this->config()['basis'] === 'before_discounts') {
            return max(0, $subtotal);
        }

        return max(0, $subtotal - (int) ($totals['bundle_discount'] ?? 0) - (int) ($totals['discount'] ?? 0));
    }

    public function countryAllowed(?string $country): bool
    {
        $rule = $this->config()['countries'];

        return $rule === 'all' || strtoupper((string) $country) === $rule;
    }

    /**
     * The bands as compiled, enabled only, lowest first.
     *
     * @return list<array{id:int,min:int,max:?int,en:string,ar:string,starts:?string,ends:?string,gifts:list<array{0:int,1:int}>}>
     */
    public function ranges(): array
    {
        $compiled = $this->settings->get(self::COMPILED);

        // v2 (main/backup gifts): an older compiled copy is rebuilt once.
        if (! is_array($compiled) || ($compiled['v'] ?? 1) < 2 || ! isset($compiled['ranges']) || ! is_array($compiled['ranges'])) {
            $compiled = $this->compile();
        }

        return $compiled['ranges'];
    }

    /** The band an amount falls in today, or null. */
    public function rangeFor(int $fils): ?array
    {
        $today = StoreTime::today()->format('Y-m-d');
        // Overlapping ranges are allowed (the owner, 22 October): a basket two
        // ranges cover gets the HIGHER one -- the larger start amount, and on a
        // tie the later-ending one. Every range is walked; none is cut short.
        $best = null;

        foreach ($this->ranges() as $range) {
            if (($range['starts'] ?? null) !== null && $today < $range['starts']) {
                continue;
            }

            if (($range['ends'] ?? null) !== null && $today > $range['ends']) {
                continue;
            }

            if (self::covers($range['min'], $range['max'], $fils)
                && ($best === null || $range['min'] > $best['min']
                    || ($range['min'] === $best['min'] && ($range['max'] === null || ($best['max'] !== null && $range['max'] > $best['max']))))) {
                $best = $range;
            }
        }

        return $best;
    }

    /** min <= amount, and amount below the dirham after max (null = and above). */
    public static function covers(int $min, ?int $max, int $fils): bool
    {
        return $fils >= $min && ($max === null || $fils < $max + 100);
    }

    /** Do two bands share any amount? */
    public static function overlaps(int $aMin, ?int $aMax, int $bMin, ?int $bMax): bool
    {
        $aEnd = $aMax === null ? PHP_INT_MAX : $aMax + 100;
        $bEnd = $bMax === null ? PHP_INT_MAX : $bMax + 100;

        return $aMin < $bEnd && $bMin < $aEnd;
    }

    /**
     * The gift lines for a basket's totals.
     *
     * A gift whose product is gone, unpublished, a variable product, or sold
     * out is left out without a word; one with less stock than its quantity
     * gives what there is.
     *
     * @return list<array{product:Product,product_id:int,quantity:int,value:int,range_id:int,range_label:string}>
     */
    public function forTotals(array $totals, ?string $country = null): array
    {
        if (! $this->visibleTo(app()->bound('request') ? request() : null) || (int) ($totals['item_count'] ?? 1) < 1) {
            return [];
        }

        $range = $this->rangeFor($this->basis($totals));

        if ($range === null || $range['gifts'] === []) {
            return [];
        }

        $country ??= $this->shopperCountry();

        if (! $this->countryAllowed($country)) {
            return [];
        }

        return $this->linesFor($range);
    }

    /**
     * The gifts a basket in this range gets, as lines.
     *
     * WITH COUNTS (the range's Masks / Minis / Full size numbers -- the owner,
     * 23 October: "the back log will only trigger if the primary products get
     * out of stock by any reason, so the system will pick any from the back
     * log as per the quantity available ... first the 3 qnty product"):
     *
     *   for each type, its MAIN gifts in their order, each up to its own
     *   quantity and to what is in stock, until the type's count is reached;
     *   then, if still short, its BACKUP gifts, the one with the MOST stock
     *   first, each as many as needed and in stock. Untagged main gifts are
     *   given as they always were. A type that cannot be filled gives what
     *   there is, and the sentence says what was actually given.
     *
     * WITHOUT COUNTS: the main gifts as listed, exactly as before; backups
     * play no part.
     *
     * @return list<array{product:Product,product_id:int,quantity:int,value:int,range_id:int,range_label:string,kind:?string,backup:bool}>
     */
    public function linesFor(array $range, ?array $products = null): array
    {
        $products ??= $this->products(array_map(static fn (array $g) => (int) $g[0], $range['gifts']));
        $label = self::rangeLabel($range);
        $needs = $range['needs'] ?? null;
        $out = [];

        $available = static function (?Product $p): int {
            if ($p === null || $p->status !== 'publish' || $p->type === 'variable' || $p->stock_status !== 'instock') {
                return 0;
            }

            return $p->manage_stock ? max(0, (int) $p->stock) : PHP_INT_MAX;
        };

        $add = function (Product $p, int $quantity, ?string $kind, bool $backup) use (&$out, $range, $label): void {
            $out[] = [
                'product' => $p,
                'product_id' => (int) $p->id,
                'quantity' => $quantity,
                'value' => max(0, (int) $p->effectivePrice()) * $quantity,
                'range_id' => (int) $range['id'],
                'range_label' => $label,
                'kind' => $kind,
                'backup' => $backup,
            ];
        };

        $gifts = array_map(static fn (array $g) => [
            'id' => (int) $g[0],
            'qty' => max(1, min(self::MAX_QTY, (int) $g[1])),
            'kind' => self::kind($g[2] ?? null),
            'backup' => ($g[3] ?? 'primary') === 'backup',
        ], $range['gifts']);

        if ($needs === null) {
            foreach ($gifts as $g) {
                $p = $products[$g['id']] ?? null;
                $quantity = min($g['qty'], $available($p));

                if (! $g['backup'] && $quantity > 0) {
                    $add($p, $quantity, $g['kind'], false);
                }
            }

            return $out;
        }

        foreach ($gifts as $g) {
            if (! $g['backup'] && $g['kind'] === null) {
                $p = $products[$g['id']] ?? null;
                $quantity = min($g['qty'], $available($p));

                if ($quantity > 0) {
                    $add($p, $quantity, null, false);
                }
            }
        }

        foreach (self::KINDS as $kind) {
            $left = (int) ($needs[$kind] ?? 0);

            foreach ($gifts as $g) {
                if ($left < 1) {
                    break;
                }

                if ($g['backup'] || $g['kind'] !== $kind) {
                    continue;
                }

                $p = $products[$g['id']] ?? null;
                $quantity = min($g['qty'], $available($p), $left);

                if ($quantity > 0) {
                    $add($p, $quantity, $kind, false);
                    $left -= $quantity;
                }
            }

            if ($left < 1) {
                continue;
            }

            $backups = array_values(array_filter($gifts, static fn ($g) => $g['backup'] && $g['kind'] === $kind));
            // Most stock first; a tie keeps the owner's order.
            usort($backups, static fn ($a, $b) => [$available($products[$b['id']] ?? null), array_search($a, $backups, true)]
                <=> [$available($products[$a['id']] ?? null), array_search($b, $backups, true)]);

            foreach ($backups as $g) {
                if ($left < 1) {
                    break;
                }

                $p = $products[$g['id']] ?? null;
                $quantity = min($available($p), $left);

                if ($quantity > 0) {
                    $add($p, $quantity, $kind, true);
                    $left -= $quantity;
                }
            }
        }

        return $out;
    }

    /**
     * What the box draws for each line: name and brand in the language being
     * drawn, the picture, the quantity and (only if Show gift value is on) the
     * value. Plain strings; the view escapes every one.
     *
     * @return list<array{name:string,brand:string,image:string,quantity:int,value:int}>
     */
    public static function rows(array $lines): array
    {
        return array_map(static function (array $l): array {
            $p = $l['product'];

            return [
                'name' => (string) ($p->t('name') ?: $p->name),
                'brand' => (string) ($p->brand?->t('name') ?? ''),
                'image' => (string) ($p->image ?? ''),
                'quantity' => (int) $l['quantity'],
                'value' => (int) $l['value'],
            ];
        }, $lines);
    }

    /**
     * The same rows from an order's own gift lines -- the snapshot, never the
     * live band. NO query for an order without gifts (`free_gift_count` 0,
     * which is every order before this package); one for an order with them.
     *
     * @return list<array{name:string,brand:string,image:string,quantity:int,value:int}>
     */
    public static function orderRows(\App\Models\Order $order): array
    {
        if ((int) ($order->free_gift_count ?? 0) < 1) {
            return [];
        }

        $order->loadMissing('gifts.product:id,image');

        return $order->gifts->map(static fn ($i) => [
            'name' => (string) ((trim((string) ($i->name_localised ?? '')) !== '' && ! Locale::isDefault()) ? $i->name_localised : $i->name),
            'brand' => (string) ($i->brand ?? ''),
            'image' => (string) ($i->product?->image ?? ''),
            'quantity' => (int) $i->quantity,
            'value' => 0,
        ])->values()->all();
    }

    /** "AED 100 – 299" / "AED 2,000+", the operator's record of a band. */
    public static function rangeLabel(array $range): string
    {
        $min = number_format(intdiv((int) $range['min'], 100));

        return $range['max'] === null
            ? 'AED ' . $min . '+'
            : 'AED ' . $min . ' – ' . number_format(intdiv((int) $range['max'], 100));
    }

    /** Gift units in a list of lines. */
    public static function units(array $lines): int
    {
        return array_sum(array_map(static fn (array $l) => (int) $l['quantity'], $lines));
    }

    /**
     * The products for a band, ONCE per request: the drawer, the cart page and
     * both copies of the checkout's order block ask for the same ids.
     *
     * @param  list<int>  $ids
     * @return array<int, Product>
     */
    private function products(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);

        if ($ids === []) {
            return [];
        }

        $key = 'kbb.free_gifts.products.' . implode(',', $ids);
        $request = app()->bound('request') ? request() : null;

        if ($request !== null && $request->attributes->has($key)) {
            return $request->attributes->get($key);
        }

        // ONE statement: the brand comes in on a join and is set as the
        // relation by hand, so the box costs a single query whatever the band
        // holds (FreeGiftsTest pins 3 gifts and 40 at the same count).
        $rows = Product::query()
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->whereIn('products.id', $ids)
            ->get(array_merge(self::PRODUCT_COLUMNS, ['brands.name as fg_brand_name', 'brands.slug as fg_brand_slug']))
            ->each(static function (Product $p): void {
                $brand = null;

                if ($p->brand_id !== null && $p->getAttribute('fg_brand_name') !== null) {
                    $brand = (new \App\Models\Brand)->forceFill(['id' => (int) $p->brand_id, 'name' => $p->getAttribute('fg_brand_name'), 'slug' => $p->getAttribute('fg_brand_slug')]);
                    $brand->exists = true;
                    $brand->syncOriginal();
                }

                $p->offsetUnset('fg_brand_name');
                $p->offsetUnset('fg_brand_slug');
                $p->syncOriginal();
                $p->setRelation('brand', $brand);
            })
            ->keyBy('id')
            ->all();

        $request?->attributes->set($key, $rows);

        return $rows;
    }

    private function shopperCountry(): string
    {
        if ($this->config()['countries'] === 'all') {
            return '';
        }

        try {
            return \App\Support\ShopperCountry::for(request())->code;
        } catch (\Throwable) {
            return (string) $this->settings->get('store_country', 'AE');
        }
    }

    /**
     * Rebuild the compiled bands from the two tables and store them: two
     * queries, on a save in the admin (or once, if the row was ever lost).
     */
    public function compile(): array
    {
        $ranges = DB::table('free_gift_ranges')->where('enabled', true)->orderBy('min_fils')->orderBy('sort')->orderBy('id')->get();
        $items = $ranges->isEmpty() ? collect() : DB::table('free_gift_range_items')
            ->whereIn('range_id', $ranges->pluck('id')->all())
            ->orderBy('sort')->orderBy('id')
            ->get(array_merge(['range_id', 'product_id', 'quantity', 'kind'], self::hasRole() ? ['role'] : []))
            ->groupBy('range_id');

        $out = [];

        foreach ($ranges as $r) {
            $out[] = [
                'id' => (int) $r->id,
                'min' => (int) $r->min_fils,
                'max' => $r->max_fils === null ? null : (int) $r->max_fils,
                'en' => (string) $r->label_en,
                'ar' => (string) $r->label_ar,
                'starts' => $r->starts_at ? substr((string) $r->starts_at, 0, 10) : null,
                'ends' => $r->ends_at ? substr((string) $r->ends_at, 0, 10) : null,
                // [product, quantity, kind, role]: the type and main/backup
                // drive the fill when the range has counts (see linesFor()).
                'gifts' => array_values(array_map(
                    static fn ($i) => [(int) $i->product_id, max(1, min(self::MAX_QTY, (int) $i->quantity)),
                        self::kind($i->kind ?? null), ($i->role ?? 'primary') === 'backup' ? 'backup' : 'primary'],
                    ($items[$r->id] ?? collect())->all(),
                )),
                'needs' => self::needsOf($r),
            ];
        }

        $compiled = ['v' => 2, 'ranges' => $out];
        $this->settings->set(self::COMPILED, $compiled);

        return $compiled;
    }

    /** Save the owner's settings (already validated by the admin controller). */
    public function saveConfig(array $config): void
    {
        $this->settings->set(self::KEY, $config);
        $this->config = null;
    }

    /**
     * "Things to check" on the Overview (Lane FG3, the owner 22 Oct: "allow me
     * to enable the gifts and test, it should not be restricted by
     * non-available products ... just highlight it").
     *
     * NOTHING here refuses the switch any more. `alert` items (red) are a
     * range that gives nothing, or a gift the shop can never give as picked;
     * `warn` items (amber) are a gift skipped until restocked or running
     * short. The shop's own walk (linesFor) already skips what it cannot give
     * and gives the rest, so a range keeps working around a missing gift.
     *
     * @return list<array{level:string,text:string}>
     */
    public function checklist(): array
    {
        $ranges = DB::table('free_gift_ranges')->orderBy('min_fils')->get();
        $enabled = $ranges->where('enabled', true);
        $items = DB::table('free_gift_range_items')->get(array_merge(['range_id', 'product_id', 'quantity', 'kind'], self::hasRole() ? ['role'] : []))->groupBy('range_id');
        $productIds = $items->flatten(1)->pluck('product_id')->unique()->values()->all();
        $products = $productIds === [] ? collect() : Product::withTrashed()->whereIn('id', $productIds)
            ->get(['id', 'name', 'status', 'type', 'stock_status', 'manage_stock', 'stock', 'deleted_at'])->keyBy('id');

        $out = [];

        if ($enabled->isEmpty()) {
            $out[] = ['level' => 'alert', 'text' => 'No range is on yet, so no basket gets a gift. Add a spend range and switch it on.', 'range' => null];
        }

        foreach ($enabled as $r) {
            $label = self::rangeLabel(['min' => (int) $r->min_fils, 'max' => $r->max_fils === null ? null : (int) $r->max_fils]);
            $gifts = $items[$r->id] ?? collect();

            if ($gifts->isEmpty()) {
                $out[] = ['level' => 'alert', 'text' => $label . ' ("' . $r->label_en . '"): no gift products picked yet, so this range gives nothing.', 'range' => (int) $r->id];

                continue;
            }

            $names = [];

            foreach ($gifts as $g) {
                $p = $products[$g->product_id] ?? null;

                if ($p === null || $p->deleted_at !== null || $p->status !== 'publish') {
                    $out[] = ['level' => 'alert', 'text' => $label . ': a picked product is deleted or not published, so it is skipped.', 'range' => (int) $r->id];
                } elseif ($p->type === 'variable') {
                    $out[] = ['level' => 'alert', 'text' => $label . ': "' . $p->name . '" has options (shades/sizes), so it is skipped; pick a simple product.', 'range' => (int) $r->id];
                } elseif ($p->stock_status !== 'instock' || ($p->manage_stock && (int) $p->stock < 1)) {
                    $out[] = ['level' => 'warn', 'text' => $label . ': "' . $p->name . '" is out of stock — skipped until restocked; the rest of the range is still given.', 'range' => (int) $r->id];
                } elseif ($p->manage_stock && (int) $p->stock < (int) $g->quantity) {
                    $out[] = ['level' => 'warn', 'text' => $label . ': only ' . (int) $p->stock . ' of "' . $p->name . '" left for ' . (int) $g->quantity . ' per order.', 'range' => (int) $r->id];
                }

                $names[] = mb_strtolower((string) ($p->name ?? ''));
            }

            // The range's criteria (Lane FG4): a WARNING per type that differs,
            // never a refusal -- the owner changes the criteria or the gifts.
            $needs = self::needsOf($r);

            // Main gifts only: a backup is a reserve, not part of the count.
            foreach (self::criteriaGaps($needs, self::tally($gifts->filter(static fn ($g) => ($g->role ?? 'primary') !== 'backup')->map(static fn ($g) => ['kind' => $g->kind, 'quantity' => $g->quantity])->all())) as $gap) {
                $out[] = ['level' => 'warn', 'text' => $label . ': ' . $gap['text'], 'range' => (int) $r->id];
            }

            if ($needs === null && str_contains(mb_strtolower((string) $r->label_en), 'mask')
                && ! collect($names)->contains(static fn (string $n) => str_contains($n, 'mask'))) {
                $out[] = ['level' => 'warn', 'text' => $label . ' says "' . $r->label_en . '" but no mask product is picked. Pick the sheet mask product.', 'range' => (int) $r->id];
            }
        }

        return $out;
    }

    /** One of KINDS, or null for anything else (untagged). A select stores one of its own options. */
    public static function kind(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::KINDS, true) ? $value : null;
    }

    /**
     * A range row's criteria, or null when none is set (all three NULL, or
     * all three 0: a range nobody has given criteria counts nothing).
     *
     * @return array{mask:int,mini:int,full:int}|null
     */
    public static function needsOf(object|array $range): ?array
    {
        $r = (array) $range;
        $needs = [];

        foreach (self::KINDS as $kind) {
            $v = $r['need_' . $kind] ?? null;
            $needs[$kind] = $v === null ? null : max(0, min(self::MAX_NEED, (int) $v));
        }

        if (array_sum(array_map('intval', $needs)) === 0) {
            return null;
        }

        return array_map('intval', $needs);
    }

    /**
     * Gift UNITS per type: 2x of one mask is 2 masks. `none` is untagged.
     *
     * @param  iterable<array{kind:?string,quantity:int}>  $gifts
     * @return array{mask:int,mini:int,full:int,none:int}
     */
    public static function tally(iterable $gifts): array
    {
        $out = ['mask' => 0, 'mini' => 0, 'full' => 0, 'none' => 0];

        foreach ($gifts as $g) {
            $out[self::kind($g['kind'] ?? null) ?? 'none'] += max(0, (int) ($g['quantity'] ?? 0));
        }

        return $out;
    }

    /**
     * Where a range's gifts differ from its criteria, one item per type, in
     * the boxes' order, then untagged units. Empty when there are no criteria
     * or everything matches. The screen runs the same walk as the owner edits
     * (criteriaGaps() in free-gifts-screen.blade.php); this is the Overview's.
     *
     * @param  array{mask:int,mini:int,full:int}|null  $needs
     * @param  array{mask:int,mini:int,full:int,none:int}  $have
     * @return list<array{kind:string,have:int,need:int,text:string}>
     */
    public static function criteriaGaps(?array $needs, array $have): array
    {
        if ($needs === null) {
            return [];
        }

        $out = [];

        foreach (self::KINDS as $kind) {
            $need = (int) $needs[$kind];
            $got = (int) ($have[$kind] ?? 0);
            $title = self::KIND_NAMES[$kind][0];

            if ($got < $need) {
                $out[] = ['kind' => $kind, 'have' => $got, 'need' => $need, 'text' => $title . ': ' . $got . ' of ' . $need . ' chosen — add ' . ($need - $got) . ' more.'];
            } elseif ($got > $need) {
                $out[] = ['kind' => $kind, 'have' => $got, 'need' => $need, 'text' => $title . ': ' . $got . ' chosen, criteria says ' . $need . '.'];
            }
        }

        $none = (int) ($have['none'] ?? 0);

        if ($none > 0) {
            $out[] = ['kind' => 'none', 'have' => $none, 'need' => 0, 'text' => ($none === 1 ? '1 gift is' : $none . ' gifts are') . ' untagged, so not counted — set its type.'];
        }

        return $out;
    }

    /**
     * The range wording from its criteria, zeros left out, in the style of the
     * owner's own seeded wording: "3 Masks + 2 Mini Products + 1 Full Size
     * Product" / "3 أقنعة + منتجان صغيران + منتج بالحجم الكامل". Arabic counts
     * four ways (1 / 2 / 3-10 / 11+). The screen's "Use for wording" button runs
     * the same table (wordingFor() there); this is its authority in the tests.
     *
     * @param  array{mask:int,mini:int,full:int}  $needs
     * @return array{en:string,ar:string}
     */
    public static function criteriaWording(array $needs): array
    {
        $en = [];
        $ar = [];

        foreach (self::WORDS as $kind => [$one, $many, $arForms]) {
            $n = max(0, (int) ($needs[$kind] ?? 0));

            if ($n === 0) {
                continue;
            }

            $en[] = $n . ' ' . ($n === 1 ? $one : $many);
            $form = $n === 1 ? 0 : ($n === 2 ? 1 : ($n <= 10 ? 2 : 3));
            $ar[] = str_replace(':n', (string) $n, $arForms[$form]);
        }

        return ['en' => implode(' + ', $en), 'ar' => implode(' + ', $ar)];
    }

    /** [kind => [English one, English many, [Arabic 1, 2, 3-10, 11+]]] for criteriaWording(). */
    public const WORDS = [
        'mask' => ['Mask', 'Masks', ['قناع واحد', 'قناعان', ':n أقنعة', ':n قناعاً']],
        'mini' => ['Mini Product', 'Mini Products', ['منتج صغير واحد', 'منتجان صغيران', ':n منتجات صغيرة', ':n منتجاً صغيراً']],
        'full' => ['Full Size Product', 'Full Size Products', ['منتج بالحجم الكامل', 'منتجان بالحجم الكامل', ':n منتجات بالحجم الكامل', ':n منتجاً بالحجم الكامل']],
    ];

    /**
     * The units for the checkout's "Free gifts (N)" line: counted as if the
     * destination qualified, because the line is hidden by :has() whenever the
     * box above it is absent (a UAE-only gift the country change removed).
     */
    /**
     * "3 Masks + 2 Mini Products + 1 Full Size Product" and its Arabic, from
     * the lines actually given (untagged gifts are not in the sentence).
     *
     * @return array{en:string,ar:string}
     */
    public static function sentence(array $lines): array
    {
        $have = ['mask' => 0, 'mini' => 0, 'full' => 0];

        foreach ($lines as $l) {
            if (isset($have[$l['kind'] ?? ''])) {
                $have[$l['kind']] += (int) $l['quantity'];
            }
        }

        return self::criteriaWording($have);
    }

    /** Whether the main/backup column has been added yet. Asked only on a compile or the checklist, never per page. */
    private static function hasRole(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('free_gift_range_items', 'role');
    }

    public function lineUnits(array $totals): int
    {
        return self::units($this->forTotals($totals, 'AE'));
    }

    /** "4 gifts" / "4 هدايا", in the language being drawn. */
    public function count(int $n): string
    {
        $form = $n === 1 ? 'count_one' : ($n === 2 ? 'count_two' : ($n <= 10 ? 'count_few' : 'count_many'));

        return $this->text($form, ['count' => $n]);
    }

    /**
     * Everything the box partial needs for a basket: its rows and the band's
     * wording in the language being drawn ("3 Masks + 1 Mini Product").
     *
     * @return array{kbbFgRows: list<array>, kbbFgWords: string}
     */
    public function box(array $totals, ?string $country = null): array
    {
        $lines = $this->forTotals($totals, $country);

        if ($lines === []) {
            return ['kbbFgRows' => [], 'kbbFgWords' => ''];
        }

        $words = '';

        foreach ($this->ranges() as $range) {
            if ((int) $range['id'] === (int) $lines[0]['range_id']) {
                $words = Locale::isDefault() || trim((string) $range['ar']) === '' ? (string) $range['en'] : (string) $range['ar'];

                // With counts the sentence is what is actually being given
                // ("3 Masks + 2 Mini Products"), so a gift that ran out and
                // was not replaced is never promised.
                if (($range['needs'] ?? null) !== null) {
                    $given = self::sentence($lines);
                    $words = Locale::isDefault() ? $given['en'] : $given['ar'];
                }
            }
        }

        return ['kbbFgRows' => self::rows($lines), 'kbbFgWords' => $words];
    }

    /**
     * The section background, normalised: a style from BG_STYLES, three
     * #RRGGBB colours and a strength of 5..60 -- anything else is its default,
     * never refused (rule 5: a select stores one of its options or the default).
     *
     * And the card's computer width, an integer clamped to 40..100 percent.
     *
     * @return array{bg:string,c1:string,c2:string,c3:string,strength:int,width:int}
     */
    public static function homeBg(mixed $in): array
    {
        $in = is_array($in) ? $in : [];
        $hex = static fn (mixed $v, string $d): string => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtoupper($v) : $d;
        $bg = is_string($in['bg'] ?? null) && isset(self::BG_STYLES[$in['bg']]) ? $in['bg'] : self::HOME['bg'];
        $s = is_numeric($in['strength'] ?? null) ? (int) $in['strength'] : self::HOME['strength'];
        $w = is_numeric($in['width'] ?? null) ? (int) $in['width'] : self::HOME['width'];

        return [
            'bg' => $bg,
            'c1' => $hex($in['c1'] ?? null, self::HOME['c1']),
            'c2' => $hex($in['c2'] ?? null, self::HOME['c2']),
            'c3' => $hex($in['c3'] ?? null, self::HOME['c3']),
            'strength' => max(5, min(60, $s)),
            'width' => max(40, min(100, $w)),
        ];
    }

    /**
     * Each colour washed to `strength` percent over white, in plain hex, so the
     * stylesheet needs no color-mix() (CLAUDE.md: no newer CSS function beside
     * var() outside @supports) and works on every browser the shop supports.
     *
     * @return list<string>
     */
    public static function washed(array $bg): array
    {
        $t = $bg['strength'] / 100;

        return array_map(static function (string $c) use ($t): string {
            $rgb = array_map('hexdec', str_split(substr($c, 1), 2));

            return sprintf('#%02X%02X%02X', ...array_map(static fn (int $v) => (int) round(255 - (255 - $v) * $t), $rgb));
        }, [$bg['c1'], $bg['c2'], $bg['c3']]);
    }

    private static function str(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 160) : $default;
    }
}
