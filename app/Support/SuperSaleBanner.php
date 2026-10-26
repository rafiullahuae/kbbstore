<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Media;
use App\Services\FreeGifts;
use Illuminate\Http\Request;

/**
 * The live Super Sale banner on /super-sale/.                     (Lane SSB)
 *
 * The owner approved variant D of the banner study (claude.ai artifact
 * 5tGDiMhkudMkmBJvQuhrg7) and refined it: ONE compact banner, a hero (the
 * tabby and tamara marks, "SUPER SALE" on one line, coupon GLOW, the lady on
 * the side) over a free-gifts row -- no pink ribbon on top, a taller gift row
 * with bigger thumbnails and a bigger red gift icon, less padding at the top,
 * and NO taller overall: a phone draws it 220-260px high. Desktop and phone,
 * English and Arabic. It replaces the PICTURE in the Super Sale header area
 * (Pages -> Page header); the page keeps its trust strip (App\Support\
 * TrustStrip) and everything else exactly where it was.
 *
 * ── LIVE, AND AS LIGHT AS THE PICTURE IT REPLACES ───────────────────────────
 *
 * HTML and one constant stylesheet, printed on /super-sale/ only. No script,
 * no font, no request of its own: the tabby/tamara marks are the checkout's
 * own inline SVGs (PaymentMarkArt), the gift icon is a constant, and the
 * settings are one autoloaded row read off SettingsService::all() -- the
 * request's one settings map. Sizes come from one unit, --u, which is the
 * banner's width in hundredths, capped so a phone never draws it taller than
 * 256px (220-260 asked) and a computer never taller than 330px; nothing is measured.
 *
 * The --u ladder: a plain px value every browser understands, then min() with
 * vw, then min() with cqw -- each inside @supports, because a declaration with
 * var() in it has NO fallback in a browser that cannot read its value (the
 * 2.60.492 lotus landmine in CLAUDE.md).
 *
 * ── THE GIFTS ARE THE SHOP'S OWN ────────────────────────────────────────────
 *
 * FreeGifts::bannerLines(): the compiled ranges running today, filled by the
 * same linesFor() the cart's gift box uses (main gifts, backups when a main is
 * out, nothing sold out), full-size first. ONE product query for every range,
 * however many gifts or products the shop has; none when gifts are off for
 * this visitor -- and then the row is simply not drawn.
 *
 * ── RULE 5 ──────────────────────────────────────────────────────────────────
 *
 * Raw output is CSS, GIFT_ICON and PaymentMarkArt's constants only. Every word
 * is plain text, clipped, printed escaped. The band is one of BANDS or a
 * `range:<id>` of the right shape, else the default. The model picture is an
 * http(s) or root-relative address checked by SafeUrl on save AND at render.
 */
final class SuperSaleBanner
{
    public const KEY = 'super_sale_banner';

    /** The lady from the Super Sale banner the page showed, cut out of resources/banners/kbeautybliss-2026-super-sale-ar.webp. */
    public const MODEL_FILE = 'kbb-super-sale-model.webp';

    public const MODEL_REL = 'uploads/appearance/'.self::MODEL_FILE;

    public const MAX_TEXT = 60;

    /** Gift photos drawn: six on a computer, the first four on a phone (bigger photos, as he asked, need the room). */
    public const MAX_GIFTS = 6;

    /** Every word on the banner: key => [English, Arabic, label]. */
    public const TEXTS = [
        'pay' => ['SHOP NOW, PAY LATER', 'تسوّقي الآن وادفعي لاحقاً', 'Line beside the tabby and tamara logos'],
        'tagline' => ['PERFECT SKINCARE TO GLOW', 'عناية مثالية لبشرة متوهّجة', 'Line under the logos'],
        'title' => ['SUPER SALE', 'تخفيضات كبرى', 'Big title'],
        'coupon' => ['COUPON CODE:', 'كود الخصم:', 'Words before the code'],
        'gifts' => ['Free gifts with every order', 'هدايا مجانية مع كل طلب', 'Gift row heading'],
        'free' => ['FREE', 'مجاناً', 'Chip at the end of the gift row'],
    ];

    /** Which gifts the row shows. `range:<id>` picks one range by its id. */
    public const BANDS = [
        'all' => 'Every gift in every range running today',
        'first' => 'The first range (the smallest basket)',
        'last' => 'The top range (the biggest basket)',
    ];

    /** The red-pink gift icon of the approved design. A constant; width/height so it never draws at 300x150. */
    public const GIFT_ICON = '<svg class="ssb-gic" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7z"/></svg>';

    /**
     * Every byte of CSS the banner prints: variant D's numbers, in units of
     * --u (one hundredth of the banner's width, capped). The phone layout is
     * the storefront's own breakpoint, 900px and under.
     *
     * Every selector is under .kbb-ssb, and the picture rules are written
     * stronger than PageHeaders::CSS's `.kbb-ph>.kbb-ph-i img`, which would
     * otherwise give the lady and the gift photos the header picture's height.
     */
    public const CSS = '.kbb-ssb{container-type:inline-size;--u:3.9px;display:block;line-height:normal;min-width:0}'
        .'@media (min-width:901px){.kbb-ssb{--u:10px}}'
        .'@supports (width:min(1vw,1px)){.kbb-ssb{--u:min(1vw,3.9px)}@media (min-width:901px){.kbb-ssb{--u:min(.94vw,10px)}}}'
        .'@supports (width:min(1cqw,1px)){.kbb-ssb>.ssb-in{--u:min(1cqw,3.9px)}@media (min-width:901px){.kbb-ssb>.ssb-in{--u:min(1cqw,10px)}}}'
        .'.kbb-ssb .ssb-in{position:relative;display:flex;flex-direction:column;overflow:hidden;color:#2a2228;text-align:start;'
        .'background:radial-gradient(120% 120% at 88% 8%,#fff6fa,transparent 55%),radial-gradient(130% 130% at 3% 122%,#fbe9f1,transparent 52%),linear-gradient(118deg,#fde8ef 0%,#fff8fb 48%,#fdeee6 100%)}'
        .'.kbb-ssb[dir=rtl] .ssb-in{font-family:"Cairo",var(--sans,system-ui),sans-serif}'
        .'.kbb-ssb .ssb-hero{display:flex;align-items:center;gap:calc(var(--u)*2.2);height:calc(var(--u)*44);padding:calc(var(--u)*1.6) calc(var(--u)*4.5) 0;box-sizing:border-box;min-height:0}'
        .'.kbb-ssb .ssb-copy{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;gap:calc(var(--u)*1.3);padding-bottom:calc(var(--u)*1.6)}'
        .'.kbb-ssb .ssb-pay{display:flex;align-items:center;flex-wrap:wrap;gap:calc(var(--u)*2)}'
        .'.kbb-ssb .ssb-pay svg{display:block;height:calc(var(--u)*4.6);width:auto;max-width:100%}'
        .'.kbb-ssb .ssb-paytxt{font-weight:700;letter-spacing:.03em;color:#3b2f36;font-size:calc(var(--u)*2.8);line-height:1.2}'
        .'.kbb-ssb .ssb-tag{font-weight:600;color:#4a3b44;letter-spacing:.02em;font-size:calc(var(--u)*3.2);line-height:1.3}'
        .'.kbb-ssb .ssb-super{margin:0;font-weight:800;color:#ec3a80;line-height:.95;letter-spacing:-.01em;white-space:nowrap;font-size:calc(var(--u)*8);'
        .'text-shadow:calc(var(--u)*.2) calc(var(--u)*.2) 0 #fff,calc(var(--u)*-.2) calc(var(--u)*.2) 0 #fff,calc(var(--u)*.2) calc(var(--u)*-.2) 0 #fff,calc(var(--u)*-.2) calc(var(--u)*-.2) 0 #fff,0 var(--u) calc(var(--u)*1.6) rgba(236,58,128,.25)}'
        .'.kbb-ssb[dir=rtl] .ssb-super{font-family:"Changa","Cairo",var(--sans,system-ui),sans-serif;letter-spacing:0;line-height:1.05}.kbb-ssb[dir=rtl] .ssb-copy{gap:calc(var(--u)*.9)}'
        .'.kbb-ssb .ssb-coupon{display:inline-flex;align-items:center;flex-wrap:wrap;gap:calc(var(--u)*1.4);font-weight:700;color:#2a2228;font-size:calc(var(--u)*3.5);margin-top:calc(var(--u)*.5);line-height:1.3}'
        .'.kbb-ssb .ssb-coupon b{background:#fff;border:calc(var(--u)*.28) dashed #e4457c;color:#c3306a;border-radius:var(--u);font-weight:800;letter-spacing:.06em;padding:calc(var(--u)*.5) calc(var(--u)*2)}'
        .'.kbb-ssb .ssb-model{position:relative;flex:none;align-self:flex-end;overflow:hidden;width:calc(var(--u)*33);height:94%;'
        .'border-radius:48% 48% 0 0/70% 70% 0 0;background:radial-gradient(58% 40% at 50% 28%,#ffd9c6,transparent 70%),linear-gradient(160deg,#ffe7d6,#f6c9bd 60%,#e9aeb6)}'
        .'.kbb-ssb .ssb-model img,.kbb-ph>.kbb-ph-i .kbb-ssb .ssb-model img{display:block;width:100%;height:100%;max-width:none;object-fit:cover;object-position:50% 12%;border-radius:0}'
        .'.kbb-ssb .ssb-gifts{display:flex;align-items:center;gap:calc(var(--u)*1.5);background:rgba(255,255,255,.74);border-top:calc(var(--u)*.16) solid rgba(46,143,98,.3);padding:calc(var(--u)*5.4) calc(var(--u)*4.5)}'
        .'.kbb-ssb .ssb-gi{display:inline-flex;align-items:center;gap:calc(var(--u)*1.3);color:#2e8f62;font-weight:800;flex:0 1 auto;max-width:calc(var(--u)*38);min-width:0;font-size:calc(var(--u)*3.1)}'
        .'.kbb-ssb .ssb-gic{flex:none;display:block;color:#e4457c;width:calc(var(--u)*7);height:calc(var(--u)*7)}'
        .'.kbb-ssb .ssb-gi span{line-height:1.12;text-wrap:balance;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}'
        .'.kbb-ssb .ssb-circs{display:flex;align-items:center;margin-inline-start:auto;min-width:0}'
        .'.kbb-ssb .ssb-c{position:relative;flex:none;border-radius:50%;background:#fff;border:calc(var(--u)*.45) solid #fff;width:calc(var(--u)*10.5);height:calc(var(--u)*10.5);margin-inline-start:calc(var(--u)*-2);box-shadow:0 calc(var(--u)*.4) calc(var(--u)*1.2) calc(var(--u)*-.3) rgba(42,34,40,.3);box-sizing:border-box}'
        .'.kbb-ssb .ssb-c:first-child{margin-inline-start:0}'
        .'.kbb-ssb .ssb-c img,.kbb-ph>.kbb-ph-i .kbb-ssb .ssb-c img{position:absolute;inset:0;display:block;width:100%;height:100%;max-width:none;object-fit:cover;border-radius:50%;background:#fff}'
        .'.kbb-ssb .ssb-free{display:inline-flex;align-items:center;flex:none;font-weight:800;color:#2e8f62;background:#eaf7f0;border:calc(var(--u)*.26) dashed #2e8f62;border-radius:var(--u);letter-spacing:.06em;text-transform:uppercase;font-size:calc(var(--u)*2.6);padding:calc(var(--u)*.7) calc(var(--u)*1.5);margin-inline-start:calc(var(--u)*.8);line-height:1.2}'
        // The on-page "Edit header" panel (page-header-editor.js) previews the
        // header's picture by inserting a <picture> beside this banner; while
        // the banner is on, that picture is not what the shop shows.
        .'.kbb-ph>picture.kbb-ph-i:has(~ .kbb-ph-i>.kbb-ssb){display:none}'
        .'@media (max-width:900px){.kbb-ssb .ssb-c:nth-child(n+5){display:none}}'
        .'@media (min-width:901px){.kbb-ssb .ssb-hero{height:calc(var(--u)*21.5);gap:calc(var(--u)*2);padding:calc(var(--u)*1.4) calc(var(--u)*4) 0}'
        .'.kbb-ssb .ssb-copy{gap:calc(var(--u)*.9);padding-bottom:calc(var(--u)*1.2)}'
        .'.kbb-ssb .ssb-pay{gap:calc(var(--u)*1.4)}.kbb-ssb .ssb-pay svg{height:calc(var(--u)*3.2)}'
        .'.kbb-ssb .ssb-paytxt{font-size:calc(var(--u)*1.85)}.kbb-ssb .ssb-tag{font-size:calc(var(--u)*2.2)}'
        .'.kbb-ssb .ssb-super{font-size:calc(var(--u)*5.6)}.kbb-ssb[dir=rtl] .ssb-super{font-size:calc(var(--u)*5.2)}'
        .'.kbb-ssb .ssb-coupon{font-size:calc(var(--u)*2.2);margin-top:calc(var(--u)*.2)}.kbb-ssb .ssb-coupon b{padding:calc(var(--u)*.3) calc(var(--u)*1.6)}'
        .'.kbb-ssb .ssb-model{width:calc(var(--u)*20);height:92%}'
        .'.kbb-ssb .ssb-gifts{gap:calc(var(--u)*2);padding:calc(var(--u)*2.6) calc(var(--u)*4)}'
        .'.kbb-ssb .ssb-gi{font-size:calc(var(--u)*2.1);gap:calc(var(--u)*1.2);max-width:calc(var(--u)*28)}'
        .'.kbb-ssb .ssb-gic{width:calc(var(--u)*5);height:calc(var(--u)*5)}'
        .'.kbb-ssb .ssb-c{width:calc(var(--u)*6.2);height:calc(var(--u)*6.2);margin-inline-start:calc(var(--u)*-1.3)}'
        .'.kbb-ssb .ssb-free{font-size:calc(var(--u)*1.7);padding:calc(var(--u)*.55) calc(var(--u)*1.5);margin-inline-start:var(--u)}}';

    /** The shape that ships: ON, because the owner approved this banner for this page. */
    public static function defaults(): array
    {
        $out = ['on' => true, 'code' => 'GLOW', 'logos' => true, 'model_on' => true, 'model' => '', 'band' => 'all', 'gifts_on' => true];

        foreach (self::TEXTS as $k => [$en, $ar]) {
            $out[$k.'_en'] = $en;
            $out[$k.'_ar'] = $ar;
        }

        return $out;
    }

    /**
     * Clean an incoming config. [clean, rejected]: with $strict anything wrong
     * is reported and the caller writes nothing; without it (the read path)
     * anything wrong quietly becomes its default, so a damaged row can never
     * take the page down.
     *
     * @return array{0: array<string,mixed>, 1: array<string,string>}
     */
    public static function sanitize(mixed $in, bool $strict = true): array
    {
        $in = is_array($in) ? $in : [];
        $d = self::defaults();
        $out = [];
        $rejected = [];

        foreach (['on', 'logos', 'model_on', 'gifts_on'] as $k) {
            $out[$k] = array_key_exists($k, $in) ? filter_var($in[$k], FILTER_VALIDATE_BOOLEAN) : $d[$k];
        }

        foreach (self::TEXTS as $k => [, , $label]) {
            foreach (['en', 'ar'] as $lang) {
                // Present and emptied = the line is not drawn; absent = the default.
                $out[$k.'_'.$lang] = array_key_exists($k.'_'.$lang, $in) ? self::text($in[$k.'_'.$lang]) : $d[$k.'_'.$lang];
            }
        }

        $code = array_key_exists('code', $in) ? self::text($in['code'], 24) : $d['code'];
        $out['code'] = $code;

        $model = trim(is_scalar($in['model'] ?? null) ? (string) $in['model'] : '');
        if ($model !== '' && ! self::pictureOk($model)) {
            $rejected['model'] = 'Model picture';
            $model = '';
        }
        $out['model'] = $model;

        $band = is_string($in['band'] ?? null) ? $in['band'] : $d['band'];
        if (! isset(self::BANDS[$band]) && preg_match('/^range:[1-9]\d{0,9}$/', $band) !== 1) {
            $rejected['band'] = 'Which gifts';
            $band = $d['band'];
        }
        $out['band'] = $band;

        return [$out, $strict ? $rejected : []];
    }

    /** The stored config, every key present. Read off the request's settings map: no query. */
    public static function config(array $all): array
    {
        $raw = $all[self::KEY] ?? null;

        return self::sanitize(is_array($raw) ? $raw : [], false)[0];
    }

    /**
     * What /super-sale/ draws, or null for "no banner -- the header picture as
     * before". Arabic when the request is Arabic.
     *
     * @return array<string,mixed>|null
     */
    public static function view(array $all, ?Request $request): ?array
    {
        $c = self::config($all);

        if (! $c['on']) {
            return null;
        }

        $ar = Locale::current() === 'ar';
        $t = [];
        foreach (self::TEXTS as $k => $_) {
            $t[$k] = (string) $c[$k.($ar ? '_ar' : '_en')];
        }

        $gifts = [];
        if ($c['gifts_on']) {
            foreach (array_slice(FreeGifts::rows(app(FreeGifts::class)->bannerLines($request, $c['band'])), 0, self::MAX_GIFTS) as $g) {
                if ($g['image'] === '' || SafeUrl::src($g['image']) === '') {
                    continue;
                }
                $gifts[] = [
                    'src' => ImageVariants::variantUrl($g['image'], 200),
                    'srcset' => self::srcset($g['image']),
                    'alt' => trim($g['brand'].' '.$g['name']),
                ];
            }
        }

        $model = $c['model_on'] ? self::modelUrl($c['model']) : '';
        $modelSet = $model === '' ? '' : self::srcset($model);
        if ($modelSet !== '' && $c['model'] === '') {
            // The shipped lady is 288px wide, so her only copy is the 200w one;
            // the original goes in the list too, or a computer's 2x screen
            // would be handed the 200px file. (A big upload has 400w and 800w.)
            $modelSet .= ', '.$model.' 288w';
        }

        return [
            'ar' => $ar,
            't' => $t,
            'code' => (string) $c['code'],
            'logos' => (bool) $c['logos'],
            'tabby' => $c['logos'] ? self::sized(PaymentMarkArt::checkoutLogo('tabby'), 71, 28) : '',
            'tamara' => $c['logos'] ? self::sized(PaymentMarkArt::checkoutLogo('tamara', $ar), 85, 28) : '',
            'model' => $model === '' ? null : [
                'src' => ImageVariants::variantUrl($model, 400),
                'srcset' => $modelSet,
            ],
            'gifts' => $gifts,
        ];
    }

    /** The model picture's address: the owner's own, or the shipped lady when the file is there, or ''. */
    public static function modelUrl(string $stored): string
    {
        $stored = trim($stored);

        if ($stored !== '') {
            return self::pictureOk($stored) ? $stored : '';
        }

        return is_file(public_path(self::MODEL_REL)) ? Media::urlFor(self::MODEL_REL) : '';
    }

    /** http(s) on the web, or a path on this shop; nothing that could break out of an attribute. */
    public static function pictureOk(string $v): bool
    {
        return strlen($v) <= 500
            && preg_match('/[\x00-\x20"\'<>`\\\\]/', $v) !== 1
            && ((str_starts_with($v, '/') && ! str_starts_with($v, '//')) || (preg_match('#^https?://[^/?\#@\s]#i', $v) === 1 && SafeUrl::src($v) !== ''));
    }

    /** One line of plain text: no tags, no control characters, clipped. */
    private static function text(mixed $v, int $max = self::MAX_TEXT): string
    {
        $v = is_scalar($v) ? (string) $v : '';
        $v = strip_tags($v);
        $v = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);
        $v = trim((string) preg_replace('/\s+/u', ' ', $v));

        return mb_substr($v, 0, $max);
    }

    private static function srcset(string $url): string
    {
        try {
            return ImageVariants::srcsetFor($url);
        } catch (\Throwable) {
            return '';
        }
    }

    /** A constant SVG with its own size, so a browser that drops the CSS still draws it small. */
    private static function sized(string $svg, int $w, int $h): string
    {
        return $svg === '' ? '' : (string) preg_replace('/^<svg /', '<svg width="'.$w.'" height="'.$h.'" ', $svg, 1);
    }
}
