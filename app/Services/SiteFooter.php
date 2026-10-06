<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SafeUrl;
use App\Support\SupportContact;
use App\Support\Url;

/**
 * The site footer on every storefront page — the new design and the switch
 * back to the old one.                                              (Lane HB)
 *
 * Admin: Appearance → Footer, the three "Site footer" tabs (drawn by the
 * existing Footer screen; App\Http\Controllers\Admin\SlimFooterApiController
 * hands them out beside the slim bar's own tabs and routes their keys here).
 *
 * THE OWNER, 3 October 2026 (master plan row 55), approving
 * docs/home-preview/footer-final.html: C design for the desktop, "reduce the
 * height on mobile. use whatsapp green colors. and the colored strip of need
 * help, will changes colors itself within same color range … replace chat with
 * us [with] 24/7 available … we don't offer returns so don't include any return
 * word … improve that footer design overall more to beauty industry. and bottom
 * we need our name super big center align with light color, and continue
 * changes, bright" — then "proceed with the development".
 *
 * ── FED FROM WHAT THE SHOP ALREADY HAS ──────────────────────────────────────
 *
 * The wordmark is Appearance → Header's; the WhatsApp number is
 * SupportContact's; the profiles are the social_* settings (Store → Search
 * appearance) behind SafeUrl; the Help column is the footer menu when one
 * exists; the payment chips are PaymentChips' 'footer' row; the copyright is
 * the store name. Settings exist here ONLY for what the shop did not have: the
 * help strip's three lines, the two addresses, the big name, and the design
 * switch itself.
 *
 * ── NO WORD "RETURN" ────────────────────────────────────────────────────────
 *
 * helpLinks() drops any footer-menu row whose label or address mentions a
 * return or a refund, and the shipped defaults carry none. The old footer's
 * "Returns Information" link (/refund_returns/) is not drawn by the new design.
 * The owner's own link lists (Lane HF) go through the same BANNED test.
 *
 * ── LANE HF, 4 OCTOBER: THE OWNER'S REWORK AND "CONTROL FOR COMPLETE FOOTER" ─
 *
 * "in mobile footer, remove the top logo, and center the social media icons,
 * and description text. and on third column will be Account and related links
 * to access their account, orders etc. also center the support text. i don't
 * like the green, i want to use our color, and it will continue shade within
 * the our color range. also the bottom should be with effects something like
 * shiny bar going from left to right and on arabic right to left, also give
 * control for complete footer, for desktop and mobile."
 *
 * He asked for each of these, so each SHIPS ON: the defaults below ARE the
 * brief (CLAUDE.md, "What every lane owes" — what he asked for is the shop's
 * new state). Four tabs were added on Appearance → Footer: "Site footer · link
 * columns", "· colours & effects", "· layout desktop" and "· layout mobile".
 * Everything the Blade prints from them comes out of presentation() and
 * columns() as constants, checked colours, clamped integers, option keys,
 * escaped text and scheme-checked addresses.
 */
final class SiteFooter
{
    /**
     * Stored in `settings` under this prefix, NOT in `module_settings`: the
     * settings map is already loaded on every storefront page, and reading the
     * module map as well cost /shop and the product page one more query each
     * (PageCostBudgetTest measured 19 → 20 and 22 → 23). SlimFooter stores the
     * same way for the same reason.
     */
    public const PREFIX = 'sitefooter_';

    public const MODULE = 'site_footer';

    /** What a footer-menu row may not mention, in the new design. */
    public const BANNED = '/return|refund/i';

    /** A whole-pixel slider, 0–80. */
    private const PAD = ['min' => 0, 'max' => 80, 'step' => 2, 'unit' => 'px'];

    /** The gap between the columns. */
    private const GAP = ['min' => 8, 'max' => 64, 'step' => 2, 'unit' => 'px'];

    private const HEAD_PX = ['min' => 14, 'max' => 32, 'step' => 1, 'unit' => 'px'];

    private const LINK_PX = ['min' => 11, 'max' => 18, 'step' => 1, 'unit' => 'px'];

    /** The big name, as a share of the size that fits it on one line. */
    private const NAME_SCALE = ['min' => 50, 'max' => 120, 'step' => 5, 'unit' => '%'];

    /** App row · install help (Lane IN): the inline hint ships; the sheet is the old way. */
    public const APP_HELP = ['inline' => 'Inline hint in the row (new)', 'sheet' => 'Pop-up sheet (old)'];

    /** Start / centre, for the brand block and the help strip. */
    private const ALIGN = ['start' => 'Lined up at the start (left in English, right in Arabic)', 'center' => 'Centred'];

    /**
     * The parts that can be shown or hidden per device, in the order the
     * layout tabs draw them. Each is `site_d_<part>` and `site_m_<part>` in
     * SCHEMA, and each hidden one is a `kft-xd-<part>` / `kft-xm-<part>` class
     * on the <footer> — a constant, never the stored value.
     *
     * Shipped hidden: `site_m_logo` — the owner, 4 October: "in mobile footer,
     * remove the top logo" — and `site_m_help_sub`, the line under the strip's
     * headline, which phones have never drawn (it was `display:none` in the
     * phone rules until it became a switch). Everything else ships showing.
     */
    public const PARTS = ['help', 'help_sub', 'logo', 'tag', 'soc', 'col1', 'col2', 'col3', 'visit', 'news', 'name', 'bot', 'pay'];

    /**
     * The app row's typing lines as shipped (Lane FB), built on the owner's own
     * words — order updates, restock alerts, coupons. No discount figure: the
     * shop sends offers through Growth & Marketing → Push Notifications, and
     * that is all "coupons" promises. The Arabic speaks to a woman.
     */
    public const APP_LINES_EN = "Order updates, straight to your phone.\nRestock alerts for your favourites.\nCoupons & offers, the moment they drop.\nYour glow, one tap away.";

    public const APP_LINES_AR = "تحديثات طلبكِ مباشرةً على هاتفكِ.\nتنبيهات عند عودة منتجاتكِ المفضّلة.\nكوبونات وعروض لحظة إطلاقها.\nإشراقتكِ على بُعد لمسة واحدة.";

    /** At most this many typing lines per language, each at most LINE_MAX characters. */
    public const MAX_LINES = 6;

    public const LINE_MAX = 60;

    /** How fast the strip and the big name drift: key => [strip s, name s]. */
    public const DRIFT = ['8' => [8, 7], '14' => [14, 12], '24' => [24, 20]];

    /** One sweep of the shine, in seconds; the key is the number. */
    public const SHEEN_SPEED = ['3' => 'Quick — every 3 seconds', '5' => 'Gentle — every 5 seconds', '8' => 'Slow — every 8 seconds'];

    public const SCHEMA = [
        'site_design' => ['type' => 'select', 'label' => 'Footer design', 'default' => 'bliss',
            'options' => [
                'bliss' => 'New — WhatsApp help strip and the big name (approved 3 October)',
                'classic' => 'Previous — the dark four-column footer',
            ],
            'help' => 'Every storefront page. Choose Previous to go back to the footer the shop had before.'],
        'site_motion' => ['type' => 'bool', 'label' => 'Colours drift slowly', 'default' => true,
            'help' => 'The strip and the big name move gently through their colours. Never for a visitor whose device asks for reduced motion.'],
        'site_help_on' => ['type' => 'bool', 'label' => 'Show the help strip', 'default' => true,
            'help' => 'Off: the strip is not drawn on any device. To hide it on one device only, use the two layout tabs.'],
        'site_help_title' => ['type' => 'text', 'label' => 'Strip headline', 'default' => '',
            'help' => 'Empty: “Find your perfect K-beauty match”.'],
        'site_help_chip' => ['type' => 'text', 'label' => 'The white chip', 'default' => '',
            'help' => 'Empty: “24/7 available”.'],
        'site_help_sub' => ['type' => 'text', 'label' => 'Line under the headline', 'default' => '',
            'help' => 'Empty: “Ask us anything about your skin, a product or your order — we reply on WhatsApp.” Shown on laptops; on phones only when “Site footer · layout mobile” says so.'],
        'site_track_on' => ['type' => 'bool', 'label' => '“Track my order” button in the strip', 'default' => true,
            'help' => 'Beside “Chat on WhatsApp”, which dials the WhatsApp number on Store → Settings.'],
        'site_addr_dubai' => ['type' => 'text', 'label' => 'Dubai address', 'default' => '',
            'help' => 'Empty: the Dubai line is not drawn. With both addresses empty, the whole “Visit us” block is left out.'],
        'site_addr_korea' => ['type' => 'text', 'label' => 'Korea address', 'default' => '',
            'help' => 'Empty: the Korea line is not drawn.'],
        'site_news_on' => ['type' => 'bool', 'label' => 'Offers sign-up box', 'default' => true,
            'help' => 'Signs the address up to the newsletter, the same list as the homepage form.'],
        'site_name_on' => ['type' => 'bool', 'label' => 'The big name at the bottom', 'default' => true, 'help' => ''],
        'site_name_text' => ['type' => 'text', 'label' => 'The big name', 'default' => 'K-Beauty Bliss',
            'help' => 'Centred, very large, in light colours that slowly change. Keep it short: it is sized to fit one line.'],

        /* ── The three link columns (Lane HF) ─────────────────────────────── */
        'site_col1_title' => ['type' => 'text', 'label' => 'First column · title', 'default' => '',
            'help' => 'Empty: “Shop”, in Arabic on the Arabic shop.'],
        'site_col1_links' => ['type' => 'textarea', 'label' => 'First column · links', 'default' => '',
            'rule' => [self::class, 'cleanLinks'],
            'help' => 'One link per line, written  Label | /address  — an address on this shop starting with /, or a full https:// address. Empty: New in, Best sellers, Brands, Super sale, #KBeautyBliss, Journal. A line that is not a safe address, or that mentions returns or refunds, is dropped.'],
        'site_col2_title' => ['type' => 'text', 'label' => 'Second column · title', 'default' => '',
            'help' => 'Empty: “Help”.'],
        'site_col2_links' => ['type' => 'textarea', 'label' => 'Second column · links', 'default' => '',
            'rule' => [self::class, 'cleanLinks'],
            'help' => 'Same format. Empty: the footer menu when one has been built, otherwise Track my order, Shipping & Delivery, FAQs, Contact us — then About us.'],
        'site_col3_title' => ['type' => 'text', 'label' => 'Third column · title', 'default' => '',
            'help' => 'Empty: “Account”.'],
        'site_col3_links' => ['type' => 'textarea', 'label' => 'Third column · links', 'default' => '',
            'rule' => [self::class, 'cleanLinks'],
            'help' => 'Same format. Empty: My account, My orders, Wishlist, Addresses. A shopper who is not signed in is asked to sign in first and then lands on the page.'],

        /* ── Colours & effects (Lane HF) ──────────────────────────────────── */
        /*
         * THE STRIP'S FOUR COLOURS. The owner, 4 October, after seeing the
         * first draft: "i need only our pinkish, red and orange and grey
         * combination effect. no any other colors." So the strip drifts pink →
         * deep pink → red → orange and back, every stop dark enough for its
         * white headline: the lowest contrast anywhere on the shipped gradient
         * is 3.63:1, at the pink (#E0567B, the shop's --pink).
         */
        'site_c_from' => ['type' => 'colour', 'label' => 'Help strip · colour 1', 'default' => '#E0567B',
            'help' => 'The strip drifts through its four colours and back. Shipped: the shop’s pink. Keep all four pink, red or orange, and dark enough for white writing.'],
        'site_c_2' => ['type' => 'colour', 'label' => 'Help strip · colour 2', 'default' => '#C13E63',
            'help' => 'Shipped: the shop’s deep pink.'],
        'site_c_3' => ['type' => 'colour', 'label' => 'Help strip · colour 3', 'default' => '#E23A4E',
            'help' => 'Shipped: the shop’s sale red.'],
        'site_c_to' => ['type' => 'colour', 'label' => 'Help strip · colour 4', 'default' => '#D9603B',
            'help' => 'Shipped: a warm orange, deep enough for the white headline.'],
        'site_c_bg' => ['type' => 'colour', 'label' => 'Footer background', 'default' => '#FFFFFF',
            'help' => 'Behind the columns and the bottom bar. The faint blush in the top corner stays.'],
        'site_c_text' => ['type' => 'colour', 'label' => 'Link and description text', 'default' => '#5E545A', 'help' => ''],
        'site_c_accent' => ['type' => 'colour', 'label' => 'Accent', 'default' => '#C13E63',
            'help' => 'Column titles, the social icons, the words on the white WhatsApp button and the chip.'],
        'site_drift_speed' => ['type' => 'select', 'label' => 'Drift speed', 'default' => '14',
            'options' => ['8' => 'Quicker — 8 seconds', '14' => 'Gentle — 14 seconds (as shipped)', '24' => 'Very slow — 24 seconds'],
            'help' => 'How long the strip takes to drift across its colours once.'],
        'site_name_tone' => ['type' => 'select', 'label' => 'The big name’s colours', 'default' => 'warm',
            'options' => ['warm' => 'Warm — light pink, rose, coral, peach and a soft grey', 'pink' => 'Pinks only'],
            'help' => 'Light and bright, drifting slowly. Both stay in the shop’s pink, red, orange and grey — the owner: “no any other colors”.'],
        /*
         * SHIPS OFF (Lane FT). The owner, 4 October: "also remove the effect
         * from the very last row of the footer. animation not from the logo."
         * The last row is the bottom bar (© · Privacy · Terms · payment marks),
         * and its effect was this shine sweeping across it. The big name's
         * slow colour drift and the help strip's drift are `site_motion` and
         * are untouched. The switch stays, so he can have it back.
         * Migration 2027_08_05_100100 turns a stored 'bar' off as well.
         */
        'site_sheen' => ['type' => 'select', 'label' => 'Effect on the last row (the shine)', 'default' => 'off',
            'options' => ['bar' => 'A shine sweeping across the last row', 'name' => 'A shine across the big name instead', 'off' => 'Off — the last row stays still'],
            'help' => 'A light that sweeps across, left to right in English and right to left in Arabic. Off, as the owner asked on 4 October; the big name keeps its slow colour drift either way. Never for a visitor whose device asks for reduced motion.'],
        'site_sheen_speed' => ['type' => 'select', 'label' => 'Shine speed', 'default' => '5',
            'options' => self::SHEEN_SPEED, 'help' => ''],

        /* ── The app row (Lane FB) ───────────────────────────────────────────── */
        /*
         * SHIPS ON. The owner, 6 October, choosing from docs/fa-preview/: "write
         * something, Get orders update, restock alerts & coupons … i want that
         * ladies must take more interest in it. the frosted glass design is fine,
         * but i don't want to cover the logo, it should downside the big logo,
         * also the icons i need colorful as official" — then "the app install
         * content need to change continues, like a writing styline … one line in
         * english, then one line in arabic, and so on." He asked for it, so it is
         * the shop's new state (CLAUDE.md rule 1, the 30 September reversal);
         * `site_app_on` is the way back. Never printed while App → Site App is
         * off, whatever this says.
         */
        'site_app_on' => ['type' => 'bool', 'label' => 'Show the app row', 'default' => true,
            'help' => 'A frosted-glass row under the big name, above the © line, with the Install button. Never shown inside the installed app, and never while App → Site App is off.'],
        'site_app_title' => ['type' => 'text', 'label' => 'App row · headline', 'default' => '',
            'help' => 'Empty: “Get the K-Beauty Bliss app” (Arabic on the Arabic shop, from Translation → Strings).'],
        'site_app_lines' => ['type' => 'textarea', 'label' => 'App row · typing lines, English', 'default' => self::APP_LINES_EN,
            'rule' => [self::class, 'cleanLines'],
            'help' => 'One line per row, up to 6, each up to 60 characters. They type themselves under the headline, one English line then one Arabic line, on both shops. Empty: the shipped lines.'],
        'site_app_lines_ar' => ['type' => 'textarea', 'label' => 'App row · typing lines, Arabic', 'default' => self::APP_LINES_AR,
            'rule' => [self::class, 'cleanLines'],
            'help' => 'Same, in Arabic. Typed right to left. Empty: the shipped lines.'],
        'site_app_button' => ['type' => 'text', 'label' => 'App row · button', 'default' => '',
            'help' => 'Empty: “Install” (Arabic on the Arabic shop). Android opens the browser’s own install box; everywhere else, see “App row · install help”.'],
        /*
         * Lane IN, 6 October. The owner: "the install app button is not giving
         * auto install, i don't want popup". SHIPS 'inline' (he asked, CLAUDE.md
         * rule 1): where the browser has no install offer to show, the tap
         * changes the row's own typing line into the one step left, with an
         * arrow at the browser's menu. 'sheet' is the way back to Lane FB's
         * pop-up sheets, which stay in the page's <template> either way.
         */
        'site_app_help' => ['type' => 'select', 'label' => 'App row · install help', 'default' => 'inline', 'options' => self::APP_HELP,
            'help' => 'Where the browser can install directly (Chrome and Edge on Android, once Chrome allows it), the button opens the browser’s own install box either way. Otherwise — iPhone, iPad, Samsung Internet, Firefox, or Chrome before it is ready — “Inline hint” changes the row’s line to the one step left, with an arrow pointing at the browser’s button; “Pop-up sheet” opens the old two-step sheet.'],
        'site_d_app_laptop' => ['type' => 'bool', 'label' => 'Show the app row on laptops (with a QR code to open the shop on the phone)', 'default' => false,
            'help' => 'Off: hidden on laptops and desktops 1024px and wider (iPads still see it). On: the button opens a QR code of the shop, drawn by the shop itself.'],

        /* ── Layout · desktop (Lane HF) ─────────────────────────────────────── */
        'site_d_help' => ['type' => 'bool', 'label' => 'Show: The help strip', 'default' => true, 'help' => ''],
        'site_d_help_sub' => ['type' => 'bool', 'label' => 'Show: The line under the strip’s headline', 'default' => true, 'help' => ''],
        'site_d_logo' => ['type' => 'bool', 'label' => 'Show: The logo (the K-BeautyBliss wordmark)', 'default' => true, 'help' => ''],
        'site_d_tag' => ['type' => 'bool', 'label' => 'Show: The description under the logo', 'default' => true, 'help' => ''],
        'site_d_soc' => ['type' => 'bool', 'label' => 'Show: The social icons', 'default' => true, 'help' => ''],
        'site_d_col1' => ['type' => 'bool', 'label' => 'Show: First link column (Shop)', 'default' => true, 'help' => ''],
        'site_d_col2' => ['type' => 'bool', 'label' => 'Show: Second link column (Help)', 'default' => true, 'help' => ''],
        'site_d_col3' => ['type' => 'bool', 'label' => 'Show: Third link column (Account)', 'default' => true, 'help' => ''],
        'site_d_visit' => ['type' => 'bool', 'label' => 'Show: Visit us (the addresses)', 'default' => true, 'help' => ''],
        'site_d_news' => ['type' => 'bool', 'label' => 'Show: The offers sign-up box', 'default' => true, 'help' => ''],
        'site_d_name' => ['type' => 'bool', 'label' => 'Show: The big name', 'default' => true, 'help' => ''],
        'site_d_bot' => ['type' => 'bool', 'label' => 'Show: The bottom bar (© · Privacy · Terms · payment marks)', 'default' => true, 'help' => ''],
        'site_d_pay' => ['type' => 'bool', 'label' => 'Show: The payment marks in the bottom bar', 'default' => true, 'help' => ''],
        'site_d_brand_align' => ['type' => 'select', 'label' => 'The logo, description and social icons line up', 'default' => 'start', 'options' => self::ALIGN, 'help' => ''],
        'site_d_help_align' => ['type' => 'select', 'label' => 'The help strip’s words line up', 'default' => 'start', 'options' => self::ALIGN, 'help' => ''],
        'site_d_pt' => ['type' => 'range', 'label' => 'Space above the columns', 'default' => 30, 'options' => self::PAD, 'help' => ''],
        'site_d_pb' => ['type' => 'range', 'label' => 'Space below the big name', 'default' => 0, 'options' => self::PAD, 'help' => ''],
        'site_d_gap' => ['type' => 'range', 'label' => 'Space between the columns', 'default' => 28, 'options' => self::GAP, 'help' => ''],
        'site_d_fs_head' => ['type' => 'range', 'label' => 'Strip headline size', 'default' => 22, 'options' => self::HEAD_PX, 'help' => ''],
        'site_d_fs_link' => ['type' => 'range', 'label' => 'Link size', 'default' => 14, 'options' => self::LINK_PX, 'help' => ''],
        'site_d_fs_name' => ['type' => 'range', 'label' => 'The big name’s size', 'default' => 100, 'options' => self::NAME_SCALE, 'help' => '100% is the size that fits the name on one line.'],

        /* ── Layout · mobile (Lane HF) ─────────────────────────────────────── */
        'site_m_help' => ['type' => 'bool', 'label' => 'Show: The help strip', 'default' => true, 'help' => ''],
        'site_m_help_sub' => ['type' => 'bool', 'label' => 'Show: The line under the strip’s headline', 'default' => false, 'help' => 'Off on phones, as it has always been: it makes the strip much taller.'],
        'site_m_logo' => ['type' => 'bool', 'label' => 'Show: The logo (the K-BeautyBliss wordmark)', 'default' => false, 'help' => 'Off on phones, as the owner asked on 4 October.'],
        'site_m_tag' => ['type' => 'bool', 'label' => 'Show: The description under the logo', 'default' => true, 'help' => ''],
        'site_m_soc' => ['type' => 'bool', 'label' => 'Show: The social icons', 'default' => true, 'help' => ''],
        'site_m_col1' => ['type' => 'bool', 'label' => 'Show: First link column (Shop)', 'default' => true, 'help' => ''],
        'site_m_col2' => ['type' => 'bool', 'label' => 'Show: Second link column (Help)', 'default' => true, 'help' => ''],
        'site_m_col3' => ['type' => 'bool', 'label' => 'Show: Third link column (Account)', 'default' => true, 'help' => ''],
        'site_m_visit' => ['type' => 'bool', 'label' => 'Show: Visit us (the addresses)', 'default' => true, 'help' => ''],
        'site_m_news' => ['type' => 'bool', 'label' => 'Show: The offers sign-up box', 'default' => true, 'help' => ''],
        'site_m_name' => ['type' => 'bool', 'label' => 'Show: The big name', 'default' => true, 'help' => ''],
        'site_m_bot' => ['type' => 'bool', 'label' => 'Show: The bottom bar (© · Privacy · Terms · payment marks)', 'default' => true, 'help' => ''],
        'site_m_pay' => ['type' => 'bool', 'label' => 'Show: The payment marks in the bottom bar', 'default' => true, 'help' => ''],
        'site_m_brand_align' => ['type' => 'select', 'label' => 'The logo, description and social icons line up', 'default' => 'center', 'options' => self::ALIGN, 'help' => 'Centred on phones, as the owner asked on 4 October.'],
        'site_m_help_align' => ['type' => 'select', 'label' => 'The help strip’s words line up', 'default' => 'center', 'options' => self::ALIGN, 'help' => 'Centred on phones, as the owner asked on 4 October.'],
        'site_m_pt' => ['type' => 'range', 'label' => 'Space above the columns', 'default' => 20, 'options' => self::PAD, 'help' => ''],
        'site_m_pb' => ['type' => 'range', 'label' => 'Space below the big name', 'default' => 0, 'options' => self::PAD, 'help' => ''],
        'site_m_gap' => ['type' => 'range', 'label' => 'Space between the columns', 'default' => 12, 'options' => self::GAP, 'help' => ''],
        'site_m_fs_head' => ['type' => 'range', 'label' => 'Strip headline size', 'default' => 17, 'options' => self::HEAD_PX, 'help' => ''],
        'site_m_fs_link' => ['type' => 'range', 'label' => 'Link size', 'default' => 13, 'options' => self::LINK_PX, 'help' => ''],
        'site_m_fs_name' => ['type' => 'range', 'label' => 'The big name’s size', 'default' => 100, 'options' => self::NAME_SCALE, 'help' => '100% is the size that fits the name on one line.'],
    ];

    public const TABS = [
        'site' => ['Site footer · design', 'The footer at the bottom of every storefront page. The new design is on, as the owner asked; Previous puts the old footer back.',
            ['site_design']],
        'site_help' => ['Site footer · help strip', 'The pink strip across the top of the footer.',
            ['site_help_on', 'site_help_title', 'site_help_chip', 'site_help_sub', 'site_track_on']],
        'site_visit' => ['Site footer · Visit us & name', 'The addresses, the offers box and the big name. An empty address is not drawn — nothing is made up.',
            ['site_addr_dubai', 'site_addr_korea', 'site_news_on', 'site_name_on', 'site_name_text']],
        'site_cols' => ['Site footer · link columns', 'The three columns of links, on laptops and phones. Leave a box empty for the shipped titles and links, which the Arabic shop shows in Arabic.',
            ['site_col1_title', 'site_col1_links', 'site_col2_title', 'site_col2_links', 'site_col3_title', 'site_col3_links']],
        'site_fx' => ['Site footer · colours & effects', 'The strip’s colours and its drift, the footer’s colours, and the shine across the bottom.',
            ['site_c_from', 'site_c_2', 'site_c_3', 'site_c_to', 'site_c_bg', 'site_c_text', 'site_c_accent', 'site_motion', 'site_drift_speed', 'site_name_tone', 'site_sheen', 'site_sheen_speed']],
        'site_d' => ['Site footer · layout desktop', 'Laptops and anything wider than 900px: what shows, how it lines up, the spacing and the type sizes.',
            self::DEVICE_KEYS_D],
        'site_m' => ['Site footer · layout mobile', 'Phones (900px and narrower): what shows, how it lines up, the spacing and the type sizes.',
            self::DEVICE_KEYS_M],
        'site_app' => ['Site footer · app row', 'The frosted-glass “get the app” row under the big name.',
            ['site_app_on', 'site_app_title', 'site_app_lines', 'site_app_lines_ar', 'site_app_button', 'site_app_help', 'site_d_app_laptop']],
    ];

    /** The layout tabs' keys, in the order they draw. */
    private const DEVICE_KEYS_D = [
        'site_d_help', 'site_d_help_sub', 'site_d_logo', 'site_d_tag', 'site_d_soc', 'site_d_col1', 'site_d_col2', 'site_d_col3',
        'site_d_visit', 'site_d_news', 'site_d_name', 'site_d_bot', 'site_d_pay',
        'site_d_brand_align', 'site_d_help_align', 'site_d_pt', 'site_d_pb', 'site_d_gap', 'site_d_fs_head', 'site_d_fs_link', 'site_d_fs_name',
    ];

    private const DEVICE_KEYS_M = [
        'site_m_help', 'site_m_help_sub', 'site_m_logo', 'site_m_tag', 'site_m_soc', 'site_m_col1', 'site_m_col2', 'site_m_col3',
        'site_m_visit', 'site_m_news', 'site_m_name', 'site_m_bot', 'site_m_pay',
        'site_m_brand_align', 'site_m_help_align', 'site_m_pt', 'site_m_pb', 'site_m_gap', 'site_m_fs_head', 'site_m_fs_link', 'site_m_fs_name',
    ];

    public const POLICY = [
        'max' => 160,
        'blank' => 'keep',
        'invalid' => 'default',
        'clamp' => true,
        'bool' => 'cast',
        // (Lane HF) A colour is `#` and six hex digits or the default: `#abc`
        // is widened to `#AABBCC`, a value with no `#` is refused. Nothing
        // shorter or longer can reach the style attribute it is printed into.
        'hex' => 'expand',
    ];

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $out = [];

        foreach (ModuleSchema::normalise(self::SCHEMA, self::POLICY) as $key => $field) {
            $saved = $this->settings->get(self::PREFIX.$key, null);
            $out[$key] = $saved === null ? $field['default'] : ModuleSchema::cast($field, $saved);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{written: list<string>, rejected: array<string, string>}
     */
    public function save(array $values): array
    {
        $fields = ModuleSchema::normalise(self::SCHEMA, self::POLICY);
        $written = [];
        $rejected = [];

        foreach ($values as $key => $value) {
            if (! isset($fields[$key])) {
                continue;
            }

            $cast = ModuleSchema::cast($fields[$key], $value);

            if ($cast === null) {
                $rejected[$key] = (string) $fields[$key]['label'];

                continue;
            }

            $this->settings->set(self::PREFIX.$key, $cast);
            $written[] = $key;
        }

        return ['written' => $written, 'rejected' => $rejected];
    }

    /** 'bliss' or 'classic' — one of the select's own keys, never the stored string. */
    public function design(): string
    {
        return ($this->all()['site_design'] ?? 'bliss') === 'classic' ? 'classic' : 'bliss';
    }

    /**
     * The Help column: the footer menu's rows when the owner has built one, the
     * shop's real help pages otherwise — minus anything about returns.
     *
     * @param  iterable<array<string, mixed>>  $nav
     * @return list<array{label: string, url: string}>
     */
    public static function helpLinks(iterable $nav): array
    {
        $out = [];

        foreach ($nav as $link) {
            $label = trim((string) ($link['label'] ?? ''));
            $url = (string) ($link['url'] ?? '/');

            if ($label === '' || preg_match(self::BANNED, $label.' '.$url) === 1) {
                continue;
            }

            $out[] = ['label' => $label, 'url' => Url::to($url)];
        }

        if ($out !== []) {
            return $out;
        }

        return [
            ['label' => __('store.footer.link_track_order'), 'url' => Url::to('/track-my-order/')],
            ['label' => __('store.footer.link_delivery'), 'url' => Url::to('/delivery/')],
            ['label' => __('store.footer.link_faqs'), 'url' => Url::to('/faqs/')],
            ['label' => __('store.footer.link_contact'), 'url' => Url::to('/contact-us/')],
        ];
    }

    /**
     * The rule behind the two typing-line boxes (Lane FB): plain text, one line
     * per row, empty rows dropped, at most MAX_LINES of at most LINE_MAX
     * characters. Tags are stripped here and Blade escapes again where the
     * lines are printed (in a data attribute and a visually-hidden span), and
     * the script only ever sets them as textContent. Never null: an emptied
     * box is '' and means the shipped lines.
     */
    public static function cleanLines(mixed $raw, array $field = []): string
    {
        return implode("\n", self::lines($raw));
    }

    /** @return list<string> */
    public static function lines(mixed $raw): array
    {
        if (! is_string($raw)) {
            return [];
        }

        $out = [];

        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim(preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', strip_tags($line)) ?? '');

            if ($line === '') {
                continue;
            }

            $out[] = mb_substr($line, 0, self::LINE_MAX);

            if (count($out) === self::MAX_LINES) {
                break;
            }
        }

        return $out;
    }

    /**
     * Everything the app row prints, or null when it is not printed at all:
     * switched off here, or App → Site App off (no manifest, no script, so
     * nothing could be installed and the button would do nothing).
     *
     * The typing lines alternate languages — the owner: "one line in english,
     * then one line in arabic, and so on" — starting with the page's own, so
     * the line printed in the HTML (the first) is in the shopper's language.
     *
     * @return array<string, mixed>|null
     */
    public function app(array $c): ?array
    {
        if (! (bool) ($c['site_app_on'] ?? true) || ! app(SiteApp::class)->on()) {
            return null;
        }

        $text = static fn (string $key): string => trim(\App\Support\RichText::toText((string) ($c[$key] ?? '')));
        $en = self::lines($c['site_app_lines'] ?? '') ?: self::lines(self::APP_LINES_EN);
        $ar = self::lines($c['site_app_lines_ar'] ?? '') ?: self::lines(self::APP_LINES_AR);
        $arabic = \App\Support\Locale::current() === 'ar';
        [$first, $second] = $arabic ? [$ar, $en] : [$en, $ar];

        $seq = [];
        for ($i = 0, $n = max(count($first), count($second)); $i < $n; $i++) {
            foreach ([[$first, $arabic], [$second, ! $arabic]] as [$list, $isAr]) {
                if (isset($list[$i])) {
                    $seq[] = [$isAr ? 1 : 0, $list[$i]];
                }
            }
        }

        $laptop = (bool) ($c['site_d_app_laptop'] ?? false);

        return [
            'title' => $text('site_app_title') !== '' ? $text('site_app_title') : __('store.footer.app_title'),
            'button' => $text('site_app_button') !== '' ? $text('site_app_button') : __('store.footer.app_button'),
            'first' => $seq[0],
            'own' => $first,
            'seq' => json_encode($seq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'laptop' => $laptop,
            // Drawn here only when the laptop switch is on, and only for the
            // shop's front page in this language: a short address, a small code
            // (version 2–3), about a millisecond and a half.
            'qr' => $laptop ? \App\Support\QrCode::svg(Url::external('/'), __('store.footer.app_qr_title')) : '',
            // App → Site App → App update (Lane UA): '' until the owner first
            // publishes one, and then the row's data-kfa-up. Read only inside
            // the installed app (site-app.js); a browser tab is unchanged.
            'update' => app(SiteAppUpdate::class)->page(),
            // Lane IN: 'sheet' only when the owner chose the old pop-up; the
            // script's default is the inline hint, so the default prints nothing.
            'sheet' => ($c['site_app_help'] ?? 'inline') === 'sheet',
            // The inline hints' words (Lane IN), in the page's language, as one
            // JSON attribute on the row's <template>: site-app.js reads it only
            // on a tap that has no install offer to show.
            'hints' => json_encode([
                'and' => __('store.footer.app_hint_and'), 'sam' => __('store.footer.app_hint_sam'),
                'ios' => __('store.footer.app_hint_ios'), 'ios26' => __('store.footer.app_hint_ios26'),
                'top' => __('store.footer.app_hint_top'), 'inapp' => __('store.footer.app_hint_inapp'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Is the footer's app row switched on (Lane IN)? Two keys of the settings
     * snapshot the page already holds — not all(), which walks the whole
     * schema — for the head's early install-offer catcher. App -> Site App
     * being on is the caller's half (partials/site-app-head).
     */
    public function appRowOn(): bool
    {
        $on = $this->settings->get(self::PREFIX.'site_app_on', null);
        $design = $this->settings->get(self::PREFIX.'site_design', null);

        $on = $on === null ? true : (is_bool($on) ? $on : (filter_var($on, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true));

        return $on && $design !== 'classic';
    }

    /** At most this many links in one column; the rest of a pasted list is dropped. */
    public const MAX_LINKS = 12;

    /**
     * The rule behind the three "links" boxes: one `Label | /address` per line,
     * cleaned to lines that are safe to print. (Lane HF)
     *
     * It REPLACES the text cast (ModuleSchema::rule()), so it is the whole
     * boundary for those fields, and it runs again on every read — all() casts
     * the stored value — so a row written some other way is cleaned too.
     *
     *  - The address is this shop's own (`/` then anything but a second `/` or
     *    a backslash, which a browser would read as another host) or a full
     *    `https://` address with a host. Nothing else: no `javascript:`, no
     *    `http:`, no `//evil.example`, no `mailto:`.
     *  - A line whose label or address mentions a return or a refund is
     *    dropped, exactly as the footer menu's rows are (BANNED) — the shop
     *    offers no returns and the owner asked for no such word.
     *  - The label is plain text: tags are stripped, it is capped at 60
     *    characters, and Blade escapes it again where it is printed.
     *
     * Never null, so the box can never be refused: an emptied box is '' and
     * means "the shipped links".
     */
    public static function cleanLinks(mixed $raw, array $field = []): string
    {
        return implode("\n", array_map(
            static fn (array $l): string => $l['label'].' | '.$l['url'],
            self::parseLinks($raw),
        ));
    }

    /**
     * @return list<array{label: string, url: string}> the address as typed (not yet through Url::to)
     */
    public static function parseLinks(mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $out = [];

        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $cut = strrpos($line, '|');

            if ($cut === false) {
                continue;
            }

            $label = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags(substr($line, 0, $cut))) ?? ''), 0, 60);
            $url = trim(substr($line, $cut + 1));

            if ($label === '' || ! self::safeAddress($url) || preg_match(self::BANNED, $label.' '.$url) === 1) {
                continue;
            }

            $out[] = ['label' => $label, 'url' => $url];

            if (count($out) === self::MAX_LINKS) {
                break;
            }
        }

        return $out;
    }

    /** This shop's own path, or an https:// address with a host. Nothing else. */
    public static function safeAddress(string $url): bool
    {
        if (strlen($url) > 300 || preg_match('/[\s<>"\'`\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        if (preg_match('#^/(?![/\\\\])#', $url) === 1 || $url === '/') {
            return true;
        }

        return preg_match('#^https://[^/\\\\?\#@]+#i', $url) === 1
            && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    /**
     * The three link columns: [id, title, links]. An empty title or list is
     * the shipped one, through __() so the Arabic shop prints its own.
     *
     * THE THIRD COLUMN IS "ACCOUNT" (the owner, 4 October: "on third column
     * will be Account and related links to access their account, orders
     * etc."). Every link is a route that exists: /my-account/ answers a guest
     * with the sign-in form, and the three behind `auth:customer` send a guest
     * to it through GuestRedirect with the address remembered. The "Discover"
     * column it replaced kept four links, and none of them is lost: About us
     * is the last Help link, #KBeautyBliss and Journal the last two Shop links,
     * and My account is here.
     *
     * @param  iterable<array<string, mixed>>  $nav
     * @return list<array{id: string, part: string, title: string, links: list<array{label: string, url: string}>}>
     */
    public function columns(array $c, iterable $nav, bool $brands): array
    {
        $own = static function (string $key) use ($c): array {
            return array_map(
                static fn (array $l): array => ['label' => $l['label'], 'url' => str_starts_with($l['url'], '/') ? Url::to($l['url']) : $l['url']],
                self::parseLinks($c[$key] ?? ''),
            );
        };
        $title = static function (string $key, string $fallback) use ($c): string {
            $t = trim(\App\Support\RichText::toText((string) ($c[$key] ?? '')));

            return $t !== '' ? $t : __($fallback);
        };

        $shop = $own('site_col1_links');

        if ($shop === []) {
            $shop = array_values(array_filter([
                ['label' => __('store.footer.link_new_in'), 'url' => Url::to('/new-in/')],
                ['label' => __('store.footer.link_best_sellers'), 'url' => Url::to('/best-sellers/')],
                // The Brands link follows the brands module, exactly as the
                // header's does (NavigationService): off, /brands/ is a 404 and
                // no chrome may link to it.
                $brands ? ['label' => __('store.footer.link_brands'), 'url' => Url::to('/brands/')] : null,
                ['label' => __('store.footer.link_super_sale'), 'url' => Url::to('/super-sale/')],
                ['label' => __('store.footer.link_spotted'), 'url' => Url::to(SpottedSettings::URL)],
                ['label' => __('store.footer.link_journal'), 'url' => Url::to('/blog/')],
            ]));
        }

        $help = $own('site_col2_links');

        if ($help === []) {
            $help = self::helpLinks($nav);
            $about = Url::to('/about/');

            if (! in_array($about, array_column($help, 'url'), true)) {
                $help[] = ['label' => __('store.footer.link_about'), 'url' => $about];
            }
        }

        $account = $own('site_col3_links');

        if ($account === []) {
            $account = [
                ['label' => __('store.footer.link_account_home'), 'url' => Url::to('/my-account/')],
                ['label' => __('store.footer.link_my_orders'), 'url' => Url::to('/my-account/orders/')],
                ['label' => __('store.footer.link_wishlist'), 'url' => Url::to('/my-wishlist/')],
                ['label' => __('store.footer.link_addresses'), 'url' => Url::to('/my-account/edit-address/')],
            ];
        }

        return [
            ['id' => 'shop', 'part' => 'col1', 'title' => $title('site_col1_title', 'store.footer.shop_heading'), 'links' => $shop],
            ['id' => 'help', 'part' => 'col2', 'title' => $title('site_col2_title', 'store.footer.help_heading'), 'links' => $help],
            ['id' => 'acct', 'part' => 'col3', 'title' => $title('site_col3_title', 'store.footer.account_title'), 'links' => $account],
        ];
    }

    /**
     * The <footer>'s classes and its custom properties. (Lane HF)
     *
     * EVERY BYTE IS A CONSTANT OR A CHECKED VALUE. Classes are literals chosen
     * by a stored bool or by a select's own key; the style attribute is
     * `--kft-*:` followed by a colour that ModuleSchema's colour cast has
     * already reduced to `#` and hex digits (and is checked again here), or an
     * integer clamped into its slider's range, or a number looked up in a
     * constant table by a select's key. Nothing typed in the console reaches
     * either attribute as text.
     *
     * @return array{classes: string, style: string, sheen: string}
     */
    public function presentation(array $c): array
    {
        $fields = ModuleSchema::normalised(self::class, self::SCHEMA, self::POLICY);
        $pick = static function (string $key) use ($c, $fields): string {
            $options = (array) ($fields[$key]['options'] ?? []);
            $v = (string) ($c[$key] ?? '');

            return array_key_exists($v, $options) ? $v : (string) $fields[$key]['default'];
        };
        $int = static function (string $key) use ($c, $fields): int {
            $o = (array) $fields[$key]['options'];

            return max((int) $o['min'], min((int) $o['max'], (int) ($c[$key] ?? $fields[$key]['default'])));
        };
        $hex = static function (string $key) use ($c, $fields): string {
            $v = (string) ($c[$key] ?? '');

            return preg_match('/^#[0-9A-Fa-f]{6}$/', $v) === 1 ? strtoupper($v) : (string) $fields[$key]['default'];
        };

        $sheen = $pick('site_sheen');
        $classes = ['kft'];

        if ((bool) ($c['site_motion'] ?? true)) {
            $classes[] = 'kft-motion';
        }

        if ($sheen !== 'off') {
            $classes[] = $sheen === 'name' ? 'kft-sheen-name' : 'kft-sheen-bar';
        }

        if ($pick('site_name_tone') === 'pink') {
            $classes[] = 'kft-name-pink';
        }

        foreach (['d', 'm'] as $dev) {
            foreach (self::PARTS as $part) {
                if (! (bool) ($c["site_{$dev}_{$part}"] ?? true)) {
                    $classes[] = "kft-x{$dev}-".str_replace('_', '-', $part);
                }
            }

            if ($pick("site_{$dev}_brand_align") === 'center') {
                $classes[] = "kft-bc-{$dev}";
            }

            if ($pick("site_{$dev}_help_align") === 'center') {
                $classes[] = "kft-hc-{$dev}";
            }
        }

        [$drift, $driftName] = self::DRIFT[$pick('site_drift_speed')] ?? self::DRIFT['14'];

        $style = [
            '--kft-from:'.$hex('site_c_from'),
            '--kft-c2:'.$hex('site_c_2'),
            '--kft-c3:'.$hex('site_c_3'),
            '--kft-to:'.$hex('site_c_to'),
            '--kft-bg:'.$hex('site_c_bg'),
            '--kft-text:'.$hex('site_c_text'),
            '--kft-accent:'.$hex('site_c_accent'),
            '--kft-dr:'.$drift.'s',
            '--kft-drn:'.$driftName.'s',
            '--kft-sh:'.(int) $pick('site_sheen_speed').'s',
        ];

        foreach (['d', 'm'] as $dev) {
            $style[] = "--kft-pt-{$dev}:".$int("site_{$dev}_pt").'px';
            $style[] = "--kft-pb-{$dev}:".$int("site_{$dev}_pb").'px';
            $style[] = "--kft-gap-{$dev}:".$int("site_{$dev}_gap").'px';
            $style[] = "--kft-fh-{$dev}:".$int("site_{$dev}_fs_head").'px';
            $style[] = "--kft-fl-{$dev}:".$int("site_{$dev}_fs_link").'px';
            $style[] = "--kft-fn-{$dev}:".$int("site_{$dev}_fs_name");
        }

        return ['classes' => implode(' ', $classes), 'style' => implode(';', $style), 'sheen' => $sheen];
    }

    /**
     * Everything the new footer prints, reduced to checked values.
     *
     * @param  iterable<array<string, mixed>>  $nav  the footer menu ($kbbFooterNav)
     * @return array<string, mixed>
     */
    public function view(iterable $nav): array
    {
        $c = $this->all();
        $header = app(HeaderSettings::class);
        $text = static fn (string $key): string => trim(\App\Support\RichText::toText((string) ($c[$key] ?? '')));

        $wa = SupportContact::whatsappDigits();

        /*
         * The owner's own profiles, through SafeUrl::href($u, '') exactly as the
         * old footer does: a refused address is '' and the icon is left out,
         * rather than '#', which would draw a control that goes nowhere. The
         * first three keep the shipped fallback the old footer had; YouTube has
         * none, so it appears only once he has entered one.
         */
        $s = app(SettingsService::class);
        $socials = array_values(array_filter([
            ['instagram', 'Instagram', SafeUrl::href((string) $s->get('social_instagram', 'https://www.instagram.com/kbeauty.bliss/'), '')],
            ['tiktok', 'TikTok', SafeUrl::href((string) $s->get('social_tiktok', 'https://www.tiktok.com/@kbeauty.bliss'), '')],
            ['facebook', 'Facebook', SafeUrl::href((string) $s->get('social_facebook', 'https://www.facebook.com/kbeautyblissuae'), '')],
            ['youtube', 'YouTube', SafeUrl::href((string) $s->get('social_youtube', ''), '')],
        ], static fn (array $row): bool => $row[2] !== '' && $row[2] !== '#'));

        $look = $this->presentation($c);
        $name = (bool) ($c['site_name_on'] ?? true) ? $text('site_name_text') : '';

        return [
            'classes' => $look['classes'],
            'style' => $look['style'],
            'logo' => [(string) $header->get('logo_text'), (string) $header->get('logo_accent')],
            'help_on' => (bool) ($c['site_help_on'] ?? true),
            'help_title' => $text('site_help_title') !== '' ? $text('site_help_title') : __('store.footer.help_headline'),
            'help_chip' => $text('site_help_chip') !== '' ? $text('site_help_chip') : __('store.footer.help_chip'),
            'help_sub' => $text('site_help_sub') !== '' ? $text('site_help_sub') : __('store.footer.help_sub'),
            'wa' => $wa !== '' ? 'https://wa.me/'.$wa : '',
            'track' => (bool) ($c['site_track_on'] ?? true) ? Url::to('/track-my-order/') : '',
            'socials' => $socials,
            'columns' => $this->columns($c, $nav, $s->moduleEnabled('brands', true)),
            'dubai' => $text('site_addr_dubai'),
            'korea' => $text('site_addr_korea'),
            'news' => (bool) ($c['site_news_on'] ?? true),
            'name' => $name,
            // The shine on the big name is a copy of the name laid over it
            // (kbb.css, .kft-sheen-name), so the text travels in an attribute —
            // escaped by Blade — only when that is where the shine is.
            'name_sheen' => $look['sheen'] === 'name' && $name !== '',
            // The app row (Lane FB): null when it is not printed.
            'app' => $this->app($c),
        ];
    }
}
