<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SafeUrl;

/**
 * Pages → Page header: the title block at the top of every CUSTOM page — the
 * four curated listings (/super-sale/, /new-in/, /best-sellers/,
 * /everything-under-54-aed/) and the content pages. (Lane PH)
 *
 * The owner, of /super-sale/: "i want full control of super sale page header,
 * to hide title, and i needed an image too, also control of image height etc.
 * and hide the all products button, count etc. make this global option for
 * all pages. desktop and mobile seeperate. and i must be able to edit the
 * inner page header from the front-end" — "also to change the position of the
 * elements on the pages title header."
 *
 * ── ONE SETTING: A GLOBAL LOOK AND PER-PAGE OVERRIDES ───────────────────────
 *
 * `page_header` = ['global' => bag, 'pages' => [page key => bag]]. A page uses
 * its own bag when it has one and the global bag otherwise. Page keys are
 * PageBanners::pageKeys()'s, so "a custom page" means the same thing on both
 * screens. A bag holds a desktop half and a phone half (`d`, `m`) and the
 * header picture, which is shared.
 *
 * ── DEFAULT IS THE PAGE AS IT WAS, BYTE FOR BYTE ────────────────────────────
 *
 * forPage() answers null when the bag a page resolves to draws exactly what
 * the page drew before this existed — every element shown, the old order, no
 * picture — and the views then print their ORIGINAL markup, untouched. Only a
 * page whose bag differs gets the configurable header. So a page nobody
 * changed is byte-identical, and the comparison is made on what the page KIND
 * actually draws: a content page has no count, intro, button or dot, so
 * hiding the count globally does not turn every content page into a
 * configured one.
 *
 * ── WHAT SHIPS ──────────────────────────────────────────────────────────────
 *
 * The global bag is the default. /super-sale/ ships its own bag with the dot,
 * the count and the "All products" button off on both devices — what he asked
 * for, applied (CLAUDE.md rule 1, 30 September). The title stays shown: "to
 * hide title" asks for the control, which is here, not for a page with no
 * visible heading.
 *
 * ── HIDDEN IS NOT REMOVED ───────────────────────────────────────────────────
 *
 * Every element the page has is still printed; hiding is a class per device.
 * The H1 is never display:none — a hidden title is VISUALLY hidden (clipped
 * to a pixel), so search engines and screen readers still read the page's
 * heading. And because every part is in the page, the front-end editor can
 * show and hide them live without asking the server for anything.
 *
 * ── RULE 5 ──────────────────────────────────────────────────────────────────
 *
 * Every select stores one of its own options or is refused; numbers are
 * clamped; the order must be a permutation of ELEMENTS; picture addresses go
 * through SafeUrl::src() on save AND on read. The style attribute is built
 * here from constants and integers only and is printed escaped; the only
 * unescaped output is the CSS constant.
 */
final class PageHeaders
{
    public const KEY = 'page_header';

    /** Phone at and below this width, desktop above — the storefront's breakpoint. */
    public const BREAKPOINT = PageBanners::BREAKPOINT;

    /** The elements whose position can be changed, and their grid-area letters. */
    public const ELEMENTS = [
        'crumb' => 'c',
        'image' => 'i',
        'title' => 't',
        'intro' => 'p',
        'button' => 'b',
    ];

    /** Show / hide switches: [label, which page kinds draw it]. */
    public const SHOWS = [
        'crumb' => ['Breadcrumb', ['collection', 'page']],
        'title' => ['Title', ['collection', 'page']],
        'count' => ['Product count', ['collection']],
        'intro' => ['Intro line', ['collection']],
        'button' => ['“All products” button', ['collection']],
        'dot' => ['Dot before the title', ['collection']],
        'image' => ['Header picture', ['collection', 'page']],
    ];

    /** Selects: [options, label]. The first option is the default. */
    public const SELECTS = [
        'align' => [['start' => 'Left', 'center' => 'Centre'], 'Alignment'],
        'button_at' => [['side' => 'Beside the title', 'row' => 'On its own row'], '“All products” button'],
        'fit' => [['cover' => 'Fill (crop to the height)', 'contain' => 'Whole picture'], 'Picture fit'],
    ];

    /** Number controls: [min, max, desktop default, phone default, label]. */
    public const NUMBERS = [
        'img_h' => [40, 600, 260, 160, 'Picture height (px)'],
        'radius' => [0, 40, 14, 10, 'Picture corners (px)'],
        'gap' => [0, 60, 12, 8, 'Space between rows (px)'],
        'space' => [0, 80, 22, 12, 'Space below the header (px)'],
    ];

    public const MAX_PAGES = 80;

    /**
     * Every byte of CSS the configured header prints. A constant (rule 5),
     * printed once on a page that draws the configured header and on no other.
     *
     * A grid whose areas come from two custom properties, one per device —
     * that is the whole of "position": the order of the rows and whether the
     * button sits beside the title is a string PageHeaders::compile() builds
     * from constant letters. No script measures anything; the picture's box is
     * the height the owner set, so it is reserved before the bytes arrive.
     */
    public const CSS = '.kbb-home div.kbb-ph{display:grid;grid-template-columns:minmax(0,1fr) auto;column-gap:18px;align-items:center;justify-items:start;justify-content:normal;flex-wrap:nowrap;position:relative}'
        .'.kbb-home .kbb-ph>.kbb-ph-c{grid-area:c;margin:0}'
        .'.kbb-ph>.kbb-ph-i{grid-area:i;justify-self:stretch;display:block;line-height:0;min-width:0}'
        .'.kbb-ph>.kbb-ph-i img{display:block;width:100%;max-width:100%}'
        .'.kbb-home .kbb-ph>.kbb-ph-t{grid-area:t;margin:0;min-width:0}'
        .'.kbb-home .kbb-ph>.kbb-ph-p{grid-area:p;margin:0;display:block}'
        .'.kbb-ph>.kbb-ph-b{grid-area:b}'
        .'.kbb-ph .kbb-ph-v{position:absolute!important;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap;border:0}'
        .'@media (max-width:900px){.kbb-home div.kbb-ph{grid-template-areas:var(--ph-am);row-gap:var(--ph-gm);margin:0 0 var(--ph-sm)}'
        .'.kbb-ph>.kbb-ph-i img{height:var(--ph-hm);object-fit:var(--ph-fm);border-radius:var(--ph-rm)}'
        .'.kbb-ph-sm>.kbb-ph-b{justify-self:end}'
        .'.kbb-home div.kbb-ph-cm{text-align:center;justify-items:center}.kbb-home .kbb-ph-cm>.kbb-ph-t{justify-content:center}'
        .'.kbb-home .kbb-ph-nom>.kbb-ph-t::before{content:none}'
        .'.kbb-ph .kbb-ph-vm{position:absolute!important;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap;border:0}'
        .'.kbb-home .kbb-ph .kbb-ph-hm{display:none}}'
        .'@media (min-width:901px){.kbb-home div.kbb-ph{grid-template-areas:var(--ph-ad);row-gap:var(--ph-gd);margin:0 0 var(--ph-sd)}'
        .'.kbb-ph>.kbb-ph-i img{height:var(--ph-hd);object-fit:var(--ph-fd);border-radius:var(--ph-rd)}'
        .'.kbb-ph-sd>.kbb-ph-b{justify-self:end}'
        .'.kbb-home div.kbb-ph-cd{text-align:center;justify-items:center}.kbb-home .kbb-ph-cd>.kbb-ph-t{justify-content:center}'
        .'.kbb-home .kbb-ph-nod>.kbb-ph-t::before{content:none}'
        .'.kbb-ph .kbb-ph-vd{position:absolute!important;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap;border:0}'
        .'.kbb-home .kbb-ph .kbb-ph-hd{display:none}}';

    private ?array $memo = null;

    public function __construct(private SettingsService $settings) {}

    /** One device's half at the page as it was. */
    public static function device(string $dev): array
    {
        $out = [];
        foreach (self::SHOWS as $k => $_) {
            // The shop hides the intro line on a phone today (.sh p under
            // 900px in kbb.css); the default is what the page already does.
            $out[$k] = ! ($k === 'intro' && $dev === 'm');
        }
        foreach (self::SELECTS as $k => [$options]) {
            $out[$k] = (string) array_key_first($options);
        }
        $out['order'] = array_keys(self::ELEMENTS);
        foreach (self::NUMBERS as $k => [, , $d, $m]) {
            $out[$k] = $dev === 'd' ? $d : $m;
        }

        return $out;
    }

    /** A whole bag at the page as it was. */
    public static function blank(): array
    {
        return ['d' => self::device('d'), 'm' => self::device('m'), 'img' => '', 'img_m' => '', 'alt' => ''];
    }

    /** What ships: the global look unchanged, and /super-sale/ as the owner asked. */
    public static function defaults(): array
    {
        $sale = self::blank();
        foreach (['d', 'm'] as $dev) {
            $sale[$dev]['dot'] = false;
            $sale[$dev]['count'] = false;
            $sale[$dev]['button'] = false;
        }

        return ['global' => self::blank(), 'pages' => ['collection:super-sale' => $sale]];
    }

    /** The stored setting, or the shipped default. */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $raw = $this->settings->get(self::KEY, null);

        if (! is_array($raw)) {
            return $this->memo = self::defaults();
        }

        [$clean] = self::sanitize($raw, false);

        return $this->memo = $clean;
    }

    public function forget(): void
    {
        $this->memo = null;
    }

    /** The bag a page resolves to: its own, or the global one. */
    public function bagFor(string $pageKey): array
    {
        $all = $this->all();

        return $all['pages'][$pageKey] ?? $all['global'];
    }

    /**
     * What a storefront view draws, or null for "print the original markup".
     *
     * @return array{key:string, kind:string, bag:array, img:?array}|null
     */
    public function forPage(string $pageKey, string $kind): ?array
    {
        $bag = $this->bagFor($pageKey);
        $img = self::picture($bag);

        if ($img === null && self::project($bag, $kind) === self::project(self::blank(), $kind)) {
            return null;
        }

        return ['key' => $pageKey, 'kind' => $kind, 'bag' => $bag, 'img' => $img];
    }

    /** The picture to draw, or null for none. */
    public static function picture(array $bag): ?array
    {
        $d = SafeUrl::src((string) ($bag['img'] ?? ''));
        $m = SafeUrl::src((string) ($bag['img_m'] ?? ''));

        if ($d === '' && $m === '') {
            return null;
        }
        if (! ($bag['d']['image'] ?? true) && ! ($bag['m']['image'] ?? true)) {
            return null;
        }

        $out = ['d' => $d !== '' ? $d : $m, 'm' => $m !== '' ? $m : $d, 'alt' => (string) ($bag['alt'] ?? '')];
        $out['two'] = $out['d'] !== $out['m'];

        return $out;
    }

    /**
     * The part of a bag a page of this kind actually draws.
     *
     * A content page has no count, intro, button or dot, and with no picture
     * the picture's settings draw nothing either, so none of them may decide
     * whether the page leaves its original markup.
     */
    public static function project(array $bag, string $kind): array
    {
        $out = [];
        foreach (['d', 'm'] as $dev) {
            $h = $bag[$dev];
            foreach (self::SHOWS as $k => [, $kinds]) {
                if (! in_array($kind, $kinds, true)) {
                    unset($h[$k]);
                }
            }
            if ($kind !== 'collection') {
                unset($h['button_at']);
            }
            unset($h['image'], $h['img_h'], $h['radius'], $h['fit']);
            $h['order'] = array_values(array_filter($h['order'], static fn (string $e): bool => self::draws($e, $kind)));
            ksort($h);
            $out[$dev] = $h;
        }

        return $out;
    }

    /** Does a page of this kind have this element at all? */
    public static function draws(string $element, string $kind): bool
    {
        return $kind === 'collection' || in_array($element, ['crumb', 'image', 'title'], true);
    }

    /**
     * The classes and the style attribute for a bag on a page.
     *
     * $has says which optional parts this page has: 'intro' (a non-empty
     * line), 'image' (a picture to draw), and 'crumb' => ['d' => bool,
     * 'm' => bool] — whether Appearance → Header → Breadcrumbs shows the trail
     * on that device at all (siteCrumb()). A part the page cannot show on a
     * device gets no grid row there and the hidden class: a grid item whose
     * area is not in the template would be placed on an implicit row, and a
     * row-gap around an empty row is 24px of nothing — measured, the first
     * build drew /super-sale/'s header 84px tall where the page drew 53.
     * resources/js/kbb/admin/
     * page-header-compile.js is this function in JavaScript, for the live
     * editor; PageHeaderTest runs both on the same bags and compares.
     *
     * @param  array{intro?:bool, image?:bool, crumb?:array{d?:bool, m?:bool}}  $has
     * @return array{wrap:string, style:string, cls:array<string,string>}
     */
    public static function compile(array $bag, string $kind, array $has): array
    {
        $isList = $kind === 'collection';
        $wrap = $isList ? 'sh kbb-ph' : 'kbb-ph kbb-ph-pg';
        $cls = [
            'crumb' => 'crumb kbb-ph-c',
            'image' => 'kbb-ph-i',
            'title' => 'kbb-ph-t',
            'count' => 'cnt',
            'intro' => 'kbb-ph-p',
            'button' => 'lnk kbb-ph-b',
        ];
        $style = [];

        foreach (['d', 'm'] as $dev) {
            $h = $bag[$dev];
            $present = [
                'crumb' => $h['crumb'] && ($has['crumb'][$dev] ?? true),
                'image' => ! empty($has['image']) && $h['image'],
                'title' => (bool) $h['title'],
                'intro' => $isList && ! empty($has['intro']) && $h['intro'],
                'button' => $isList && $h['button'],
            ];
            $side = $isList && $h['button_at'] === 'side' && $present['button'];

            $rows = [];
            foreach ($h['order'] as $el) {
                $row = match ($el) {
                    'title' => $present['title'] ? ($side ? 't b' : 't t') : ($side ? '. b' : null),
                    'button' => $present['button'] && ! $side ? 'b b' : null,
                    default => $present[$el] ? self::ELEMENTS[$el].' '.self::ELEMENTS[$el] : null,
                };
                if ($row !== null) {
                    $rows[] = '"'.$row.'"';
                }
            }

            $style[] = '--ph-a'.$dev.':'.($rows === [] ? 'none' : implode(' ', $rows));
            $style[] = '--ph-h'.$dev.':'.$h['img_h'].'px';
            $style[] = '--ph-f'.$dev.':'.$h['fit'];
            $style[] = '--ph-r'.$dev.':'.$h['radius'].'px';
            $style[] = '--ph-g'.$dev.':'.$h['gap'].'px';
            $style[] = '--ph-s'.$dev.':'.$h['space'].'px';

            if ($h['align'] === 'center') {
                $wrap .= ' kbb-ph-c'.$dev;
            }
            if ($side) {
                $wrap .= ' kbb-ph-s'.$dev;
            }
            if ($isList && ! $h['dot']) {
                $wrap .= ' kbb-ph-no'.$dev;
            }
            if (! $h['title']) {
                $cls['title'] .= ' kbb-ph-v'.$dev;
            }
            foreach (['crumb', 'image', 'intro', 'button'] as $el) {
                if (! $present[$el]) {
                    $cls[$el] .= ' kbb-ph-h'.$dev;
                }
            }
            if (! $h['count']) {
                $cls['count'] .= ' kbb-ph-h'.$dev;
            }
        }

        // Hidden on both: one class rather than two, so the H1 is clipped at
        // every width without depending on the two media queries meeting.
        if (! $bag['d']['title'] && ! $bag['m']['title']) {
            $cls['title'] = 'kbb-ph-t kbb-ph-v';
        }

        return ['wrap' => $wrap, 'style' => implode(';', $style), 'cls' => $cls];
    }

    /**
     * Does Appearance → Header → Breadcrumbs show the trail, per device? Read
     * from the settings the layout has already loaded to print that switch's
     * own <style>, so it costs no query.
     *
     * @return array{d: bool, m: bool}
     */
    public static function siteCrumb(): array
    {
        $h = app(HeaderSettings::class);

        return ['d' => (bool) $h->get('bc_desktop'), 'm' => (bool) $h->get('bc_mobile')];
    }

    /**
     * Clean an incoming setting. Returns [clean, rejected].
     *
     * With $strict anything wrong is REPORTED and the caller writes nothing;
     * without it (the read path) anything wrong becomes its default, so a
     * damaged row can never take a page down.
     *
     * @return array{0: array{global: array, pages: array<string, array>}, 1: array<string, string>}
     */
    public static function sanitize(mixed $in, bool $strict = true): array
    {
        $rejected = [];
        $in = is_array($in) ? $in : [];

        $global = self::bag($in['global'] ?? null, 'global', $rejected);

        $pages = [];
        $known = $strict ? PageBanners::pageKeys() : null;
        $list = is_array($in['pages'] ?? null) ? $in['pages'] : [];

        if (count($list) > self::MAX_PAGES) {
            $rejected['pages'] = 'Too many pages';
            $list = array_slice($list, 0, self::MAX_PAGES, true);
        }

        foreach ($list as $key => $raw) {
            $key = (string) $key;
            $ok = $known !== null ? isset($known[$key]) : preg_match('/^(collection|page):[a-z0-9_-]{1,60}$/', $key) === 1;
            if (! $ok) {
                if ($strict) {
                    $rejected['pages.'.$key] = 'Unknown page';
                }
                continue;
            }
            if ($raw === null) {
                continue;   // "follow the global look"
            }
            $pages[$key] = self::bag($raw, 'pages.'.$key, $rejected);
        }

        return [['global' => $global, 'pages' => $pages], $strict ? $rejected : []];
    }

    /** One bag, cleaned. Anything refused is written into $rejected and replaced by its default. */
    public static function bag(mixed $raw, string $at, array &$rejected): array
    {
        $raw = is_array($raw) ? $raw : [];
        $out = self::blank();

        foreach (['d', 'm'] as $dev) {
            $in = is_array($raw[$dev] ?? null) ? $raw[$dev] : [];
            $h = $out[$dev];

            foreach (self::SHOWS as $k => [$label]) {
                if (array_key_exists($k, $in)) {
                    $v = filter_var($in[$k], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                    if ($v === null) {
                        $rejected["$at.$dev.$k"] = $label;
                    } else {
                        $h[$k] = $v;
                    }
                }
            }

            foreach (self::SELECTS as $k => [$options, $label]) {
                if (array_key_exists($k, $in)) {
                    $v = is_string($in[$k]) ? $in[$k] : '';
                    if (isset($options[$v])) {
                        $h[$k] = $v;
                    } else {
                        $rejected["$at.$dev.$k"] = $label;
                    }
                }
            }

            if (array_key_exists('order', $in)) {
                $order = is_array($in['order']) ? array_values(array_map(static fn ($e): string => is_string($e) ? $e : '', $in['order'])) : [];
                $want = array_keys(self::ELEMENTS);
                $sorted = $order;
                sort($sorted);
                $sortedWant = $want;
                sort($sortedWant);
                if ($sorted === $sortedWant) {
                    $h['order'] = $order;
                } else {
                    $rejected["$at.$dev.order"] = 'Order of the elements';
                }
            }

            foreach (self::NUMBERS as $k => [$min, $max, , , $label]) {
                if (array_key_exists($k, $in)) {
                    $v = $in[$k];
                    if (is_numeric($v) && (float) $v >= $min && (float) $v <= $max) {
                        $h[$k] = (int) round((float) $v);
                    } else {
                        $rejected["$at.$dev.$k"] = $label;
                    }
                }
            }

            $out[$dev] = $h;
        }

        foreach (['img' => 'Desktop picture', 'img_m' => 'Phone picture'] as $k => $label) {
            $v = trim(is_string($raw[$k] ?? null) ? $raw[$k] : '');
            if ($v !== '' && (strlen($v) > 500 || SafeUrl::src($v) === '' || preg_match('/[\x00-\x20"\'<>`\\\\]/', $v) === 1)) {
                $rejected["$at.$k"] = $label;
                $v = '';
            }
            $out[$k] = $v;
        }

        $alt = is_scalar($raw['alt'] ?? null) ? strip_tags((string) $raw['alt']) : '';
        $alt = trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $alt));
        $out['alt'] = mb_substr($alt, 0, 160);

        return $out;
    }

    /**
     * Save the whole setting. All or nothing.
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

    /**
     * The front-end editor's save: one page's own look, the global look, or
     * "this page follows the global look again".
     *
     * `global` also removes this page's own bag — the owner pressed "apply to
     * every custom page" while looking at this one, and a page that kept its
     * override would not show what he just applied.
     *
     * @return array<string,string> what was refused; empty means written
     */
    public function apply(string $pageKey, string $scope, mixed $bag): array
    {
        if (! isset(PageBanners::pageKeys()[$pageKey])) {
            return ['key' => 'Unknown page'];
        }
        if (! in_array($scope, ['page', 'global', 'inherit'], true)) {
            return ['scope' => 'Where to apply'];
        }

        $all = $this->all();
        $rejected = [];
        $clean = $scope === 'inherit' ? null : self::bag($bag, 'bag', $rejected);

        if ($rejected !== []) {
            return $rejected;
        }

        if ($scope === 'page') {
            $all['pages'][$pageKey] = $clean;
        } else {
            unset($all['pages'][$pageKey]);
            if ($scope === 'global') {
                $all['global'] = $clean;
            }
        }

        return $this->save($all);
    }
}
