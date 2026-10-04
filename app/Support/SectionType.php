<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\HomepageSections;

/**
 * Fonts & size, per homepage section.                             (Lane FS)
 *
 * The owner, on the Edit content popup's tab bar: "I want here another tab on
 * every edit popup of content sections. Fonts & Size. to control fonts and
 * size etc, along with pading spacings."
 *
 * ── ONE SETTING, SPARSE ─────────────────────────────────────────────────────
 *
 * `homepage_section_type` holds section key => only the values somebody MOVED.
 * A field that is not in the map is "as designed": no class on the section, no
 * rule in the page. So a shop that never opens the tab renders the homepage
 * byte for byte as before, and moving one size prints exactly one rule.
 *
 * It is its own row and its own endpoint (POST /admin-api/homepage-hub/type)
 * rather than more columns on `homepage_sections`, because that row has FOUR
 * writers — Appearance → Homepage, the hub's Laptop/Phone pills, the live
 * editor and the layout presets — each posting the whole list from what it
 * knows. A key one of them did not know about would be wiped by the next
 * pill tap. One writer per value, which is the hub's own rule.
 *
 * ── THE SPACING A SECTION ALREADY HAS IS NOT DUPLICATED ─────────────────────
 *
 * Seven sections were built with their own space-above / space-below / space-
 * under-the-heading controls (home_bs_pt_d and friends, Spotted's pad_top_d).
 * Those keep their keys and their writer; the hub MOVES them into this tab.
 * OWNED says which, and fields() simply does not offer a second one.
 *
 * ── RULE 5 ──────────────────────────────────────────────────────────────────
 *
 * Every selector, property and unit printed is a literal below. A stored value
 * reaches the CSS only as an int clamped to its own range, a key of CASES, or
 * a FontLibrary stack — clean() refuses anything else, and read() drops it.
 * The section key in the class name comes from the registry, never the post.
 */
final class SectionType
{
    public const SETTING = 'homepage_section_type';

    /** The shop's own breakpoint: laptop above it, phone at or below. */
    private const LAPTOP = '@media (min-width:901px){';

    private const PHONE = '@media (max-width:900px){';

    public const CASES = [
        '' => 'As designed',
        'none' => 'As typed',
        'upper' => 'UPPERCASE',
        'lower' => 'lowercase',
        'capitalize' => 'Capitalise Each Word',
    ];

    private const CASE_CSS = ['none' => 'none', 'upper' => 'uppercase', 'lower' => 'lowercase', 'capitalize' => 'capitalize'];

    /**
     * Every px control: key => [label, min, max, what it sizes].
     * The ranges are wide enough for a display heading and narrow enough that
     * a slip of the slider cannot put a 300px word on a phone.
     */
    public const PX = [
        'h_d' => ['Heading size · laptop', 14, 72, 'h'],
        'h_m' => ['Heading size · phone', 12, 48, 'h'],
        'eb_d' => ['Eyebrow size · laptop', 9, 24, 'eb'],
        'eb_m' => ['Eyebrow size · phone', 9, 20, 'eb'],
        'sub_d' => ['Subheading size · laptop', 10, 32, 'sub'],
        'sub_m' => ['Subheading size · phone', 10, 26, 'sub'],
        'body_d' => ['Body & card text size · laptop', 10, 28, 'body'],
        'body_m' => ['Body & card text size · phone', 10, 24, 'body'],
        'pt_d' => ['Space above the section · laptop', 0, 160, 'pt'],
        'pt_m' => ['Space above the section · phone', 0, 120, 'pt'],
        'pb_d' => ['Space below the section · laptop', 0, 160, 'pb'],
        'pb_m' => ['Space below the section · phone', 0, 120, 'pb'],
        'gap_d' => ['Space under the heading · laptop', 0, 80, 'gap'],
        'gap_m' => ['Space under the heading · phone', 0, 64, 'gap'],
    ];

    /** No popup of its own: the hero's slides are edited on the Hero slider tab. */
    private const EXCLUDED = ['hero'];

    /** Drawn with no section heading — the font then sets the section's text. */
    private const NO_HEADING = ['topstrip', 'countries', 'cards_banner', 'delivery', 'ticker', 'categories', 'trust'];

    /** No words at all: pictures only. Spacing is all it is offered. */
    private const NO_TEXT = ['cards_banner'];

    /**
     * Sections with body or card text this tab can size. The two strips are
     * absent on purpose: the top strip has its own "Strip text size" (moved
     * into this tab by the hub) and the flag bar's sizes live on its own tab.
     */
    private const BODY = ['delivery', 'ticker', 'categories', 'bundles', 'bestselling', 'recommended', 'routine', 'quiz',
        'brands', 'trending', 'bestsellers', 'flash', 'blog', 'under54', 'feature', 'about', 'reviews', 'trust',
        'videos', 'instagram'];

    /**
     * The small line ABOVE a heading (the hs eyebrow, Spotted-era badges, the
     * quiz's and newsletter's kickers) and the line UNDER it are two controls,
     * because they are two sizes today — 12px over 16px on Best Sellers — and
     * one slider for both would move the eyebrow to the paragraph's size the
     * first time anybody touched it.
     */
    private const EYEBROW_IN = ['recommended', 'routine', 'quiz', 'bestselling', 'brands', 'trending', 'bestsellers',
        'flash', 'blog', 'under54', 'about', 'newsletter'];

    private const NO_SUB = ['about', 'routine', 'quiz', 'feature', 'instagram'];

    /** Headed, but with no heading ROW for a gap to open under. */
    private const NO_GAP = ['quiz', 'newsletter', 'instagram', 'feature'];

    /**
     * Spacing a section already owns, under its own keys — the hub moves those
     * fields into this tab and this class offers no second copy.
     */
    public const OWNED = [
        'bundles' => ['pt', 'gap'],
        'bestselling' => ['pt', 'pb', 'gap'],
        'brands' => ['pt', 'pb', 'gap'],
        'trending' => ['pt', 'pb', 'gap'],
        'blog' => ['pt', 'pb', 'gap'],
        'under54' => ['pt', 'pb', 'gap'],
        'feature' => ['pt', 'pb'],
        'about' => ['pt', 'pb'],
        'spotted' => ['pt', 'pb', 'gap'],
    ];

    /** What each part of a section is, as a selector under the section's own class. */
    private const HEADING = 'h2';

    private const EYEBROW = ':is(.hs-eyebrow,h2>.cnt,.kick,.q-k)';

    private const SUB = ':is(.hs-ht>p,.sh p,.gs-head p,.spt-head p,.lede,.ugcr-sub)';

    private const BODY_SEL = ':is(.kbb-card-nm,.hs-pex,.hs-post h3,.hs-abtext p,.hs-fp>p,.q-left>p,.rb b,.rb span,.i b,.i span,.ct b,.ct .n,.hs-blb,.rtext,.igp-cap,.ugcr-cap)';

    /** The two strips drawn inside the hero carry their words as direct children. */
    private const BODY_OF = ['delivery' => ':is(b,span)', 'ticker' => 'span'];

    private const HEAD_ROW = ':is(.sh,.hs-head,.gs-head,.ugcr-head)';

    /**
     * What the page draws today, at the shipped settings — the number a slider
     * STARTS at, nothing more. Nothing is printed until a value moves, so if a
     * number here were ever off, the page would still be untouched; the
     * slider would merely open one notch away from the truth. Measured in
     * Chromium at 1280px and 390px (tools/fs-measure.cjs) rather than read
     * from the stylesheet, because half of these are clamp()s and inline
     * styles. Headings and subheadings that follow Homepage content →
     * Section headings are read from that setting instead (seed()).
     *
     * @var array<string, array<string, int>>
     */
    public const TODAY = [
        'topstrip' => ['pt_d' => 3, 'pt_m' => 3, 'pb_d' => 3, 'pb_m' => 3],
        'countries' => ['pt_d' => 0, 'pt_m' => 0, 'pb_d' => 0, 'pb_m' => 0],
        'delivery' => ['body_d' => 13, 'body_m' => 12, 'pt_d' => 10, 'pt_m' => 9, 'pb_d' => 10, 'pb_m' => 9],
        'ticker' => ['body_d' => 14, 'body_m' => 14, 'pt_d' => 11, 'pt_m' => 9, 'pb_d' => 11, 'pb_m' => 9],
        'categories' => ['body_d' => 13, 'body_m' => 11, 'pt_d' => 8, 'pt_m' => 8, 'pb_d' => 14, 'pb_m' => 6],
        'bundles' => ['h_d' => 34, 'h_m' => 24, 'sub_d' => 16, 'sub_m' => 16, 'body_d' => 14, 'body_m' => 14, 'pt_d' => 8, 'pt_m' => 8, 'pb_d' => 14, 'pb_m' => 6, 'gap_d' => 24, 'gap_m' => 12],
        'bestselling' => ['h_d' => 34, 'h_m' => 24, 'eb_d' => 12, 'eb_m' => 11, 'sub_d' => 16, 'sub_m' => 16, 'body_d' => 14, 'body_m' => 14, 'pt_d' => 0, 'pt_m' => 0, 'pb_d' => 0, 'pb_m' => 0, 'gap_d' => 28, 'gap_m' => 16],
        'recommended' => ['h_d' => 30, 'h_m' => 18, 'eb_d' => 12, 'eb_m' => 11, 'sub_d' => 14, 'sub_m' => 14, 'body_d' => 14, 'body_m' => 14, 'pt_d' => 0, 'pt_m' => 0, 'pb_d' => 14, 'pb_m' => 6, 'gap_d' => 22, 'gap_m' => 12],
        'routine' => ['h_d' => 30, 'h_m' => 18, 'eb_d' => 12, 'eb_m' => 11, 'body_d' => 14, 'body_m' => 14, 'pt_d' => 0, 'pt_m' => 0, 'pb_d' => 14, 'pb_m' => 6, 'gap_d' => 22, 'gap_m' => 12],
        'quiz' => ['h_d' => 38, 'h_m' => 22, 'eb_d' => 11, 'eb_m' => 11, 'body_d' => 14, 'body_m' => 14, 'pt_d' => 0, 'pt_m' => 0, 'pb_d' => 14, 'pb_m' => 6],
        'brands' => ['h_d' => 34, 'h_m' => 24, 'eb_d' => 12, 'eb_m' => 11, 'sub_d' => 16, 'sub_m' => 16, 'body_d' => 14, 'body_m' => 14, 'pt_d' => 0, 'pt_m' => 0, 'pb_d' => 0, 'pb_m' => 0, 'gap_d' => 28, 'gap_m' => 16],
        'spotted' => ['h_d' => 34, 'h_m' => 24, 'pt_d' => 8, 'pt_m' => 8, 'pb_d' => 8, 'pb_m' => 8, 'gap_d' => 24, 'gap_m' => 16],
        'trending' => ['h_d' => 34, 'h_m' => 24, 'eb_d' => 12, 'eb_m' => 11, 'sub_d' => 16, 'sub_m' => 16, 'body_d' => 14, 'body_m' => 14, 'pt_d' => 0, 'pt_m' => 0, 'pb_d' => 0, 'pb_m' => 0, 'gap_d' => 28, 'gap_m' => 16],
        'bestsellers' => ['h_d' => 30, 'h_m' => 18, 'eb_d' => 12, 'eb_m' => 11, 'sub_d' => 14, 'sub_m' => 14, 'body_d' => 14, 'body_m' => 14, 'pt_d' => 0, 'pt_m' => 0, 'pb_d' => 14, 'pb_m' => 6, 'gap_d' => 22, 'gap_m' => 12],
        'flash' => ['h_d' => 30, 'h_m' => 18, 'eb_d' => 12, 'eb_m' => 11, 'sub_d' => 14, 'sub_m' => 14, 'body_d' => 14, 'body_m' => 14, 'pt_d' => 0, 'pt_m' => 0, 'pb_d' => 14, 'pb_m' => 6, 'gap_d' => 22, 'gap_m' => 12],
        'blog' => ['h_d' => 34, 'h_m' => 24, 'eb_d' => 12, 'eb_m' => 11, 'sub_d' => 16, 'sub_m' => 16, 'body_d' => 19, 'body_m' => 17, 'pt_d' => 0, 'pt_m' => 0, 'pb_d' => 0, 'pb_m' => 0, 'gap_d' => 28, 'gap_m' => 16],
        'under54' => ['h_d' => 34, 'h_m' => 24, 'eb_d' => 12, 'eb_m' => 11, 'sub_d' => 16, 'sub_m' => 16, 'body_d' => 14, 'body_m' => 14, 'pt_d' => 0, 'pt_m' => 0, 'pb_d' => 0, 'pb_m' => 0, 'gap_d' => 28, 'gap_m' => 16],
        'feature' => ['h_d' => 26, 'h_m' => 22, 'body_d' => 14, 'body_m' => 14, 'pt_d' => 0, 'pt_m' => 0, 'pb_d' => 0, 'pb_m' => 0],
        'about' => ['h_d' => 34, 'h_m' => 24, 'eb_d' => 12, 'eb_m' => 11, 'body_d' => 16, 'body_m' => 15, 'pt_d' => 0, 'pt_m' => 0, 'pb_d' => 0, 'pb_m' => 0, 'gap_d' => 20, 'gap_m' => 14],
        'trust' => ['body_d' => 13, 'body_m' => 13, 'pt_d' => 0, 'pt_m' => 0, 'pb_d' => 14, 'pb_m' => 6],
        'newsletter' => ['h_d' => 36, 'h_m' => 22, 'eb_d' => 11, 'eb_m' => 11, 'sub_d' => 14, 'sub_m' => 14, 'pt_d' => 0, 'pt_m' => 0, 'pb_d' => 14, 'pb_m' => 6],
        'grid' => ['h_d' => 26, 'h_m' => 19, 'sub_d' => 14, 'sub_m' => 14, 'body_d' => 14, 'body_m' => 14, 'pt_d' => 0, 'pt_m' => 0, 'pb_d' => 14, 'pb_m' => 6, 'gap_d' => 14, 'gap_m' => 14],
    ];

    /** Falls back to these where a section was not drawn when measured. */
    private const TODAY_FALLBACK = ['h_d' => 34, 'h_m' => 24, 'eb_d' => 12, 'eb_m' => 11, 'sub_d' => 16, 'sub_m' => 16, 'body_d' => 13, 'body_m' => 12,
        'pt_d' => 52, 'pt_m' => 18, 'pb_d' => 52, 'pb_m' => 18, 'gap_d' => 22, 'gap_m' => 12];

    /** Sections whose heading and subheading sizes come from HomeHeadings. */
    private const FOLLOWS_HEADINGS = ['bundles', 'bestselling', 'brands', 'spotted', 'trending', 'blog', 'under54', 'about'];

    public static function applies(string $key): bool
    {
        return ! in_array($key, self::EXCLUDED, true) && isset(HomepageSections::registry()[$key]);
    }

    /**
     * The controls one section is offered, in the order the tab draws them.
     *
     * @return list<string>
     */
    public static function keys(string $key): array
    {
        if (! self::applies($key)) {
            return [];
        }

        $heading = ! in_array($key, self::NO_HEADING, true);
        $text = ! in_array($key, self::NO_TEXT, true);
        $body = in_array($key, self::BODY, true) || (\App\Models\GridSection::idFromKey($key) !== null);
        $owned = self::OWNED[$key] ?? [];
        $out = [];

        if ($text) {
            $out[] = 'font';
        }

        if ($heading) {
            array_push($out, 'case', 'h_d', 'h_m');

            if (in_array($key, self::EYEBROW_IN, true)) {
                array_push($out, 'eb_d', 'eb_m');
            }

            if (! in_array($key, self::NO_SUB, true)) {
                array_push($out, 'sub_d', 'sub_m');
            }
        }

        if ($body) {
            array_push($out, 'body_d', 'body_m');
        }

        foreach (['pt', 'pb'] as $s) {
            if (! in_array($s, $owned, true)) {
                array_push($out, $s.'_d', $s.'_m');
            }
        }

        if ($heading && ! in_array($key, self::NO_GAP, true) && ! in_array('gap', $owned, true)) {
            array_push($out, 'gap_d', 'gap_m');
        }

        return $out;
    }

    /**
     * The tab's fields for one section, in the hub's field shape. `value` is
     * '' for a control nobody moved; `today` is what the page draws now, so
     * the slider opens where the section already is.
     *
     * @param  array<string, mixed>  $stored  the section's sparse map
     * @param  array<string, mixed>  $c       Homepage content settings
     * @return list<array<string, mixed>>
     */
    public static function fields(string $key, array $stored, array $c = []): array
    {
        $today = self::seed($key, $c);
        $out = [];

        foreach (self::keys($key) as $k) {
            if ($k === 'font') {
                $heading = ! in_array($key, self::NO_HEADING, true);
                $out[] = ['key' => $k, 'type' => 'font', 'label' => $heading ? 'Heading font' : 'Text font',
                    'help' => $heading ? 'The site heading font unless you pick one here. Only the font you pick is loaded by the shop.' : 'The site font unless you pick one here.',
                    'options' => ['' => $heading ? 'Site heading font' : 'Site font'] + FontLibrary::LABELS, 'default' => '', 'value' => (string) ($stored[$k] ?? '')];

                continue;
            }

            if ($k === 'case') {
                $out[] = ['key' => $k, 'type' => 'select', 'label' => 'Heading letter case', 'help' => '',
                    'options' => self::CASES, 'default' => '', 'value' => (string) ($stored[$k] ?? '')];

                continue;
            }

            [$label, $min, $max] = self::PX[$k];
            $out[] = ['key' => $k, 'type' => 'px', 'label' => $label, 'help' => '',
                'options' => ['min' => $min, 'max' => $max, 'today' => max($min, min($max, $today[$k]))],
                'default' => '', 'value' => isset($stored[$k]) ? (int) $stored[$k] : ''];
        }

        return $out;
    }

    /**
     * Validate one section's posted values. Returns [clean sparse map, errors].
     * '' or null clears a control back to "as designed"; anything else must be
     * one of the control's own options or an integer inside its own range —
     * a bogus font or a 900px heading is REFUSED, not clamped, so the owner is
     * told rather than surprised.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $posted
     * @return array{0: array<string, string|int>, 1: array<string, string>}
     */
    public static function clean(string $key, array $current, array $posted): array
    {
        $allowed = self::keys($key);
        $out = self::readOne($key, $current);
        $errors = [];

        foreach ($posted as $k => $v) {
            $k = (string) $k;

            if (! in_array($k, $allowed, true)) {
                $errors[$k] = 'Not a control of this section.';

                continue;
            }

            if ($v === null || $v === '') {
                unset($out[$k]);

                continue;
            }

            if ($k === 'font') {
                FontLibrary::exists($v) ? $out[$k] = $v : $errors[$k] = 'Not a font in the library.';

                continue;
            }

            if ($k === 'case') {
                is_string($v) && isset(self::CASE_CSS[$v]) ? $out[$k] = $v : $errors[$k] = 'Not a letter case.';

                continue;
            }

            [, $min, $max] = self::PX[$k];
            $n = is_int($v) ? $v : (is_string($v) && preg_match('/^\d{1,3}$/', $v) === 1 ? (int) $v : null);

            if ($n === null || $n < $min || $n > $max) {
                $errors[$k] = "Must be a whole number of pixels from {$min} to {$max}.";

                continue;
            }

            $out[$k] = $n;
        }

        return [$out, $errors];
    }

    /**
     * The stored map, re-validated on the way OUT as well as in: a hand-edited
     * settings row cannot put a word into the stylesheet, because anything that
     * is not a key of this section's own controls with a valid value is dropped.
     *
     * Reads the AUTOLOADED map only (SettingsService::all()), never get(): on a
     * shop that has never saved this, get() would miss and take a snapshot of
     * the whole settings table — one query the homepage did not make before.
     *
     * @param  array<string, mixed>|null  $all  the settings map, when the caller has it
     * @return array<string, array<string, string|int>>
     */
    public static function read(?array $all = null): array
    {
        $all ??= app(\App\Services\SettingsService::class)->all();
        $raw = $all[self::SETTING] ?? null;

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $out = [];

        foreach ($raw as $key => $map) {
            $key = (string) $key;

            if (is_array($map) && self::applies($key)) {
                $one = self::readOne($key, $map);

                if ($one !== []) {
                    $out[$key] = $one;
                }
            }
        }

        return $out;
    }

    /** @return array<string, string|int> */
    private static function readOne(string $key, array $map): array
    {
        $allowed = self::keys($key);
        $out = [];

        foreach ($map as $k => $v) {
            if (! in_array($k, $allowed, true)) {
                continue;
            }

            if ($k === 'font') {
                if (FontLibrary::exists($v)) {
                    $out[$k] = $v;
                }
            } elseif ($k === 'case') {
                if (is_string($v) && isset(self::CASE_CSS[$v])) {
                    $out[$k] = $v;
                }
            } elseif (is_int($v) || (is_string($v) && preg_match('/^\d{1,3}$/', $v) === 1)) {
                [, $min, $max] = self::PX[$k];
                $n = (int) $v;

                if ($n >= $min && $n <= $max) {
                    $out[$k] = $n;
                }
            }
        }

        return $out;
    }

    /** The class a section carries when it has anything moved; '' otherwise. */
    public static function classFor(string $key, array $map): string
    {
        return isset($map[$key]) && preg_match('/^[a-z0-9_]+$/', $key) === 1 ? 'kbb-ty-'.$key : '';
    }

    /**
     * Every font a section has chosen, once each.
     *
     * @return list<string>
     */
    public static function fonts(array $map): array
    {
        $out = [];

        foreach ($map as $one) {
            if (isset($one['font']) && ! in_array($one['font'], $out, true)) {
                $out[] = (string) $one['font'];
            }
        }

        return $out;
    }

    /**
     * The homepage's ONE inline <style> for this tab: the @font-face of each
     * chosen section font that is not already on the page, then each moved
     * value as exactly one declaration. '' while nothing has moved.
     *
     * @param  list<string>  $already  families the page prints elsewhere (SiteFonts)
     */
    public static function style(array $map, array $already = [], bool $arabicPage = false): string
    {
        if ($map === []) {
            return '';
        }

        $css = '';

        foreach (self::fonts($map) as $font) {
            if (! in_array($font, $already, true)) {
                $css .= FontLibrary::faceCss($font, $arabicPage);
            }
        }

        $all = '';
        $laptop = '';
        $phone = '';

        foreach ($map as $key => $v) {
            // Tripled: the section rules it overrides are up to three classes
            // deep (`.kbb-home .sec.hs>.wrap`), and a tie would go to source
            // order — which is not a thing a setting should depend on.
            $b = '.kbb-ty-'.$key.'.kbb-ty-'.$key.'.kbb-ty-'.$key;
            $heading = ! in_array($key, self::NO_HEADING, true);
            $body = self::BODY_OF[$key] ?? self::BODY_SEL;

            if (isset($v['font'])) {
                $all .= ($heading ? $b.' '.self::HEADING : $b).'{font-family:'.FontLibrary::stack((string) $v['font']).'}';
            }

            if (isset($v['case'])) {
                $all .= $b.' '.self::HEADING.'{text-transform:'.self::CASE_CSS[$v['case']].'}';
            }

            foreach (self::PX as $k => [, , , $part]) {
                if (! isset($v[$k])) {
                    continue;
                }

                $rule = match ($part) {
                    'h' => $b.' '.self::HEADING.'{font-size:'.(int) $v[$k].'px}',
                    'eb' => $b.' '.self::EYEBROW.'{font-size:'.(int) $v[$k].'px}',
                    'sub' => $b.' '.self::SUB.'{font-size:'.(int) $v[$k].'px}',
                    'body' => $b.' '.$body.'{font-size:'.(int) $v[$k].'px}',
                    'pt' => $b.'{padding-top:'.(int) $v[$k].'px!important}',
                    'pb' => $b.'{padding-bottom:'.(int) $v[$k].'px!important}',
                    'gap' => $b.' '.self::HEAD_ROW.'{margin-bottom:'.(int) $v[$k].'px}',
                };

                str_ends_with($k, '_d') ? $laptop .= $rule : $phone .= $rule;
            }
        }

        $css .= $all
            .($laptop !== '' ? self::LAPTOP.$laptop.'}' : '')
            .($phone !== '' ? self::PHONE.$phone.'}' : '');

        return $css === '' ? '' : '<style id="kbb-sec-type">'.$css.'</style>';
    }

    /**
     * What a slider starts at: the measured page, with headings that follow
     * Homepage content → Section headings read from that setting.
     *
     * @return array<string, int>
     */
    private static function seed(string $key, array $c): array
    {
        $base = ((\App\Models\GridSection::idFromKey($key) !== null) ? (self::TODAY['grid'] ?? []) : (self::TODAY[$key] ?? [])) + self::TODAY_FALLBACK;

        if (in_array($key, self::FOLLOWS_HEADINGS, true)) {
            $h = HomeHeadings::values($c);
            $base = ['h_d' => (int) $h['home_hd_h2_d'], 'h_m' => (int) $h['home_hd_h2_m'], 'sub_d' => (int) $h['home_hd_sub_d'], 'sub_m' => (int) $h['home_hd_sub_m']] + $base;
        }

        return $base;
    }
}
