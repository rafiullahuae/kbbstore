<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\HomepageContent;
use App\Services\ModuleSchema;
use App\Services\SettingsService;

/**
 * The homepage's "Big savings bundles" row: carousel, arrows, heading and the
 * All sets button, per device.                                   (2.60.370)
 *
 * THE OWNER, 3 October 2026: "on homepage, i need the bundle section to be
 * carousel with proper beautiful arrows, give controls of everything for
 * desktop mobile both. center the heading, redesign the All Sets button
 * beautifully" — and then "i want this button in mobile at bottom of carsousel,
 * give controls of spacing etc." The controls are Appearance → Homepage content
 * → Big savings bundles (HomepageContent::SCHEMA, `home_hb_*`); their defaults
 * are what he asked for.
 *
 * Everything printed is built here from a select's own option keys, a bool, or
 * an escaped/validated string: the classes are literals, the style is integers
 * and one decimal from a fixed list, the URL is scheme-checked. The arrows and
 * the swipe are resources/js/kbb/ymal.js (the product page's carousel, which
 * measures nothing); the sizes are CSS (kbb.css, `.kbb-home .bndl`).
 */
final class HomeBundles
{
    public const DEFAULT_URL = '/shop/?cat=skincare-sets';

    /**
     * @return array{classes: string, style: string, auto: int, title: string, sub: string, label: string, url: string, count: bool, above: int}
     */
    public static function config(): array
    {
        try {
            $c = ModuleSchema::read(app(SettingsService::class), 'homepage_content', HomepageContent::SCHEMA);
        } catch (\Throwable) {
            $c = [];
        }

        $pick = static function (string $key, array $allowed, string $default) use ($c): string {
            $v = (string) ($c[$key] ?? $default);

            return in_array($v, $allowed, true) ? $v : $default;
        };

        $px = ['0', '4', '8', '12', '16', '20', '24', '28', '32', '40', '48'];

        $layoutD = $pick('home_hb_layout_d', ['carousel', 'grid'], 'carousel');
        $layoutM = $pick('home_hb_layout_m', ['carousel', 'grid'], 'carousel');
        $btnD = $pick('home_hb_btn_d', ['top', 'bottom', 'off'], 'top');
        $btnM = $pick('home_hb_btn_m', ['top', 'bottom', 'off'], 'bottom');

        $perM = $pick('home_hb_per_m', ['1', '1.5', '2', '2.2', '2.3', '2.5'], '2.3');
        $perD = $pick('home_hb_per_d', ['3', '4', '5', '6'], '4');

        $classes = [
            'bndl',
            $layoutD === 'carousel' ? 'bndl-car-d' : 'bndl-grid-d',
            $layoutM === 'carousel' ? 'bndl-car-m' : 'bndl-grid-m',
            // (Lane PF) A fractional count on a phone carousel runs the track
            // to the screen edge, so the part card is cut by the screen and
            // not by the gutter — "so the user will know that there's more".
            $layoutM === 'carousel' && ! ctype_digit($perM) ? 'bndl-peek-m' : '',
            ($c['home_hb_arrows_d'] ?? true) ? '' : 'bndl-noarr-d',
            ($c['home_hb_arrows_m'] ?? false) ? '' : 'bndl-noarr-m',
            'bndl-'.$pick('home_hb_align', ['center', 'start'], 'center'),
            'bndl-btn-d-'.$btnD,
            'bndl-btn-m-'.$btnM,
        ];

        $style = implode(';', [
            '--bndl-per-d:'.$perD,
            '--bndl-per-m:'.$perM,
            '--bndl-pad-d:'.$pick('home_hb_pad_d', $px, '8').'px',
            '--bndl-pad-m:'.$pick('home_hb_pad_m', $px, '8').'px',
            '--bndl-hg-d:'.$pick('home_hb_head_gap_d', $px, '24').'px',
            '--bndl-hg-m:'.$pick('home_hb_head_gap_m', $px, '12').'px',
            '--bndl-bg-d:'.$pick('home_hb_btn_gap_d', $px, '24').'px',
            '--bndl-bg-m:'.$pick('home_hb_btn_gap_m', $px, '16').'px',
        ]);

        $url = trim((string) ($c['home_hb_btn_url'] ?? ''));
        $ok = ($url !== '' && str_starts_with($url, '/') && ! str_starts_with($url, '//'))
            || preg_match('#^https?://[^\s"<>]+$#i', $url) === 1;

        $text = static fn (string $key): string => trim(RichText::toText((string) ($c[$key] ?? '')));

        return [
            'classes' => implode(' ', array_filter($classes)),
            'style' => $style,
            'auto' => (int) $pick('home_hb_auto', ['0', '3', '5', '7', '10'], '0'),
            'title' => $text('home_hb_title'),
            'sub' => $text('home_hb_sub'),
            'label' => $text('home_hb_btn_text'),
            'url' => $ok ? $url : self::DEFAULT_URL,
            // (Lane PF) The "8 sets" badge beside the heading. The owner crossed
            // it out, 4 October; `home_hb_count` brings it back.
            'count' => (bool) ($c['home_hb_count'] ?? false),
            // (Lane LZ) How many cards the row shows at once, on whichever
            // device shows more: the carousel's own "per view" (a part card
            // counts), or the shop's first row when it is a grid. Used only
            // when this is the first section under the banner.
            'above' => max(
                $layoutD === 'carousel' ? (int) ceil((float) $perD) : app(\App\Services\SiteLayout::class)->aboveFoldCards(),
                $layoutM === 'carousel' ? (int) ceil((float) $perM) : 2,
            ),
        ];
    }
}
