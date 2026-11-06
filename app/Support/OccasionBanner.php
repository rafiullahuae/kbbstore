<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Banners;
use App\Services\Cutouts\ProductCutouts;

/**
 * The homepage OCCASION BANNER (Lane OHB) -- Appearance -> Occasion banner.
 *
 * The owner, 6 November 2026: floating products with small animations, a
 * background whose colours change "beautifully, not dull or rushed", big words
 * on the LEFT with NO box, the slider's own size (computer full width x 600 --
 * 548 at 1024, 411 at 768 -- phone full width x 500), five products at random
 * from a pool he chooses, one per brand, "super transparent" cut-outs at their
 * TRUE relative size ("the mini must be smaller than the Medicube stick"), and
 * three display options. Then: "allow us to choose the designs from the list
 * along with preview, and need all controls to edit those sections."
 *
 * The approved designs are docs/plans/home-hero/build4.py (layouts G, H, I x
 * four occasions). css() is that file's BASE + COPY_DESKTOP + PLACE + layout
 * CSS, renamed hz- -> ohb- and with the product positions generated from
 * SLOTS so the Arabic shop can mirror them.
 *
 * ONE SETTING, `home_occasion_banner`, autoloaded: the page reads it out of the
 * request's settings map and issues NO query of its own. Everything the
 * banner needs about a product -- its brand, its name for the alt, the cut-out
 * files' widths -- is a SNAPSHOT written into the pool when the owner saves or
 * prepares cut-outs (OccasionBannerController), never looked up on a page view.
 *
 * SHIPPED OFF: display defaults to `slider`, and view() answers null for it, so
 * the homepage of a shop that never opens the screen is byte-identical.
 */
final class OccasionBanner
{
    public const KEY = 'home_occasion_banner';

    public const DISPLAYS = [
        'slider' => 'Slider only',
        'banner' => 'Banner only',
        'both' => 'Banner first, then the slider',
    ];

    public const LAYOUTS = [
        'G' => 'G · Floating Cluster',
        'H' => 'H · Glow Halo',
        'I' => 'I · Wave Stage',
    ];

    public const TEXTS = [
        'label' => ['Eyebrow', 40],
        'num' => ['Big number', 12],
        'title' => ['Title', 40],
        'offer' => ['Offer line', 40],
        'line' => ['Body (computers only)', 160],
        'cta' => ['Button text', 40],
    ];

    /** The colours: the three cross-fading grounds (a = base, b = glow, c = light) and the ink. */
    public const COLOURS = ['ink', 'acc', 'btn', 'btnInk', 'a1', 'a2', 'a3', 'b1', 'b2', 'b3', 'c1', 'c2', 'c3'];

    public const POOL_MAX = 60;

    public const COUNT_MAX = 5;

    /** Real heights, cm. A product with none is drawn as a 12 cm one. */
    public const CM = [2.0, 40.0, 12.0];

    /** The tallest product in the pool is drawn this tall, in % of the banner's height (build4: COSRX 62%). */
    public const TALLEST = 62.0;

    /**
     * The occasion templates, word for word from build4.py, with Arabic.
     * [name, en texts, ar texts, colours]
     */
    public const TEMPLATES = [
        '1111' => ['11.11 Mega Sale',
            ['label' => '11.11 MEGA SALE', 'num' => '11.11', 'title' => 'MEGA SALE', 'offer' => 'Up to 50% off', 'line' => 'Korean skincare bestsellers, free gifts with every order and fast UAE delivery.', 'cta' => 'Shop the 11.11 sale'],
            ['label' => 'تخفيضات ميجا 11.11', 'num' => '11.11', 'title' => 'تخفيضات ميجا', 'offer' => 'خصم حتى 50%', 'line' => 'الأكثر مبيعاً من العناية الكورية، هدايا مجانية مع كل طلب وتوصيل سريع داخل الإمارات.', 'cta' => 'تسوّقي عروض 11.11'],
            ['ink' => '#2a1033', 'acc' => '#e3246e', 'btn' => '#e3246e', 'btnInk' => '#ffffff',
                'a1' => '#ffd6e8', 'a2' => '#e9d5ff', 'a3' => '#ffe3cf', 'b1' => '#ffb4d2', 'b2' => '#c9b5ff', 'b3' => '#ffc9a8', 'c1' => '#fff0f6', 'c2' => '#f3ecff', 'c3' => '#fff4ea']],
        'uae' => ['UAE National Day',
            ['label' => 'UAE NATIONAL DAY', 'num' => '55', 'title' => 'NATIONAL DAY SALE', 'offer' => 'Up to 55% off', 'line' => 'Celebrating Eid Al Etihad with K-beauty favourites, free gifts and fast UAE delivery.', 'cta' => 'Shop the National Day sale'],
            ['label' => 'اليوم الوطني الإماراتي', 'num' => '55', 'title' => 'تخفيضات اليوم الوطني', 'offer' => 'خصم حتى 55%', 'line' => 'نحتفل بعيد الاتحاد مع مفضّلات الجمال الكوري، هدايا مجانية وتوصيل سريع داخل الإمارات.', 'cta' => 'تسوّقي عروض اليوم الوطني'],
            ['ink' => '#0d2a1b', 'acc' => '#00732f', 'btn' => '#e31b23', 'btnInk' => '#ffffff',
                'a1' => '#dff5e7', 'a2' => '#ffffff', 'a3' => '#ffe4e4', 'b1' => '#b9ebcb', 'b2' => '#f4f4f4', 'b3' => '#ffc9cb', 'c1' => '#f1fbf5', 'c2' => '#ffffff', 'c3' => '#fff3f3']],
        'ny' => ['New Year Sale',
            ['label' => 'NEW YEAR SALE', 'num' => '2027', 'title' => 'NEW YEAR SALE', 'offer' => 'Up to 50% off', 'line' => 'Start the year glowing: K-beauty bestsellers, free gifts and fast UAE delivery.', 'cta' => 'Shop the New Year sale'],
            ['label' => 'تخفيضات العام الجديد', 'num' => '2027', 'title' => 'تخفيضات العام الجديد', 'offer' => 'خصم حتى 50%', 'line' => 'ابدئي العام بإشراقة: الأكثر مبيعاً من الجمال الكوري، هدايا مجانية وتوصيل سريع داخل الإمارات.', 'cta' => 'تسوّقي عروض العام الجديد'],
            ['ink' => '#231a33', 'acc' => '#b07a12', 'btn' => '#2b1d4a', 'btnInk' => '#ffe7a8',
                'a1' => '#fff1cf', 'a2' => '#e7ddff', 'a3' => '#ffe0ef', 'b1' => '#ffe19a', 'b2' => '#cdbcff', 'b3' => '#ffc4df', 'c1' => '#fffaf0', 'c2' => '#f5f1ff', 'c3' => '#fff3f8']],
        'super' => ['Super Sale',
            ['label' => 'SUPER SALE', 'num' => '', 'title' => 'SUPER SALE', 'offer' => 'Up to 50% off', 'line' => 'Perfect skincare to glow: free gifts with every order, fast UAE delivery, pay later with Tabby or Tamara.', 'cta' => 'Shop the Super Sale'],
            ['label' => 'تخفيضات كبرى', 'num' => '', 'title' => 'تخفيضات كبرى', 'offer' => 'خصم حتى 50%', 'line' => 'عناية مثالية لبشرة متوهّجة: هدايا مجانية مع كل طلب، توصيل سريع داخل الإمارات، وادفعي لاحقاً مع تابي أو تمارا.', 'cta' => 'تسوّقي التخفيضات الكبرى'],
            ['ink' => '#2a2228', 'acc' => '#e3246e', 'btn' => '#e3246e', 'btnInk' => '#ffffff',
                'a1' => '#ffe0ea', 'a2' => '#ffe9dc', 'a3' => '#f6e1ff', 'b1' => '#ffc2d6', 'b2' => '#ffd2b8', 'b3' => '#ecc6ff', 'c1' => '#fff4f8', 'c2' => '#fff7f1', 'c3' => '#fbf4ff']],
    ];

    /**
     * The five places a product can float, from build4.py's PLACE, anchored by
     * their BOTTOM edge (top + height there) so a taller or shorter product
     * than the design's stands on the same line: [computer inline-end %,
     * computer bottom %, 768-1023 bottom %, phone inline-start % (null: the
     * inline-END edge, phone end %), phone bottom %, design height %, z, tilt
     * per layout G/H/I, delay per layout G/H/I].
     */
    public const SLOTS = [
        0 => ['end' => 32.5, 'bottom' => 24, 'tab' => 21, 'ps' => 21, 'pe' => null, 'pb' => 3, 'h' => 30, 'z' => 3, 'r' => [-8, -5, -6], 'd' => [0, 0, 0]],
        1 => ['end' => 22, 'bottom' => 28, 'tab' => 28, 'ps' => 38, 'pe' => null, 'pb' => 4, 'h' => 60, 'z' => 0, 'r' => [6, 7, 5], 'd' => [-2, -2.5, -2]],
        2 => ['end' => 12.5, 'bottom' => 27, 'tab' => 27, 'ps' => 57, 'pe' => null, 'pb' => 3, 'h' => 62, 'z' => 1, 'r' => [-4, -6, -7], 'd' => [-4, -4, -3.5]],
        3 => ['end' => 4, 'bottom' => 26, 'tab' => 26, 'ps' => null, 'pe' => 4, 'pb' => 4, 'h' => 54, 'z' => 0, 'r' => [9, 10, 11], 'd' => [-1, -1.5, -1]],
        4 => ['end' => 43, 'bottom' => 18, 'tab' => 18, 'ps' => 3, 'pe' => null, 'pb' => 3, 'h' => 40, 'z' => 2, 'r' => [-10, -12, -9], 'd' => [-3, -3, -2.5]],
    ];

    /** Which slots n products use: the right-hand cluster first. */
    public const SLOTS_FOR = [1 => [2], 2 => [1, 2], 3 => [1, 2, 3], 4 => [4, 1, 2, 3], 5 => [4, 0, 1, 2, 3]];

    /** Phone and 768-1023 heights, as a share of the computer's (build4: 36/62 on a phone). */
    public const PHONE_SCALE = 0.58;

    public const TABLET_SCALE = 0.92;

    /** The live slider's frame (kbbs-1 on kbeautybliss.com, 6 Nov): 2560/1371 capped at 600; phone exactly 500. */
    public const FRAME = ['ratio' => '2560 / 1371', 'max' => 600, 'phone' => 500];

    /** @return array<string,mixed> */
    public static function defaults(string $template = '1111'): array
    {
        $t = self::TEMPLATES[$template] ?? self::TEMPLATES['1111'];

        return [
            'display' => 'slider',
            'layout' => 'G',
            'template' => isset(self::TEMPLATES[$template]) ? $template : '1111',
            'en' => $t[1],
            'ar' => $t[2],
            'colours' => $t[3],
            'link' => '/super-sale/',
            'animate' => true,
            'count' => 5,
            'per_brand' => true,
            'pool' => [],
        ];
    }

    /**
     * Every key present, every value one of its own options or the default,
     * numbers clamped, text trimmed and capped. The pool's snapshot fields are
     * kept only in the exact shapes the controller writes them.
     *
     * @return array<string,mixed>
     */
    public static function sanitize(mixed $in): array
    {
        $in = is_array($in) ? $in : [];
        $template = is_string($in['template'] ?? null) && isset(self::TEMPLATES[$in['template']]) ? $in['template'] : '1111';
        $d = self::defaults($template);
        $out = $d;

        $out['display'] = is_string($in['display'] ?? null) && isset(self::DISPLAYS[$in['display']]) ? $in['display'] : 'slider';
        $out['layout'] = is_string($in['layout'] ?? null) && isset(self::LAYOUTS[$in['layout']]) ? $in['layout'] : 'G';

        foreach (['en', 'ar'] as $lang) {
            $src = is_array($in[$lang] ?? null) ? $in[$lang] : [];
            foreach (self::TEXTS as $k => [, $max]) {
                $v = $src[$k] ?? null;
                $out[$lang][$k] = is_string($v) || is_int($v) || is_float($v)
                    ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $v) ?? ''), 0, $max)
                    : $d[$lang][$k];
            }
        }

        $cols = is_array($in['colours'] ?? null) ? $in['colours'] : [];
        foreach (self::COLOURS as $k) {
            $v = is_string($cols[$k] ?? null) ? strtolower(trim($cols[$k])) : '';
            $out['colours'][$k] = preg_match('/^#[0-9a-f]{6}$/', $v) === 1 ? $v : $d['colours'][$k];
        }

        $link = is_string($in['link'] ?? null) ? mb_substr(trim($in['link']), 0, 300) : $d['link'];
        $out['link'] = Banners::safeUrl($link);
        $out['animate'] = array_key_exists('animate', $in) ? (bool) $in['animate'] : true;
        $out['per_brand'] = array_key_exists('per_brand', $in) ? (bool) $in['per_brand'] : true;
        $out['count'] = max(1, min(self::COUNT_MAX, (int) ($in['count'] ?? 5)));

        $pool = [];
        foreach (is_array($in['pool'] ?? null) ? $in['pool'] : [] as $row) {
            $item = self::cleanItem($row);
            if ($item !== null && ! isset($pool[$item['id']])) {
                $pool[$item['id']] = $item;
            }
            if (count($pool) >= self::POOL_MAX) {
                break;
            }
        }
        $out['pool'] = array_values($pool);

        return $out;
    }

    /** @return array<string,mixed>|null */
    private static function cleanItem(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            return null;
        }
        $cm = is_numeric($row['cm'] ?? null) ? (float) $row['cm'] : 0.0;

        $cut = null;
        $c = $row['cut'] ?? null;
        if (is_array($c) && is_string($c['p'] ?? null) && preg_match('#^[A-Za-z0-9%._~\-]+(/[A-Za-z0-9%._~\-]+)*$#', $c['p']) === 1
            && ! str_contains($c['p'], '..') && is_array($c['w'] ?? null)) {
            $w = [];
            foreach (ProductCutouts::HEIGHTS as $h) {
                $v = (int) ($c['w'][$h] ?? $c['w'][(string) $h] ?? 0);
                if ($v > 0 && $v <= 4000) {
                    $w[$h] = $v;
                }
            }
            if ($w !== []) {
                $cut = ['p' => $c['p'], 'w' => $w];
            }
        }

        return [
            'id' => $id,
            'cm' => $cm > 0 ? round(max(self::CM[0], min(self::CM[1], $cm)), 1) : 0.0,
            'white' => (bool) ($row['white'] ?? false),
            'name' => mb_substr(trim((string) (is_scalar($row['name'] ?? null) ? $row['name'] : '')), 0, 120),
            'brand' => mb_substr(trim((string) (is_scalar($row['brand'] ?? null) ? $row['brand'] : '')), 0, 60),
            'brand_id' => max(0, (int) ($row['brand_id'] ?? 0)),
            'image' => mb_substr(trim((string) (is_scalar($row['image'] ?? null) ? $row['image'] : '')), 0, 300),
            'cut' => $cut,
        ];
    }

    /** The snapshot of a cut-out, from the disk (ProductCutouts::for). Admin-side only. */
    public static function cutSnapshot(string $image): ?array
    {
        $rel = ProductCutouts::relative($image);
        if ($rel === null || ProductCutouts::for($image) === null) {
            return null;
        }
        $w = [];
        foreach (ProductCutouts::HEIGHTS as $h) {
            $size = @getimagesize(public_path(ProductCutouts::DIR.'/'.$h.'/'.$rel.'.webp'));
            if (is_array($size) && $size[0] > 0) {
                $w[$h] = (int) $size[0];
            }
        }

        return $w === [] ? null : ['p' => implode('/', array_map('rawurlencode', explode('/', $rel))), 'w' => $w];
    }

    /** The stored config, read from the request's settings map. No query. */
    public static function config(array $all): array
    {
        $raw = $all[self::KEY] ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return self::sanitize(is_array($raw) ? $raw : []);
    }

    /** The cut-out URLs of a snapshot. */
    public static function cutUrls(array $cut): array
    {
        $base = Url::base().'/'.ProductCutouts::DIR.'/';
        $parts = [];
        $top = 0;
        foreach ($cut['w'] as $h => $w) {
            $parts[] = $base.$h.'/'.$cut['p'].'.webp '.$w.'w';
            $top = max($top, (int) $h);
        }

        return ['src' => $base.$top.'/'.$cut['p'].'.webp', 'srcset' => implode(', ', $parts), 'w' => (int) $cut['w'][$top], 'h' => $top];
    }

    /**
     * Pick n of the ready products, at random, distinct brands first.
     *
     * @param  list<array<string,mixed>>  $pool
     * @return list<array<string,mixed>>
     */
    public static function pick(array $pool, int $n, bool $perBrand): array
    {
        $ready = array_values(array_filter($pool, static fn ($p) => $p['cut'] !== null));
        shuffle($ready);
        // One of each PHOTO: two products sharing a picture (variants, a set and
        // its single) drew the same jar twice side by side in the integrator's check.
        $photos = [];
        $ready = array_values(array_filter($ready, static function ($p) use (&$photos) {
            $k = (string) $p['image'];
            if ($k !== '' && isset($photos[$k])) {
                return false;
            }
            $photos[$k] = true;

            return true;
        }));
        if (! $perBrand) {
            return array_slice($ready, 0, $n);
        }
        $out = [];
        $rest = [];
        $seen = [];
        foreach ($ready as $p) {
            $b = $p['brand_id'] > 0 ? 'i'.$p['brand_id'] : 'n'.mb_strtolower($p['brand']);
            if ($b !== 'n' && isset($seen[$b])) {
                $rest[] = $p;

                continue;
            }
            $seen[$b] = true;
            $out[] = $p;
        }

        return array_slice(array_merge($out, $rest), 0, $n);
    }

    /**
     * Heights for one shown set, one cm scale for the whole pool: the tallest
     * product in the POOL is TALLEST% of the banner, everything else in
     * proportion -- so a mini is always smaller than a stick, whichever five
     * come up. Each product then goes to the slot whose design height is the
     * same rank as its own.
     *
     * @return list<array{item: array, slot: int, h: float}>
     */
    public static function place(array $pool, array $shown): array
    {
        $max = 0.0;
        foreach ($pool as $p) {
            if ($p['cut'] !== null) {
                $max = max($max, $p['cm'] > 0 ? $p['cm'] : self::CM[2]);
            }
        }
        $max = $max > 0 ? $max : self::CM[2];
        $n = count($shown);
        if ($n === 0) {
            return [];
        }
        $slots = self::SLOTS_FOR[$n];
        usort($slots, static fn ($a, $b) => self::SLOTS[$b]['h'] <=> self::SLOTS[$a]['h']);
        $byCm = $shown;
        usort($byCm, static fn ($a, $b) => ($b['cm'] ?: self::CM[2]) <=> ($a['cm'] ?: self::CM[2]));
        $out = [];
        foreach ($byCm as $i => $item) {
            $h = round(self::TALLEST * ($item['cm'] > 0 ? $item['cm'] : self::CM[2]) / $max, 1);
            $out[] = ['item' => $item, 'slot' => $slots[$i], 'h' => max(10.0, min(66.0, $h))];
        }
        usort($out, static fn ($a, $b) => $a['slot'] <=> $b['slot']);

        return $out;
    }

    /**
     * What the homepage draws, or null for "the slider only, as before".
     *
     * @return array<string,mixed>|null
     */
    public static function view(array $all): ?array
    {
        $raw = $all[self::KEY] ?? null;
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }
        $c = self::config($all);
        if ($c['display'] === 'slider') {
            return null;
        }

        $ar = Locale::current() === 'ar';
        $t = $c[$ar ? 'ar' : 'en'];
        $li = array_search($c['layout'], array_keys(self::LAYOUTS), true);
        $products = [];
        foreach (self::place($c['pool'], self::pick($c['pool'], $c['count'], $c['per_brand'])) as $pl) {
            $u = self::cutUrls($pl['item']['cut']);
            $ratio = $u['w'] / max(1, $u['h']);
            $s = self::SLOTS[$pl['slot']];
            $products[] = $u + [
                'alt' => $pl['item']['name'],
                'slot' => $pl['slot'],
                'style' => '--h:'.$pl['h'].';--r:'.$s['r'][$li].'deg;animation-delay:'.$s['d'][$li].'s',
                'sizes' => '(max-width:767px) '.max(24, (int) ceil($pl['h'] * self::PHONE_SCALE / 100 * self::FRAME['phone'] * $ratio)).'px, '
                    .max(24, (int) ceil($pl['h'] / 100 * self::FRAME['max'] * $ratio)).'px',
            ];
        }

        return [
            'display' => $c['display'],
            'layout' => $c['layout'],
            'ar' => $ar,
            't' => $t,
            'style' => self::styleVars($c['colours']),
            'href' => $c['link'] === '' ? '' : Url::to($c['link']),
            'animate' => $c['animate'],
            'products' => $products,
        ];
    }

    /** The colours as custom properties. Each value matched /^#[0-9a-f]{6}$/ in sanitize(). */
    public static function styleVars(array $colours): string
    {
        $out = [];
        foreach (self::COLOURS as $k) {
            $out[] = '--'.$k.':'.$colours[$k];
        }

        return implode(';', $out);
    }

    /**
     * The stylesheet, printed inline once in front of the banner (never in
     * <head>). Animations are transform/opacity only; everything stops for
     * prefers-reduced-motion and for `.ohb-still` (Animation off).
     */
    public static function css(): string
    {
        $f = self::FRAME;
        $css = '.ohb{position:relative;overflow:hidden;width:100%;height:'.$f['phone'].'px;isolation:isolate;color:var(--ink);background:var(--a1);text-align:start}'
            .'@media (min-width:768px){.ohb{height:auto;aspect-ratio:'.$f['ratio'].';max-height:'.$f['max'].'px}}'
            .'.kbbs-s .ohb{height:100%;aspect-ratio:auto;max-height:none}'
            .'.ohb-bg{position:absolute;inset:0;z-index:-2;pointer-events:none}'
            .'.ohb-bg i{position:absolute;inset:0;opacity:0;animation:ohbCycle 18s ease-in-out infinite}'
            .'.ohb-bg i:nth-child(1){background:radial-gradient(70% 90% at 80% 30%,var(--b1),transparent 70%),linear-gradient(115deg,var(--c1),var(--a1) 55%,var(--a2));animation-delay:0s}'
            .'.ohb-bg i:nth-child(2){background:radial-gradient(70% 90% at 70% 70%,var(--b2),transparent 70%),linear-gradient(115deg,var(--c2),var(--a2) 55%,var(--a3));animation-delay:-6s}'
            .'.ohb-bg i:nth-child(3){background:radial-gradient(70% 90% at 85% 50%,var(--b3),transparent 70%),linear-gradient(115deg,var(--c3),var(--a3) 55%,var(--a1));animation-delay:-12s}'
            .'@keyframes ohbCycle{0%,100%{opacity:0}16%,50%{opacity:1}66%{opacity:0}}'
            .'.ohb-glow{position:absolute;z-index:-1;inset:-30%;pointer-events:none;background:radial-gradient(20% 26% at 30% 40%,rgba(255,255,255,.75),transparent 70%),radial-gradient(18% 22% at 70% 65%,rgba(255,255,255,.55),transparent 70%);animation:ohbDrift 24s ease-in-out infinite alternate}'
            .'@keyframes ohbDrift{to{transform:translate(8%,-6%) rotate(8deg)}}'
            .'.ohb-p{position:absolute;height:calc(var(--h) * 1%);filter:drop-shadow(0 26px 22px rgba(40,20,40,.22));will-change:transform;animation:ohbBob 7s ease-in-out infinite;pointer-events:none}'
            .'.ohb-p img{display:block;height:100%;width:auto;max-width:none}'
            .'@keyframes ohbBob{0%,100%{transform:translateY(0) rotate(var(--r,0deg))}50%{transform:translateY(-14px) rotate(calc(var(--r,0deg) + 3deg))}}'
            .'.ohb-dot{position:absolute;border-radius:50%;background:radial-gradient(circle at 35% 30%,#fff,rgba(255,255,255,.55) 40%,rgba(255,255,255,0) 70%);animation:ohbBob 9s ease-in-out infinite;pointer-events:none}'
            .'.ohb h2{margin:0;font:inherit;color:inherit}'
            .'.ohb-copy{position:absolute;z-index:4;display:flex;flex-direction:column;align-items:flex-start;left:clamp(18px,5vw,96px);top:50%;transform:translateY(-50%);width:min(46%,620px);gap:clamp(10px,1.2vw,18px)}'
            .'[dir=rtl] .ohb-copy{left:auto;right:clamp(18px,5vw,96px)}'
            .'.ohb-label{font-weight:800;letter-spacing:.18em;color:var(--acc);font-size:clamp(13px,1.1vw,16px)}'
            .'.ohb-num{font-weight:900;line-height:.82;letter-spacing:-.045em;color:var(--acc);font-size:clamp(90px,11vw,170px)}'
            .'.ohb-title{display:block;font-weight:900;line-height:.92;letter-spacing:-.02em;font-size:clamp(42px,5.4vw,84px)}'
            .'.ohb-offer{font-weight:900;color:var(--acc);font-size:clamp(28px,3.2vw,50px);line-height:1}'
            .'.ohb-line{margin:0;opacity:.82;font-size:clamp(15px,1.3vw,19px);max-width:34ch}'
            .'.ohb .ohb-btn{display:inline-flex;align-items:center;gap:10px;background:var(--btn);color:var(--btnInk);text-decoration:none;font-weight:900;border-radius:999px;box-shadow:0 18px 30px -16px var(--btn);font-size:clamp(16px,1.5vw,21px);padding:clamp(14px,1.3vw,20px) clamp(26px,2.6vw,42px)}'
            .'.ohb .ohb-btn:hover,.ohb .ohb-btn:visited{color:var(--btnInk)}.ohb .ohb-btn:hover{filter:brightness(1.06)}'
            .'.ohb .ohb-btn:focus-visible{outline:3px solid var(--ink);outline-offset:3px}'
            .'[dir=rtl] .ohb-label,[dir=rtl] .ohb-title,[dir=rtl] .ohb-num{letter-spacing:0}[dir=rtl] .ohb-title{line-height:1.12}'
            .'[dir=rtl] .ohb-arw{display:inline-block;transform:scaleX(-1)}'
            .'@media (max-width:1023px){.ohb-line{display:none}.ohb-num{font-size:clamp(76px,10vw,110px)}.ohb-p{height:calc(var(--h) * '.self::TABLET_SCALE.'%)}}'
            .'@media (max-width:767px){.ohb-copy,[dir=rtl] .ohb-copy{left:16px;right:16px;top:20px;transform:none;width:auto;gap:6px}'
            .'.ohb-label{font-size:12px}.ohb-num{font-size:min(21vw,96px)}.ohb-title{font-size:min(10vw,42px)}.ohb-offer{font-size:26px}'
            .'.ohb .ohb-btn{margin-top:10px;font-size:15px;padding:13px 22px}.ohb-p{height:calc(var(--h) * '.self::PHONE_SCALE.'%)}}';

        // The places, generated from SLOTS so /ar mirrors them: computer from
        // the inline END (right), phone from the inline START (left).
        $d = '';
        $tab = '';
        $ph = '';
        foreach (self::SLOTS as $i => $s) {
            $d .= '.ohb-s'.$i.'{right:'.$s['end'].'%;bottom:'.$s['bottom'].'%'.($s['z'] ? ';z-index:'.$s['z'] : '').'}'
                .'[dir=rtl] .ohb-s'.$i.'{right:auto;left:'.$s['end'].'%}';
            if ($s['tab'] !== $s['bottom']) {
                $tab .= '.ohb-s'.$i.'{bottom:'.$s['tab'].'%}';
            }
            $ph .= $s['ps'] !== null
                ? '.ohb-s'.$i.'{right:auto;left:'.$s['ps'].'%;bottom:'.$s['pb'].'%}[dir=rtl] .ohb-s'.$i.'{left:auto;right:'.$s['ps'].'%}'
                : '.ohb-s'.$i.'{left:auto;right:'.$s['pe'].'%;bottom:'.$s['pb'].'%}[dir=rtl] .ohb-s'.$i.'{right:auto;left:'.$s['pe'].'%}';
        }
        $css .= $d.'@media (max-width:1023px){'.$tab.'}@media (max-width:767px){'.$ph.'}';

        // The three layouts' own pieces.
        $css .= '.ohb-d1{width:70px;height:70px;right:44%;top:12%}.ohb-d2{width:44px;height:44px;right:6%;bottom:12%;animation-delay:-3s}.ohb-d3{width:30px;height:30px;right:36%;bottom:16%;animation-delay:-5s}'
            .'.ohb-halo{position:absolute;right:8%;top:50%;width:clamp(260px,34vw,520px);aspect-ratio:1;transform:translateY(-50%);border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.95) 0,rgba(255,255,255,.55) 45%,rgba(255,255,255,0) 70%);pointer-events:none}'
            .'[dir=rtl] .ohb-halo{right:auto;left:8%}'
            .'.ohb-halo::after{content:"";position:absolute;inset:6%;border-radius:50%;border:2px dashed rgba(255,255,255,.9);animation:ohbSpin 40s linear infinite}'
            .'@keyframes ohbSpin{to{transform:rotate(360deg)}}'
            .'.ohb-wave{position:absolute;left:0;bottom:0;width:200%;height:34%;background:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 1200 200\' preserveAspectRatio=\'none\'%3E%3Cpath fill=\'%23fff\' fill-opacity=\'.55\' d=\'M0 80 C200 20 400 140 600 80 S1000 20 1200 80 V200 H0Z\'/%3E%3C/svg%3E") 0 100%/50% 100% repeat-x;animation:ohbWave 16s linear infinite;z-index:0;pointer-events:none}'
            .'.ohb-wave.w2{height:24%;opacity:.7;animation-duration:24s;animation-direction:reverse}'
            .'@keyframes ohbWave{to{transform:translateX(-50%)}}'
            .'@media (max-width:767px){.ohb-halo,[dir=rtl] .ohb-halo{right:-10%;left:auto;top:auto;bottom:-14%;width:78%;transform:none}[dir=rtl] .ohb-halo{right:auto;left:-10%}.ohb-wave{height:30%}}'
            .'.ohb-still .ohb-bg i,.ohb-still .ohb-glow,.ohb-still .ohb-p,.ohb-still .ohb-dot,.ohb-still .ohb-halo::after,.ohb-still .ohb-wave{animation:none}.ohb-still .ohb-bg i:nth-child(1){opacity:1}'
            .'@media (prefers-reduced-motion:reduce){.ohb-bg i,.ohb-glow,.ohb-p,.ohb-dot,.ohb-halo::after,.ohb-wave{animation:none}.ohb-bg i:nth-child(1){opacity:1}}';

        return $css;
    }

    /** What the admin's in-browser preview needs: the templates, the layouts, the stylesheet. */
    public static function adminCatalogue(): array
    {
        $tpl = [];
        foreach (self::TEMPLATES as $k => [$name, $en, $ar, $colours]) {
            $tpl[] = ['key' => (string) $k, 'name' => $name, 'en' => $en, 'ar' => $ar, 'colours' => $colours];
        }

        return [
            'templates' => $tpl,
            'layouts' => array_map(static fn ($k, $v) => ['key' => $k, 'name' => $v], array_keys(self::LAYOUTS), self::LAYOUTS),
            'displays' => array_map(static fn ($k, $v) => ['key' => $k, 'name' => $v], array_keys(self::DISPLAYS), self::DISPLAYS),
            'texts' => array_map(static fn ($k, $v) => ['key' => $k, 'label' => $v[0], 'max' => $v[1]], array_keys(self::TEXTS), self::TEXTS),
            'slots' => self::SLOTS,
            'slots_for' => self::SLOTS_FOR,
            'tallest' => self::TALLEST,
            'phone_scale' => self::PHONE_SCALE,
            'css' => self::css(),
            'pool_max' => self::POOL_MAX,
            'cm' => self::CM,
        ];
    }
}
