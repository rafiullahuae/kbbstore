<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Services\Cutouts\ProductCutouts;
use App\Services\FreeGifts;

/**
 * THE HOMEPAGE FREE-GIFTS STRIP -- Appearance -> Free gifts strip.  (Lane GST)
 *
 * The owner, 6 November 2026: "a thin bar type box, under the main banner on
 * homepage ... keep the heading in center, on left side 3 floating products,
 * 1 can go outside the box etc, and same on right side further products, the
 * live products which we actually offer as gifts" -- "the size of the products
 * are very important in real life" -- "i want a small gift icon upon each
 * product in the strip" -- "allow us to choose the designs from the list along
 * with preview, and need all controls to edit those sections".
 *
 * The five designs are docs/plans/gift-strip/build.py's S1-S5, ported class
 * for class (gs- became kgs-, so nothing on the shop is restyled by them).
 *
 * TRUE SIZE. Every product is drawn at ONE scale: --k pixels per real
 * centimetre (10 on a computer, 6 on a phone) times its real height --h in
 * cm, which the owner sets per product. A 9 cm jar is drawn 90px tall next to
 * a 14.5 cm tube at 145px, as they stand on a shelf.
 *
 * NOTHING WHEN OFF, AND OFF BY DEFAULT. forPage() looks at one key of the
 * settings map the request already holds and returns null before it
 * normalises anything: no query, no byte (StorefrontEnglishUnchangedTest).
 *
 * NO QUERY WHEN ON EITHER. What the strip needs of each product -- its id,
 * names and photo path -- is a SNAPSHOT written into this setting when the
 * owner saves the screen or prepares cut-outs (the only moments a new gift
 * can become drawable, because a gift without a cut-out is never drawn). With
 * "Live free gifts" the page then keeps only the snapshot products that are a
 * gift in a range running TODAY, read from FreeGifts' compiled ranges -- a
 * settings value, not a table -- so a gift the owner removes leaves the strip
 * on the next page view without anybody touching this screen. Whether a
 * cut-out is ready is read from disk (ProductCutouts::for()), never stored.
 */
final class GiftStrip
{
    public const KEY = 'gift_strip';

    /** The approved designs, in the owner's list order. */
    public const STYLES = [
        'S1' => 'Mint Fresh',
        'S2' => 'Blush Ribbon',
        'S3' => 'Gold Ticket',
        'S4' => 'Plum Night',
        'S5' => 'Pastel Shimmer',
    ];

    /**
     * The words, EN and AR. The Arabic follows the shop's own gift wording
     * (FreeGifts: "هدايا مجانية مع كل طلب") and speaks to her, as the rest of
     * the shop does ("احصلي", "تسوّقي"). The arrow points the way an Arabic
     * line reads.
     */
    public const TEXTS = [
        'h' => ['FREE GIFTS', 'هدايا مجانية', 'Heading'],
        'sub' => ['with every order', 'مع كل طلب', 'Under the heading'],
        'link' => ['See the gifts →', 'شاهدي الهدايا ←', 'Link text'],
    ];

    /** The page the Super Sale gift row lives on -- the shop's free-gifts page. */
    public const DEFAULT_URL = '/super-sale/';

    public const PER_SIDE = 3;

    public const MAX_PICKS = 6;

    /** How many snapshot rows are kept: every live gift, with room to spare. */
    public const MAX_SNAP = 24;

    public const H_MIN = 2.0;

    public const H_MAX = 40.0;

    public const H_DEFAULT = 12.0;

    /**
     * Real heights (cm) of the six gifts live on 6 November, matched by name
     * so a fresh install of the same catalogue starts right (build.py REAL):
     * Arencia Vitamin C Booster Shot 30 ml tube 14 · AXIS-Y Glow Serum 50 ml
     * 14.5 · Medicube deodorant 83 g stick 11.5 · Medicube Gua Sha neck cream
     * 50 ml 12 · Medicube eye mask 6-pair pack 12 · Medicube peeling pad
     * 70-pad jar 9. Anything else starts at 12 until he says otherwise.
     */
    public const KNOWN_HEIGHTS = [
        '/arencia.*vitamin\s*c/i' => 14.0,
        '/axis-?y.*(glow|serum)/i' => 14.5,
        '/medicube.*deodorant/i' => 11.5,
        '/medicube.*gua\s*sha/i' => 12.0,
        '/medicube.*eye\s*mask/i' => 12.0,
        '/medicube.*peel(ing)?\s*pad/i' => 9.0,
    ];

    /** Every switch's default: OFF, so the shop is unchanged until he turns it on. */
    public const DEFAULTS = [
        'on' => false,
        'desktop' => true,
        'mobile' => true,
        'style' => 'S1',
        'icon' => true,
        'anim' => true,
        'url' => self::DEFAULT_URL,
        'source' => 'live',
    ];

    /**
     * The approved CSS. Scoped to kgs- classes; plain values and calc() only
     * -- no round(), no color-mix() (CLAUDE.md's landmine) -- and every
     * animation is transform or opacity. Phone rules under max-width:767px.
     */
    public const BASE_CSS = '.kgs-sec{padding:30px 0 12px}'
        . '.kgs{position:relative;display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);align-items:center;height:130px;max-width:1200px;margin:0 auto;border-radius:22px;padding:0 24px;isolation:isolate;--k:10px}'
        . '.kgs-bg{position:absolute;inset:0;border-radius:inherit;overflow:hidden;z-index:-1}'
        . '.kgs-mid{position:relative;z-index:3;text-align:center;padding:0 18px;display:flex;flex-direction:column;align-items:center;gap:4px;min-width:0}'
        . '.kgs-h{margin:0;font-weight:800;font-size:clamp(28px,3.4vw,44px);line-height:1;letter-spacing:.02em;white-space:nowrap;color:inherit}'
        . '.kgs-sub{font-weight:600;font-size:clamp(14px,1.4vw,18px);line-height:1.2;opacity:.9}'
        . '.kgs-link{font-weight:800;font-size:13px;line-height:1.3;text-decoration:none;margin-top:2px}'
        . '.kgs-link:hover{text-decoration:underline}'
        . '.kgs-side{position:relative;height:100%}'
        . '.kgs-p{position:absolute;bottom:12px;height:calc(var(--k) * var(--h));transform:rotate(var(--r,0deg));filter:drop-shadow(0 12px 12px rgba(40,20,40,.22));animation:kgsBob 6s ease-in-out infinite}'
        . '.kgs-p img{display:block;height:100%;width:auto}'
        . '@keyframes kgsBob{0%,100%{transform:translateY(0) rotate(var(--r,0deg))}50%{transform:translateY(-6px) rotate(calc(var(--r,0deg) + 2deg))}}'
        . '.kgs-l .kgs-p:nth-child(1){left:8%;bottom:10px;--r:-8deg}'
        . '.kgs-l .kgs-p:nth-child(2){left:30%;bottom:6px;--r:4deg;animation-delay:-2s;z-index:1}'
        . '.kgs-l .kgs-p:nth-child(3){left:56%;bottom:12px;--r:9deg;animation-delay:-4s}'
        . '.kgs-r .kgs-p:nth-child(1){right:60%;bottom:10px;--r:-8deg;animation-delay:-1s}'
        . '.kgs-r .kgs-p:nth-child(2){right:26%;bottom:22px;--r:-3deg;animation-delay:-3s;z-index:1}'
        . '.kgs-r .kgs-p:nth-child(3){right:3%;bottom:12px;--r:6deg;animation-delay:-5s}'
        // The gift icon (the owner: "a small gift icon upon each product").
        . '.kgs-gi{position:absolute;top:-7px;right:-9px;width:24px;height:24px;border-radius:50%;display:grid;place-items:center;background:var(--kgs-a,#2e8f62);color:var(--kgs-ai,#fff);box-shadow:0 0 0 2px #fff,0 4px 8px -2px rgba(0,0,0,.3);animation:kgsGi 5s ease-in-out infinite;animation-delay:inherit}'
        . '.kgs-gi svg{display:block;width:14px;height:14px}'
        . '@keyframes kgsGi{0%,84%,100%{transform:scale(1) rotate(0)}89%{transform:scale(1.18) rotate(-10deg)}94%{transform:scale(1) rotate(8deg)}}'
        . '.kgs-still .kgs-p,.kgs-still .kgs-gi,.kgs-still .kgs-fx,.kgs-still .kgs-shine{animation:none}'
        . '@media (prefers-reduced-motion:reduce){.kgs-p,.kgs-gi,.kgs-fx,.kgs-shine{animation:none}}'
        . '@media (min-width:768px){.kgs-sec.kgs-nd{display:none}}'
        . '@media (max-width:767px){.kgs-sec{padding:16px 0 6px}.kgs-sec.kgs-nm{display:none}'
        . '.kgs{--k:6px;height:84px;padding:0 4px;border-radius:18px;grid-template-columns:31% minmax(0,1fr) 31%}'
        . '.kgs-mid{padding:0 2px;gap:2px}.kgs-h{font-size:18px}.kgs-sub{font-size:11.5px}.kgs-link{font-size:11.5px}'
        . '.kgs-l .kgs-p:nth-child(1){left:3%;bottom:6px}.kgs-l .kgs-p:nth-child(2){left:31%;bottom:3px}.kgs-l .kgs-p:nth-child(3){left:64%;bottom:8px}'
        . '.kgs-r .kgs-p:nth-child(1){right:63%;bottom:12px;z-index:2}.kgs-r .kgs-p:nth-child(2){right:25%;bottom:6px;z-index:1}.kgs-r .kgs-p:nth-child(3){right:-3%;bottom:4px;z-index:2}'
        . '.kgs-gi{width:17px;height:17px;top:-5px;right:-6px;box-shadow:0 0 0 1.5px #fff,0 3px 6px -2px rgba(0,0,0,.3)}.kgs-gi svg{width:10px;height:10px}}';

    /** Each design's own rules, after BASE_CSS (build.py STYLES, renamed). */
    public const STYLE_CSS = [
        'S1' => '.kgs-bg{background:radial-gradient(60% 140% at 50% 0%,#ffffff,transparent 70%),linear-gradient(90deg,#e3f7ec,#f4fbf7 50%,#e3f7ec);border:1.5px solid #bfe6cf}'
            . '.kgs{color:#1d4e35;--kgs-a:#2e8f62;--kgs-ai:#fff}.kgs-link{color:#2e8f62}',
        'S2' => '.kgs-bg{background:linear-gradient(90deg,#ffe3ec,#fff4f7 50%,#ffe3ec)}'
            . '.kgs-bg::before{content:"";position:absolute;left:0;right:0;top:50%;height:22px;transform:translateY(-50%);background:linear-gradient(180deg,#f7a7c1,#ec7fa5);opacity:.35}'
            . '.kgs-mid{background:#fff;border-radius:999px;padding:10px 28px;box-shadow:0 10px 22px -12px rgba(236,58,128,.5)}'
            . '.kgs{color:#3b1f2b;--kgs-a:#e3246e;--kgs-ai:#fff}.kgs-link{color:#e3246e}'
            . '@media (max-width:767px){.kgs-mid{padding:8px 12px}}',
        'S3' => '.kgs-bg{background:linear-gradient(90deg,#fff3dc,#fffaf0 50%,#fff3dc)}'
            . '.kgs-bg::before{content:"";position:absolute;inset:8px;border:2px dashed #d9b46a;border-radius:16px}'
            . '.kgs::before,.kgs::after{content:"";position:absolute;top:50%;width:26px;height:26px;border-radius:50%;background:#fff;transform:translateY(-50%);z-index:0}'
            . '.kgs::before{left:-13px}.kgs::after{right:-13px}'
            . '.kgs{color:#3d2a08;--kgs-a:#a87a22;--kgs-ai:#fff}.kgs-link{color:#a87a22}',
        'S4' => '.kgs-bg{background:radial-gradient(40% 120% at 50% 50%,rgba(255,79,154,.35),transparent 70%),linear-gradient(90deg,#2a0f3d,#3d1452 50%,#2a0f3d)}'
            . '.kgs-bg::before{content:"";position:absolute;inset:0;background:radial-gradient(circle,#FFD36E 0 1.2px,transparent 2.2px) 0 0/34px 34px;opacity:.35}'
            . '.kgs{color:#fff;--kgs-a:#FFD36E;--kgs-ai:#3d1452}.kgs-sub{opacity:.8}.kgs-link{color:#FFD36E}'
            . '.kgs-p{filter:drop-shadow(0 0 14px rgba(255,211,110,.35)) drop-shadow(0 12px 12px rgba(0,0,0,.35))}',
        'S5' => '.kgs-bg{background:linear-gradient(90deg,#ffe0ef,#efe4ff,#ffe9da)}'
            . '.kgs-fx{position:absolute;inset:-60% -10%;background:radial-gradient(25% 60% at 20% 50%,#ffc2dc,transparent 70%),radial-gradient(25% 60% at 80% 50%,#d6c6ff,transparent 70%),radial-gradient(20% 60% at 50% 50%,#ffd9bf,transparent 70%);animation:kgsSpin 18s linear infinite;opacity:.9}'
            . '.kgs-shine{position:absolute;inset:0;background:linear-gradient(100deg,transparent 30%,rgba(255,255,255,.6) 45%,transparent 60%);transform:translateX(-100%);animation:kgsShine 6s ease-in-out infinite}'
            . '@keyframes kgsSpin{to{transform:rotate(360deg)}}@keyframes kgsShine{60%,100%{transform:translateX(100%)}}'
            . '.kgs{color:#2a1033;--kgs-a:#c0246b;--kgs-ai:#fff}.kgs-link{color:#c0246b}',
    ];

    /** The gift icon, drawn once per strip as a symbol and used per product. */
    public const GIFT_SYMBOL = '<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><symbol id="kgs-gift" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" d="M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7z"/></symbol></svg>';

    public const GIFT_ICON = '<i class="kgs-gi" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" focusable="false"><use href="#kgs-gift"/></svg></i>';

    /**
     * The saved setting with every key present, each value one of its own
     * options or its default (rule 5). Never refuses: a damaged row reads as
     * the defaults, and the defaults are OFF.
     *
     * @return array{on:bool,desktop:bool,mobile:bool,style:string,icon:bool,anim:bool,url:string,source:string,texts:array<string,string>,picks:list<int>,heights:array<int,float>,white:list<int>,snap:list<array{id:int,name:string,name_ar:string,image:string}>}
     */
    public static function normalise(mixed $in): array
    {
        $in = is_array($in) ? $in : [];
        $bool = static fn (string $k): bool => array_key_exists($k, $in) ? filter_var($in[$k], FILTER_VALIDATE_BOOLEAN) : self::DEFAULTS[$k];

        $texts = [];
        $savedTexts = is_array($in['texts'] ?? null) ? $in['texts'] : [];
        foreach (self::TEXTS as $k => [$en, $ar]) {
            $texts[$k . '_en'] = self::text($savedTexts[$k . '_en'] ?? null, $en);
            $texts[$k . '_ar'] = self::text($savedTexts[$k . '_ar'] ?? null, $ar);
        }

        $picks = [];
        foreach (is_array($in['picks'] ?? null) ? $in['picks'] : [] as $id) {
            $id = filter_var($id, FILTER_VALIDATE_INT);
            if (is_int($id) && $id > 0 && ! in_array($id, $picks, true)) {
                $picks[] = $id;
            }
        }

        $heights = [];
        foreach (is_array($in['heights'] ?? null) ? $in['heights'] : [] as $id => $cm) {
            $id = filter_var($id, FILTER_VALIDATE_INT);
            if (is_int($id) && $id > 0 && is_numeric($cm)) {
                $heights[$id] = self::cm((float) $cm);
            }
        }

        $white = [];
        foreach (is_array($in['white'] ?? null) ? $in['white'] : [] as $id) {
            $id = filter_var($id, FILTER_VALIDATE_INT);
            if (is_int($id) && $id > 0 && ! in_array($id, $white, true)) {
                $white[] = $id;
            }
        }

        $snap = [];
        foreach (is_array($in['snap'] ?? null) ? $in['snap'] : [] as $row) {
            $id = is_array($row) ? filter_var($row['id'] ?? null, FILTER_VALIDATE_INT) : false;
            $image = is_array($row) && is_string($row['image'] ?? null) ? trim($row['image']) : '';
            if (! is_int($id) || $id < 1 || $image === '' || strlen($image) > 500 || preg_match('/[\s"\'<>\\\\]/', $image)) {
                continue;
            }
            $snap[] = ['id' => $id, 'name' => self::text($row['name'] ?? null, ''), 'name_ar' => self::text($row['name_ar'] ?? null, ''), 'image' => $image];
            if (count($snap) >= self::MAX_SNAP) {
                break;
            }
        }

        $style = is_string($in['style'] ?? null) && isset(self::STYLES[$in['style']]) ? $in['style'] : self::DEFAULTS['style'];
        $source = ($in['source'] ?? null) === 'pick' ? 'pick' : 'live';

        return [
            'on' => $bool('on'),
            'desktop' => $bool('desktop'),
            'mobile' => $bool('mobile'),
            'style' => $style,
            'icon' => $bool('icon'),
            'anim' => $bool('anim'),
            'url' => self::url($in['url'] ?? null),
            'source' => $source,
            'texts' => $texts,
            'picks' => array_slice($picks, 0, self::MAX_PICKS),
            'heights' => $heights,
            'white' => $white,
            'snap' => $snap,
        ];
    }

    /** A text: a string, trimmed, at most 80 characters, or the default. */
    private static function text(mixed $v, string $default): string
    {
        if (! is_string($v)) {
            return $default;
        }
        $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '');

        return $v === '' ? $default : mb_substr($v, 0, 80);
    }

    /** A real height in cm: 2..40, to half a millimetre's tenth. */
    public static function cm(float $cm): float
    {
        return round(max(self::H_MIN, min(self::H_MAX, $cm)), 1);
    }

    /**
     * The link, scheme-checked BEFORE it is ever an href (rule 5): a path on
     * this shop ("/super-sale/") or an http(s) address. Anything else --
     * javascript:, data:, "//evil", a stray space -- is the default.
     */
    public static function url(mixed $v): string
    {
        if (! is_string($v)) {
            return self::DEFAULT_URL;
        }
        $v = trim($v);
        if ($v === '' || strlen($v) > 300 || preg_match('/[\s"\'<>\\\\\x00-\x1F]/', $v)) {
            return self::DEFAULT_URL;
        }
        if (str_starts_with($v, '/') && ! str_starts_with($v, '//')) {
            return $v;
        }
        if (preg_match('#^https?://[a-z0-9.-]+(:\d+)?(/|$)#i', $v) === 1) {
            return $v;
        }

        return self::DEFAULT_URL;
    }

    /** The real height a product starts at: his number, else a known gift's, else 12. */
    public static function heightFor(array $cfg, int $id, string $name): float
    {
        if (isset($cfg['heights'][$id])) {
            return (float) $cfg['heights'][$id];
        }
        foreach (self::KNOWN_HEIGHTS as $re => $cm) {
            if (preg_match($re, $name) === 1) {
                return $cm;
            }
        }

        return self::H_DEFAULT;
    }

    /** The CSS one design needs, and only that design. */
    public static function css(string $style): string
    {
        return self::BASE_CSS . (self::STYLE_CSS[$style] ?? self::STYLE_CSS['S1']);
    }

    /**
     * What the homepage draws, or null for nothing at all.
     *
     * Order of the checks is the cost order: the switch first, from the map
     * the request already holds (an absent row is off, and costs nothing);
     * then the gifts' own visibility, the compiled ranges and the cut-outs on
     * disk. No database query at any step.
     *
     * @param  array<string,mixed>  $settings  SettingsService::all()
     */
    public static function forPage(array $settings, ?\Illuminate\Http\Request $request): ?array
    {
        $raw = $settings[self::KEY] ?? null;

        if (! is_array($raw) || ! filter_var($raw['on'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $cfg = self::normalise($raw);

        if (! $cfg['desktop'] && ! $cfg['mobile']) {
            return null;
        }

        $gifts = app(FreeGifts::class);

        // "FREE GIFTS with every order" is a promise; while the gifts are off
        // (or in testing for somebody else) the strip does not make it.
        if (! $gifts->visibleTo($request)) {
            return null;
        }

        $rows = $cfg['snap'];

        if ($cfg['source'] === 'live') {
            $live = array_flip(self::runningGiftIds($gifts));
            $rows = array_values(array_filter($rows, static fn (array $r) => isset($live[$r['id']])));
        } else {
            $byId = array_column($rows, null, 'id');
            $rows = array_values(array_filter(array_map(static fn (int $id) => $byId[$id] ?? null, $cfg['picks'])));
        }

        $ar = ! Locale::isDefault();
        $items = [];
        foreach ($rows as $r) {
            $cut = ProductCutouts::for($r['image']);
            if ($cut === null) {
                continue;
            }
            $name = $ar && $r['name_ar'] !== '' ? $r['name_ar'] : $r['name'];
            $cm = self::heightFor($cfg, $r['id'], $r['name']);
            $ratio = $cut['w'] / max(1, $cut['h']);
            $items[] = [
                'cm' => $cm,
                'alt' => $name,
                'src' => $cut['src'],
                'srcset' => $cut['srcset'],
                'w' => $cut['w'],
                'h' => $cut['h'],
                // The drawn width on each device: --k px per cm x height x the cut-out's shape.
                'sizes' => '(max-width:767px) ' . max(1, (int) ceil(6 * $cm * $ratio)) . 'px, ' . max(1, (int) ceil(10 * $cm * $ratio)) . 'px',
            ];
            if (count($items) >= self::PER_SIDE * 2) {
                break;
            }
        }

        if ($items === []) {
            return null;
        }

        $half = (int) ceil(count($items) / 2);
        $lang = $ar ? 'ar' : 'en';

        return [
            'style' => $cfg['style'],
            'css' => self::css($cfg['style']),
            'icon' => $cfg['icon'],
            'anim' => $cfg['anim'],
            'cls' => trim('kgs-sec' . ($cfg['desktop'] ? '' : ' kgs-nd') . ($cfg['mobile'] ? '' : ' kgs-nm')),
            'h' => $cfg['texts']['h_' . $lang],
            'sub' => $cfg['texts']['sub_' . $lang],
            'link' => $cfg['texts']['link_' . $lang],
            'href' => str_starts_with($cfg['url'], '/') ? Url::to($cfg['url']) : $cfg['url'],
            'left' => array_slice($items, 0, $half),
            'right' => array_slice($items, $half),
        ];
    }

    /**
     * Every product that is a gift (main or backup) in a range running today,
     * from FreeGifts' compiled ranges -- a settings value, so no query.
     *
     * @return list<int>
     */
    public static function runningGiftIds(FreeGifts $gifts): array
    {
        $ids = [];
        foreach (self::running($gifts) as $r) {
            foreach ($r['gifts'] as $g) {
                $ids[(int) $g[0]] = true;
            }
        }

        return array_keys($ids);
    }

    /** The ranges with gifts, in date today -- the rule bannerChoices() uses. */
    private static function running(FreeGifts $gifts): array
    {
        $today = StoreTime::today()->format('Y-m-d');

        return array_values(array_filter($gifts->ranges(), static fn (array $r) => ($r['gifts'] ?? []) !== []
            && (($r['starts'] ?? null) === null || $today >= $r['starts'])
            && (($r['ends'] ?? null) === null || $today <= $r['ends'])));
    }

    /**
     * The live gifts as the cart gives them today (linesFor(): a sold-out
     * main gift gives way to its backup), each product once, full size first.
     * ADMIN ONLY. Two queries however many gifts: the products with their
     * translations, and the chosen variations when a gift names one.
     *
     * @return list<Product>
     */
    public static function liveProducts(FreeGifts $gifts): array
    {
        $running = self::running($gifts);
        if ($running === []) {
            return [];
        }

        $ids = self::runningGiftIds($gifts);
        $products = Product::query()->with('translations')->whereIn('products.id', $ids)
            ->get(FreeGifts::PRODUCT_COLUMNS)->keyBy('id')->all();
        $variants = $gifts->variantsFor($running);

        $lines = [];
        foreach ($running as $r) {
            foreach ($gifts->linesFor($r, $products, $variants) as $line) {
                $lines[$line['product_id']] ??= $line;
            }
        }

        return array_values(array_map(static fn (array $l) => $l['product'], FreeGifts::fullFirst(array_values($lines))));
    }

    /**
     * The snapshot row for a product: what the page needs and nothing else.
     *
     * @return array{id:int,name:string,name_ar:string,image:string}
     */
    public static function snapRow(Product $p): array
    {
        $name = (string) $p->name;
        $ar = (string) $p->t('name', 'ar');

        return ['id' => (int) $p->id, 'name' => $name, 'name_ar' => $ar === $name ? '' : $ar, 'image' => trim((string) $p->image)];
    }
}
