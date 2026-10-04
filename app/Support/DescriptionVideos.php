<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use App\Services\Import\MediaRewrite;
use App\Services\SettingsService;

/**
 * A video file named in a product's copy, drawn as a player. (Lane PD)
 *
 * ── WHAT THE OWNER SAW ──────────────────────────────────────────────────────
 *
 *   "on few product pages, we added a custom section of 2 videos [...] but
 *    it's not showing at all."
 *
 * /product/medicube-collagen-booster-set-pink-edition/ ended its Description
 * tab with two addresses glued into one line of text:
 *
 *   http://kbeautybliss.com/wp-content/uploads/2024/11/qfeoq0zeznudldm76nvl-1.webm
 *   http://kbeautybliss.com/wp-content/uploads/2024/11/wafopbfejfivqjxml2ky-1.webm
 *
 * printed with nothing between them. On WordPress a media address on a line of
 * its own is an embed -- autoembed turns it into the [video] shortcode and the
 * page draws a player -- and the export carried the address, not the player.
 * Nothing here knew what it meant, so the shopper read a URL.
 *
 * ── THE RULES ───────────────────────────────────────────────────────────────
 *
 *  - ON ITS OWN LINE, AS WORDPRESS DID IT. A run of video addresses (and
 *    [video …] / [embed]…[/embed] shortcodes) that fills a paragraph, a line of
 *    one, or a block element is drawn. One inside a sentence -- "watch it at
 *    https://…/a.mp4 today" -- stays the words it was, exactly as autoembed
 *    left it. Inside a link, a heading or <pre>, never.
 *  - GLUED ADDRESSES ARE TWO. `…a.webmhttp://…b.webm` is split at the second
 *    scheme, which is the shape the export left on the owner's shop.
 *  - ALLOWLISTED HOSTS ONLY. http(s), no user-info, no port, and a host that is
 *    this shop (site_url, APP_URL, the canonical host and its aliases) or the
 *    old shop (kbeautybliss.com, extrabeauty.ae, with or without www). Any
 *    other address in the run leaves the whole run as text.
 *  - THE SHOP'S OWN COPY FIRST. When `php artisan kbb:fetch-description-videos`
 *    has brought the file across, the player points at it under this web root
 *    -- one stat() per video, no query -- so nothing depends on the old
 *    WordPress once kbeautybliss.com is pointed here.
 *  - ESCAPED, ALWAYS. The address is matched without quotes, angle brackets or
 *    whitespace, re-validated whole, entity-decoded and printed through e().
 *  - LIGHT. controls, playsinline and preload="metadata": no autoplay, no
 *    sound, a few kilobytes until the shopper presses play. The box is sized
 *    in CSS (`.kbb-dvid__v`), so nothing shifts when the metadata arrives.
 *  - NOTHING TO DO, NOTHING CHANGED. Copy naming no video comes back as the
 *    same string, byte for byte, and costs no query (present() is a stripos).
 *  - THE SWITCH. Store → Modules → This app only → "Videos in product
 *    descriptions" (module `desc_videos`, on). Off prints the copy as it was.
 */
final class DescriptionVideos
{
    /** The old shop's hosts, allowlisted whatever the settings say. */
    public const OLD_HOSTS = ['kbeautybliss.com', 'extrabeauty.ae'];

    /** The extensions drawn as a player. */
    public const EXTENSIONS = ['mp4', 'webm', 'mov', 'm4v'];

    /** Container key for the request's allowlist. */
    private const MEMO = 'kbb.description_videos.hosts';

    /** Whitespace, a non-breaking space in any spelling, or a <br>. */
    private const EDGE = '(?:[\s\x{00A0}]|&nbsp;|&#160;|&#xa0;|<br\s*\/?>)*';

    /** Elements a player may sit directly inside, or next to. */
    private const BLOCKS = ['p', 'div', 'section', 'article', 'aside', 'li', 'ul', 'ol', 'td', 'th', 'tr', 'table',
        'tbody', 'thead', 'tfoot', 'blockquote', 'figure', 'figcaption', 'dd', 'dt', 'dl', 'hr', 'h2', 'h3', 'h4',
        'h5', 'h6'];

    /** Of those, the ones whose OPENING tag may stand right before a player. */
    private const CONTAINERS = ['div', 'section', 'article', 'aside', 'li', 'td', 'th', 'blockquote', 'figure', 'dd'];

    /** Is there anything here for expand() to look at? Cheap; no query. */
    public static function present(?string $html): bool
    {
        if ($html === null || $html === '') {
            return false;
        }

        foreach (self::EXTENSIONS as $ext) {
            if (stripos($html, '.' . $ext) !== false) {
                return true;
            }
        }

        return stripos($html, '[video') !== false;
    }

    /**
     * Clean HTML in, the same HTML with every video run drawn as players out.
     *
     * @param  bool|null  $on  the switch; null reads Store → Modules
     */
    public static function expand(?string $html, ?bool $on = null): string
    {
        $html = (string) $html;

        if (! self::present($html)) {
            return $html;
        }

        $on ??= app(SettingsService::class)->moduleEnabled('desc_videos', true);

        if (! $on) {
            return $html;
        }

        if ((int) preg_match_all('/' . self::run() . '/iu', $html, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return $html;
        }

        // Right to left, so every offset still to come is still true.
        foreach (array_reverse($matches[0]) as [$text, $at]) {
            $html = self::splice($html, $text, (int) $at);
        }

        return self::merge($html);
    }

    /**
     * Every video a run of copy names, as the address a player would load.
     *
     * For the fetch command: the same parser as the storefront, so the files
     * it brings across are exactly the ones the page would play. Addresses on
     * hosts outside the allowlist are left out, as the page leaves them out.
     *
     * @return list<string>
     */
    public static function addresses(?string $html): array
    {
        if (! self::present($html)) {
            return [];
        }

        $text = (string) $html;

        if (stripos($text, '<video') !== false) {
            $text = self::unwrapTags($text);
        }

        preg_match_all('/' . self::item() . '/iu', $text, $matches);

        $out = [];

        foreach ($matches[0] as $item) {
            $url = self::source($item);

            if ($url !== null && ! in_array($url, $out, true)) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /**
     * A raw `<video>` element as the addresses it plays, one per line.
     *
     * For RichText::clean(), BEFORE the allowlist runs. `video` is on its
     * DROP_WHOLE list, so a pasted or imported `<video src="…">` -- the other
     * shape a "section of 2 videos" can arrive in -- vanished whole and left
     * no trace. Its address is kept as text instead, on a paragraph of its
     * own, which is what expand() draws as a player at render time. The tag
     * and every attribute on it still go: nothing new is printed by clean().
     */
    public static function unwrapTags(string $html): string
    {
        return (string) preg_replace_callback(
            '#<video\b[^>]*>.*?</video\s*>|<video\b[^>]*/?>#is',
            static function (array $m): string {
                preg_match_all('#\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $m[0], $srcs, PREG_SET_ORDER);

                $urls = [];

                foreach ($srcs as $s) {
                    $url = trim(html_entity_decode(($s[1] ?? '') . ($s[2] ?? '') . ($s[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                    if (preg_match('/^' . self::url() . '$/iu', $url) === 1 && ! in_array($url, $urls, true)) {
                        $urls[] = $url;
                    }
                }

                return $urls === [] ? '' : '<p>' . implode('<br>', array_map(static fn (string $u): string => e($u), $urls)) . '</p>';
            },
            $html,
        );
    }

    /* ═══════════════════════════════ patterns ═══════════════════════════════ */

    /**
     * One video address. Lazy up to the first extension that is followed by a
     * boundary -- whitespace, a tag, the next `http(s)://` (the glued case), a
     * quote or bracket, sentence punctuation, or the end.
     */
    private static function url(): string
    {
        $c = '[^\s\x{00A0}<>"\'\[\]]';

        return 'https?:\/\/' . $c . '+?\.(?:' . implode('|', self::EXTENSIONS) . ')(?:\?' . $c . '*?)?'
            . '(?=https?:\/\/|[\s\x{00A0}<>"\'\[\]]|&nbsp;|&#160;|&#xa0;|[.,!)]?(?:[\s\x{00A0}<]|$)|$)';
    }

    /** One item: an address, a [video] shortcode, or an [embed] of an address. */
    private static function item(): string
    {
        return '(?:' . self::url()
            . '|\[video\b[^\]\[]*\](?:' . self::EDGE . '\[\/video\])?'
            . '|\[embed\b[^\]\[]*\]' . self::EDGE . self::url() . self::EDGE . '\[\/embed\])';
    }

    /** Items separated only by edge. Never inside a tag. */
    private static function run(): string
    {
        return '(?![^<>]*>)' . self::item() . '(?:' . self::EDGE . self::item() . ')*';
    }

    /* ═══════════════════════════════ splicing ═══════════════════════════════ */

    /** One run, drawn in place if it stands on its own line, else left alone. */
    private static function splice(string $html, string $run, int $at): string
    {
        preg_match_all('/' . self::item() . '/iu', $run, $items);

        $urls = [];

        foreach ($items[0] as $item) {
            $url = self::source($item);

            if ($url === null) {
                return $html;   // one address off the allowlist: the run stays text
            }

            $urls[] = $url;
        }

        if ($urls === []) {
            return $html;
        }

        $left = self::edgeBefore($html, $at);
        $right = self::edgeAfter($html, $at + strlen($run));

        $before = self::tagBefore($html, $left['at']);
        $after = self::tagAfter($html, $right['at']);

        $token = self::token($urls);

        // A whole paragraph: the paragraph goes.
        if ($before !== null && $before['name'] === 'p' && ! $before['close']
            && $after !== null && $after['name'] === 'p' && $after['close']) {
            return substr($html, 0, $before['at']) . $token . substr($html, $after['at'] + strlen($after['raw']));
        }

        // The first line of a paragraph: the player, then the paragraph.
        if ($before !== null && $before['name'] === 'p' && ! $before['close'] && $right['br']) {
            return substr($html, 0, $before['at']) . $token . $before['raw'] . substr($html, $right['at']);
        }

        // The last line of a paragraph: the paragraph, then the player.
        if ($left['br'] && $after !== null && $after['name'] === 'p' && $after['close']) {
            return substr($html, 0, $left['at']) . '</p>' . $token . substr($html, $after['at'] + strlen($after['raw']));
        }

        // A line in the middle of a paragraph: the paragraph is split round it.
        if ($left['br'] && $right['br']) {
            return self::insideParagraph($html, $at)
                ? substr($html, 0, $left['at']) . '</p>' . $token . '<p>' . substr($html, $right['at'])
                : substr($html, 0, $left['at']) . $token . substr($html, $right['at']);
        }

        // Standing between two block boundaries, or at the start or end.
        if (self::blockEdge($before, true) && self::blockEdge($after, false)) {
            return substr($html, 0, $left['at']) . $token . substr($html, $right['at']);
        }

        return $html;   // inside a sentence, a link, a heading: the words stay
    }

    /**
     * Walk left over whitespace and <br>s. `at` is where the edge begins;
     * `br` says a line break was crossed.
     *
     * @return array{at: int, br: bool}
     */
    private static function edgeBefore(string $html, int $at): array
    {
        $br = false;

        while ($at > 0) {
            if (preg_match('/(?:\s|\xC2\xA0|&nbsp;|&#160;|&#xa0;|<br\s*\/?>)\z/i', substr($html, max(0, $at - 8), min($at, 8)), $m) !== 1) {
                break;
            }

            $br = $br || stripos($m[0], '<br') === 0;
            $at -= strlen($m[0]);
        }

        return ['at' => $at, 'br' => $br];
    }

    /** @return array{at: int, br: bool} */
    private static function edgeAfter(string $html, int $at): array
    {
        $br = false;

        while ($at < strlen($html)
            && preg_match('/\G(?:\s|\xC2\xA0|&nbsp;|&#160;|&#xa0;|<br\s*\/?>)/i', $html, $m, 0, $at) === 1) {
            $br = $br || stripos($m[0], '<br') === 0;
            $at += strlen($m[0]);
        }

        return ['at' => $at, 'br' => $br];
    }

    /**
     * The tag that ends exactly at `at`, or ['name' => '^'] at the start of the
     * document, or null when text stands there.
     *
     * @return array{name: string, close: bool, at: int, raw: string}|null
     */
    private static function tagBefore(string $html, int $at): ?array
    {
        if ($at === 0) {
            return ['name' => '^', 'close' => false, 'at' => 0, 'raw' => ''];
        }

        if ($html[$at - 1] !== '>') {
            return null;
        }

        $open = strrpos(substr($html, 0, $at), '<');

        return $open === false ? null : self::tag(substr($html, $open, $at - $open), $open);
    }

    /** @return array{name: string, close: bool, at: int, raw: string}|null */
    private static function tagAfter(string $html, int $at): ?array
    {
        if ($at >= strlen($html)) {
            return ['name' => '$', 'close' => true, 'at' => $at, 'raw' => ''];
        }

        if ($html[$at] !== '<') {
            return null;
        }

        $close = strpos($html, '>', $at);

        return $close === false ? null : self::tag(substr($html, $at, $close - $at + 1), $at);
    }

    /** @return array{name: string, close: bool, at: int, raw: string}|null */
    private static function tag(string $raw, int $at): ?array
    {
        if (preg_match('/^<\s*(\/)?\s*([a-z][a-z0-9]*)/i', $raw, $m) !== 1) {
            return null;
        }

        return ['name' => strtolower($m[2]), 'close' => $m[1] === '/', 'at' => $at, 'raw' => $raw];
    }

    /** May a player stand right after (or right before) this boundary? */
    private static function blockEdge(?array $tag, bool $before): bool
    {
        if ($tag === null) {
            return false;
        }

        if ($tag['name'] === '^' || $tag['name'] === '$') {
            return true;
        }

        // Before the run: a block that CLOSED, or a container that OPENED.
        // After it: a block that OPENS, or a container that CLOSES.
        return $tag['close'] === $before
            ? in_array($tag['name'], self::BLOCKS, true)
            : in_array($tag['name'], self::CONTAINERS, true);
    }

    /** Is offset `at` inside an open <p>? clean() output is well formed. */
    private static function insideParagraph(string $html, int $at): bool
    {
        $head = substr($html, 0, $at);

        preg_match_all('/<p[\s>]/i', $head, $opens, PREG_OFFSET_CAPTURE);
        $lastOpen = $opens[0] === [] ? -1 : (int) end($opens[0])[1];
        $lastClose = ($c = strripos($head, '</p>')) === false ? -1 : $c;

        return $lastOpen > $lastClose;
    }

    /* ═══════════════════════════════ drawing ════════════════════════════════ */

    /** A placeholder for a group of players, merged with its neighbours later. */
    private static function token(array $urls): string
    {
        return "\x00kbbdv:" . base64_encode(implode("\n", $urls)) . "\x00";
    }

    /**
     * Neighbouring groups become one grid -- two paragraphs of one address each
     * are the same "section of 2 videos" as one paragraph of two -- and every
     * group is drawn.
     */
    private static function merge(string $html): string
    {
        if (! str_contains($html, "\x00kbbdv:")) {
            return $html;
        }

        $html = (string) preg_replace_callback(
            '/\x00kbbdv:[A-Za-z0-9+\/=]*\x00(?:\s*\x00kbbdv:[A-Za-z0-9+\/=]*\x00)+/',
            static function (array $m): string {
                preg_match_all('/\x00kbbdv:([A-Za-z0-9+\/=]*)\x00/', $m[0], $parts);

                $urls = [];

                foreach ($parts[1] as $part) {
                    array_push($urls, ...explode("\n", (string) base64_decode($part, true)));
                }

                return self::token($urls);
            },
            $html,
        );

        return (string) preg_replace_callback(
            '/\x00kbbdv:([A-Za-z0-9+\/=]*)\x00/',
            static fn (array $m): string => self::grid(explode("\n", (string) base64_decode($m[1], true))),
            $html,
        );
    }

    /** @param list<string> $urls */
    private static function grid(array $urls): string
    {
        $out = '<div class="kbb-dvid kbb-dvid--' . (count($urls) > 1 ? '2' : '1') . '">';

        foreach ($urls as $url) {
            $out .= '<video class="kbb-dvid__v" src="' . e(self::local($url)) . '" controls playsinline preload="metadata"></video>';
        }

        return $out . '</div>';
    }

    /* ═══════════════════════════════ addresses ══════════════════════════════ */

    /** The address one item plays, if it is one this shop will draw. */
    private static function source(string $item): ?string
    {
        $item = trim($item);

        if (stripos($item, '[video') === 0) {
            $attrs = html_entity_decode((string) preg_replace('/\[\/video\]$/i', '', $item), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $attrs = str_replace(['“', '”', '″', '‘', '’'], '"', $attrs);

            preg_match_all('/\b(?:src|mp4|webm|m4v|mov)\s*=\s*["\']?([^"\'\s\]]+)/i', $attrs, $m);

            foreach ($m[1] as $candidate) {
                if (($ok = self::allowed($candidate)) !== null) {
                    return $ok;
                }
            }

            return null;
        }

        if (stripos($item, '[embed') === 0) {
            return preg_match('/' . self::url() . '/iu', $item, $m) === 1 ? self::allowed($m[0]) : null;
        }

        return self::allowed($item);
    }

    /** The decoded address, when it is http(s) to an allowlisted host. */
    private static function allowed(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (preg_match('/^' . self::url() . '$/iu', $url) !== 1 || preg_match('/[\s"\'<>`\\\\]/u', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        return $host !== '' && in_array($host, self::hosts(), true) ? $url : null;
    }

    /**
     * This shop's hosts and the old shop's, each with and without www.
     * Read from the settings snapshot the page already holds: no query.
     *
     * @return list<string>
     */
    public static function hosts(): array
    {
        if (app()->bound(self::MEMO)) {
            return (array) app(self::MEMO);
        }

        $map = Setting::map();
        $hosts = [...self::OLD_HOSTS, SiteHost::canonical(), ...SiteHost::aliases()];

        foreach ([(string) ($map['site_url'] ?? ''), (string) config('app.url'), (string) config('kbb.media_root')] as $url) {
            $host = parse_url($url, PHP_URL_HOST);

            if (is_string($host)) {
                $hosts[] = $host;
            }
        }

        $out = [];

        foreach ($hosts as $host) {
            $host = strtolower(rtrim(trim((string) $host), '.'));

            if ($host === '' || ! str_contains($host, '.')) {
                continue;
            }

            $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
            $out[$bare] = true;
            $out['www.' . $bare] = true;
        }

        $out = array_keys($out);
        app()->instance(self::MEMO, $out);

        return $out;
    }

    /** Forget the request's allowlist (tests, and a settings save). */
    public static function forget(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    /**
     * This shop's copy of the file when it has one, else the address as written.
     * The file is where kbb:fetch-description-videos (and the picture fetch)
     * put an upload: the same path under this web root.
     */
    public static function local(string $url): string
    {
        $relative = self::relative($url);

        return $relative !== null && is_file(public_path($relative)) ? Url::raw($relative) : $url;
    }

    /** `wp-content/uploads/…/a.webm` for an uploads address, or null. */
    public static function relative(string $url): ?string
    {
        $relative = MediaRewrite::uploadsRelativeTo((string) parse_url($url, PHP_URL_PATH));

        if ($relative === null || str_contains($relative, '..') || preg_match('#^[A-Za-z0-9/._-]+$#', $relative) !== 1) {
            return null;
        }

        return $relative;
    }
}
