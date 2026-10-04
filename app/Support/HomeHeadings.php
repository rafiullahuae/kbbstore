<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One size for every homepage section heading, and one for every section
 * description — per device.                                       (Lane PF)
 *
 * THE OWNER, 4 October 2026, over a screenshot with the Best Sellers heading
 * and description ticked and the bundles description circled: "i think the
 * sections headings and descriptions should have the same font size. in
 * desktop and mobile both … purely as per the best seo and google liking".
 *
 * Google does not rank on font size; what a consistent hierarchy buys is a
 * page that reads as one document, and descriptions a phone can read without
 * zooming (16px is the size below which mobile browsers and Lighthouse start
 * to complain). Measured on 2.60.372 before this class: at 1280 the eight
 * section headings came in two sizes (30px on the bundles and Spotted,
 * 33.28px on the rest) and the descriptions in three (13.5px, 14.5px,
 * 14.72px); at 390 the headings were 18px and 23.4px, and the bundles' and
 * Spotted's descriptions were display:none while the others were 13.5px.
 *
 * ── HOW IT IS ONE SIZE ──────────────────────────────────────────────────────
 *
 * kbb.css defines four custom properties on `.kbb-home` — `--hs-h2-d`,
 * `--hs-h2-m`, `--hs-sub-d`, `--hs-sub-m` — and ONE rule per device sizes
 * every section head's <h2> and <p> from them: the row-55 `.hs-head`, the
 * bundles' `.bndl-head` and the homepage Spotted `.spt-head`. No section
 * carries a font-size of its own for either any more (HomeSectionHeadingsTest
 * reads the sheet and fails on one). The defaults are the Best Sellers style
 * the owner ticked, at the width it reaches on a laptop (34px — its clamp's
 * ceiling, reached at 1308px) and the width of the phone he holds (24px), with
 * the description lifted to 16px on both.
 *
 * The two-column feature's panel titles are CARD titles — two side by side,
 * each over its own photo — and keep their own size.
 *
 * ── WHAT IS PRINTED, AND WHEN ───────────────────────────────────────────────
 *
 * Appearance → Homepage content → Section headings holds the four values as
 * selects of fixed px options. style() prints them as ONE rule on `.kbb-home`
 * in the page's <head> — and prints NOTHING while all four are the defaults,
 * because kbb.css already says exactly that, so a shop that never opens the tab
 * gets not one byte more. Every value printed is an option KEY, re-checked
 * against the option list on the way out: a stored value that is not one of
 * the select's own options becomes the default, never a string in a <style>.
 */
final class HomeHeadings
{
    /** The defaults; kbb.css's `.kbb-home{--hs-…}` line says the same and HomeSectionHeadingsTest holds the two together. */
    public const DEFAULTS = [
        'home_hd_h2_d' => '34',
        'home_hd_h2_m' => '24',
        'home_hd_sub_d' => '16',
        'home_hd_sub_m' => '16',
    ];

    /** setting => the custom property it drives. */
    public const PROPERTIES = [
        'home_hd_h2_d' => '--hs-h2-d',
        'home_hd_h2_m' => '--hs-h2-m',
        'home_hd_sub_d' => '--hs-sub-d',
        'home_hd_sub_m' => '--hs-sub-m',
    ];

    /*
     * The phone description starts at 16: the owner's brief is "at least 16px
     * on phones", so the select cannot be set under it.
     */
    public const SCHEMA = [
        'home_hd_h2_d' => ['type' => 'select', 'label' => 'Section heading size · laptop', 'default' => '34', 'store' => 'setting',
            'options' => ['26' => '26px', '28' => '28px', '30' => '30px', '32' => '32px', '34' => '34px — the Best Sellers heading', '36' => '36px', '38' => '38px', '40' => '40px'],
            'help' => 'Every section heading on the homepage: Big savings bundles, Best Sellers, Brands, Spotted, Trending, the Blog, Under AED 54 and About us.'],
        'home_hd_h2_m' => ['type' => 'select', 'label' => 'Section heading size · phone', 'default' => '24', 'store' => 'setting',
            'options' => ['20' => '20px', '21' => '21px', '22' => '22px', '23' => '23px', '24' => '24px — the Best Sellers heading', '26' => '26px', '28' => '28px'],
            'help' => '900px and narrower.'],
        'home_hd_sub_d' => ['type' => 'select', 'label' => 'Line under the heading · laptop', 'default' => '16', 'store' => 'setting',
            'options' => ['14' => '14px', '15' => '15px', '16' => '16px', '17' => '17px', '18' => '18px'],
            'help' => 'The description under every section heading.'],
        'home_hd_sub_m' => ['type' => 'select', 'label' => 'Line under the heading · phone', 'default' => '16', 'store' => 'setting',
            'options' => ['16' => '16px — the smallest a phone reads without zooming', '17' => '17px', '18' => '18px'],
            'help' => 'Never under 16px on a phone.'],
    ];

    /** tab => [label, description, keys] — spread into HomepageContent::TABS. */
    public const TABS = [
        'headings' => ['Section headings', 'One size for every section heading on the homepage, and one for every line under a heading — separately for a laptop and a phone (900px and narrower). Each section keeps its own alignment. The two-column feature’s panel titles are card titles and keep their own size.', ['home_hd_h2_d', 'home_hd_h2_m', 'home_hd_sub_d', 'home_hd_sub_m']],
    ];

    /**
     * The four values, each one of its select's own option keys.
     *
     * @param  array<string, mixed>  $c  HomeSections::settings()
     * @return array<string, string>
     */
    public static function values(array $c): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $field) {
            $v = (string) ($c[$key] ?? $field['default']);
            $out[$key] = array_key_exists($v, $field['options']) ? $v : (string) $field['default'];
        }

        return $out;
    }

    /**
     * `<style>.kbb-home{--hs-h2-d:30px;…}</style>`, or '' while every value is
     * the default. Integers from option keys only; safe to print unescaped.
     *
     * @param  array<string, mixed>  $c  HomeSections::settings()
     */
    public static function style(array $c): string
    {
        $values = self::values($c);

        if ($values === self::DEFAULTS) {
            return '';
        }

        $decl = [];

        foreach ($values as $key => $v) {
            $decl[] = self::PROPERTIES[$key].':'.(int) $v.'px';
        }

        return '<style>.kbb-home{'.implode(';', $decl).'}</style>';
    }
}
