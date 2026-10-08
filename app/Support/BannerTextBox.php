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

    /** The defaults, as the flat keys the admin draft and the JSON both use. */
    public static function defaults(): array
    {
        $out = ['style' => 'a', 'glow' => 'pastel', 'button' => 'fill'] + self::SHOWS;

        foreach (self::SLIDERS as $k => [, , $d, $m]) {
            $out['size_'.$k.'_d'] = $d[1];
            $out['size_'.$k.'_m'] = $m[1];
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

        return implode(';', $out);
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
    public static function words(BannerCard $card, array $cfg, bool $arabic): ?array
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
            'phone' => $card->hasPhonePicture(),
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
