<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Services\Banners;

/**
 * THE TEXT BOX ON A SLIDER PICTURE -- Lane HB.
 *
 * Two halves, and this class is the only place either is decided:
 *
 *   SET-WIDE (banner_sets.text_box, JSON): the style (A frosted glass or
 *   D sticker card), the glow, which elements show, the button style, and ten
 *   sizes -- five sliders, each with a desktop and a phone value. "across all
 *   banners together" is the owner's phrase, so the sizes belong to the set,
 *   not to a picture.
 *
 *   PER PICTURE (banner_cards): the words, in English and Arabic, the sticker,
 *   the box position and the per-picture switch.
 *
 * Everything that reaches a page passes through normalize() (selects store one
 * of their own options or the default, sliders are clamped and stepped) and
 * every size leaves through cssVariables() as a NUMBER followed by a unit this
 * file writes. Nothing an operator typed is ever printed inside CSS.
 */
final class BannerTextBox
{
    public const STYLES = ['a' => 'A · Frosted glass', 'd' => 'D · Sticker card'];

    public const GLOWS = ['pastel' => 'Pastel gradient', 'white' => 'Soft white'];

    public const BUTTONS = [
        'fill' => 'Filled (pill)',
        'outline' => 'Outline',
        'text' => 'Text link with an arrow',
        'soft' => 'Soft (tinted)',
        'under' => 'Underline',
        'grad' => 'Gradient pill (Sticker card only)',
    ];

    public const POSITIONS = ['start' => 'Start (left; right in Arabic)', 'end' => 'End (right; left in Arabic)'];

    /** The five things that can be hidden on every picture, and their default. */
    public const SHOWS = ['show_eyebrow' => true, 'show_heading' => true, 'show_text' => true, 'show_button' => true, 'show_sticker' => true];

    /**
     * The sliders: key => [label, unit, desktop [min, default, max, step], phone [min, default, max, step]].
     *
     * The maxima are what fit. Measured in Chromium with every slider at its
     * maximum and deliberately long words, the box and the sticker stay inside a
     * 1280 x 367 desktop frame and a 390 x 468 phone frame. They were 48px and
     * 19px for the desktop heading and text until that measurement put D's
     * sticker 18px above the frame.
     */
    public const SLIDERS = [
        'h' => ['Heading size', 'px', [22, 34, 44, 1], [20, 26, 36, 1]],
        't' => ['Text size', 'px', [12, 15, 18, 0.5], [12, 14, 17, 0.5]],
        'e' => ['Eyebrow size', 'px', [9, 11.5, 15, 0.5], [9, 11, 14, 0.5]],
        'b' => ['Button size', '%', [80, 100, 130, 5], [80, 100, 130, 5]],
        'w' => ['Box width', 'px|%', [280, 420, 560, 10], [70, 100, 100, 5]],
    ];

    /*
     * ── POSITION (Lane HB2) ─────────────────────────────────────────────────
     * The owner: "the control for the box that it should not go outside the
     * site width. and also the position for the box like bottom, middle and a
     * custom positioning by setting up the percentage or px. same for mobile."
     * Every one of these has a computer and a phone value.
     */
    public const VPOS = [
        'auto' => 'Style’s own',
        'top' => 'Top', 'middle' => 'Middle', 'bottom' => 'Bottom', 'custom' => 'Custom',
    ];

    public const HPOS = [
        'auto' => 'Picture’s side',
        'start' => 'Start', 'centre' => 'Centre', 'end' => 'End',
        'custom' => 'Custom',
    ];

    public const UNITS = ['pct' => '%', 'px' => 'px'];

    /** The custom value's range by axis and unit: [min, max]. */
    public const OFFSETS = ['v' => ['pct' => [0, 100], 'px' => [0, 600]], 'h' => ['pct' => [0, 100], 'px' => [0, 1000]]];

    /** The position half of the document, per device, at its shipped values. */
    public const POSITION = [
        // "it should not go outside the site width" -- he asked, so it ships ON.
        'inside' => true,
        'vpos' => 'auto', 'vval' => 50, 'vunit' => 'pct',
        'hpos' => 'auto', 'hval' => 0, 'hunit' => 'px',
    ];

    /** The defaults, as the flat keys the admin draft and the JSON both use. */
    public static function defaults(): array
    {
        $out = ['style' => 'a', 'glow' => 'pastel', 'button' => 'fill'] + self::SHOWS;

        foreach (self::SLIDERS as $k => [, , $d, $m]) {
            $out['size_'.$k.'_d'] = $d[1];
            $out['size_'.$k.'_m'] = $m[1];
        }

        foreach (['d', 'm'] as $dev) {
            foreach (self::POSITION as $k => $v) {
                $out[$k.'_'.$dev] = $v;
            }
        }

        return $out;
    }

    /** The keys of the set-wide document, in a stable order. */
    public static function keys(): array
    {
        return array_keys(self::defaults());
    }

    /**
     * Any input -> a complete, safe document. Unknown keys are dropped, an
     * unknown option is the default, a size is clamped to its range and
     * snapped to its step, and a show flag is a real boolean.
     *
     * @param  mixed  $raw  a JSON string, an array, or null
     */
    public static function normalize(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        $raw = is_array($raw) ? $raw : [];
        $out = self::defaults();

        foreach (['style' => self::STYLES, 'glow' => self::GLOWS, 'button' => self::BUTTONS] as $key => $options) {
            if (isset($raw[$key]) && is_string($raw[$key]) && array_key_exists($raw[$key], $options)) {
                $out[$key] = $raw[$key];
            }
        }

        foreach (self::SHOWS as $key => $default) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = filter_var($raw[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
            }
        }

        foreach (self::SLIDERS as $k => [, , $d, $m]) {
            foreach (['d' => $d, 'm' => $m] as $dev => [$min, , $max, $step]) {
                $key = 'size_'.$k.'_'.$dev;

                if (isset($raw[$key]) && is_numeric($raw[$key])) {
                    $v = max($min, min($max, (float) $raw[$key]));
                    $out[$key] = $min + round(($v - $min) / $step) * $step;
                }
            }
        }

        foreach (['d', 'm'] as $dev) {
            if (array_key_exists('inside_'.$dev, $raw)) {
                $out['inside_'.$dev] = filter_var($raw['inside_'.$dev], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
            }

            foreach (['vpos' => self::VPOS, 'hpos' => self::HPOS, 'vunit' => self::UNITS, 'hunit' => self::UNITS] as $k => $options) {
                $key = $k.'_'.$dev;

                if (isset($raw[$key]) && is_string($raw[$key]) && array_key_exists($raw[$key], $options)) {
                    $out[$key] = $raw[$key];
                }
            }

            // The custom value is clamped to its own unit's range, so a unit
            // switched after the number was typed cannot leave 900% behind.
            foreach (['v', 'h'] as $axis) {
                $key = $axis.'val_'.$dev;
                [$min, $max] = self::OFFSETS[$axis][$out[$axis.'unit_'.$dev]];

                $out[$key] = isset($raw[$key]) && is_numeric($raw[$key])
                    ? (int) round(max($min, min($max, (float) $raw[$key])))
                    : (int) max($min, min($max, (int) $out[$key]));
            }
        }

        foreach ($out as $key => $value) {
            if (is_float($value) && floor($value) === $value) {
                $out[$key] = (int) $value;
            }
        }

        return $out;
    }

    public static function forSet(BannerSet $set): array
    {
        return self::normalize($set->getAttribute('text_box'));
    }

    /** The button style that will actually draw: Gradient is the Sticker card's own. */
    public static function buttonStyle(array $cfg): string
    {
        return $cfg['button'] === 'grad' && $cfg['style'] !== 'd' ? 'fill' : $cfg['button'];
    }

    /**
     * The ten sizes as custom properties. Every value is a number this method
     * formats from the normalised document; the unit is a literal here.
     */
    public static function cssVariables(array $cfg): string
    {
        $cfg = self::normalize($cfg);
        $out = [];

        foreach (self::SLIDERS as $k => $unused) {
            foreach (['d', 'm'] as $dev) {
                $v = (float) $cfg['size_'.$k.'_'.$dev];

                $value = match (true) {
                    $k === 'b' => self::num($v / 100),
                    $k === 'w' && $dev === 'm' => self::num($v).'%',
                    default => self::num($v).'px',
                };

                $out[] = '--hb-'.$k.'-'.$dev.':'.$value;
            }
        }

        foreach (['d', 'm'] as $dev) {
            foreach (self::positionNumbers($cfg, $dev) as $name => $value) {
                $out[] = '--hb-'.$name.'-'.$dev.':'.$value;
            }
        }

        return implode(';', $out);
    }

    /**
     * ONE DEVICE'S POSITION AS NUMBERS -- and the technique, because the box's
     * own height is never known to the server or measured in the browser.
     *
     * VERTICAL. The box sits in a flex column (.hb-pos) that spans the frame
     * between a top and a bottom inset, with a spacer above it (::before) and
     * one below (::after). The free height -- column minus box -- is shared
     * between the two spacers by their flex-grow:
     *     vg1 = p above, vg2 = 1 - p below      (top 0, middle .5, bottom 1)
     * so the box's top is  inset + p x (column - box)  -- a percentage of the
     * TRAVEL, which can never put the box outside the column, at any size. A
     * custom % is that p. A custom px is the spacer's flex-basis instead (vb)
     * with grow 0 and shrink 1: when the box would run off the bottom the
     * spacer shrinks, so the box stops at the bottom inset rather than leaving.
     *
     * HORIZONTAL. The box's width is CSS the server wrote -- min(width, column)
     * -- so the room beside it IS known to the stylesheet: (100% - width). The
     * box's margin-inline-start is  clamp(0, xo + room x xp, room):  xp is 0 /
     * .5 / 1 for start / centre / end, xo a custom offset; the clamp keeps it
     * between the column's two edges. Pictures set to End mirror the offset
     * when the mode follows the picture's side (the hb-hs-* class).
     *
     * @return array<string, string>  name => a number, optionally px or %
     */
    public static function positionNumbers(array $cfg, string $dev): array
    {
        $vpos = $cfg['vpos_'.$dev];

        if ($vpos === 'auto') {
            $vpos = $cfg['style'] === 'd' && $dev === 'd' ? 'middle' : 'bottom';
        }

        $p = ['top' => 0.0, 'middle' => 0.5, 'bottom' => 1.0][$vpos] ?? null;
        $vb = '0px';

        if ($vpos === 'custom') {
            if ($cfg['vunit_'.$dev] === 'px') {
                $p = 0.0;
                $vb = self::num((float) $cfg['vval_'.$dev]).'px';
                $g1 = '0';
                $g2 = '1';
            } else {
                $p = (float) $cfg['vval_'.$dev] / 100;
            }
        }

        $g1 ??= self::num($p);
        $g2 ??= self::num(1 - $p);

        $hpos = $cfg['hpos_'.$dev];
        $xp = ['auto' => 0.0, 'start' => 0.0, 'centre' => 0.5, 'end' => 1.0, 'custom' => 0.0][$hpos] ?? 0.0;
        $xo = $hpos === 'custom'
            ? self::num((float) $cfg['hval_'.$dev]).($cfg['hunit_'.$dev] === 'px' ? 'px' : '%')
            : '0px';

        return ['vg1' => $g1, 'vb' => $vb, 'vg2' => $g2, 'xp' => self::num($xp), 'xo' => $xo];
    }

    /**
     * THE HEADER'S OWN WIDTH AND PADDING, for "Keep the box inside the site
     * width" -- printed only when that switch is on for a device.
     *
     * Read from the two services the header itself is drawn from, so the box
     * cannot disagree with the logo: HeaderSettings::maxWidthCss() is
     * `var(--site-max)` or "<n>px", MobileHeader's two paddings are integers.
     * Both read the request's one settings map, which the header has already
     * read on this page -- no query, no second read.
     */
    public static function siteVariables(array $cfg): string
    {
        if (! $cfg['inside_d'] && ! $cfg['inside_m']) {
            return '';
        }

        $max = app(\App\Services\HeaderSettings::class)->maxWidthCss();
        $max = $max === 'var(--site-max)' || preg_match('/^\d{1,5}px$/', $max) === 1 ? $max : 'var(--site-max)';
        $mobile = app(\App\Services\MobileHeader::class)->all();

        return ';--hb-hdmax:'.$max
            .';--hb-mhl:'.max(0, min(64, (int) ($mobile['pad_left'] ?? 12))).'px'
            .';--hb-mhr:'.max(0, min(64, (int) ($mobile['pad_right'] ?? 12))).'px';
    }

    /**
     * The slider's position classes -- constants, one per switch that is on:
     * hb-site-{d,m}  keep the box inside the site width
     * hb-va-d        computer vertical is the style's own (D's exact old centre)
     * hb-hs-{d,m}    the horizontal mode follows each picture's Start/End
     * hb-na-m        a phone's box is not at the bottom: the arrows step aside
     */
    public static function rootClasses(array $cfg): string
    {
        $out = '';

        foreach (['d', 'm'] as $dev) {
            $out .= $cfg['inside_'.$dev] ? ' hb-site-'.$dev : '';
            $out .= in_array($cfg['hpos_'.$dev], ['auto', 'custom'], true) ? ' hb-hs-'.$dev : '';
        }

        // A phone's box is the full width, so wherever it sits that is not the
        // bottom, the slider's arrows (at the frame's two edges) would land on
        // it -- and on its button. They step aside there; swipe and the bars
        // still move the slider. At the bottom they stay where 2.60.432 put them.
        $phoneBottom = BannerTextBox::positionNumbers($cfg, 'm')['vg1'] === '1';

        return $out.($cfg['vpos_d'] === 'auto' ? ' hb-va-d' : '').($phoneBottom ? '' : ' hb-na-m');
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /**
     * A link the BUTTON may carry: http, https or a path on this shop. The
     * picture's own link keeps Banners::safeUrl(), which also allows mailto:
     * and tel: -- a pill that says "Shop the Glow Edit" and opens the mail
     * client is not a shopping button.
     */
    public static function buttonUrl(?string $raw): string
    {
        $url = Banners::safeUrl($raw);

        if ($url === '') {
            return '';
        }

        $probe = strtolower((string) preg_replace('/[\s\x00-\x1F\x7F-\x9F]+/u', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $probe, $m) === 1 && ! in_array($m[1], ['http', 'https'], true)) {
            return '';
        }

        return $url;
    }

    /**
     * The words one picture shows, in the page's language, or null for NO BOX.
     *
     * Null when the picture's switch is off, or when every element left after
     * the set's show/hide and the language fallback is empty. An empty field
     * hides that element on that picture only.
     *
     * @return array{eyebrow:string,heading:string,text:string,button:string,href:string,sticker:string,ring:string,end:bool,phone:bool}|null
     */
    public static function words(BannerCard $card, array $cfg, bool $arabic, bool $phoneFrame = false): ?array
    {
        if (! filter_var($card->getAttribute('box_on'), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $pick = static function (string $field) use ($card, $arabic): string {
            if ($arabic) {
                $own = trim((string) $card->getAttribute($field.'_ar'));

                if ($own !== '') {
                    return $own;
                }
            }

            return trim((string) $card->getAttribute($field));
        };

        $href = self::buttonUrl($card->button_url);

        $w = [
            'eyebrow' => $cfg['show_eyebrow'] ? $pick('eyebrow') : '',
            'heading' => $cfg['show_heading'] ? $pick('heading') : '',
            'text' => $cfg['show_text'] ? $pick('body') : '',
            // A button with nowhere to go is not drawn: the label alone would be
            // a pill that does nothing when tapped.
            'button' => $cfg['show_button'] && $href !== '' ? $pick('button_label') : '',
            'href' => $href,
            'sticker' => $cfg['show_sticker'] && $cfg['style'] === 'd' ? $pick('sticker') : '',
            'ring' => '',
            'end' => (string) $card->getAttribute('box_pos') === 'end',
            // Lane HB3: with an EXACT phone height the frame is a fixed phone
            // shape (430 x N crop or the phone picture), so the box has room.
            'phone' => $card->hasPhonePicture() || $phoneFrame,
        ];

        if ($w['sticker'] !== '') {
            $w['ring'] = $pick('sticker_ring');
        }

        if ($w['eyebrow'] === '' && $w['heading'] === '' && $w['text'] === '' && $w['button'] === '') {
            return null;
        }

        return $w;
    }

    /**
     * The heading as HTML: ESCAPED FIRST, then `*a few words*` becomes the
     * Sticker card's highlighter. The pattern runs over escaped text, so the
     * only markup that can come out is the <mark> this method writes.
     */
    public static function headingHtml(string $heading): string
    {
        $safe = e($heading);

        return (string) preg_replace('/\*([^*]{1,60})\*/u', '<mark>$1</mark>', $safe);
    }

    /** The same heading with the asterisks dropped, for anything that is not HTML. */
    public static function headingText(string $heading): string
    {
        return (string) preg_replace('/\*([^*]{1,60})\*/u', '$1', $heading);
    }
}
