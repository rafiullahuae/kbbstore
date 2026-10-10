<?php

declare(strict_types=1);

namespace App\Services\Mail\Kit;

use App\Support\ImageVariants;
use App\Support\Money;
use App\Support\Url;

/**
 * Look A, the approved email design, as data for the Blade kit — Lane RM.
 *
 * THE MARKUP LIVES IN resources/views/emails/kit/*.blade.php, one partial per
 * block of tools/rj-email-kit.cjs, which is the approved design as code (the
 * owner signed off docs/rj-email-previews/ on 3 October 2026: "i want 100% same
 * stuff as in previews"). This class holds only what those partials share: the
 * palette, theme A's numbers, the icon and tone tables, and the store's own
 * facts (fonts, colours, wordmark, support channels, footer) resolved once per
 * email from the `$brand` array every Mailable already carries.
 *
 * EVERY VALUE HERE IS EITHER A CONSTANT OR VALIDATED. The partials print
 * dynamic text through {{ }}; the few values that reach an attribute or a
 * <style> raw — colours, the font URL — are checked here against a pattern
 * that cannot carry markup (#rrggbb, an http(s) URL with no quote or bracket).
 * A URL from a setting is scheme-checked by url() before it becomes an href.
 */
final class MailKit
{
    /** tools/rj-email-kit.cjs `P`, verbatim. */
    public const P = [
        'cream' => '#FFF8F5', 'pinkSoft' => '#FFF0F4', 'blush' => '#FCE0E8', 'pink' => '#E0567B', 'pinkDeep' => '#C13E63',
        'pinkInk' => '#A82F53', 'ink' => '#2A2228', 'ink2' => '#5E545A', 'muted' => '#8C828A', 'line' => '#F0E4E9',
        'green' => '#2E9E6B', 'greenSoft' => '#E6F5EE', 'amber' => '#B86E12', 'amberSoft' => '#FDF1E1', 'red' => '#C0392B', 'redSoft' => '#FCEBEA',
        'white' => '#FFFFFF', 'ink0' => '#141013',
    ];

    /** The kit's SANS stack: the shop's Outfit, then the system fonts. */
    public const SANS = "'Outfit',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    /**
     * The font file, at an address that never changes.
     *
     * NOT Vite::asset(): that URL carries a content hash and changes on every
     * build, and an email is read for years — a receipt opened next spring
     * would ask for a file the server deleted in a later package. NOT
     * public/fonts/… either: the updater ships only public/build/ (UpdateGuard
     * ::ALLOWED_PREFIXES) and the live web root is a different directory from
     * the app root (CLAUDE.md), so a file placed there never reaches the shop.
     * routes/mail-kit.php serves resources/fonts/outfit/outfit-latin.woff2 —
     * the same bytes the storefront self-hosts — at this fixed path.
     */
    public const FONT_PATH = '/mail/font/outfit-latin.woff2';

    /** Theme A of the kit (THEMES.A), the numbers the partials read. */
    public const A = [
        'outer' => '#FFF8F5', 'outerPad' => '22px 10px 30px', 'radius' => '18px',
        'chipBg' => '#FFF8F5', 'chipLine' => '#F0E4E9', 'chipRadius' => '12px', 'helpBg' => '#FFF0F4',
        'headBg' => '#FFF0F4', 'headPad' => '26px 24px 20px',
        'wordSize' => '26px', 'wordWeight' => 800, 'wordTrack' => '-.02em', 'wordInk' => '#2A2228', 'wordAccent' => '#C13E63',
        'navInk' => '#5E545A', 'h1' => '28px', 'h1Weight' => 600, 'h1Track' => '-.015em',
        'btnRadius' => '99px', 'topInk' => '#8C828A', 'footInk' => '#8C828A', 'footStrong' => '#5E545A',
    ];

    /** ICONS, as the numeric entities the kit prints. Constants: printed raw. */
    public const ICONS = [
        'heart' => '&#10084;', 'check' => '&#10003;', 'box' => '&#128230;', 'truck' => '&#128666;', 'gift' => '&#127873;',
        'pause' => '&#10074;&#10074;', 'cross' => '&#10005;', 'back' => '&#8634;', 'card' => '&#128179;', 'clock' => '&#9719;',
        'bell' => '&#128276;', 'bag' => '&#128717;', 'mail' => '&#9993;', 'key' => '&#128273;', 'spark' => '&#10024;', 'star' => '&#9733;',
        // Lane EC: design C's section mark ("🌸 Fresh picks for less").
        'blossom' => '&#127800;',
    ];

    /** TONES: [soft background, ink]. */
    public const TONES = [
        'pink' => ['#FFF0F4', '#C13E63'], 'green' => ['#E6F5EE', '#2E9E6B'], 'amber' => ['#FDF1E1', '#B86E12'],
        'red' => ['#FCEBEA', '#C0392B'], 'ink' => ['#F1EDEF', '#2A2228'],
    ];

    /**
     * The policy pages in the footer, in the approved order -- WITHOUT the
     * preview's "Returns & refunds": the owner, after approving the previews,
     * "no returns link, the shop does not offer returns" (Lane EM).
     */
    public const FOOTER_LINKS = [
        'terms' => ['email.kit.footer_terms', '/terms-and-conditions/'],
        'privacy' => ['email.kit.footer_privacy', '/privacy-policy/'],
    ];

    /** The header's three links. */
    public const NAV = [
        ['email.kit.nav_shop', '/shop/'],
        ['email.kit.nav_track', '/track-my-order/'],
        ['email.kit.nav_account', '/my-account/'],
    ];

    /** The help box's one-letter glyph per channel kind. */
    public const CHANNEL_GLYPH = ['whatsapp' => 'W', 'email' => '@', 'instagram' => 'IG'];

    /**
     * Everything the partials read, resolved once.
     *
     * @param  array<string, mixed>  $brand  EmailBranding::present() (or its fallback)
     * @return array<string, mixed>
     */
    public static function for(array $brand): array
    {
        $look = is_array($brand['look'] ?? null) ? $brand['look'] : [];

        $sans = self::fontStack($look['bodyFont'] ?? null);
        $head = self::fontStack($look['headingFont'] ?? null);
        $usesOutfit = str_contains($sans, "'Outfit'") || str_contains($head, "'Outfit'");

        [$ink, $accent] = is_array($brand['wordmark'] ?? null) ? array_values($brand['wordmark']) + ['', ''] : ['', ''];

        $store = trim((string) ($brand['storeName'] ?? ''));
        $store = $store !== '' ? $store : \App\Support\BrandName::appName();

        if (trim((string) $ink) === '' && trim((string) $accent) === '') {
            [$ink, $accent] = [$store, ''];
        }

        return [
            'sans' => $sans,
            'head' => $head,
            'fontFace' => $usesOutfit ? self::fontFaceCss() : '',
            'button' => self::hex($look['button'] ?? null, self::P['pinkDeep']),
            'accent' => self::hex($look['accent'] ?? null, self::P['pink']),
            // Emails → Design & branding → Colours → Background and Text
            // (Lane EM): the page behind the card and the ink of every
            // heading and figure. #rrggbb or the approved default.
            'background' => self::hex($look['background'] ?? null, self::P['cream']),
            'text' => self::hex($look['text'] ?? null, self::P['ink']),
            'storeName' => $store,
            'wordmark' => [(string) $ink, (string) $accent],
            'logo' => self::logo($brand['logoUrl'] ?? null),
            'support' => self::support($brand['support'] ?? []),
            'signature' => array_values(array_filter(
                array_map(static fn ($l) => trim((string) $l), (array) ($brand['signature'] ?? [])),
                static fn (string $l) => $l !== '',
            )),
            'addresses' => self::addresses($brand),
            'links' => self::links(),
            'nav' => array_map(static fn (array $n) => [__($n[0]), Url::external($n[1])], self::NAV),
            'year' => (int) now()->format('Y'),
            'site' => self::siteHost(),
        ];
    }

    /** A font stack the partials may print inside a style attribute. */
    public static function fontStack(mixed $stack): string
    {
        $stack = is_string($stack) ? trim($stack) : '';

        // Letters, digits, spaces, commas, hyphens and single quotes — a font
        // list and nothing that can close the attribute or the declaration.
        return $stack !== '' && preg_match("/^[A-Za-z0-9 ,'\\-]{1,200}$/", $stack) === 1 ? $stack : self::SANS;
    }

    /** #rrggbb, upper-cased the way the kit writes it, else the default. */
    public static function hex(mixed $value, string $default): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtoupper($value) : $default;
    }

    /**
     * The @font-face rule, or '' when the address cannot be trusted raw.
     *
     * Printed inside <style>, where {{ }} is the wrong escape, so the URL is
     * proved to be a plain http(s) URL with no quote, bracket, backslash or
     * space before it is allowed anywhere near the rule.
     */
    public static function fontFaceCss(): string
    {
        $url = Url::external(self::FONT_PATH);

        if (preg_match('#^https?://[^\s\'"()<>\\\\]+$#i', $url) !== 1) {
            return '';
        }

        return "@font-face{font-family:'Outfit';src:url('" . $url . "') format('woff2');font-weight:100 900;font-style:normal;font-display:swap}";
    }

    /**
     * An href from data: http(s) or mailto: as given, a site path made
     * absolute, anything else refused (null).
     */
    public static function url(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return null;
        }

        if (preg_match('#^(https?://|mailto:)#i', $value) === 1) {
            return $value;
        }

        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return Url::external($value);
        }

        return null;
    }

    /**
     * A picture for an email: an https URL of a file that exists in this web
     * root, as a JPEG copy sized for the box, or null.             (Lane EM)
     *
     * $width keeps its old meaning — the shop variant the caller used to ask
     * for (200 for the 64px item and rate rows, 400 for 200px cards, 600 for
     * full-width blocks) — and maps onto MailImage::WIDTHS. Everything else,
     * and why the old body sent every product picture to a 404, is in
     * MailImage's header.
     */
    public static function image(mixed $stored, int $width = 200, bool $placeholder = true): ?string
    {
        return MailImage::src($stored, $width <= 200 ? 128 : ($width <= 400 ? 400 : 600), $placeholder);
    }

    /**
     * The height an <img> of $width should declare, from the file's own
     * proportions; square when they cannot be read.
     */
    public static function heightFor(?string $stored, int $width): int
    {
        try {
            $aspect = is_string($stored) && $stored !== '' ? ImageVariants::aspectOf($stored) : null;
        } catch (\Throwable) {
            $aspect = null;
        }

        return $aspect !== null && $aspect > 0.2 && $aspect < 5 ? (int) round($width / $aspect) : $width;
    }

    /** Money as the kit prints it: the shop's own formatter, plain text. */
    public static function money(int $minor): string
    {
        return Money::plain($minor);
    }

    /**
     * The logo: [url, width, height] at 170px wide, or null.
     *
     * Null unless the file's proportions can be read, because every <img> in
     * these emails declares both dimensions (an image-blocked Outlook draws a
     * box of exactly that size, and without a height it collapses the header).
     * Without a logo the header prints the store's wordmark in text, which is
     * the approved look and shows even with images off.
     *
     * @return array{0:string,1:int,2:int|null}|null
     */
    private static function logo(mixed $logoUrl): ?array
    {
        /*
         * (Lane EM, 8 October) Through MailImage, like every other picture in
         * the kit: a local logo is a file that exists on the shop's https
         * origin (a WebP one gets a PNG copy), a missing one prints the
         * wordmark rather than a broken frame.
         *
         * A logo whose proportions cannot be read (an https URL on another
         * host, the shape EmailLook and org_logo both accept) is still the
         * owner's logo: it prints at 170px wide with no declared height.
         * Dropping it printed the wordmark instead of a logo the owner had
         * set, with nothing saying why.
         */
        $logo = MailImage::logo($logoUrl);

        if ($logo === null) {
            return null;
        }

        [$url, $w, $h] = $logo;

        if ($w === null || $h === null || $w < 1 || $h < 1) {
            return [$url, 170, null];
        }

        return [$url, 170, max(1, (int) round(170 * $h / $w))];
    }

    /**
     * The help box's channels, from EmailBranding::support() — already the
     * store's real values; re-checked here because they become hrefs.
     *
     * @return list<array{kind:string,glyph:string,value:string,label:string,url:string}>
     */
    private static function support(mixed $support): array
    {
        $out = [];

        foreach (is_array($support) ? $support : [] as $channel) {
            $kind = (string) ($channel['kind'] ?? '');
            $url = self::url($channel['url'] ?? null);

            if (! isset(self::CHANNEL_GLYPH[$kind]) || $url === null) {
                continue;
            }

            $out[] = [
                'kind' => $kind,
                'glyph' => self::CHANNEL_GLYPH[$kind],
                'value' => (string) ($channel['value'] ?? ''),
                'label' => (string) ($channel['label'] ?? ''),
                'url' => $url,
            ];
        }

        return $out;
    }

    /**
     * The footer's two addresses, whichever EmailBranding carries them under
     * (Lane RK's `addresses`, or `footer.addresses`). A place with no lines is
     * left out; with neither, the footer drops the whole row rather than print
     * a placeholder to a customer.
     *
     * @return list<array{place:string,label:string,lines:list<string>}>
     */
    private static function addresses(array $brand): array
    {
        $raw = $brand['addresses'] ?? ($brand['footer']['addresses'] ?? []);
        $out = [];

        foreach (is_array($raw) ? $raw : [] as $row) {
            $place = (string) ($row['place'] ?? '');
            $lines = array_values(array_filter(
                array_map(static fn ($l) => trim((string) $l), (array) ($row['lines'] ?? [])),
                static fn (string $l) => $l !== '',
            ));

            if (! in_array($place, ['dubai', 'korea'], true) || $lines === []) {
                continue;
            }

            $out[] = ['place' => $place, 'label' => __('email.layout.address_' . $place), 'lines' => $lines];
        }

        return $out;
    }

    /** @return list<array{label:string,url:string}> */
    private static function links(): array
    {
        $out = [];

        foreach (self::FOOTER_LINKS as [$key, $path]) {
            $out[] = ['label' => __($key), 'url' => Url::external($path)];
        }

        return $out;
    }

    /** "extrabeauty.ae" — the shop's own host, for the footer's why-line. */
    private static function siteHost(): string
    {
        $host = (string) parse_url(Url::external('/'), PHP_URL_HOST);

        return preg_replace('/^www\./i', '', $host) ?? $host;
    }
}
