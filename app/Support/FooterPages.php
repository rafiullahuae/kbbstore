<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SiteFooter;
use App\Services\SlimFooter;

/**
 * Appearance → Footer, as FOUR PAGES rather than thirteen tabs.       (Lane FT)
 *
 * THE OWNER, 4 October, looking at the one screen that mixed the site footer's
 * seven tabs with the cart bar's five: "The footer page is completely messed
 * up. I want total 4 pages in footer. Desktop, Mobile. Cart-Checkout Footer for
 * Desktop and Mobile. and also i need previews on each page. make it super easy
 * to use. and complete controls."
 *
 * So this is a MAP and nothing else: page → plain sections → the keys that
 * already exist in SiteFooter::SCHEMA and SlimFooter::SCHEMA. It adds no
 * setting and stores nothing. Every key is drawn on at least one page; a key
 * that only one device reads is drawn only on that device's page; a key both
 * devices read (a word, a colour, a link) is drawn on both and is the SAME key,
 * written through the same endpoint — `shared()` names them so the screen can
 * say "applies to desktop and mobile" beside each one.
 *
 * FooterPagesTest pins all three of those statements against both schemas, so a
 * key added to either service without a home here goes red rather than
 * becoming a setting with no control.
 */
final class FooterPages
{
    /** Which footer each page edits, which device it is, and its preview frame. */
    public const PAGES = [
        'site-d' => ['label' => 'Site footer · Desktop', 'footer' => 'site', 'device' => 'd',
            'hint' => 'The footer under every shop page, on laptops and anything wider than 900px.'],
        'site-m' => ['label' => 'Site footer · Mobile', 'footer' => 'site', 'device' => 'm',
            'hint' => 'The same footer on phones, 900px and narrower.'],
        'bar-d' => ['label' => 'Cart & Checkout footer · Desktop', 'footer' => 'bar', 'device' => 'd',
            'hint' => 'The slim bar at the foot of the cart page and the checkout, on laptops.'],
        'bar-m' => ['label' => 'Cart & Checkout footer · Mobile', 'footer' => 'bar', 'device' => 'm',
            'hint' => 'The same slim bar on phones, 900px and narrower.'],
    ];

    /**
     * The site footer's sections, written once with `{dev}` where the desktop
     * page reads `site_d_` and the mobile page `site_m_`. Both layout tabs had
     * every control for both devices already (SiteFooter::PARTS), so the two
     * pages are the same shape by construction and cannot drift apart.
     */
    private const SITE = [
        ['Design', 'Which footer the shop draws. Everything below styles the new one.',
            ['site_design']],
        ['Help strip', 'The coloured strip across the top.',
            ['site_help_on', 'site_{dev}_help', 'site_{dev}_help_sub', 'site_{dev}_help_align', 'site_{dev}_fs_head',
                'site_help_title', 'site_help_chip', 'site_help_sub', 'site_track_on']],
        ['Logo & description', 'The wordmark, the line under it and the social icons.',
            ['site_{dev}_logo', 'site_{dev}_tag', 'site_{dev}_soc', 'site_{dev}_brand_align']],
        ['Link columns', 'Three columns of links. Empty boxes keep the shipped titles and links.',
            ['site_{dev}_col1', 'site_{dev}_col2', 'site_{dev}_col3', 'site_{dev}_fs_link',
                'site_col1_title', 'site_col1_links', 'site_col2_title', 'site_col2_links', 'site_col3_title', 'site_col3_links']],
        ['Visit us', 'The two addresses. An empty one is not drawn.',
            ['site_{dev}_visit', 'site_addr_dubai', 'site_addr_korea']],
        ['Offers sign-up', 'The email box for offers.',
            ['site_news_on', 'site_{dev}_news']],
        ['Big name', 'The large name across the bottom.',
            ['site_name_on', 'site_{dev}_name', 'site_name_text', 'site_{dev}_fs_name', 'site_name_tone']],
        // (Lane FB) Under the big name, above the © line. `site_d_app_laptop` is
        // the one desktop-only key here, so the Mobile page leaves it out.
        ['App row', 'The frosted-glass “get the app” row under the big name, with the typing lines and the Install button.',
            ['site_app_on', 'site_app_title', 'site_app_lines', 'site_app_lines_ar', 'site_app_button', 'site_app_help', 'site_d_app_laptop']],
        ['Bottom bar', 'The last row: © line, Privacy, Terms, the payment marks, and its effect.',
            ['site_{dev}_bot', 'site_{dev}_pay', 'site_sheen', 'site_sheen_speed']],
        ['Colours & effects', 'The strip’s four colours, the footer’s colours and the slow drift.',
            ['site_c_from', 'site_c_2', 'site_c_3', 'site_c_to', 'site_c_bg', 'site_c_text', 'site_c_accent',
                'site_motion', 'site_drift_speed']],
        ['Spacing', 'Room above, between and below the columns.',
            ['site_{dev}_pt', 'site_{dev}_gap', 'site_{dev}_pb']],
    ];

    /** The cart & checkout bar on a laptop. */
    private const BAR_D = [
        ['Where it shows', 'One bar, two pages, a switch each.',
            ['co_on', 'cart_on']],
        ['Shape', 'How the blocks are arranged and where they sit.',
            ['variant', 'align', 'links_pos', 'sep']],
        ['Look', 'Background, the line above, corners and shadow.',
            ['tone', 'divider', 'line_w', 'radius', 'shadow']],
        ['Size & spacing', 'Height, padding and the gaps between blocks.',
            ['space_above', 'pad_y', 'pad_top', 'pad_bottom', 'pad_x', 'gap', 'row_pad', 'row_h', 'width_mode', 'max_w']],
        ['Text & marks', 'Type sizes, the wordmark and the small icons.',
            ['font', 'brand_size', 'brand_style', 'upper', 'phone_mark', 'icons_on']],
        ['Content', 'Every word in the bar. Empty means not drawn.',
            ['brand', 'byline', 'help_title', 'help_sub', 'phone', 'phone_url', 'email',
                'l1_text', 'l1_url', 'l2_text', 'l2_url', 'l3_text', 'l3_url', 'copy']],
        ['Back-to-top arrow', 'The small arrow at the end of the bar.',
            ['top_on', 'top_style', 'top_label']],
        ['Payment marks', 'Off by default. Turn on only the ones this shop takes.',
            ['pay_on', 'pay_visa', 'pay_mc', 'pay_apple', 'pay_google', 'pay_tabby', 'pay_tamara']],
    ];

    /** The same bar on a phone: the `m_` twins, plus everything shared. */
    private const BAR_M = [
        ['Phone layout', 'Off hands the phone the Desktop page’s shape and sizes.',
            ['mobile_on']],
        ['Where it shows', 'One bar, two pages, a switch each.',
            ['co_on', 'cart_on']],
        ['Shape', 'How the blocks are arranged and where they sit.',
            ['m_variant', 'm_align', 'links_pos', 'sep']],
        ['Look', 'Background, the line above, corners and shadow.',
            ['tone', 'divider', 'line_w', 'radius', 'shadow']],
        ['Size & spacing', 'Height, padding and the gaps between blocks.',
            ['m_space_above', 'm_pad_y', 'm_pad_top', 'm_pad_bottom', 'm_pad_x', 'm_gap', 'm_row_pad', 'm_row_h', 'width_mode', 'max_w']],
        ['Text & marks', 'Type sizes, the wordmark and the small icons.',
            ['m_font', 'm_brand_size', 'brand_style', 'upper', 'phone_mark', 'icons_on']],
        ['Content', 'Every word in the bar. Empty means not drawn.',
            ['brand', 'byline', 'help_title', 'help_sub', 'phone', 'phone_url', 'email',
                'l1_text', 'l1_url', 'l2_text', 'l2_url', 'l3_text', 'l3_url', 'copy']],
        ['Back-to-top arrow', 'The small arrow at the end of the bar.',
            ['top_on', 'top_style', 'top_label']],
        ['Payment marks', 'Off by default. Turn on only the ones this shop takes.',
            ['pay_on', 'pay_visa', 'pay_mc', 'pay_apple', 'pay_google', 'pay_tabby', 'pay_tamara']],
    ];

    /**
     * Keys only ONE device reads. Everything else drawn on a page is shared.
     *
     * The slim bar's desktop sizes are listed by name because they carry no
     * prefix; its phone keys are `mobile_on` and the `m_` twins.
     */
    private const BAR_DESKTOP_ONLY = [
        'variant', 'align', 'space_above', 'pad_y', 'pad_top', 'pad_bottom', 'pad_x', 'gap', 'row_pad', 'row_h',
        'font', 'brand_size',
    ];

    /**
     * @return list<array{key: string, label: string, footer: string, device: string, hint: string,
     *                    sections: list<array{title: string, hint: string, keys: list<string>}>}>
     */
    public static function pages(): array
    {
        $out = [];

        foreach (self::PAGES as $key => $page) {
            $out[] = ['key' => $key] + $page + ['sections' => self::sections($key)];
        }

        return $out;
    }

    /** @return list<array{title: string, hint: string, keys: list<string>}> */
    public static function sections(string $page): array
    {
        $meta = self::PAGES[$page] ?? null;

        if ($meta === null) {
            return [];
        }

        $rows = $meta['footer'] === 'site' ? self::SITE : ($meta['device'] === 'd' ? self::BAR_D : self::BAR_M);
        $out = [];

        foreach ($rows as [$title, $hint, $keys]) {
            $keys = array_map(static fn (string $k): string => str_replace('{dev}', $meta['device'], $k), $keys);

            $out[] = [
                'title' => $title,
                'hint' => $hint,
                // A key written for one device (site_d_…) is drawn on that
                // device's page only, even in a section both pages share.
                'keys' => array_values(array_filter($keys, static fn (string $k): bool => in_array(self::deviceOf($k), [null, $meta['device']], true))),
            ];
        }

        return $out;
    }

    /** @return list<string> every key drawn on one page, in order */
    public static function keys(string $page): array
    {
        return array_merge(...array_column(self::sections($page), 'keys') ?: [[]]);
    }

    /** 'd', 'm', or null when both devices read the key. */
    public static function deviceOf(string $key): ?string
    {
        if (preg_match('/^site_([dm])_/', $key, $m) === 1) {
            return $m[1];
        }

        if (str_starts_with($key, 'site_')) {
            return null;
        }

        if ($key === 'mobile_on' || str_starts_with($key, 'm_')) {
            return 'm';
        }

        return in_array($key, self::BAR_DESKTOP_ONLY, true) ? 'd' : null;
    }

    /** @return list<string> keys both devices read, drawn on both device pages */
    public static function shared(): array
    {
        $all = array_merge(array_keys(SiteFooter::SCHEMA), array_keys(SlimFooter::SCHEMA));

        return array_values(array_filter($all, static fn (string $k): bool => self::deviceOf($k) === null));
    }
}
