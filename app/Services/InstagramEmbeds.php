<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Instagram embeds: paste a post or reel address, the shop draws Instagram's
 * own player for it. No API, no login, no token.                    (Lane IGE)
 *
 * THE OWNER, 9 October 2026: "change in plan for instagram. i want the embed
 * functionality. build me a system where i can place the instagram post or
 * video url, and on front-end it will create automatically. as embed
 * functinoality, without any api or login etc. if we can change the card
 * styles, then give me cards styles to choose from".
 *
 * Admin: Content → Instagram embeds (resources/views/admin/partials/
 * ig-embeds-screen.blade.php, endpoints in routes/ig-embeds-admin.php).
 * Shop: the `igembeds` row on Appearance → Homepage, and [kbb_instagram_embeds]
 * in any page, post or HTML block. The API module beside it (Content →
 * Instagram, App\Services\Instagram\*) is untouched and is not read here.
 *
 * ── WHAT IS EMBEDDED, AND WHY NOT INSTAGRAM'S SCRIPT ────────────────────────
 *
 * Instagram serves a framable page for every public post at
 * https://www.instagram.com/{p|reel}/{shortcode}/embed/ (and /embed/captioned/
 * for the caption and comment count). That is the page its own embed.js puts in
 * an iframe; embed.js only adds the blockquote swap and a postMessage height
 * resize. Without the script the frame does not resize, so this class reserves a
 * height per type in CSS (HEIGHT_NOTE) and the frame scrolls inside rather than
 * clipping. oEmbed now needs a Facebook access token, so it is out.
 *
 * ── NOTHING TYPED IS PRINTED ────────────────────────────────────────────────
 *
 * The owner's paste is reduced to TWO values, a kind ('p' or 'reel') and a
 * shortcode that matched CODE_RE, and everything the shop prints — the iframe
 * src, the "View on Instagram" href — is rebuilt from those two with a constant
 * host. A query string, a fragment, a username segment, an `embed/` suffix: all
 * dropped. The optional label is plain text, escaped by Blade. Every class and
 * style comes from a select's own option keys (rule 5).
 *
 * ── COST ────────────────────────────────────────────────────────────────────
 *
 * Everything is in `settings` (autoload), so the section reads the settings map
 * the request has ALREADY read (SettingsRequestMemo) — no query, on any page.
 * A page whose content does not name the shortcode never reaches this class
 * (Shortcodes::render() returns early without '[kbb_'), and the homepage row
 * renders nothing at all while the list is empty.
 */
final class InstagramEmbeds
{
    public const PREFIX = 'igembed_';

    /** The list, one JSON setting: [{c: shortcode, k: 'p'|'reel', l: label, on: bool}, …]. */
    public const ITEMS_KEY = 'igembed_items';

    /** The most addresses the list keeps; the section draws at most `max` of them. */
    public const MAX_ITEMS = 48;

    /** The most lines one paste is read for. */
    public const MAX_LINES = 60;

    /** The longest label the owner may give a card. */
    public const LABEL_MAX = 80;

    /**
     * A shortcode as Instagram writes one: the base64url alphabet. Public posts
     * are 11 characters today and older ones fewer; 5..40 refuses a stray word
     * and still takes every real code. Anchored, so a newline cannot ride along.
     */
    public const CODE_RE = '/^[A-Za-z0-9_-]{5,40}$/D';

    /** Hosts a pasted address may name. instagr.am is Instagram's own short host. */
    public const HOSTS = ['instagram.com', 'www.instagram.com', 'm.instagram.com', 'instagr.am', 'www.instagr.am'];

    /** The ONE host anything printed points at. */
    public const ORIGIN = 'https://www.instagram.com';

    /** The iframe's sandbox: the brief's four tokens, exactly. */
    public const SANDBOX = 'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox';

    public const REFERRER = 'strict-origin-when-cross-origin';

    /** The frame styles the owner chooses between. Keys are printed as classes. */
    public const STYLES = [
        'clean' => 'Clean — white card, thin border, rounded',
        'soft' => 'Soft — floating card with a shadow and a white mat',
        'polaroid' => 'Polaroid — square corners, a deep white mat',
        'ring' => 'Ring — Instagram-gradient border',
    ];

    public const SCHEMA = [
        'on' => ['type' => 'bool', 'label' => 'Show the section', 'default' => true,
            'help' => 'Off hides it on the homepage and wherever [kbb_instagram_embeds] is placed. Nothing shows while the list is empty either.'],
        'heading' => ['type' => 'text', 'label' => 'Heading', 'default' => 'Seen on Instagram',
            'help' => 'Empty draws no heading.'],
        'heading_ar' => ['type' => 'text', 'label' => 'Heading · Arabic shop', 'default' => '',
            'help' => 'Empty: the Arabic shop shows the heading above.'],
        'layout' => ['type' => 'select', 'label' => 'Layout', 'default' => 'grid',
            'options' => ['grid' => 'Grid — rows of cards', 'slider' => 'Slider — one row, swipe sideways']],
        'cols_d' => ['type' => 'select', 'label' => 'Cards across · laptop', 'default' => '3',
            'options' => ['2' => '2', '3' => '3', '4' => '4'],
            'help' => 'Instagram draws its post at 326px or wider for the best result, so 4 across suits wide screens.'],
        'cols_m' => ['type' => 'select', 'label' => 'Cards across · phone', 'default' => '1',
            'options' => ['1' => '1', '2' => '2 — small'],
            'help' => '900px and narrower. In the slider, the next card peeks in from the side.'],
        'style' => ['type' => 'select', 'label' => 'Card style', 'default' => 'clean', 'options' => self::STYLES,
            'help' => 'Styles the frame around the post. The inside of the post is Instagram’s own page and cannot be styled.'],
        'badge' => ['type' => 'bool', 'label' => 'Instagram badge on each card', 'default' => true,
            'help' => 'The Instagram glyph in the card’s top line, beside your label.'],
        'link' => ['type' => 'bool', 'label' => '“View on Instagram” link under each card', 'default' => true],
        'caption' => ['type' => 'bool', 'label' => 'Show Instagram’s caption inside the post', 'default' => false,
            'help' => 'On: Instagram’s captioned player (caption and comment count). The card is taller.'],
        'max' => ['type' => 'select', 'label' => 'Posts shown', 'default' => '6',
            'options' => ['3' => '3', '4' => '4', '6' => '6', '8' => '8', '9' => '9', '12' => '12', '16' => '16', '24' => '24'],
            'help' => 'The first ones switched on in the list, in its order.'],
        'load' => ['type' => 'select', 'label' => 'When the post loads', 'default' => 'near',
            'options' => [
                'near' => 'Automatically, as the shopper scrolls near it',
                'tap' => 'Only when the shopper taps the card',
            ],
            'help' => 'Either way nothing is fetched from Instagram while the page opens far above the section. “Tap” costs nothing at all until a tap.'],
        'fit' => ['type' => 'select', 'label' => 'Frame height', 'default' => '0',
            'options' => ['-60' => '60px shorter', '-30' => '30px shorter', '0' => 'Standard', '30' => '30px taller', '60' => '60px taller', '120' => '120px taller'],
            'help' => 'Instagram does not tell a page how tall its post is without its script. If a post shows a white band at the bottom, go shorter; if it scrolls inside, go taller.'],
    ];

    public const POLICY = [
        'max' => 120,
        'blank' => 'keep',
        'invalid' => 'default',
        'clamp' => true,
        'bool' => 'cast',
    ];

    /**
     * The Instagram glyph. A constant, so printing it raw is safe; it is drawn
     * inline, so it costs no request.
     */
    public const GLYPH = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="1" fill="currentColor" stroke="none"/></svg>';

    public function __construct(private SettingsService $settings) {}

    /* ───────────────────────────────────────────────────────── parsing */

    /**
     * One pasted line → ['kind' => 'p'|'reel', 'code' => …] or ['error' => why].
     *
     * Accepts https://, http:// and no scheme at all (the address bar's copy
     * often drops it); instagram.com, www., m. and instagr.am; /p/, /reel/,
     * /reels/ and /tv/, with or without a username segment in front, a query, a
     * fragment, a trailing slash or a pasted /embed/ suffix. Refuses every other
     * scheme (javascript:, data:, …), every other host, a userinfo or a port, a
     * /share/ link (it only resolves by asking Instagram) and a profile address.
     *
     * @return array{kind: string, code: string}|array{error: string}
     */
    public static function parse(?string $raw): array
    {
        $url = trim((string) $raw);

        if ($url === '') {
            return ['error' => 'Empty line.'];
        }

        if (strlen($url) > 500 || preg_match('/[\x00-\x20\x7f"\'<>\\\\`{}|^]/', $url) === 1) {
            return ['error' => 'Not an Instagram address.'];
        }

        if (preg_match('#^([A-Za-z][A-Za-z0-9+.\-]*):#', $url, $s) === 1) {
            if (! in_array(strtolower($s[1]), ['https', 'http'], true)) {
                return ['error' => 'Only Instagram web addresses are accepted (https://www.instagram.com/…).'];
            }
        } elseif (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } else {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return ['error' => 'Not an Instagram address.'];
        }

        if (! in_array(strtolower((string) ($parts['host'] ?? '')), self::HOSTS, true)) {
            return ['error' => 'Not an Instagram address — only instagram.com post and reel links work here.'];
        }

        $path = (string) ($parts['path'] ?? '');

        if (preg_match('#^/share/#i', $path) === 1) {
            return ['error' => 'This is an Instagram share link, which only resolves by asking Instagram. Open it, then copy the address from the browser bar (it has /p/ or /reel/ in it).'];
        }

        if (preg_match('#^/(?:[A-Za-z0-9._]{1,30}/)?(p|reel|reels|tv)/([^/]+)/?(?:embed(?:/captioned)?/?)?$#', $path, $m) !== 1) {
            return ['error' => preg_match('#^/[A-Za-z0-9._]{1,30}/?$#', $path) === 1
                ? 'This is a profile address. Open the post or reel and copy its address.'
                : 'Not a post or reel address — it needs /p/, /reel/ or /tv/ in it.'];
        }

        if (preg_match(self::CODE_RE, $m[2]) !== 1) {
            return ['error' => 'The post code in this address is not a valid Instagram code.'];
        }

        return ['kind' => $m[1] === 'p' || $m[1] === 'tv' ? 'p' : 'reel', 'code' => $m[2]];
    }

    /**
     * A paste of one or many addresses (one per line, or separated by spaces or
     * commas) → the accepted items, de-duplicated, and every refused line with
     * its reason. Nothing is stored here.
     *
     * @return array{items: list<array{c: string, k: string, l: string, on: bool}>, refused: list<array{line: string, reason: string}>}
     */
    public static function parseMany(?string $text): array
    {
        $tokens = preg_split('/[\s,]+/', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $items = [];
        $refused = [];
        $seen = [];

        foreach (array_slice($tokens, 0, self::MAX_LINES) as $token) {
            $r = self::parse($token);

            if (isset($r['error'])) {
                $refused[] = ['line' => mb_substr($token, 0, 120), 'reason' => $r['error']];

                continue;
            }

            if (isset($seen[$r['code']])) {
                continue;
            }

            $seen[$r['code']] = true;
            $items[] = ['c' => $r['code'], 'k' => $r['kind'], 'l' => '', 'on' => true];
        }

        if (count($tokens) > self::MAX_LINES) {
            $refused[] = ['line' => '…', 'reason' => 'Only the first '.self::MAX_LINES.' addresses of one paste are read.'];
        }

        return ['items' => $items, 'refused' => $refused];
    }

    /**
     * The iframe src: a constant host, a kind from a two-word set, and a code
     * that matched CODE_RE. Null for anything else — never a string the owner
     * typed.
     */
    public static function src(string $kind, string $code, bool $captioned): ?string
    {
        if (! in_array($kind, ['p', 'reel'], true) || preg_match(self::CODE_RE, $code) !== 1) {
            return null;
        }

        return self::ORIGIN.'/'.$kind.'/'.$code.'/embed/'.($captioned ? 'captioned/' : '');
    }

    /** The post's own address, for "View on Instagram". Same rules as src(). */
    public static function permalink(string $kind, string $code): ?string
    {
        if (! in_array($kind, ['p', 'reel'], true) || preg_match(self::CODE_RE, $code) !== 1) {
            return null;
        }

        return self::ORIGIN.'/'.$kind.'/'.$code.'/';
    }

    /**
     * A stored list, checked again on the way out: a row can also arrive by a
     * restore or by hand in phpMyAdmin. Bad rows are dropped, duplicates
     * dropped, the label reduced to one line of plain text.
     *
     * @return list<array{c: string, k: string, l: string, on: bool}>
     */
    public static function clean(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        $seen = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = is_string($row['c'] ?? null) ? $row['c'] : '';
            $kind = is_string($row['k'] ?? null) ? $row['k'] : '';

            if (self::src($kind, $code, false) === null || isset($seen[$code])) {
                continue;
            }

            $seen[$code] = true;
            $out[] = [
                'c' => $code,
                'k' => $kind,
                'l' => self::label($row['l'] ?? ''),
                'on' => filter_var($row['on'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ];

            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $out;
    }

    public static function label(mixed $raw): string
    {
        $text = is_scalar($raw) ? (string) $raw : '';
        $text = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', strip_tags($text)));

        return mb_substr($text, 0, self::LABEL_MAX);
    }

    /* ───────────────────────────────────────────────────────── storage */

    /** @return array<string, mixed> The section's options, each cast to its own field. */
    public function options(): array
    {
        $all = $this->settings->all();
        $out = [];

        foreach (ModuleSchema::normalise(self::SCHEMA, self::POLICY) as $key => $field) {
            $saved = $all[self::PREFIX.$key] ?? null;
            $cast = $saved === null ? null : ModuleSchema::cast($field, $saved);
            $out[$key] = $cast ?? $field['default'];
        }

        return $out;
    }

    /** @return list<array{c: string, k: string, l: string, on: bool}> */
    public function items(): array
    {
        return self::clean($this->settings->all()[self::ITEMS_KEY] ?? []);
    }

    /**
     * Save the options and the list. Unknown option keys are ignored; a value a
     * field will not take is reported back by its label and not written.
     *
     * @param  array<string, mixed>  $options
     * @return array{rejected: array<string, string>, items: int}
     */
    public function save(array $options, mixed $items): array
    {
        $fields = ModuleSchema::normalise(self::SCHEMA, self::POLICY);
        $rejected = [];

        foreach ($options as $key => $value) {
            if (! is_string($key) || ! isset($fields[$key])) {
                continue;
            }

            $cast = ModuleSchema::cast($fields[$key], $value);

            if ($cast === null) {
                $rejected[$key] = (string) $fields[$key]['label'];

                continue;
            }

            $this->settings->set(self::PREFIX.$key, $cast);
        }

        $count = 0;

        if ($items !== null) {
            $clean = self::clean($items);
            $this->settings->set(self::ITEMS_KEY, $clean);
            $count = count($clean);
        }

        return ['rejected' => $rejected, 'items' => $count];
    }

    /* ───────────────────────────────────────────────────────── the shop */

    /**
     * Everything the section prints, already reduced to literals — or null
     * when there is nothing to draw (switched off, or no post switched on).
     *
     * @param  array<string, string>  $overrides  shortcode attributes, checked here
     * @return array{classes: string, style: string, heading: string, cards: list<array<string, mixed>>, load: string, badge: bool, link: bool}|null
     */
    public function section(array $overrides = [], ?string $locale = null): ?array
    {
        $o = $this->options();

        foreach (['layout', 'style', 'max', 'cols_d', 'cols_m', 'load'] as $key) {
            if (isset($overrides[$key]) && array_key_exists((string) $overrides[$key], self::SCHEMA[$key]['options'])) {
                $o[$key] = (string) $overrides[$key];
            }
        }

        if (isset($overrides['caption'])) {
            $o['caption'] = in_array((string) $overrides['caption'], ['1', 'yes', 'true', 'on'], true);
        }

        if (! $o['on']) {
            return null;
        }

        $captioned = (bool) $o['caption'];
        $cards = [];

        foreach ($this->items() as $item) {
            if (! $item['on']) {
                continue;
            }

            $src = self::src($item['k'], $item['c'], $captioned);
            $href = self::permalink($item['k'], $item['c']);

            if ($src === null || $href === null) {
                continue;
            }

            $cards[] = ['kind' => $item['k'], 'src' => $src, 'href' => $href, 'label' => $item['l']];

            if (count($cards) >= (int) $o['max']) {
                break;
            }
        }

        if ($cards === []) {
            return null;
        }

        $heading = (string) $o['heading'];

        if (($locale ?? app()->getLocale()) === 'ar' && trim((string) $o['heading_ar']) !== '') {
            $heading = (string) $o['heading_ar'];
        }

        if (array_key_exists('title', $overrides)) {
            $heading = (string) $overrides['title'];
        }

        return [
            'classes' => self::classes($o),
            'style' => '--kie-fit:'.self::pick($o, 'fit').'px',
            'heading' => trim($heading),
            'cards' => $cards,
            'load' => self::pick($o, 'load'),
            'badge' => (bool) $o['badge'],
            'link' => (bool) $o['link'],
        ];
    }

    /** The section's classes, from option keys only. */
    public static function classes(array $o): string
    {
        return 'kie kie-s-'.self::pick($o, 'style')
            .' kie-l-'.self::pick($o, 'layout')
            .' kie-cd-'.self::pick($o, 'cols_d')
            .' kie-cm-'.self::pick($o, 'cols_m')
            .(! empty($o['caption']) ? ' kie-cap' : '');
    }

    /** A select's value only if it is one of its own keys, else its default. */
    private static function pick(array $o, string $key): string
    {
        $v = (string) ($o[$key] ?? '');

        return array_key_exists($v, self::SCHEMA[$key]['options']) ? $v : (string) self::SCHEMA[$key]['default'];
    }

    /**
     * ── THE HEIGHT EACH FRAME RESERVES ─────────────────────────────────────
     *
     * Instagram's embed page is a header (avatar and name), the media at the
     * frame's width, and a footer (actions, likes, "View more on Instagram").
     * So a frame is `width × media ratio + a constant`, which is a calc() on the
     * card's own width through container query units — nothing is measured,
     * and the box is the same size before and after the post arrives, so CLS
     * is 0. Posts reserve 4:5 (Instagram's tallest photo), reels 9:16.
     * The constant is an ESTIMATE: instagram.com was not reachable from the
     * lane that built this, so the owner gets `fit` (±px) to tune it, and the
     * frame scrolls inside rather than clipping if a caption runs long.
     */
    public const HEIGHT_NOTE = 'post: 100cqw × 1.25 + 230px; reel: 100cqw × 1.7778 + 230px; captioned +120px; ± Frame height.';

    /**
     * Rules a host page may also style are written `.kie ul.kie-list` (0,2,1):
     * a content page styles `.kbb-home .policy-body ul|li|a|h2` at the same
     * weight, and this <style> comes later in the document, so it wins without
     * an !important. Measured on /delivery/ with the shortcode: without it the
     * cards wore pink bullets and an uppercase 13px heading.
     *
     * The section's stylesheet, printed once per page by the first section
     * (no file, no request, nothing render-blocking in <head>). The admin
     * preview draws with the same string, so the two cannot drift.
     */
    public static function css(): string
    {
        return <<<'CSS'
.kie{--kie-gap:16px;--kie-r:1.25;--kie-c:230px;margin:0 auto;max-width:1680px}
.kie h2.kie-h{margin:0 0 16px;padding:0;border:0;text-align:center;font-size:clamp(22px,2.6vw,30px);font-weight:700;letter-spacing:normal;text-transform:none;color:inherit;line-height:1.2}
.kie ul.kie-list{list-style:none;margin:0;padding:0;display:grid;gap:var(--kie-gap);align-items:start;grid-template-columns:repeat(var(--kie-n,1),minmax(0,1fr))}
.kie-cm-1{--kie-n:1}.kie-cm-2{--kie-n:2;--kie-gap:10px}
@media (min-width:901px){.kie-cd-2{--kie-n:2}.kie-cd-3{--kie-n:3}.kie-cd-4{--kie-n:4}.kie{--kie-gap:20px}}
.kie-l-slider ul.kie-list{grid-template-columns:none;grid-auto-flow:column;grid-auto-columns:calc((100% - (var(--kie-n) - 1) * var(--kie-gap)) / var(--kie-n) * var(--kie-peek,1));overflow-x:auto;overscroll-behavior-x:contain;scroll-snap-type:x mandatory;padding-bottom:8px;scrollbar-width:thin}
@media (max-width:900px){.kie-l-slider{--kie-peek:.86}}
.kie-l-slider .kie-card{scroll-snap-align:start}
.kie li.kie-card{min-width:0;margin:0;padding:0}
.kie-fr{min-width:0;color:#2A2228}
.kie-in{container-type:inline-size;overflow:hidden;background:#fff}
.kie-k-reel{--kie-r:1.7778}
.kie-cap{--kie-c:350px}
.kie-box{position:relative;display:block;margin:0;aspect-ratio:3/5;background:#fafafa}
@supports (height:1cqw){.kie-box{aspect-ratio:auto;height:calc(100cqw * var(--kie-r) + var(--kie-c) + var(--kie-fit,0px))}}
.kie-fac{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:16px;text-align:center;color:#8e8e8e;font-size:13px;line-height:1.4;list-style:none}
.kie-fac::-webkit-details-marker{display:none}
.kie-fac svg{width:40px;height:40px;color:#c13584}
.kie-tap .kie-fac{cursor:pointer}
.kie-tap .kie-go{display:inline-block;margin-top:4px;padding:8px 14px;border-radius:999px;background:#2A2228;color:#fff;font-weight:600;font-size:12.5px}
.kie-tap[open] > .kie-fac{display:none}
.kie-if{position:absolute;inset:0;width:100%;height:100%;border:0;display:block;background:transparent}
.kie-top{display:flex;align-items:center;gap:8px;min-height:40px;padding:8px 12px;font-size:13px;font-weight:600;line-height:1.3}
.kie-top svg{flex:none;color:#c13584}
.kie-top span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.kie a.kie-more{display:flex;align-items:center;justify-content:center;gap:6px;min-height:44px;padding:0 12px;font-size:13px;font-weight:600;color:inherit;text-decoration:none}
.kie a.kie-more:hover{text-decoration:underline}
.kie-s-clean .kie-fr{border:1px solid #ececec;border-radius:12px;overflow:hidden;background:#fff}
.kie-s-soft .kie-fr{border-radius:18px;background:#fff;padding:8px;box-shadow:0 8px 28px rgba(42,34,40,.10)}
.kie-s-soft .kie-in{border-radius:12px}
.kie-s-polaroid .kie-fr{border-radius:3px;background:#fff;padding:12px 12px 4px;border:1px solid #f0e6e9;box-shadow:0 2px 10px rgba(42,34,40,.08)}
.kie-s-ring .kie-fr{border-radius:20px;padding:3px;background:linear-gradient(45deg,#feda75,#fa7e1e,#d62976,#962fbf,#4f5bd5)}
.kie-s-ring .kie-in{border-radius:17px}
.kie-s-ring .kie-more,.kie-s-ring .kie-top{background:#fff}
CSS;
    }
}
