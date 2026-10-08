<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Coming Soon page itself, and the two answers the hidden address gives
 * besides the shop (Lane CS).
 *
 * ONE SELF-CONTAINED DOCUMENT. Inline CSS, no script, no font file, no request
 * to any other host; nothing from the shop's layout, Blade views or view
 * composers is loaded, so it cannot run a catalogue query or print a setting it
 * was not written to print. The wordmark is the header's own (Appearance ->
 * Header -> Logo), drawn as text, so "the shop logo" costs no request either.
 *
 * EVERY VARIABLE IS ESCAPED; every colour is checked as #RRGGBB before it
 * reaches the stylesheet; the background picture is a root-relative path made
 * of a closed set of characters (ComingSoon::imagePath()); the two contact
 * links are built here from a digit string and an http(s)-checked profile
 * address.
 *
 * SEO-SAFE ANSWER: 503 + Retry-After, X-Robots-Tag noindex, and
 * `Cache-Control: no-store, private`, so neither Varnish nor a browser keeps
 * it and the shop appears the moment the switch goes off. Varnish's built-in
 * logic does not cache a 503, and it does not cache anything marked
 * private/no-store either -- two independent reasons, so one of them being
 * changed in Cloudways' VCL is not enough to cache this page.
 */
final class ComingSoonPage
{
    public const INK = '#2A2228';

    public const ACCENT = '#C6395F';

    /** The 503 for a visitor on the hidden address. */
    public static function response(Request $request): Response
    {
        $map = Setting::map();
        $lang = self::language($request);
        $html = self::html(ComingSoon::content($map), $lang, $map, rtrim($request->getBaseUrl(), '/'));

        return self::headers(new Response($html, 503), $lang)
            ->setProtocolVersion($request->getProtocolVersion() === 'HTTP/1.0' ? '1.0' : '1.1');
    }

    /** robots.txt on the hidden address: nothing to crawl. */
    public static function robots(): Response
    {
        $r = new Response("User-agent: *\nDisallow: /\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        $r->headers->set('Cache-Control', 'no-store, private');
        $r->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $r;
    }

    public static function headers(Response $r, string $lang): Response
    {
        $r->headers->set('Content-Type', 'text/html; charset=UTF-8');
        $r->headers->set('Retry-After', (string) ComingSoon::RETRY_AFTER);
        $r->headers->set('Cache-Control', 'no-store, private');
        $r->headers->set('Pragma', 'no-cache');
        $r->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $r->headers->set('Vary', 'Accept-Language');
        $r->headers->set('Content-Language', $lang);
        $r->headers->set('X-Content-Type-Options', 'nosniff');
        $r->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // No policy header: this application names only the report-only one
        // (SecurityCspTest). The page has no script and loads nothing from
        // another host, so there is nothing for a policy to forbid.
        $r->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $r->headers->set('X-KBB-Coming-Soon', '1');

        return $r;
    }

    /** /ar first, then the browser's own order between the two languages. */
    public static function language(Request $request): string
    {
        if (ComingSoon::isArabicPath($request->getPathInfo())) {
            return 'ar';
        }

        return $request->getPreferredLanguage(['en', 'ar']) === 'ar' ? 'ar' : 'en';
    }

    /**
     * @param  array{en: array<string,string>, ar: array<string,string>, bg: string, bg_image: string, show_whatsapp: bool, show_instagram: bool}  $c
     * @param  array<string, mixed>  $map  Setting::map(), for the wordmark and the contact links
     */
    public static function html(array $c, string $lang, array $map, string $base = ''): string
    {
        $lang = $lang === 'ar' ? 'ar' : 'en';
        $t = [];

        foreach (ComingSoon::DEFAULT_TEXT[$lang] as $field => $default) {
            $own = (string) ($c[$lang][$field] ?? '');
            $t[$field] = $own !== '' ? $own : $default;
        }

        [$word, $accent, $ink, $acc] = self::wordmark($map);
        $bg = preg_match('/^#[0-9A-F]{6}$/iD', $c['bg']) === 1 ? $c['bg'] : ComingSoon::DEFAULT_BG;
        $image = ComingSoon::imagePath($c['bg_image']);
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        $links = '';

        if ($c['show_whatsapp']) {
            $digits = SupportContact::whatsappDigits();

            if ($digits !== '') {
                $links .= '<a href="https://wa.me/'.$e($digits).'" rel="noopener">'
                    .'<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M4.5 19.5 6 15.6A8 8 0 1 1 9 18.6z"/><path d="M9.5 9.5c.4 2.2 2.3 4.4 5 5"/></svg>'
                    .'WhatsApp</a>';
            }
        }

        if ($c['show_instagram']) {
            $ig = SafeUrl::web((string) ($map['social_instagram'] ?? 'https://www.instagram.com/kbeauty.bliss/'));

            if ($ig !== '') {
                $links .= '<a href="'.$e($ig).'" rel="noopener">'
                    .'<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.3" cy="6.7" r="1"/></svg>'
                    .'Instagram</a>';
            }
        }

        $other = $lang === 'ar' ? ['/', 'English', 'en'] : ['/ar/', 'العربية', 'ar'];
        $dir = $lang === 'ar' ? 'rtl' : 'ltr';
        $bgImage = $image !== ''
            ? "url('".$image."') center/cover no-repeat,"
            : 'radial-gradient(circle at 18% 12%,rgba(255,255,255,.95),rgba(255,255,255,0) 46%),radial-gradient(circle at 86% 92%,rgba(198,57,95,.14),rgba(198,57,95,0) 52%),';

        $css = '*{box-sizing:border-box}html{-webkit-text-size-adjust:100%}'
            .'body{margin:0;min-height:100vh;min-height:100dvh;display:grid;place-items:center;padding:56px 16px 32px;'
            .'background:'.$bgImage.$bg.';color:'.$ink.';font:16px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}'
            .'html[lang=ar] body{font-family:"Segoe UI",Tahoma,"Geeza Pro","Noto Naskh Arabic","Noto Sans Arabic",Arial,sans-serif}'
            .'main{width:100%;max-width:560px;text-align:center;background:rgba(255,255,255,.78);border:1px solid rgba(198,57,95,.14);border-radius:28px;'
            .'padding:44px 24px 36px;box-shadow:0 30px 60px -32px rgba(168,47,83,.38)}'
            .'.logo{margin:0 0 22px;font-size:30px;line-height:1.1;font-weight:800;letter-spacing:-.02em;color:'.$ink.'}.logo span{color:'.$acc.'}'
            .'.dots{display:flex;gap:7px;justify-content:center;margin:0 0 20px}.dots i{width:8px;height:8px;border-radius:50%;background:'.$acc.';opacity:.25;animation:p 1.6s ease-in-out infinite}'
            .'.dots i:nth-child(2){animation-delay:.2s}.dots i:nth-child(3){animation-delay:.4s}@keyframes p{40%{opacity:1;transform:translateY(-3px)}}'
            .'@media (prefers-reduced-motion:reduce){.dots i{animation:none;opacity:.6}}'
            .'h1{margin:0 0 12px;font-size:clamp(28px,7.4vw,40px);line-height:1.15;font-weight:750;letter-spacing:-.02em;overflow-wrap:anywhere}'
            .'html[lang=ar] h1{letter-spacing:0;line-height:1.35}'
            .'.m{margin:0 auto;max-width:420px;font-size:17.5px;color:#5E545A;overflow-wrap:anywhere}'
            .'.s{margin:16px 0 0;font-size:13.5px;color:#756C74;overflow-wrap:anywhere}'
            .'.links{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin:26px 0 0}'
            .'.links a{display:inline-flex;align-items:center;gap:8px;min-height:44px;padding:10px 18px;border-radius:999px;background:#fff;color:'.$ink.';text-decoration:none;font-weight:600;font-size:14.5px;border:1px solid rgba(42,34,40,.14)}'
            .'.links a:hover{border-color:'.$acc.';color:'.$acc.'}'
            .'.lang{position:absolute;top:14px;inset-inline-end:16px;display:inline-flex;align-items:center;min-height:36px;padding:6px 14px;border-radius:999px;background:rgba(255,255,255,.75);color:'.$ink.';font-size:13.5px;font-weight:600;text-decoration:none}'
            .'@media (min-width:700px){main{padding:56px 48px 44px}.logo{font-size:34px}}';

        return '<!doctype html><html lang="'.$lang.'" dir="'.$dir.'"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
            .'<title>'.$e($t['heading']).' · '.$e($word.$accent).'</title><style>'.$css.'</style></head><body>'
            .'<a class="lang" href="'.$e($base.$other[0]).'" hreflang="'.$other[2].'" lang="'.$other[2].'">'.$e($other[1]).'</a>'
            .'<main><p class="logo" dir="ltr"><bdi>'.$e($word).'<span>'.$e($accent).'</span></bdi></p>'
            .'<div class="dots" aria-hidden="true"><i></i><i></i><i></i></div>'
            .'<h1>'.$e($t['heading']).'</h1><p class="m">'.$e($t['message']).'</p>'
            .($t['small'] !== '' ? '<p class="s">'.$e($t['small']).'</p>' : '')
            .($links !== '' ? '<nav class="links">'.$links.'</nav>' : '')
            .'</main></body></html>';
    }

    /**
     * The header's wordmark and colours, read raw from the settings map the
     * gate already holds (no SettingsService read), each colour checked.
     *
     * @param  array<string, mixed>  $map
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    public static function wordmark(array $map): array
    {
        $h = json_decode((string) ($map['header_settings'] ?? ''), true);
        $h = is_array($h) ? $h : [];
        $text = static fn ($v, string $d): string => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, 60) : $d;
        $hex = static fn ($v, string $d): string => is_string($v) && preg_match('/^#[0-9A-F]{6}$/iD', trim($v)) === 1 ? strtoupper(trim($v)) : $d;

        return [
            $text($h['logo_text'] ?? null, 'K-Beauty'),
            $text($h['logo_accent'] ?? null, 'Bliss'),
            $hex($h['logo_colour'] ?? null, self::INK),
            $hex($h['logo_accent_col'] ?? null, self::ACCENT),
        ];
    }

    /**
     * The small notice a signed-in admin or a preview-link holder sees on the
     * real shop at the hidden address. Constant text; pointer-events:none so it
     * can never sit over a link and swallow a click.
     */
    public static function notice(string $who): string
    {
        $text = $who === ComingSoon::ADMIN
            ? 'Coming Soon is ON for visitors on this address'
            : 'Preview — Coming Soon is ON for visitors on this address';

        return '<div role="status" data-kbb-coming-soon-notice style="position:fixed;bottom:12px;left:12px;z-index:2147483646;max-width:calc(100vw - 24px);'
            .'display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;background:#2A2228;color:#fff;'
            .'font:600 12.5px/1.35 system-ui,-apple-system,\'Segoe UI\',Roboto,sans-serif;box-shadow:0 8px 24px -8px rgba(0,0,0,.45);pointer-events:none">'
            .'<span aria-hidden="true" style="width:8px;height:8px;border-radius:50%;background:#F59E0B;flex:none"></span>'.$text.'</div>';
    }
}
