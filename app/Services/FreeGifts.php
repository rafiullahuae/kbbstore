<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
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
        // The basket reminder email (Lane FGE, owner 24 October: "write one
        // line that depends on the availability while placing the order").
        // The basket reminder's closing line, at the box's bottom (owner, 24
        // October: "Don’t miss out on your FREE GIFTS! 🎁 Grab yours before
        // they are gone. add this line to the abandoned gift box at the bottom").
        'basket_cta' => ['Don’t miss out on your FREE GIFTS! 🎁 Grab yours before they are gone.', 'لا تفوّتي هداياكِ المجانية! 🎁 احصلي عليها قبل نفادها.'],
        // A successful order's emails (Lane FGE, owner 24 October: "the heading
        // should change. Gifts are added to your order ... only for the
        // successed order"): the box's heading and its line under the gifts.
        'email_added' => ['Free gifts added to your order', 'هدايا مجانية أُضيفت إلى طلبكِ'],
        'email_added_note' => ['They arrive together with your order.', 'تصلكِ مع طلبكِ.'],
        'availability' => ['Added to your order when you place it, depending on availability.', 'تُضاف إلى طلبكِ عند إتمامه، حسب التوفّر.'],
        // " · N gifts" after the band's wording. Arabic counts four ways
        // (هدية واحدة / هديتان / 3-10 هدايا / 11+ هدية), English two.
        'count_one' => ['1 gift', 'هدية واحدة'],
        'count_two' => [':count gifts', 'هديتان'],
        'count_few' => [':count gifts', ':count هدايا'],
        'count_many' => [':count gifts', ':count هدية'],
        // THE URGENCY LINE under the box (Lane GU, owner 26 October, design 5
        // "Fire sweep": "this design is final ... on cart panel, cart page,
        // checkout summary section, and in the abandoned cart email. need this
        // only at 4 places. also in arabic too"). Shipped ON with these words
        // because he asked for it; LOOK['urgency'] switches it off. A leading
        // emoji is drawn outside the sweep (urgency()).
        'urgency' => ['🔥 Gifts go fast! Grab yours now', '🔥 الهدايا تنفد بسرعة! احصلي على هديتك الآن'],
    ];

    /** The urgency line is one line: a longer text is refused by the admin and cut here. */
    public const URGENCY_MAX = 80;

    /** The homepage section's data. No storefront section yet: the owner is choosing its design. */
    public const HOME = [
        'on' => false,
        'title_en' => 'Free gifts with every order', 'title_ar' => 'هدايا مجانية مع كل طلب',
        'sub_en' => 'The more you shop, the more you get.', 'sub_ar' => 'كلما تسوّقتِ أكثر، حصلتِ على المزيد.',
        'btn_en' => 'Shop now', 'btn_ar' => 'تسوّقي الآن',
        // The line under the gifts, with a gift icon (owner, 24 October: "gift
        // icon + New gifts added daily ... give control to change this line for
        // both languages"). Emptied in the admin, it is not drawn.
        'note_en' => 'New gifts added daily', 'note_ar' => 'هدايا جديدة تُضاف يومياً',
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
        /*
         * (Lane GU2) The line under the gifts (owner, 26 October: "the gift grab
         * yours line should also come on homepage and replace the line new gifts
         * added daily. give option to switch on off. by default just keep on the
         * Grab yours line"). ON: the box's "🔥 Gifts go fast! Grab yours now"
         * (texts `urgency`, its look from LOOK u_*) in place of `note`. OFF: the
         * `note` line exactly as before.
         */
        'urgency' => true,
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
        'photos' => 'fill5',      // LOOK_PHOTOS: how the closed row sizes its photos
        'urgency' => true,        // Lane GU: "Gifts go fast" under the box (ON: the owner asked for it)
        // (Lane GU2) The urgency line's look, on the box AND the homepage
        // ("allow us to control the line font size etc, same effect i need
        // continues"): px on a computer, px on a phone (760px and under),
        // weight (LOOK_U_WEIGHTS), sweep in tenths of a second. Today's values.
        'u_px' => 11,
        'u_px_m' => 11,
        'u_weight' => 800,
        'u_speed' => 26,
    ];

    /** (Lane GU2) The urgency line's font weights. */
    public const LOOK_U_WEIGHTS = [500, 600, 700, 800, 900];

    public const LOOK_TITLES = ['clamp2', 'fade', 'wrap'];

    /**
     * THE CLOSED ROW'S PHOTOS (Lane FGP). The owner, 24 October, on a cart
     * panel whose five photos stopped well short of the box's right edge:
     * "increase the gifts thumbnails size to the end of the box. if one
     * product, then make 25% more ... 2-4 products 15-20% ... 4-5 products
     * 10-15 ... if more products then it may come in 2nd row" -- then, having
     * seen the three previews: "give us option to choose all three options
     * from backend, and keep option B by default."
     *
     *   fixed  every photo at "Photo size, closed row" (the look until 2.60.596)
     *   scale  A: 1 gift x1.25, 2-4 x1.17, 5 or more x1.11, wrapping
     *   fill5  B (default, as asked): 1 gift x1.25, 2-4 x1.17, 5 or more
     *          five to a row edge to edge, up to 64px, then a second row
     *   fill4  C: 1 gift x1.25, 2-3 x1.17, 4 or more four to a row edge to
     *          edge, up to 72px
     * Multiples of the owner's own "Photo size, closed row", so that number
     * still moves the row. Pure CSS from fixed class names (photoTier()).
     */
    public const LOOK_PHOTOS = ['fixed', 'scale', 'fill5', 'fill4'];

    /** Each number's [min, max]. Out of range is clamped; not a number is the default. */
    public const LOOK_RANGES = [
        'free_gap' => [0, 40],
        'title_px' => [11, 16],
        'photo_list' => [22, 48],
        'photo_row' => [22, 48],
        'row_gap' => [0, 16],
        'u_px' => [9, 24],
        'u_px_m' => [9, 24],
        'u_speed' => [10, 100], // 1.0 - 10.0 s
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
                : (is_int($default) ? $default : (str_starts_with($key, 'note_') && isset($saved['home'][$key]) && is_string($saved['home'][$key])
                    ? mb_substr(trim($saved['home'][$key]), 0, 160) // may be '' -- the owner hid it
                    : self::str($saved['home'][$key] ?? null, $default)));
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
     * @return array{brand:bool,titles:string,free_gap:int,title_px:int,photo_list:int,photo_row:int,separator:bool,row_gap:int,photos:string}
     */
    public static function lookOf(mixed $saved): array
    {
        $saved = is_array($saved) ? $saved : [];
        $look = self::LOOK;

        foreach (['brand', 'separator', 'urgency'] as $key) {
            if (array_key_exists($key, $saved)) {
                $bool = filter_var($saved[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $look[$key] = is_bool($bool) && ! is_array($saved[$key]) ? $bool : self::LOOK[$key];
            }
        }

        $look['titles'] = in_array($saved['titles'] ?? null, self::LOOK_TITLES, true) ? $saved['titles'] : self::LOOK['titles'];
        $look['photos'] = in_array($saved['photos'] ?? null, self::LOOK_PHOTOS, true) ? $saved['photos'] : self::LOOK['photos'];
        $w = $saved['u_weight'] ?? null;
        $look['u_weight'] = (is_int($w) || (is_string($w) && ctype_digit($w))) && in_array((int) $w, self::LOOK_U_WEIGHTS, true) ? (int) $w : self::LOOK['u_weight'];

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

    /**
     * The closed row's size class for $tiles photos under the look's "photos"
     * choice (LOOK_PHOTOS), and the largest size a photo can be drawn at, for
     * its `sizes` hint: [' fgb-k1', 45]. A fixed class name, never a setting.
     *
     *   fgb-k1  x1.25   fgb-k2  x1.17   fgb-k3  x1.11
     *   fgb-g5  five to a row, edge to edge, up to 64px
     *   fgb-g4  four to a row, edge to edge, up to 72px
     *
     * @return array{0:string,1:int}
     */
    public static function photoTier(array $look, int $tiles): array
    {
        $look = self::lookOf($look);
        $px = (int) $look['photo_row'];
        $grid = ['fill5' => 5, 'fill4' => 4][$look['photos']] ?? null;
        $few = $look['photos'] === 'fill4' ? 3 : 4;

        if ($look['photos'] === 'fixed' || $tiles < 1) {
            return ['', $px];
        }
        if ($tiles === 1) {
            return [' fgb-k1', (int) round($px * 1.25)];
        }
        if ($tiles <= $few) {
            return [' fgb-k2', (int) round($px * 1.17)];
        }

        return $grid === null ? [' fgb-k3', (int) round($px * 1.11)] : [' fgb-g' . $grid, $grid === 5 ? 64 : 72];
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
        $variants = $this->variantsFor($ranges);
        $ar = ! Locale::isDefault();
        $rows = [];

        foreach ($ranges as $r) {
            $lines = $this->linesFor($r, $products, $variants);

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
                // The row's small photos: full size first, then minis, then
                // masks (owner, 24 October: "show the full sizes products then
                // mini and mask if space is there. don't start with the masks
                // first ... only default thumbnails"). The popup keeps the
                // admin's own order.
                'thumbs' => self::rows(self::fullFirst($lines)),
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
            'note' => $ar ? $h['note_ar'] : $h['note_en'],
            // (Lane GU2) The urgency line in the note's place, or null for the note.
            'urgency' => $h['urgency'] ? $this->urgencyWords() : null,
            'look' => self::lookOf($cfg['look'] ?? null),
            'url' => \App\Support\HomeSections::url((string) $h['url'], \App\Support\Url::to(self::HOME['url'])),
            'rows' => $rows,
            'ar' => $ar,
            'bg' => self::homeBg($h),
        ];
    }

    /**
     * THE SUPER SALE BANNER'S GIFT ROW (Lane SSB, App\Support\SuperSaleBanner):
     * the gifts the shop gives right now, picked exactly as the cart's box
     * picks them -- linesFor() over the compiled ranges in date today, so a
     * sold-out main gift gives way to its backup and a gift nobody can get is
     * never shown. $band: 'all' (every such range, each product once), 'first'
     * or 'last' (the smallest / biggest basket's range), or 'range:<id>'.
     * Full-size first, as the homepage section orders its photos.
     *
     * Nothing at all -- and no query -- when gifts are off for this visitor
     * (visibleTo(), the same rule as the box) or no range is running. Otherwise
     * ONE product query for every range together (products(), memoised on the
     * request), so the cost is flat however many gifts or products there are.
     *
     * @return list<array{product:Product,product_id:int,quantity:int,value:int,range_id:int,range_label:string,kind:?string,backup:bool}>
     */
    public function bannerLines(?\Illuminate\Http\Request $request, string $band = 'all'): array
    {
        return $this->bannerChoices($request, [$band])[$band] ?? [];
    }

    /**
     * bannerLines() for several bands at once -- what the banner's editor
     * shows as he switches "Which gifts", with no request per choice. Still
     * ONE product query for every range together, and none at all when gifts
     * are off for this visitor or no range is running.
     *
     * @param  list<string>  $bands
     * @return array<string, list<array>>
     */
    public function bannerChoices(?\Illuminate\Http\Request $request, array $bands): array
    {
        $out = array_fill_keys($bands, []);

        if (! $this->visibleTo($request)) {
            return $out;
        }

        $today = StoreTime::today()->format('Y-m-d');
        $running = array_values(array_filter($this->ranges(), static fn (array $r) => $r['gifts'] !== []
            && (($r['starts'] ?? null) === null || $today >= $r['starts'])
            && (($r['ends'] ?? null) === null || $today <= $r['ends'])));

        if ($running === []) {
            return $out;
        }

        $products = $this->products(array_merge(...array_map(static fn (array $r) => array_map(static fn (array $g) => (int) $g[0], $r['gifts']), $running)));
        $variants = $this->variantsFor($running);

        foreach ($bands as $band) {
            if (str_starts_with($band, 'range:')) {
                $id = (int) substr($band, 6);
                $ranges = array_values(array_filter($running, static fn (array $r) => (int) $r['id'] === $id));
            } elseif ($band === 'first' || $band === 'last') {
                // ranges() is lowest first.
                $ranges = [$band === 'first' ? $running[0] : $running[count($running) - 1]];
            } else {
                $ranges = $running;
            }

            $lines = [];
            foreach ($ranges as $r) {
                foreach ($this->linesFor($r, $products, $variants) as $line) {
                    // Each product once -- each VARIATION once (Lane FGV): the
                    // pink and the black of one product are two gifts.
                    $lines[$line['product_id'] . (isset($line['variant_id']) ? ':' . $line['variant_id'] : '')] ??= $line;
                }
            }
            $out[$band] = self::fullFirst(array_values($lines));
        }

        return $out;
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
    public function linesFor(array $range, ?array $products = null, ?array $variants = null): array
    {
        $products ??= $this->products(array_map(static fn (array $g) => (int) $g[0], $range['gifts']));
        // (Lane FGV) The chosen variations: no query unless a gift names one.
        $variants ??= $this->variantsFor([$range]);
        $label = self::rangeLabel($range);
        $needs = $range['needs'] ?? null;
        $out = [];

        $available = static function (?Product $p): int {
            if ($p === null || $p->status !== 'publish' || $p->type === 'variable' || $p->stock_status !== 'instock') {
                return 0;
            }

            return $p->manage_stock ? max(0, (int) $p->stock) : PHP_INT_MAX;
        };

        /*
         * (Lane FGV) A gift may name ONE variation of a product with options
         * ("the pink one"): then that variation's own stock decides, and its
         * line carries `variant` / `variant_id`. A gift with no variation is
         * read exactly as before -- the keys are not even added -- so every
         * band saved before this lane gives, draws and fingerprints the same.
         */
        $avail = static function (array $g) use ($products, $variants, $available): int {
            $p = $products[$g['id']] ?? null;

            return $g['variant'] === null ? $available($p) : self::variantAvailable($p, $variants[$g['variant']] ?? null);
        };

        $add = function (array $g, int $quantity, ?string $kind, bool $backup) use (&$out, $range, $label, $products, $variants): void {
            $p = $products[$g['id']];
            $v = $g['variant'] === null ? null : $variants[$g['variant']];
            $v?->setRelation('product', $p);

            $out[] = [
                'product' => $p,
                'product_id' => (int) $p->id,
                'quantity' => $quantity,
                'value' => max(0, (int) ($v ?? $p)->effectivePrice()) * $quantity,
                'range_id' => (int) $range['id'],
                'range_label' => $label,
                'kind' => $kind,
                'backup' => $backup,
            ] + ($v === null ? [] : ['variant' => $v, 'variant_id' => (int) $v->id]);
        };

        $gifts = array_map(static fn (array $g) => [
            'id' => (int) $g[0],
            'qty' => max(1, min(self::MAX_QTY, (int) $g[1])),
            'kind' => self::kind($g[2] ?? null),
            'backup' => ($g[3] ?? 'primary') === 'backup',
            'variant' => (int) ($g[4] ?? 0) > 0 ? (int) $g[4] : null,
        ], $range['gifts']);

        if ($needs === null) {
            foreach ($gifts as $g) {
                $quantity = min($g['qty'], $avail($g));

                if (! $g['backup'] && $quantity > 0) {
                    $add($g, $quantity, $g['kind'], false);
                }
            }

            return self::fullFirst($out);
        }

        foreach ($gifts as $g) {
            if (! $g['backup'] && $g['kind'] === null) {
                $quantity = min($g['qty'], $avail($g));

                if ($quantity > 0) {
                    $add($g, $quantity, null, false);
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

                $quantity = min($g['qty'], $avail($g), $left);

                if ($quantity > 0) {
                    $add($g, $quantity, $kind, false);
                    $left -= $quantity;
                }
            }

            if ($left < 1) {
                continue;
            }

            $backups = array_values(array_filter($gifts, static fn ($g) => $g['backup'] && $g['kind'] === $kind));
            // Most stock first; a tie keeps the owner's order.
            usort($backups, static fn ($a, $b) => [$avail($b), array_search($a, $backups, true)]
                <=> [$avail($a), array_search($b, $backups, true)]);

            foreach ($backups as $g) {
                if ($left < 1) {
                    break;
                }

                $quantity = min($avail($g), $left);

                if ($quantity > 0) {
                    $add($g, $quantity, $kind, true);
                    $left -= $quantity;
                }
            }
        }

        // (Lane GBX) Full size, then minis, then masks, then untagged -- the
        // one order every list, photo row, sentence and new order follows
        // (owner, 28 October). Which gifts are given is unchanged.
        return self::fullFirst($out);
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
            // (Lane FGV) A chosen variation: "Product – Pink" and its own photo.
            $v = $l['variant'] ?? null;

            return [
                'name' => self::giftName((string) ($p->t('name') ?: $p->name), $v),
                'brand' => (string) ($p->brand?->t('name') ?? ''),
                'image' => (string) (($v !== null && trim((string) $v->image) !== '' ? $v->image : null) ?? $p->image ?? ''),
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

        $order->loadMissing('gifts.product:id,image,gift_kind');
        // (Lane FGV) A gift that is one variation shows that variation's photo.
        $images = self::variantImages($order->gifts);

        // (Lane GBX) Full size, minis, masks -- also for orders placed before
        // linesFor() gave them in that order. No query: gift_kind is loaded above.
        return array_column(self::fullFirst($order->gifts->map(static fn ($i) => ['kind' => (string) ($i->product?->gift_kind ?? ''), 'row' => [
            'name' => (string) ((trim((string) ($i->name_localised ?? '')) !== '' && ! Locale::isDefault()) ? $i->name_localised : $i->name),
            'brand' => (string) ($i->brand ?? ''),
            'image' => (string) ($images[(int) ($i->product_variant_id ?? 0)] ?? $i->product?->image ?? ''),
            'quantity' => (int) $i->quantity,
            'value' => 0,
        ]])->values()->all()), 'row');
    }

    /**
     * (Lane FGV) The own photos of the variations some gift lines are, by
     * variant id, for every screen that shows an order's gifts. NO query when
     * no line is a variation (every order before this lane); one otherwise.
     *
     * @param  iterable<object>  $gifts  order_items rows
     * @return array<int, string>
     */
    public static function variantImages(iterable $gifts): array
    {
        $ids = [];
        foreach ($gifts as $g) {
            if ((int) ($g->product_variant_id ?? 0) > 0) {
                $ids[] = (int) $g->product_variant_id;
            }
        }

        if ($ids === []) {
            return [];
        }

        return DB::table('product_variants')->whereIn('id', array_values(array_unique($ids)))
            ->whereNotNull('image')->where('image', '!=', '')
            ->pluck('image', 'id')->map(static fn ($i) => (string) $i)->all();
    }

    /**
     * The same rows for the box's small photos: full size first, then minis,
     * then masks, by each gift's own type (owner, 24 October: "only default
     * thumbnails"). The list keeps orderRows()' order. The type itself never
     * reaches the page.
     *
     * @return list<array{name:string,brand:string,image:string,quantity:int,value:int}>
     */
    public static function orderThumbs(\App\Models\Order $order): array
    {
        // (Lane GBX) orderRows() is in this order itself now.
        return self::orderRows($order);
    }

    /**
     * Why a backup gift was given, in words for the order's notes, or null when
     * every gift came from the main list. (Lane FGB, owner 24 October: "we set
     * the fwee product as primary ... but the customer get the mixsoon product
     * instead ... i need an urgent fix. check everything.")
     *
     * The choice itself is linesFor()'s and is unchanged: a main gift is given
     * while it is published, has no options, is marked in stock and has units
     * left; otherwise the type's backups fill in, most stock first. What was
     * missing is the RECORD: an order that got a backup said nothing about why,
     * so "fwee had its last unit held by an unpaid order" and "the basket was
     * over AED 500.99, so it took the higher range" looked the same as a fault.
     * This names the range used, each main gift that was passed over with its
     * reason, and what was given instead. No query: the products are the
     * request's own, already loaded by forTotals().
     *
     * @param  list<array<string, mixed>>  $lines  forTotals()' lines for this order
     */
    public function backupNote(array $lines): ?string
    {
        $used = array_values(array_filter($lines, static fn (array $l) => ! empty($l['backup'])));

        if ($used === []) {
            return null;
        }

        $range = null;
        foreach ($this->ranges() as $r) {
            if ((int) $r['id'] === (int) $used[0]['range_id']) {
                $range = $r;
                break;
            }
        }

        if ($range === null) {
            return null;
        }

        $products = $this->products(array_map(static fn (array $g) => (int) $g[0], $range['gifts']));
        $variants = $this->variantsFor([$range]);
        $why = static function (?Product $p, int $want, ?int $variantId = null) use ($variants): string {
            if ($p === null) {
                return 'the product no longer exists';
            }
            if ($p->status !== 'publish') {
                return 'it is not published';
            }
            // (Lane FGV) A chosen variation answers with its own shelf.
            if ($variantId !== null) {
                $v = $variants[$variantId] ?? null;
                if ($v === null || (int) $v->product_id !== (int) $p->id) {
                    return 'the chosen variation no longer exists';
                }
                if ($v->stock_status !== 'instock') {
                    return 'it is marked out of stock';
                }
                $have = self::variantAvailable($p, $v);

                return $have < $want ? ($have < 1 ? '0 left' : 'only '.$have.' left') : 'not enough units';
            }
            if ($p->type === 'variable') {
                return 'it has options (shades/sizes)';
            }
            if ($p->stock_status !== 'instock') {
                return 'it is marked out of stock';
            }

            return $p->manage_stock && (int) $p->stock < $want ? ((int) $p->stock < 1 ? '0 left' : 'only '.(int) $p->stock.' left') : 'not enough units';
        };

        $parts = [];
        foreach (array_unique(array_map(static fn (array $l) => (string) $l['kind'], $used)) as $kind) {
            $skipped = [];
            foreach ($range['gifts'] as $g) {
                if (($g[3] ?? 'primary') === 'backup' || self::kind($g[2] ?? null) !== $kind) {
                    continue;
                }
                $p = $products[(int) $g[0]] ?? null;
                $vid = (int) ($g[4] ?? 0) > 0 ? (int) $g[4] : null;
                $name = $p === null ? '#'.(int) $g[0] : self::giftName((string) $p->name, $vid === null ? null : ($variants[$vid] ?? null));
                $skipped[] = '“'.$name.'” ('.$why($p, (int) $g[1], $vid).')';
            }
            $given = array_map(static fn (array $l) => '“'.self::giftName((string) $l['product']->name, $l['variant'] ?? null).'” ×'.(int) $l['quantity'], array_filter($used, static fn (array $l) => (string) $l['kind'] === $kind));
            $parts[] = ucfirst(self::WORDS[$kind][1] ?? $kind).': main gift'.(count($skipped) === 1 ? '' : 's').' '.($skipped === [] ? 'none set' : implode(', ', $skipped)).' — backup given: '.implode(', ', $given).'.';
        }

        return 'Free gifts, range '.self::rangeLabel($range).': a backup gift was used. '.implode(' ', $parts);
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

    /**
     * THE CHOSEN VARIATIONS (Lane FGV) of every gift in these ranges, keyed by
     * variant id, in ONE statement however many ranges, gifts or values: the
     * variant joined to its option values and their axis, folded here. NO
     * query at all when no gift names a variation -- every band saved before
     * this lane -- so a shop page costs exactly what it cost before.
     * Memoised on the request like products().
     *
     * Each variant carries `fg_values`: list of [axis, value, value id, axis id]
     * in the axis order the product page uses, read by variantLabel().
     *
     * @param  list<array>  $ranges  compiled ranges (their `gifts`)
     * @return array<int, ProductVariant>
     */
    public function variantsFor(array $ranges): array
    {
        $ids = [];
        foreach ($ranges as $r) {
            foreach ($r['gifts'] ?? [] as $g) {
                if ((int) ($g[4] ?? 0) > 0) {
                    $ids[] = (int) $g[4];
                }
            }
        }

        return self::variants($ids);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, ProductVariant>
     */
    public static function variants(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i) => $i > 0)));
        sort($ids);

        if ($ids === []) {
            return [];
        }

        $key = 'kbb.free_gifts.variants.' . implode(',', $ids);
        $request = app()->bound('request') ? request() : null;

        if ($request !== null && $request->attributes->has($key)) {
            return $request->attributes->get($key);
        }

        $out = self::loadVariants('v.id', $ids);
        $request?->attributes->set($key, $out);

        return $out;
    }

    /**
     * Every variation of these products, for the admin's "Choose variation"
     * list (Lane FGV): ONE statement for every product on the screen, in the
     * product page's order (position, then id).
     *
     * @param  list<int>  $productIds
     * @return array<int, list<ProductVariant>>  by product id
     */
    public static function variantsOfProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $i) => $i > 0)));

        if ($productIds === []) {
            return [];
        }

        $out = [];
        foreach (self::loadVariants('v.product_id', $productIds) as $v) {
            $out[(int) $v->product_id][] = $v;
        }

        return $out;
    }

    /** @return array<int, ProductVariant> */
    private static function loadVariants(string $column, array $ids): array
    {
        $rows = DB::table('product_variants as v')
            ->leftJoin('product_variant_attribute_value as pv', 'pv.product_variant_id', '=', 'v.id')
            ->leftJoin('attribute_values as av', 'av.id', '=', 'pv.attribute_value_id')
            ->leftJoin('attributes as a', 'a.id', '=', 'av.attribute_id')
            ->whereIn($column, $ids)
            ->orderBy('v.position')->orderBy('v.id')->orderBy('a.position')->orderBy('av.attribute_id')->orderBy('av.position')
            ->get(['v.id', 'v.product_id', 'v.sku', 'v.price', 'v.sale_price', 'v.manage_stock', 'v.stock', 'v.stock_status', 'v.image', 'v.position',
                'av.id as av_id', 'av.name as av_name', 'a.id as a_id', 'a.name as a_name']);

        $out = [];
        $values = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;

            if (! isset($out[$id])) {
                $v = (new ProductVariant)->forceFill([
                    'id' => $id, 'product_id' => (int) $row->product_id, 'sku' => $row->sku,
                    'price' => $row->price, 'sale_price' => $row->sale_price, 'manage_stock' => (bool) $row->manage_stock,
                    'stock' => $row->stock, 'stock_status' => (string) $row->stock_status, 'image' => $row->image,
                ]);
                $v->exists = true;
                $v->syncOriginal();
                $out[$id] = $v;
                $values[$id] = [];
            }

            if ($row->av_id !== null) {
                $values[$id][] = [(string) ($row->a_name ?? ''), (string) $row->av_name, (int) $row->av_id, (int) ($row->a_id ?? 0)];
            }
        }

        foreach ($out as $id => $v) {
            $v->setAttribute('fg_values', $values[$id]);
            $v->syncOriginal();
        }

        return $out;
    }

    /**
     * A variation's own words: "Pink" ($axes false, for "Product – Pink") or
     * "Colour: Pink" ($axes true, for the admin), in the language being drawn.
     * No query in English; the translation store otherwise, as the product
     * page reads it. '' for a variation with no values.
     */
    public static function variantLabel(?ProductVariant $v, bool $axes = false, ?string $locale = null): string
    {
        if ($v === null) {
            return '';
        }

        $parts = [];
        foreach ((array) ($v->getAttribute('fg_values') ?? []) as [$axis, $value, $valueId, $axisId]) {
            $value = \App\Support\VariantOptions::valueName((int) $valueId, (string) $value, $locale);
            $parts[] = $axes && trim((string) $axis) !== ''
                ? \App\Support\VariantOptions::axisName((int) $axisId, (string) $axis, $locale) . ': ' . $value
                : $value;
        }

        return implode($axes ? ' · ' : ' / ', $parts);
    }

    /** "Product – Pink"; the product's own name when no variation (or no label). */
    public static function giftName(string $name, ?ProductVariant $v, ?string $locale = null): string
    {
        $label = self::variantLabel($v, false, $locale);

        return $label === '' ? $name : $name . ' – ' . $label;
    }

    /**
     * Units a chosen variation can give: the parent published, the variation
     * its own product's and marked in stock, and its shelf -- the same shelf
     * StockClaim takes from (the variation's own count when it keeps one, the
     * parent's otherwise, unlimited when neither counts).
     */
    public static function variantAvailable(?Product $p, ?ProductVariant $v): int
    {
        if ($p === null || $v === null || $p->status !== 'publish' || (int) $v->product_id !== (int) $p->id || $v->stock_status !== 'instock') {
            return 0;
        }

        if ($v->manage_stock) {
            return max(0, (int) $v->stock);
        }

        return $p->manage_stock ? max(0, (int) $p->stock) : PHP_INT_MAX;
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
            ->get(array_merge(['range_id', 'product_id', 'quantity', 'kind'], self::hasRole() ? ['role'] : [], self::hasVariant() ? ['variant_id'] : []))
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
                // [product, quantity, kind, role(, variant)]: the type and
                // main/backup drive the fill when the range has counts (see
                // linesFor()). The fifth entry is the chosen variation (Lane
                // FGV) and is there ONLY when one was chosen, so every gift
                // saved before it compiles to exactly the four it always did.
                'gifts' => array_values(array_map(
                    static fn ($i) => array_merge([(int) $i->product_id, max(1, min(self::MAX_QTY, (int) $i->quantity)),
                        self::kind($i->kind ?? null), ($i->role ?? 'primary') === 'backup' ? 'backup' : 'primary'],
                        (int) ($i->variant_id ?? 0) > 0 ? [(int) $i->variant_id] : []),
                    ($items[$r->id] ?? collect())->all(),
                )),
                'needs' => self::needsOf($r),
            ];
        }

        $compiled = ['v' => 2, 'ranges' => $out];
        $this->settings->set(self::COMPILED, $compiled);

        return $compiled;
    }

    /**
     * (Lane OAG) The ranges-and-gifts revision: a short fingerprint of every
     * range row and every gift row as stored, timestamps and row ids left out,
     * so it moves when the CONTENT moves and a save that changes nothing keeps
     * it. The owner edits from the desk and from the phone: each screen sends
     * the revision it loaded, and FreeGiftsController::guard() refuses a save
     * made from an older one (409) instead of overwriting the newer work.
     *
     * Built from rows the caller already holds (the payload's two reads), so
     * it costs no query of its own.
     */
    public static function revisionOf(iterable $ranges, iterable $items): string
    {
        $norm = static function (iterable $rows, array $drop): array {
            $out = [];
            foreach ($rows as $row) {
                $r = array_diff_key((array) $row, array_flip($drop));
                ksort($r);
                $out[] = array_map(static fn ($v) => $v === null ? null : (is_bool($v) ? (string) (int) $v : (string) $v), $r);
            }
            usort($out, static fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));

            return $out;
        };

        return substr(sha1(json_encode([
            $norm($ranges, ['created_at', 'updated_at']),
            $norm($items, ['id', 'created_at', 'updated_at']),
        ])), 0, 16);
    }

    /**
     * The revision as stored NOW, read with FOR UPDATE inside the caller's
     * transaction, so two saves cannot both pass the comparison: on MySQL the
     * second waits for the first to commit, then reads the new rows.
     */
    public static function lockedRevision(): string
    {
        return self::revisionOf(
            DB::table('free_gift_ranges')->orderBy('id')->lockForUpdate()->get(),
            DB::table('free_gift_range_items')->orderBy('id')->lockForUpdate()->get(),
        );
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
        $items = DB::table('free_gift_range_items')->get(array_merge(['range_id', 'product_id', 'quantity', 'kind'], self::hasRole() ? ['role'] : [], self::hasVariant() ? ['variant_id'] : []))->groupBy('range_id');
        $productIds = $items->flatten(1)->pluck('product_id')->unique()->values()->all();
        $products = $productIds === [] ? collect() : Product::withTrashed()->whereIn('id', $productIds)
            ->get(['id', 'name', 'status', 'type', 'stock_status', 'manage_stock', 'stock', 'deleted_at'])->keyBy('id');
        // (Lane FGV) The chosen variations, one query, none when there are none.
        $variants = self::variants($items->flatten(1)->pluck('variant_id')->filter()->all());

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

                $vid = (int) ($g->variant_id ?? 0);

                if ($p === null || $p->deleted_at !== null || $p->status !== 'publish') {
                    $out[] = ['level' => 'alert', 'text' => $label . ': a picked product is deleted or not published, so it is skipped.', 'range' => (int) $r->id];
                } elseif ($vid > 0) {
                    $v = $variants[$vid] ?? null;
                    $vname = self::giftName((string) $p->name, $v);
                    $have = self::variantAvailable($p, $v);

                    if ($v === null || (int) $v->product_id !== (int) $p->id) {
                        $out[] = ['level' => 'alert', 'text' => $label . ': the variation picked for "' . $p->name . '" no longer exists, so it is skipped; pick it again.', 'range' => (int) $r->id];
                    } elseif ($have < 1) {
                        $out[] = ['level' => 'warn', 'text' => $label . ': "' . $vname . '" is out of stock — skipped until restocked; the rest of the range is still given.', 'range' => (int) $r->id];
                    } elseif ($have < (int) $g->quantity) {
                        $out[] = ['level' => 'warn', 'text' => $label . ': only ' . $have . ' of "' . $vname . '" left for ' . (int) $g->quantity . ' per order.', 'range' => (int) $r->id];
                    }
                } elseif ($p->type === 'variable') {
                    $out[] = ['level' => 'alert', 'text' => $label . ': "' . $p->name . '" has options (shades/sizes) and no variation is picked, so it is skipped; add it again and choose the variation.', 'range' => (int) $r->id];
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

    /** [kind => [English one, English many, [Arabic 1, 2, 3-10, 11+]]] for criteriaWording(), in the sentence's order: full size, minis, masks (Lane GBX). */
    public const WORDS = [
        'full' => ['Full Size Product', 'Full Size Products', ['منتج بالحجم الكامل', 'منتجان بالحجم الكامل', ':n منتجات بالحجم الكامل', ':n منتجاً بالحجم الكامل']],
        'mini' => ['Mini Product', 'Mini Products', ['منتج صغير واحد', 'منتجان صغيران', ':n منتجات صغيرة', ':n منتجاً صغيراً']],
        'mask' => ['Mask', 'Masks', ['قناع واحد', 'قناعان', ':n أقنعة', ':n قناعاً']],
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

    /** Whether the chosen-variation column (Lane FGV) has been added yet. Asked only on a compile or the checklist, never per page. */
    public static function hasVariant(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('free_gift_range_items', 'variant_id');
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
     * @return array{kbbFgRows: list<array>, kbbFgThumbs?: list<array>, kbbFgWords: string}
     */
    public function box(array $totals, ?string $country = null): array
    {
        $lines = $this->forTotals($totals, $country);

        if ($lines === []) {
            // (Lane FGL) A shopper who can see gifts gets an empty marker, so a
            // box that comes into stock later has a place to appear; with the
            // switch off (or Testing, for everyone else) there is no marker and
            // no live check at all.
            return ['kbbFgRows' => [], 'kbbFgWords' => '', 'kbbFgSig' => $this->visibleTo(app()->bound('request') ? request() : null) ? '0' : null];
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

        // kbbFgThumbs: the small photos, full size first (fullFirst()); the list keeps this order.
        $box = ['kbbFgRows' => self::rows($lines), 'kbbFgThumbs' => self::rows(self::fullFirst($lines)), 'kbbFgWords' => $words];

        return $box + ['kbbFgSig' => $this->sig($box)];
    }

    /**
     * THE LIVE BOX'S FINGERPRINT (Lane FGL). The owner, 24 October: "the free
     * gifts box must update silently at backend during customer journey, to
     * see what's actually available as gifts and as per criteria ... i don't
     * want to disturb the customer at all. and neither compromise on the page
     * speed or server load".
     *
     * Twelve hex characters over everything the box draws -- the rows (product,
     * name, brand, photo, quantity, value), the photo order, the band's
     * sentence, the look and the owner's words -- printed on the box as
     * data-fgs. GET /api/cart/gifts compares the shopper's copy with a fresh
     * one and answers 204, no body, when they match, which is nearly always;
     * only a box that really changed travels. No query of its own: it hashes
     * what box() already read.
     */
    public function sig(array $box): string
    {
        $config = $this->config();

        return substr(sha1(serialize([
            $box['kbbFgRows'], $box['kbbFgThumbs'] ?? null, $box['kbbFgWords'] ?? '',
            $config['look'] ?? null, $config['show_value'] ?? null,
            $this->text('title'), $this->text('view_all'), $this->text('hide'), $this->text('free'),
            $this->count(self::units($box['kbbFgRows'])), $this->urgency(),
        ])), 0, 12);
    }

    /**
     * THE URGENCY LINE (Lane GU): [lead, words] in the language being drawn,
     * or null while Display -> Gift box look -> "Urgency line under the box"
     * is off or its text is empty. `lead` is the text's leading emoji or
     * symbols ("🔥"), drawn in its own colours outside the fire sweep; `words`
     * the rest. Both are the owner's settings: callers print them escaped.
     * No query: config() is the free_gifts row the box has already read.
     *
     * Drawn only where the caller asks -- the live box (cart panel, cart page,
     * checkout: box() supplies kbbFgSig) and the basket reminder email --
     * never on an order's own box or email.
     *
     * @return array{lead:string,words:string}|null
     */
    public function urgency(): ?array
    {
        if (! (self::lookOf($this->config()['look'] ?? null)['urgency'])) {
            return null;
        }

        return $this->urgencyWords();
    }

    /** urgency() without the box's switch: the homepage has its own (HOME['urgency']). */
    public function urgencyWords(): ?array
    {
        $text = trim(mb_substr($this->text('urgency'), 0, self::URGENCY_MAX));
        if ($text === '') {
            return null;
        }

        return preg_match('/^([^\p{L}\p{N}\s]+)\s+(\S.*)$/us', $text, $m) === 1
            ? ['lead' => $m[1], 'words' => $m[2]]
            : ['lead' => '', 'words' => $text];
    }

    /**
     * The email's still frame of the sweep (Lane GU): email clients run no
     * CSS animation, so the reminder prints the line grey with about a fifth
     * of it -- whole words, nearest the middle -- in the fire colour.
     * [before, hot, after], each a plain string the caller escapes.
     *
     * @return array{0:string,1:string,2:string}
     */
    public static function urgencySlice(string $words): array
    {
        $parts = preg_split('/(\s+)/u', $words, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $len = mb_strlen($words);
        $target = $len * 0.2;
        $best = null;
        $offset = 0;
        $starts = [];

        foreach ($parts as $i => $part) {
            $starts[$i] = $offset;
            $offset += mb_strlen($part);
        }

        // Every run of whole words; the one whose length is nearest a fifth,
        // then whose centre is nearest the line's centre.
        foreach ($parts as $i => $part) {
            if (trim($part) === '') {
                continue;
            }
            for ($j = $i; $j < count($parts); $j++) {
                if (trim($parts[$j]) === '') {
                    continue;
                }
                $end = $starts[$j] + mb_strlen($parts[$j]);
                $score = [abs(($end - $starts[$i]) - $target), abs(($starts[$i] + $end) / 2 - $len / 2)];
                if ($best === null || $score < $best[0]) {
                    $best = [$score, $i, $j];
                }
            }
        }

        if ($best === null) {
            return [$words, '', ''];
        }

        return [
            implode('', array_slice($parts, 0, $best[1])),
            implode('', array_slice($parts, $best[1], $best[2] - $best[1] + 1)),
            implode('', array_slice($parts, $best[2] + 1)),
        ];
    }

    /**
     * Gift lines ordered full size, mini, mask, then untagged, keeping each
     * kind's own order (usort is not stable before PHP 8; the index breaks ties).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public static function fullFirst(array $lines): array
    {
        $rank = ['full' => 0, 'mini' => 1, 'mask' => 2];
        $keyed = array_map(null, $lines, array_keys($lines));
        usort($keyed, static fn (array $a, array $b) => [($rank[$a[0]['kind'] ?? ''] ?? 3), $a[1]] <=> [($rank[$b[0]['kind'] ?? ''] ?? 3), $b[1]]);

        return array_column($keyed, 0);
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
