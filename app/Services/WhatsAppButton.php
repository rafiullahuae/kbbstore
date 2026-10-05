<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Locale;
use App\Support\SupportContact;

/**
 * Appearance → WhatsApp button — the floating chat button.          (Lane WA)
 *
 * ── WHAT THE OWNER ASKED FOR ────────────────────────────────────────────────
 *
 * "also i need a floating whatsapp icon with outer layers animation type
 *  circle continues. on the bottom right side. give full control of changing
 *  positioning and space from all four sides. give me multiple designs to
 *  choose from" — then, on the previews (docs/whatsapp-float-preview/):
 *  "orbit is fine, but i want to mention some avatars instead of dots like 2
 *  females, 3 males etc. and also have full control to adjust the overall size
 *  with just drag size bar. and also i need Chat with us capsule, but with
 *  'available 24/7' text", "start from 30px overall size", and the two lines
 *  the shopper sees and sends: "Hi there 👋 Welcome to K-Beauty Bliss / Need
 *  help choosing? Our beauty team is on WhatsApp, 24/7."
 *
 * He picked G · "Orbit team" off the preview, so G is the DEFAULT and the six
 * others (A–F, the same file) stay selectable. And it SHIPS ON: CLAUDE.md rule
 * 1 as it reads since 30 September — "a thing he asked for is the shop's new
 * state, not a switch he has to go and find". Every control is built anyway so
 * that he can take it back.
 *
 * ── WHAT A STOREFRONT PAGE PAYS FOR IT ──────────────────────────────────────
 *
 *   - ZERO QUERIES. Every value is a `settings` row read through
 *     SettingsService, whose autoload map and per-request snapshot every page
 *     of the shop already pays for; the number comes from SupportContact,
 *     which reads the same map. StorefrontQueryBudgetTest is unchanged.
 *   - ONE <style>, holding the base rules and the rules of the ONE design in
 *     use — not all seven. css() below.
 *   - NO PICTURES. The faces are two inline SVG <symbol>s, drawn once and
 *     reused by <use>; each face's colours come from the stylesheet.
 *   - NO LAYOUT JAVASCRIPT. Position, size and the orbit are CSS: the size is
 *     one custom property (--k) multiplying a transform, the offsets are
 *     custom properties switched by one media query. The only script is the
 *     welcome bubble's "show once" — a localStorage read in a try/catch, and
 *     nothing at all when the bubble is off.
 *
 * ── RULE 5, AND WHERE EACH HALF OF IT IS ENFORCED ───────────────────────────
 *
 *   - every select stores one of its own options: ModuleSchema::cast(), with
 *     `invalid => reject`, so a bogus design is refused and REPORTED;
 *   - every number is clamped: `size` by the range arm, each offset by
 *     cleanOffset(), and both again at render;
 *   - the link is scheme-checked TWICE, when it is saved (cleanLink) and again
 *     when it is printed (link()), so a row written behind the screen's back
 *     cannot become an `href` either;
 *   - nothing from a setting is printed unescaped. The partial prints the CSS,
 *     the icon and the face symbols raw, and all three are CONSTANTS of this
 *     class; every value that came from the owner goes through `{{ }}`.
 */
class WhatsAppButton
{
    /** The seven designs, in the order of the preview page. */
    public const DESIGNS = [
        'A' => 'A · Pulse',
        'B' => 'B · Brand rings',
        'C' => 'C · Ripple',
        'D' => 'D · Halo',
        'E' => 'E · Sonar + label',
        'F' => 'F · Orbit',
        'G' => 'G · Orbit team (faces)',
    ];

    /** How long one lap of the orbit takes, by the three words the owner sees. */
    public const SPEEDS = ['slow' => '20s', 'calm' => '14s', 'lively' => '8s'];

    /** The four sides, and the two devices each one is set for. */
    public const SIDES = ['top' => 'Top', 'right' => 'Right', 'bottom' => 'Bottom', 'left' => 'Left'];

    public const DEVICES = ['m' => 'Phone', 'd' => 'Desktop'];

    /**
     * Where the phone layout ends. 900px is the shop's own: kbb.css reveals the
     * burger, the menu sheet and the tab bar at `max-width: 900px`, and the
     * product page's phone-only sticky bar hides at 901px. One number, so "on
     * a phone" means the same thing for this button as for everything else.
     */
    public const PHONE_MAX = 900;

    /** The highest offset a box accepts; the screen's boxes say the same. */
    public const OFFSET_MAX = 400;

    /** Size bounds the owner set: "start from 30px", the preview went to 110. */
    public const SIZE_MIN = 30;

    public const SIZE_MAX = 110;

    /** The size every number in the stylesheet is written at; --k = size / 60. */
    private const BASE = 60;

    /*
     * ── THE SIDE TAB: CART & CHECKOUT, PHONES ONLY (Lane WS) ────────────────
     *
     * The owner, with a picture of his phone's cart and the round button
     * sitting on the line items: "on cart and checkout mobile pages, i want the
     * whatsapp floating to move to the left side of the screen, stiky type
     * vertical bar, having 24/7 Support + whatsapp animated icon. must be size
     * adjustable of overall bar with size dragger bar, must be unique with
     * background colors grandient changing, but with light colors, as the text
     * will be black and icon will be green."
     *
     * WHERE IT APPEARS. Only on a page whose template declares the section
     * TAB_SECTION — store/cart and store/checkout, nothing else — and only below
     * PHONE_MAX, where it replaces the round button. On a laptop the cart and
     * the checkout keep the round button; on every other page view() returns
     * exactly what it returned before, so they gain not one byte.
     *
     * NOTHING UNDERNEATH IT. The tab is fixed to the left edge, so on those two
     * pages the content moves over by the tab's width (TAB_PHONE). The width is the one number a stylesheet cannot know, so
     * the partial sets it as --kbtw from an integer, through the escaping echo.
     * Vertically it lives in a fixed column that starts below the sticky header
     * (TAB_TOP) and ends above the cart's docked checkout bar and the
     * checkout's Place order bar (TAB_BOTTOM); the owner's position splits the
     * free space of that column, so no setting and no screen height can put the
     * tab on either bar — and no script measures anything to get there.
     */

    /** Section name the cart and checkout templates declare. */
    public const TAB_SECTION = 'kbb-wa-tab';

    /** The tab's width, in px: the size bar's ends and its shipped value. */
    public const TAB_MIN = 22;

    public const TAB_MAX = 44;

    public const TAB_BASE = 26;

    /** Below the sticky header (the cart's, with its search, is 127px at 390). */
    public const TAB_TOP = 140;

    /** Above the cart's docked bar (102px at 390) and the checkout's (70px). */
    public const TAB_BOTTOM = 112;

    public const TAB_LABEL_MAX = 24;

    /**
     * The light palettes, three stops each. Black text stays above 15:1 on
     * every stop; the icon sits on a white disc, so its green is measured
     * against white. WhatsAppButtonTabTest computes both.
     */
    public const TAB_PALETTES = [
        'blush' => ['#FFE1EA', '#FFF0D9', '#E2F6EA'],
        'sky' => ['#D9F5E6', '#DDF3FF', '#ECE4FF'],
        'lilac' => ['#ECE4FF', '#FFE6F2', '#FFF0D9'],
        'lemon' => ['#FFF6C7', '#E2F6EA', '#DDF3FF'],
    ];

    /** The text and the icon's green; contrast is pinned by the test. */
    public const TAB_INK = '#111111';

    public const TAB_GREEN = '#0B7A3E';

    /**
     * The darkest a custom colour may be, as WCAG relative luminance. At 0.6
     * the black text is still above 11:1, so "light colours only" is a rule,
     * not a hope.
     */
    public const TAB_LIGHT_MIN = 0.6;

    /**
     * The tab's rules. CONSTANT — printed raw by the partial, and sent to the
     * admin preview as they are. Hidden (`display:none` on the column) until
     * TAB_PHONE switches it on inside the phone media query.
     *
     * GPU-cheap motion only: the gradient is a pseudo-element three times the
     * tab's height sliding by `transform`, and the icon's ring is CSS_BASE's
     * kbwP (transform + opacity). Neither repaints the page.
     */
    public const TAB_CSS = '.kbt-z{position:fixed;z-index:85;left:0;top:'.self::TAB_TOP.'px;bottom:'.self::TAB_BOTTOM.'px;display:none;flex-direction:column;pointer-events:none}'
        .'.kbt-z::before{content:"";flex:var(--y,50) 1 0}'
        .'.kbt-z::after{content:"";flex:calc(100 - var(--y,50)) 1 0}'
        .'.kbt-z.kbt-rt{left:auto;right:0}'
        .'.kbt{position:relative;isolation:isolate;overflow:hidden;flex:none;display:flex;flex-direction:column;align-items:center;gap:calc(7px*var(--q,1));width:calc(26px*var(--q,1));padding:calc(6px*var(--q,1)) 0 calc(10px*var(--q,1));box-sizing:border-box;border:1px solid rgba(42,34,40,.08);border-left:0;border-radius:0 calc(13px*var(--q,1)) calc(13px*var(--q,1)) 0;background:var(--c1);box-shadow:0 6px 18px rgba(42,34,40,.14);color:'.self::TAB_INK.';text-decoration:none;font-size:16px;pointer-events:auto;-webkit-tap-highlight-color:transparent}'
        .'.kbt-rt .kbt{border-left:1px solid rgba(42,34,40,.08);border-right:0;border-radius:calc(13px*var(--q,1)) 0 0 calc(13px*var(--q,1))}'
        .'.kbt::before{content:"";position:absolute;z-index:-1;left:0;right:0;top:0;height:300%;background:linear-gradient(180deg,var(--c1),var(--c2),var(--c3),var(--c1));animation:kbtG 9s ease-in-out infinite alternate}'
        .'.kbt:focus-visible{outline:3px solid #E0567B;outline-offset:2px}'
        .'.kbt-i{position:relative;flex:none;display:grid;place-items:center;width:calc(20px*var(--q,1));height:calc(20px*var(--q,1));border-radius:50%;background:#fff;color:'.self::TAB_GREEN.';box-shadow:0 1px 4px rgba(11,122,62,.28)}'
        .'.kbt-i::before{content:"";position:absolute;z-index:-1;inset:0;border-radius:50%;background:#25D366;animation:kbwP 2.4s ease-out infinite}'
        .'.kbt .kbw-i{width:64%;height:64%}'
        .'.kbt-l{writing-mode:vertical-rl;transform:rotate(180deg);font-size:calc(11px*var(--q,1));font-weight:700;line-height:1;letter-spacing:.04em;white-space:nowrap}'
        .'.kbt-still .kbt::before,.kbt-still .kbt-i::before{animation:none}'
        .'.kbt-still .kbt-i::before{opacity:0}'
        .'@keyframes kbtG{to{transform:translateY(-66.6667%)}}'
        .'@media (prefers-reduced-motion:reduce){.kbt::before,.kbt-i::before{animation:none!important}.kbt-i::before{opacity:0}}'
        .'@media print{.kbt-z{display:none!important}}';

    /**
     * The phone half, printed only on the two pages: the tab appears, the round
     * button goes, and the page's content moves over by the tab's width — on
     * the side the tab is on — so nothing scrolls underneath it.
     *
     * #content and not the containers inside it, because those disagree: the
     * cart's column sits 14px in, its "Recommended" rail 9px, the checkout's
     * grid 20px, its thumbnail strip 17px and its slim footer 20px. Moving the
     * one element that holds them all keeps every one of those gutters as the
     * gap between the tab and what is beside it, including blocks that are not
     * on the page today. The checkout's sticky header lives inside #content
     * too and sits above the tab's column (TAB_TOP), so it is pulled back out
     * to full width. Fixed bars and sheets ignore the padding. `%1$s` is
     * `left` or `right`, from a ternary in view().
     */
    private const TAB_PHONE = '@media (max-width:'.self::PHONE_MAX.'px){.kbt-z{display:flex}.kbw{display:none}'
        .'#content{padding-%1$s:var(--kbtw)}.kbb-checkout .co-head{margin-%1$s:calc(var(--kbtw)*-1)}}';

    /**
     * The tab FLOATING over the page, nothing moved (2.60.390, the owner: "i
     * don't want a dedicated left side space, please remove the space and the
     * support vatical bar will float on the left side. do this on mobile
     * checckout page too"). How it ships; TAB_PHONE, the reserved space, is
     * "Make room beside the tab".
     */
    private const TAB_PHONE_FLOAT = '@media (max-width:'.self::PHONE_MAX.'px){.kbt-z{display:flex}.kbw{display:none}}';

    /**
     * The squeezed cart's "Recommended" rail is FULL BLEED by `calc(50% -
     * 50vw)` and `100vw`, which measure from the viewport and not from
     * #content — so once #content moves over, the rail would start 13px from
     * the edge, under the tab, and run off the other side. Half the tab's
     * width on each side puts it back edge to edge of the space beside the
     * tab. Printed only while the squeezed cart is on, like LIFT_CART: on the
     * classic shop no page names the squeezed furniture at all.
     */
    private const TAB_SQUEEZE = '@media (max-width:'.self::PHONE_MAX.'px){.kbb-cartpage.cpg-squeeze .cpg-rec{margin-inline:calc(50% - 50vw + var(--kbtw)/2);width:calc(100vw - var(--kbtw));max-width:calc(100vw - var(--kbtw))}}';

    /**
     * On the two pages the round button (and its bubble) is hidden on a phone,
     * so the bubble's "show once" must not spend itself there unseen. Printed
     * into the bubble script only on those pages; '' everywhere else.
     */
    public const TAB_BUBBLE_GUARD = "if(matchMedia('(max-width:".self::PHONE_MAX."px)').matches)return;";

    public const SCHEMA = [
        /* ── Design ─────────────────────────────────────────────────────── */
        'enabled' => ['bool', 'Show the WhatsApp button', true,
            'On is how this ships, because you asked for it. Off takes the button, its stylesheet and its script off every page — not one byte is left behind.'],
        'show_phone' => ['bool', 'Show on phones', true,
            'Screens 900px wide and narrower, the same width at which the shop switches to its phone menu.'],
        'show_desktop' => ['bool', 'Show on desktop', true,
            'Screens wider than 900px.'],
        'design' => ['select', 'Design', 'G',
            'G · Orbit team is the one you picked. The other six are the designs from the preview page, kept so you can switch at any time. Women, men, orbit speed and the capsule apply to G; the label of E uses the capsule’s first line.',
            self::DESIGNS],
        'size' => ['range', 'Overall size', 60,
            'The size of the green button. Everything around it — the faces, the rings, the capsule — grows and shrinks with it, and the space from the edges is measured from the outside of the faces.',
            ['min' => self::SIZE_MIN, 'max' => self::SIZE_MAX, 'step' => 1, 'unit' => 'px']],
        'women' => ['select', 'Women on the ring', '2',
            'Design G only. The faces alternate man, woman, man… around the ring.',
            ['0' => '0', '1' => '1', '2' => '2', '3' => '3']],
        'men' => ['select', 'Men on the ring', '3',
            'Design G only.',
            ['0' => '0', '1' => '1', '2' => '2', '3' => '3']],
        'speed' => ['select', 'Orbit speed', 'calm',
            'Design G only: one lap of the ring takes 20 seconds (slow), 14 (calm) or 8 (lively). Faces stay upright as they travel. Visitors who have asked their phone for less motion see everything still.',
            ['slow' => 'Slow', 'calm' => 'Calm', 'lively' => 'Lively']],

        /* ── Position — blank means "auto" ──────────────────────────────── */
        'm_top' => ['type' => 'text', 'label' => 'Phone · top', 'default' => '', 'rule' => [self::class, 'cleanOffset'],
            'help' => 'Pixels from the top of the screen. Leave empty to use Bottom.'],
        'm_right' => ['type' => 'text', 'label' => 'Phone · right', 'default' => '16', 'rule' => [self::class, 'cleanOffset'],
            'help' => 'Pixels from the right edge. Empty it and fill Left to move the button to the left side.'],
        'm_bottom' => ['type' => 'text', 'label' => 'Phone · bottom', 'default' => '20', 'rule' => [self::class, 'cleanOffset'],
            'help' => 'Pixels from the bottom of the screen. On a product page whose sticky Add to cart bar is showing, on the cart page’s docked checkout bar and on the checkout’s Place order bar, the button rises above the bar by itself.'],
        'm_left' => ['type' => 'text', 'label' => 'Phone · left', 'default' => '', 'rule' => [self::class, 'cleanOffset'],
            'help' => 'Pixels from the left edge. Used only when Right is empty.'],
        'd_top' => ['type' => 'text', 'label' => 'Desktop · top', 'default' => '', 'rule' => [self::class, 'cleanOffset'],
            'help' => 'Pixels from the top of the window. Leave empty to use Bottom.'],
        'd_right' => ['type' => 'text', 'label' => 'Desktop · right', 'default' => '24', 'rule' => [self::class, 'cleanOffset'],
            'help' => 'Pixels from the right edge. Empty it and fill Left to move the button to the left side.'],
        'd_bottom' => ['type' => 'text', 'label' => 'Desktop · bottom', 'default' => '24', 'rule' => [self::class, 'cleanOffset'],
            'help' => 'Pixels from the bottom of the window.'],
        'd_left' => ['type' => 'text', 'label' => 'Desktop · left', 'default' => '', 'rule' => [self::class, 'cleanOffset'],
            'help' => 'Pixels from the left edge. Used only when Right is empty.'],
        'ar_side' => ['select', 'On the Arabic shop', 'mirror',
            'Arabic reads right to left, and the mirrored Arabic shop puts everything that sits on the right on the left. “Mirror” does the same for this button: your Right becomes Left there, and the capsule and bubble flip with it. Applies only while the mirrored (right-to-left) layout is switched on.',
            ['mirror' => 'Mirror it (bottom-left)', 'same' => 'Keep the same side as English']],

        /* ── Message ────────────────────────────────────────────────────── */
        'link' => ['type' => 'text', 'label' => 'Your own link', 'default' => '', 'rule' => [self::class, 'cleanLink'],
            'help' => 'Leave empty to open WhatsApp to the shop’s number with the two lines below already typed. Paste a link of your own (it must start with https://) to send the button there instead — nothing is added to your link.'],
        'welcome' => ['text', 'Welcome line', 'Hi there 👋 Welcome to K-Beauty Bliss',
            'The bubble’s heading, and the first line of the message the shopper sends you. Empty hides it.'],
        'welcome_ar' => ['text', 'Welcome line — Arabic', '',
            'Leave empty for the standard Arabic (Translation → Strings, “store.whatsapp.welcome”). Your English line is never shown on the Arabic shop.'],
        'support' => ['textarea', 'Support line', 'Need help choosing? Our beauty team is on WhatsApp, 24/7.',
            'Under the welcome line in the bubble, and the second line of the message. Empty hides it.'],
        'support_ar' => ['textarea', 'Support line — Arabic', '',
            'Leave empty for the standard Arabic (“store.whatsapp.support”).'],

        /* ── Capsule & bubble ───────────────────────────────────────────── */
        'capsule' => ['bool', 'Show the capsule', true,
            'The white “Chat with us · Available 24/7” pill beside the button (design G). Tapping it opens WhatsApp too.'],
        'cap1' => ['text', 'Capsule line 1', 'Chat with us',
            'Bold. Also the label of design E.'],
        'cap1_ar' => ['text', 'Capsule line 1 — Arabic', '',
            'Leave empty for the standard Arabic (“store.whatsapp.capsule_title”).'],
        'cap2' => ['text', 'Capsule line 2', 'Available 24/7',
            'The small green line. Empty hides it.'],
        'cap2_ar' => ['text', 'Capsule line 2 — Arabic', '',
            'Leave empty for the standard Arabic (“store.whatsapp.capsule_note”).'],
        'bubble' => ['select', 'Welcome bubble', 'once',
            'The speech bubble above the button with your welcome and support lines. “Show once” shows it on a visitor’s first page and then never again on that browser; closing it hides it at once.',
            ['once' => 'Show once, then stay closed', 'off' => 'Do not show']],

        /* ── Cart & checkout · phone — the side tab (Lane WS) ─────────────── */
        'tab_on' => ['bool', 'Side tab on the cart and checkout', true,
            'On is how this ships, because you asked for it. On phones, the cart and the checkout show a slim “24/7 Support” tab floating on the left edge instead of the round button, above the checkout bar. Laptops, and every other page, keep the round button exactly as it is. Off puts the round button back on those two pages.'],
        'tab_size' => ['range', 'Overall size', self::TAB_BASE,
            'Drag to make the whole tab bigger or smaller: its width, the icon, the text and its height all follow.',
            ['min' => self::TAB_MIN, 'max' => self::TAB_MAX, 'step' => 1, 'unit' => 'px']],
        'tab_y' => ['range', 'Vertical position', 50,
            '0 puts the tab as high as it goes (just under the header), 50 in the middle, 100 as low as it goes — always above the checkout bar at the bottom of the screen.',
            ['min' => 0, 'max' => 100, 'step' => 1, 'unit' => '%']],
        'tab_label' => ['type' => 'text', 'label' => 'Label', 'default' => '24/7 Support', 'max' => self::TAB_LABEL_MAX,
            'help' => 'Black, set sideways. Up to 24 letters. Empty shows the icon alone.'],
        'tab_label_ar' => ['type' => 'text', 'label' => 'Label — Arabic', 'default' => '', 'max' => self::TAB_LABEL_MAX,
            'help' => 'Leave empty for the standard Arabic (“store.whatsapp.tab_label”).'],
        'tab_palette' => ['select', 'Background colours', 'blush',
            'Light colours that drift slowly from one to the next, so the black text and the green icon stay easy to read. “My own two colours” uses the two boxes below.',
            ['blush' => 'Blush, peach & mint', 'sky' => 'Mint, sky & lilac', 'lilac' => 'Lilac, rose & cream', 'lemon' => 'Lemon, mint & sky', 'custom' => 'My own two colours']],
        'tab_c1' => ['type' => 'text', 'label' => 'Own colour 1', 'default' => '#FFE1EA', 'rule' => [self::class, 'cleanLight'],
            'help' => 'Used with “My own two colours”. Light colours only — one too dark for black text is refused.'],
        'tab_c2' => ['type' => 'text', 'label' => 'Own colour 2', 'default' => '#E2F6EA', 'rule' => [self::class, 'cleanLight'],
            'help' => 'Used with “My own two colours”.'],
        'tab_space' => ['bool', 'Make room beside the tab', false,
            'Off, as you asked: the tab floats over the left edge of the page and nothing moves. On: the page moves over by the tab\'s width so the tab never sits on anything.'],
        'tab_anim' => ['bool', 'Animate', true,
            'The colours drift slowly and the icon pulses gently. Off keeps both still. Phones set to reduce motion always see it still.'],
    ];

    public const TABS = [
        'design' => ['Design',
            'Which of the seven designs, how big, and on which screens. The live preview moves as you change anything — nothing reaches the shop until you press Save.',
            ['enabled', 'show_phone', 'show_desktop', 'design', 'size', 'women', 'men', 'speed']],
        'position' => ['Position',
            'Space from each edge of the screen, in pixels, set separately for phones and for desktop. Leave a box empty for “auto”: Top and Left are normally empty, which keeps the button at the bottom right. If both Right and Left are filled, Right is used; if both Top and Bottom are filled, Bottom is used.',
            ['m_top', 'm_right', 'm_bottom', 'm_left', 'd_top', 'd_right', 'd_bottom', 'd_left', 'ar_side']],
        'message' => ['Message',
            'Where the button goes and what the shopper sends. By default it opens WhatsApp to the shop’s number (Store → Business Details → WhatsApp number) with your two lines already typed, ready to send.',
            ['link', 'welcome', 'welcome_ar', 'support', 'support_ar']],
        'capsule' => ['Capsule & bubble',
            'The pill beside the button and the welcome bubble above it.',
            ['capsule', 'cap1', 'cap1_ar', 'cap2', 'cap2_ar', 'bubble']],
        'tab' => ['Cart & checkout · phone',
            'On phones only, the cart and the checkout swap the round button for a slim tab on the left edge — the WhatsApp icon and “24/7 Support” on a slowly shifting light background. It opens the same chat, with the same message or your own link, as the round button. Laptops and every other page are not touched.',
            ['tab_on', 'tab_size', 'tab_y', 'tab_label', 'tab_label_ar', 'tab_palette', 'tab_c1', 'tab_c2', 'tab_space', 'tab_anim']],
    ];

    /**
     * `invalid => reject`: a select that is not one of its options, an offset
     * that is not a number and a link that is not https:// are REFUSED and named
     * back to the owner, rather than quietly replaced. `clamp => true` for the
     * one slider, which cannot emit an out-of-range value, so a POST that does
     * is pulled to the nearest end. `blank => keep`: an emptied line is how the
     * owner hides it. `markup => keep`: a typed `<3` is wording, and every line
     * reaches the page through Blade's escaping, which is what makes it safe.
     */
    public const POLICY = [
        'max' => 200,
        'blank' => 'keep',
        'invalid' => 'reject',
        'clamp' => true,
        'hex' => 'strict',
        'bool' => 'words',
        'markup' => 'keep',
    ];

    /** Every key lives in `settings`, written by this module's own endpoint. */
    private const STORE = ModuleSchema::STORE_SETTING;

    private const PREFIX = 'waf_';

    /** The localStorage key that remembers the bubble was shown. */
    public const BUBBLE_KEY = 'kbbWaBubble';

    /**
     * The interface strings behind the Arabic boxes and the two labels that
     * have no box. Owner-typed English lives in `settings`; the STANDARD
     * wording lives in App\Services\Translation\InterfaceStrings, which is
     * where an Arabic translation can be attached and approved.
     */
    public const KEYS = [
        'welcome' => 'store.whatsapp.welcome',
        'support' => 'store.whatsapp.support',
        'cap1' => 'store.whatsapp.capsule_title',
        'cap2' => 'store.whatsapp.capsule_note',
        'open' => 'store.whatsapp.open_label',
        'close' => 'store.whatsapp.close_label',
        'tab_label' => 'store.whatsapp.tab_label',
    ];

    /**
     * The WhatsApp glyph. A CONSTANT, so the partial may print it raw.
     */
    public const ICON = '<svg class="kbw-i" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2.5a9.4 9.4 0 0 0-8.1 14.2L2.5 21.5l4.9-1.4a9.4 9.4 0 1 0 4.64-17.6zm0 17.1a7.7 7.7 0 0 1-3.94-1.08l-.28-.17-2.9.82.83-2.83-.18-.29a7.7 7.7 0 1 1 6.47 3.55zm4.23-5.77c-.23-.12-1.37-.68-1.58-.75-.21-.08-.37-.12-.52.11-.15.24-.6.76-.73.91-.14.15-.27.17-.5.06-.23-.12-.98-.36-1.86-1.15a7 7 0 0 1-1.29-1.6c-.13-.24 0-.36.1-.48.1-.1.23-.27.35-.4.11-.14.15-.24.23-.4.07-.15.04-.29-.02-.4-.06-.12-.52-1.26-.72-1.72-.19-.45-.38-.39-.52-.4h-.45a.86.86 0 0 0-.62.3c-.21.22-.82.8-.82 1.95s.84 2.27.96 2.42c.11.15 1.65 2.52 4 3.53.56.24 1 .39 1.34.5.56.18 1.07.15 1.48.09.45-.07 1.37-.56 1.57-1.1.19-.54.19-1 .13-1.1-.06-.1-.21-.16-.44-.27z"/></svg>';

    /**
     * The two faces, as <symbol>s, drawn ONCE per page and reused by every
     * avatar through <use>. The paths are the preview's face() verbatim; the
     * four colours come from custom properties each avatar's <i> inherits from
     * the stylesheet (--g background, --h hair, --s skin, --c top), which is
     * what lets five faces cost five short <use> tags instead of five copies.
     */
    private const FACE_COMMON = '<rect x="16.5" y="24" width="7" height="7" style="fill:var(--s)"/><path d="M6 42c1-8 7-12 14-12s13 4 14 12z" style="fill:var(--c)"/><ellipse cx="20" cy="19" rx="7" ry="8" style="fill:var(--s)"/>';

    private const FACE_EYES = '<circle cx="17.4" cy="19.6" r=".9" fill="#2A2228"/><circle cx="22.6" cy="19.6" r=".9" fill="#2A2228"/><path d="M18 23.2q2 1.6 4 0" stroke="#B5475F" stroke-width=".9" fill="none" stroke-linecap="round"/>';

    public const SYMBOLS = [
        'W' => '<symbol id="kbwW" viewBox="0 0 40 40"><rect width="40" height="40" style="fill:var(--g)"/><path d="M9 24c0-10 5-15 11-15s11 5 11 15v10H9z" style="fill:var(--h)"/>'
            .self::FACE_COMMON
            .'<path d="M12.6 18c.6-5.6 3.8-8.2 7.4-8.2s6.9 2.6 7.4 8.2c-3-.8-6-2.8-7.4-5-1.4 2.2-4.4 4.2-7.4 5z" style="fill:var(--h)"/>'
            .self::FACE_EYES.'</symbol>',
        'M' => '<symbol id="kbwM" viewBox="0 0 40 40"><rect width="40" height="40" style="fill:var(--g)"/>'
            .self::FACE_COMMON
            .'<path d="M12.8 17.5c0-5 3.2-8.2 7.2-8.2s7.2 3.2 7.2 8.2c-1.2-2.4-3.6-3.4-7.2-3.4s-6 1-7.2 3.4z" style="fill:var(--h)"/>'
            .self::FACE_EYES.'</symbol>',
    ];

    /**
     * The rules every design shares. CONSTANT — printed raw by the partial.
     *
     * The wrapper `.kbw` is the button's whole FOOTPRINT, sized from --k, so
     * the four offsets measure from what the shopper sees: the green circle on
     * A–F, the outside of the faces on G (the preview's `.waf.G` margin, moved
     * into the footprint). It takes no pointer events; only the link and the
     * bubble do, so the empty corners of the box never swallow a tap meant for
     * the page underneath.
     *
     * `margin-bottom` is the LIFT. For a box anchored by `bottom` it moves the
     * button up; for one anchored by `top` it does nothing at all — which is
     * exactly right, and is why the lift needs no knowledge of the anchor.
     * See LIFT_CSS.
     *
     * Physical left/right are the point here, not an oversight: the owner's
     * four boxes are physical sides of the screen. The mirror on the Arabic
     * shop is done by swapping the two numbers in placement(), so the CSS
     * never has to guess. Everything INSIDE the bubble and the capsule is
     * logical (inline-start/end), so their text reads the right way round in
     * either language.
     */
    private const CSS_BASE = '.kbw{position:fixed;z-index:85;--t:var(--dt);--r:var(--dr);--b:var(--db);--l:var(--dl);top:var(--t);right:var(--r);bottom:var(--b);left:var(--l);width:calc(60px*var(--k));height:calc(60px*var(--k));pointer-events:none;transition:margin-bottom .28s;font-size:16px;line-height:1.4}'
        .'.kbw.kbw-G{width:calc(116px*var(--k));height:calc(116px*var(--k))}'
        .'.kbw-f{position:absolute;top:0;left:0;width:60px;height:60px;transform:scale(var(--k));transform-origin:0 0}'
        .'.kbw-G .kbw-f{top:calc(28px*var(--k));left:calc(28px*var(--k))}'
        .'.kbw-a{position:relative;z-index:2;display:grid;place-items:center;width:100%;height:100%;border-radius:50%;color:#fff;background:#25D366;box-shadow:0 8px 22px rgba(18,140,126,.35);text-decoration:none;transition:transform .2s;pointer-events:auto;-webkit-tap-highlight-color:transparent}'
        .'.kbw-a:hover,.kbw-a:focus-visible{transform:scale(1.07)}'
        .'.kbw-a:focus-visible{outline:3px solid #E0567B;outline-offset:3px}'
        .'.kbw-i{width:32px;height:32px}'
        .'.kbw-r{position:absolute;inset:0;border-radius:50%;pointer-events:none}'
        .'.kbw-s{position:absolute;width:0;height:0;overflow:hidden}'
        .'@keyframes kbwP{0%{transform:scale(1);opacity:.45}100%{transform:scale(1.9);opacity:0}}'
        .'@keyframes kbwS{to{transform:rotate(1turn)}}';

    /**
     * The welcome bubble's rules — only on a page that has a bubble.
     */
    private const CSS_BUBBLE = '.kbw-b{position:absolute;right:0;bottom:calc(100% + 12px);width:min(260px,calc(100vw - 32px));box-sizing:border-box;background:#fff;color:#2A2228;border:1px solid #F1E3E8;border-radius:16px 16px 4px 16px;padding:12px 14px;padding-inline-end:30px;box-shadow:0 12px 30px rgba(42,34,40,.14);font-size:13.5px;line-height:1.5;text-align:start;pointer-events:auto;transform-origin:100% 100%;animation:kbwO .5s 1s both}'
        .'.kbw-b strong{display:block;font-size:14.5px;margin-bottom:2px}'
        .'.kbw-b span{display:block;color:#8C828A}'
        .'.kbw-x{position:absolute;top:6px;inset-inline-end:8px;border:0;background:none;color:#8C828A;font:inherit;font-size:18px;line-height:1;padding:2px 4px;cursor:pointer}'
        .'@keyframes kbwO{from{transform:scale(.6);opacity:0}to{transform:none;opacity:1}}';

    /**
     * The rules that depend on WHICH SIDE the button is on, written once with
     * a `%s` for the side class and emitted twice — inside the phone media
     * query for `.kbw-ml`/`.kbw-mt`, and inside the desktop one for
     * `.kbw-dl`/`.kbw-dt` — because the phone and the desktop may be on
     * different sides. The offsets themselves switch by custom property, so
     * the media queries carry only these few flips.
     */
    private const CSS_LEFT = '.kbw.%1$s .kbw-b{right:auto;left:0;border-radius:16px 16px 16px 4px;transform-origin:0 100%%}'
        .'.kbw.%1$s .kbw-c{right:auto;left:calc(100%% + 26px)}'
        .'.kbw.%1$s .kbw-t{right:auto;left:68px}'
        .'.kbw.%1$s{--nx:4px}';

    private const CSS_TOP = '.kbw.%1$s .kbw-b{bottom:auto;top:calc(100%% + 12px);border-radius:16px;transform-origin:50%% 0}';

    /**
     * The lift: the bars a phone already pins to the bottom of the screen.
     *
     * Measured on the preview (docs/lane-wa-shots/), not guessed:
     *   - product page, sticky Add to cart (`#stickybar.show`, Appearance →
     *     Product styles → Sticky Add to Cart): 69px tall at 390;
     *   - cart page, the docked checkout rows (`.cpg-docked`, fixed on a
     *     phone): 102px at 390;
     *   - checkout, the floating Place order bar (`.mpbar.is-on`, or always
     *     with `.cop-floatalways`): 70px.
     * The shop's bottom tab bar is not in the markup (kbb.css's own note), and
     * the shop has no cookie bar. The cart's rule is LIFT_CART, printed only
     * while the squeezed cart is on. `:has()` and not a script, so it follows the
     * bar in and out with no JavaScript at all; on a browser without `:has()`
     * the button simply stays where it was set.
     */
    private const LIFT_CSS = '@media (max-width:900px){body:has(#stickybar.show) .kbw,body:has(.mpbar.is-on) .kbw,body:has(.cop-floatalways .mpbar) .kbw{margin-bottom:72px}}'
        .'@media (min-width:901px){body:has(#stickybar.show.sb-all) .kbw{margin-bottom:72px}}'
        .'@media (min-width:901px) and (max-width:1180px){body:has(#stickybar.show.sb-pt) .kbw{margin-bottom:72px}}';

    /**
     * The cart page's docked rows exist only on the SQUEEZED cart (Appearance →
     * Cart page → Layout, which ships `classic`), so this rule is printed only
     * while that layout is on. On the classic shop no page names the squeezed
     * furniture at all — CartPageSqueezeTest pins exactly that.
     */
    private const LIFT_CART = '@media (max-width:900px){body:has(.cpg-squeeze .cpg-docked) .kbw{margin-bottom:106px}}';

    /**
     * ONLY what a visitor who asked for less motion must not get: every
     * animation and transition stops, and the bubble is simply there.
     */
    private const CSS_TAIL = '@media (prefers-reduced-motion:reduce){.kbw,.kbw *{animation:none!important;transition:none!important}}'
        .'@media print{.kbw{display:none}}';

    /**
     * Each design's own rules, ported from the preview's A–G blocks with the
     * class names prefixed. Only the one in use reaches a storefront page.
     */
    public const CSS_DESIGNS = [
        'A' => '.kbw-A .kbw-r{background:#25D366;opacity:.45;animation:kbwP 2.4s cubic-bezier(.2,.6,.3,1) infinite}.kbw-A .kbw-r2{animation-delay:1.2s}',
        'B' => '.kbw-B .kbw-a{background:linear-gradient(135deg,#2BDB70,#128C7E)}.kbw-B .kbw-r{border:2px solid #E0567B;animation:kbwR 3s ease-out infinite}.kbw-B .kbw-r2{border-color:#F28C5B;animation-delay:1s}.kbw-B .kbw-r3{border-color:#B9AFB5;animation-delay:2s}@keyframes kbwR{0%{transform:scale(1);opacity:.9}100%{transform:scale(2.1);opacity:0}}',
        'C' => '.kbw-C .kbw-r{border:1.5px solid #25D366;animation:kbwR 2.7s linear infinite}.kbw-C .kbw-r2{animation-delay:.9s}.kbw-C .kbw-r3{animation-delay:1.8s}@keyframes kbwR{0%{transform:scale(1);opacity:.9}100%{transform:scale(2.1);opacity:0}}',
        'D' => '.kbw-D .kbw-h{position:absolute;inset:-7px;z-index:1;border-radius:50%;background:conic-gradient(from 0deg,#E0567B,#F28C5B,#FFD1B3,#C13E63,#E0567B);animation:kbwS 4s linear infinite;filter:blur(.3px)}.kbw-D .kbw-h::after{content:"";position:absolute;inset:4px;border-radius:50%;background:#fff}.kbw-D .kbw-r{background:radial-gradient(circle,rgba(224,86,123,.35),transparent 70%);animation:kbwB 2.8s ease-in-out infinite}@keyframes kbwB{0%,100%{transform:scale(1.15);opacity:.5}50%{transform:scale(1.7);opacity:.15}}',
        'E' => '.kbw-E .kbw-r{background:#25D366;opacity:.35;animation:kbwP 2s ease-out infinite}.kbw-t{position:absolute;right:68px;bottom:14px;white-space:nowrap;background:#fff;color:#2A2228;border:1px solid #F1E3E8;border-radius:999px;padding:6px 14px;font-size:13px;font-weight:600;line-height:1.5;box-shadow:0 6px 18px rgba(42,34,40,.12);animation:kbwN 3.2s ease-in-out infinite}.kbw-t::before{content:"";display:inline-block;width:8px;height:8px;border-radius:50%;background:#25D366;margin-inline-end:7px;vertical-align:1px;box-shadow:0 0 0 3px rgba(37,211,102,.2)}@keyframes kbwN{0%,80%,100%{transform:none}88%{transform:translateX(var(--nx,-4px))}}',
        'F' => '.kbw-F .kbw-o{position:absolute;inset:-12px;border-radius:50%;border:2px dashed rgba(224,86,123,.55);animation:kbwS 9s linear infinite}.kbw-F .kbw-o::before,.kbw-F .kbw-o::after{content:"";position:absolute;width:9px;height:9px;border-radius:50%;background:#E0567B;top:-5px;left:calc(50% - 4.5px);box-shadow:0 0 8px #E0567B}.kbw-F .kbw-o::after{background:#F28C5B;top:auto;bottom:-5px;box-shadow:0 0 8px #F28C5B}.kbw-F .kbw-r{background:#25D366;opacity:.3;animation:kbwP 2.6s ease-out infinite}',
        'G' => '.kbw-G .kbw-o{position:absolute;inset:-16px;border-radius:50%;border:2px dashed rgba(224,86,123,.5);animation:kbwS var(--spd,14s) linear infinite}'
            .'.kbw-v{position:absolute;left:50%;top:50%;width:24px;height:24px;margin:-12px;transform:rotate(var(--a)) translateY(-46px) rotate(calc(var(--a)*-1))}'
            .'.kbw-v i{display:block;width:100%;height:100%;box-sizing:border-box;border-radius:50%;overflow:hidden;border:2px solid #fff;box-shadow:0 2px 8px rgba(42,34,40,.22);animation:kbwS var(--spd,14s) linear infinite reverse;background:#fff}'
            .'.kbw-v svg{display:block;width:100%;height:100%}'
            // The preview's face(i) palette: background, hair, skin, top, by
            // position on the ring — SK[i], HR[i+2], BG[i], SH[i].
            .'.kbw-v:nth-child(6n+1) i{--g:#FCE0E8;--h:#1C1C1C;--s:#F3CFB3;--c:#E0567B}'
            .'.kbw-v:nth-child(6n+2) i{--g:#FFE3D3;--h:#7A4B2E;--s:#E2AE8A;--c:#F28C5B}'
            .'.kbw-v:nth-child(6n+3) i{--g:#E7F6EE;--h:#3B2A20;--s:#C68E6B;--c:#8C828A}'
            .'.kbw-v:nth-child(6n+4) i{--g:#EDE7F6;--h:#4A2C1E;--s:#F0C5A2;--c:#C13E63}'
            .'.kbw-v:nth-child(6n+5) i{--g:#FFF1D6;--h:#2B1B17;--s:#D29C78;--c:#B9AFB5}'
            .'.kbw-v:nth-child(6n+6) i{--g:#E3F0FB;--h:#5A3825;--s:#EBC0A0;--c:#F5A3B8}'
            .'.kbw-G .kbw-r{background:#25D366;opacity:.28;animation:kbwP 2.6s ease-out infinite}'
            .'.kbw-c{position:absolute;right:calc(100% + 26px);bottom:8px;display:flex;align-items:center;gap:9px;white-space:nowrap;background:#fff;border:1px solid #F1E3E8;border-radius:999px;padding-block:7px;padding-inline:12px 16px;box-shadow:0 8px 22px rgba(42,34,40,.13);animation:kbwN 3.6s ease-in-out infinite;color:#2A2228;text-align:start}'
            .'.kbw-d{flex:none;width:9px;height:9px;border-radius:50%;background:#25D366;box-shadow:0 0 0 3px rgba(37,211,102,.22);animation:kbwK 1.6s ease-in-out infinite}'
            .'.kbw-c b{display:block;font-size:13.5px;line-height:1.15;font-weight:700}'
            .'.kbw-c small{display:block;font-size:11.5px;color:#128C7E;font-weight:600;line-height:1.2}'
            .'.kbw-G .kbw-b{bottom:calc(100% + 10px)}'
            .'@keyframes kbwK{50%{box-shadow:0 0 0 6px rgba(37,211,102,0)}}'
            .'@keyframes kbwN{0%,80%,100%{transform:none}88%{transform:translateX(var(--nx,-4px))}}',
    ];

    /** The rings each design draws, as how many `.kbw-r` spans. */
    private const RINGS = ['A' => 2, 'B' => 3, 'C' => 3, 'D' => 1, 'E' => 1, 'F' => 1, 'G' => 1];

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    public function __construct(private SettingsService $settings) {}

    /* ═════════════════════════════════════════════ schema and storage ═══ */

    /** @return array<string, array<string, mixed>> */
    public static function normalised(): array
    {
        return ModuleSchema::normalised('whatsapp_button', self::SCHEMA, self::POLICY, self::overrides());
    }

    /** @return array<string, array<string, string>> */
    public static function overrides(): array
    {
        $out = [];

        foreach (array_keys(self::SCHEMA) as $key) {
            $out[$key] = ['store' => self::STORE, 'alias' => self::PREFIX.$key];
        }

        return $out;
    }

    /**
     * Every value, saved or shipped, cast against the schema on the way OUT as
     * well as on the way in — so a row written behind the screen's back (an
     * import, a hand edit) is no more able to reach the page than a POST is.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $out = [];

        foreach (self::normalised() as $key => $field) {
            $saved = $this->settings->get($field['alias'], null);

            $out[$key] = $saved === null
                ? $field['default']
                : (ModuleSchema::cast($field, $saved) ?? $field['default']);
        }

        return $this->memo = $out;
    }

    /**
     * Save, returning the keys it refused so the caller can report them.
     *
     * @param  array<string, mixed>  $values
     * @return array{written: list<string>, rejected: array<string, string>}
     */
    public function save(array $values): array
    {
        $clean = [];
        $rejected = [];

        // ALL OR NOTHING. Every value is cast before any is written, so a save
        // the screen reports as "Not saved" has in fact saved nothing — not the
        // half of the form that happened to come before the bad box.
        foreach (self::normalised() as $key => $field) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $cast = ModuleSchema::cast($field, $values[$key]);

            if ($cast === null) {
                $rejected[$key] = $field['label'];
            } else {
                $clean[$field['alias']] = is_bool($cast) ? ($cast ? '1' : '0') : (string) $cast;
            }
        }

        if ($rejected !== []) {
            return ['written' => [], 'rejected' => $rejected];
        }

        foreach ($clean as $alias => $value) {
            $this->settings->set($alias, $value);
        }

        $this->memo = null;

        return ['written' => array_keys($clean), 'rejected' => []];
    }

    /**
     * An offset box: empty for "auto", otherwise whole pixels 0–400.
     *
     * Out-of-range numbers are CLAMPED (a box of 999 is somebody asking for
     * "as far as it goes"); text that is not a number is REFUSED, so the owner
     * is told rather than finding the button somewhere else. A trailing "px"
     * is forgiven, because that is how people write pixels.
     */
    public static function cleanOffset(mixed $raw, array $field = []): ?string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        if (! is_scalar($raw) || is_bool($raw)) {
            return null;
        }

        $value = strtolower(trim((string) $raw));

        if ($value === '' || $value === 'auto') {
            return '';
        }

        $value = (string) preg_replace('/\s*px$/', '', $value);

        if (preg_match('/^-?\d{1,6}(\.\d+)?$/', $value) !== 1) {
            return null;
        }

        return (string) max(0, min(self::OFFSET_MAX, (int) round((float) $value)));
    }

    /**
     * The custom link: empty, or an https:// address and nothing else.
     *
     * Rule 5 — "a URL from a setting is scheme-checked before it becomes an
     * href". `javascript:`, `data:`, `http:`, a protocol-relative `//host` and
     * a bare `wa.me/…` are all REFUSED here, so the save is reported rather than
     * stored; link() checks again at render for a row this did not write.
     */
    public static function cleanLink(mixed $raw, array $field = []): ?string
    {
        if ($raw === null) {
            return '';
        }

        if (! is_scalar($raw) || is_bool($raw)) {
            return null;
        }

        $value = trim((string) $raw);

        if ($value === '') {
            return '';
        }

        return self::isSafeLink($value) ? $value : null;
    }

    /** True for an absolute https:// URL with a host and no whitespace or control bytes. */
    public static function isSafeLink(string $value): bool
    {
        // Whitespace, control bytes, and the characters that only ever appear in
        // a URL to break out of the attribute or the address around it.
        if (strlen($value) > 500 || preg_match('/[\x00-\x20\x7F"\'<>`\\\\{}|^]/', $value) === 1) {
            return false;
        }

        if (preg_match('#^https://[^/?\#@\s]#i', $value) !== 1) {
            return false;
        }

        $parts = parse_url($value);

        // No user-info: `https://wa.me@evil.example` reads as WhatsApp and goes
        // to evil.example.
        return is_array($parts) && ($parts['host'] ?? '') !== '' && ! isset($parts['user']) && ! isset($parts['pass'])
            && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * A custom tab colour: `#` and six hex digits, and LIGHT — relative
     * luminance at or above TAB_LIGHT_MIN — because the tab's text is black.
     * Anything else is refused and named back to the owner. Upper-cased, so a
     * stored colour is one spelling.
     */
    public static function cleanLight(mixed $raw, array $field = []): ?string
    {
        if (! is_string($raw) || preg_match('/^#[0-9a-fA-F]{6}$/', trim($raw)) !== 1) {
            return null;
        }

        $hex = strtoupper(trim($raw));

        return self::luminance($hex) >= self::TAB_LIGHT_MIN ? $hex : null;
    }

    /** WCAG 2 relative luminance of a `#RRGGBB` colour. */
    public static function luminance(string $hex): float
    {
        $channel = static function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel(substr($hex, 1, 2)) + 0.7152 * $channel(substr($hex, 3, 2)) + 0.0722 * $channel(substr($hex, 5, 2));
    }

    /* ═══════════════════════════════════════════════════════ rendering ═══ */

    /**
     * Is this page an Arabic one? The shop's own test, as AlsoLikeSettings
     * words it: a non-default locale that is Arabic.
     */
    private static function arabic(): bool
    {
        return ! Locale::isDefault() && Locale::current() === 'ar';
    }

    /**
     * The four visible lines for this page's language.
     *
     * English: the owner's boxes, exactly. Arabic: the Arabic box when he has
     * filled it, else the STANDARD Arabic — the interface string, which
     * answers with an approved translation or, until he approves one, with the
     * English standard (Translation → Strings lists it as a draft to review).
     * A line he EMPTIED in English stays hidden on the Arabic shop too: an
     * empty English box is the one way he has to say "no second line".
     *
     * @param  array<string, mixed>|null  $c
     * @return array{welcome:string, support:string, cap1:string, cap2:string}
     */
    public function lines(?array $c = null, ?bool $arabic = null): array
    {
        $c ??= $this->all();
        $arabic ??= self::arabic();
        $out = [];

        foreach (['welcome', 'support', 'cap1', 'cap2'] as $key) {
            $english = trim((string) ($c[$key] ?? ''));

            if (! $arabic || $english === '') {
                $out[$key] = $english;

                continue;
            }

            $own = trim((string) ($c[$key.'_ar'] ?? ''));
            $out[$key] = $own !== '' ? $own : trim((string) __(self::KEYS[$key]));
        }

        return $out;
    }

    /**
     * The message the shopper sends: the welcome line, a newline, the support
     * line — the two lines the bubble shows, in the page's language.
     */
    public static function message(array $lines): string
    {
        return implode("\n", array_values(array_filter(
            [trim($lines['welcome'] ?? ''), trim($lines['support'] ?? '')],
            static fn (string $l): bool => $l !== ''
        )));
    }

    /**
     * Where the button goes.
     *
     * A custom link that passes isSafeLink() is used exactly as typed, with
     * nothing added. Anything else — including a stored value that was never
     * checked — falls back to wa.me with the shop's number from SupportContact
     * (the same number every other WhatsApp link on the shop dials) and the
     * message URL-encoded as `text`.
     */
    public function link(?array $c = null, ?array $lines = null): string
    {
        $c ??= $this->all();
        $custom = trim((string) ($c['link'] ?? ''));

        if ($custom !== '' && self::isSafeLink($custom)) {
            return $custom;
        }

        $url = 'https://wa.me/'.SupportContact::whatsappDigits();
        $text = self::message($lines ?? $this->lines($c));

        return $text === '' ? $url : $url.'?text='.rawurlencode($text);
    }

    /**
     * The stylesheet one storefront page needs: the shared rules, ONE design,
     * and the bubble's rules only when the page has a bubble.
     */
    public static function css(string $design, bool $bubble = true, bool $squeezedCart = false): string
    {
        $design = isset(self::CSS_DESIGNS[$design]) ? $design : self::SCHEMA['design'][2];

        return self::CSS_BASE.($bubble ? self::CSS_BUBBLE : '').self::CSS_DESIGNS[$design]
            .self::sideCss().self::LIFT_CSS.($squeezedCart ? self::LIFT_CART : '').self::CSS_TAIL;
    }

    /**
     * Every design's rules at once, for the admin preview, which switches
     * design without asking the server again. The duplicate @keyframes that
     * B/C and E/G each declare are identical, so the second is harmless.
     */
    public static function cssAll(): string
    {
        return self::CSS_BASE.self::CSS_BUBBLE.implode('', self::CSS_DESIGNS).self::sideCss().self::CSS_TAIL.self::TAB_CSS;
    }

    /**
     * The per-device switches: which offsets apply, which side flips, and the
     * per-device "hide". Phone first, inside max-width; desktop inside
     * min-width, so exactly one block applies at any width.
     */
    private static function sideCss(): string
    {
        $phone = '.kbw{--t:var(--mt);--r:var(--mr);--b:var(--mb);--l:var(--ml)}'
            .sprintf(self::CSS_LEFT, 'kbw-ml').sprintf(self::CSS_TOP, 'kbw-mt').'.kbw.kbw-mo{display:none}';
        $desktop = sprintf(self::CSS_LEFT, 'kbw-dl').sprintf(self::CSS_TOP, 'kbw-dt').'.kbw.kbw-do{display:none}';

        return '@media (max-width:'.self::PHONE_MAX.'px){'.$phone.'}'
            .'@media (min-width:'.(self::PHONE_MAX + 1).'px){'.$desktop.'}';
    }

    /**
     * Where the button sits on each device, after "auto" and the Arabic mirror.
     *
     * Per device: Right if it is filled, else Left if THAT is filled, else the
     * shipped Right; Bottom if filled, else Top, else the shipped Bottom. Then,
     * on a mirrored Arabic page with `ar_side = mirror`, the left and right
     * numbers swap — so the owner's "16 from the right" is "16 from the left"
     * there, and a button he deliberately put on the left comes to the right.
     *
     * @return array<string, array{t:?int, r:?int, b:?int, l:?int, left:bool, top:bool}>
     */
    public static function placement(array $c, bool $mirror): array
    {
        $out = [];

        foreach (array_keys(self::DEVICES) as $dev) {
            $n = static function (string $side) use ($c, $dev): ?int {
                $v = self::cleanOffset($c[$dev.'_'.$side] ?? '');

                return $v === null || $v === '' ? null : (int) $v;
            };

            [$t, $r, $b, $l] = [$n('top'), $n('right'), $n('bottom'), $n('left')];

            if ($r !== null) {
                $l = null;
            } elseif ($l === null) {
                $r = (int) self::SCHEMA[$dev.'_right']['default'];
            }

            if ($b !== null) {
                $t = null;
            } elseif ($t === null) {
                $b = (int) self::SCHEMA[$dev.'_bottom']['default'];
            }

            if ($mirror) {
                [$l, $r] = [$r, $l];
            }

            $out[$dev] = ['t' => $t, 'r' => $r, 'b' => $b, 'l' => $l, 'left' => $l !== null, 'top' => $t !== null];
        }

        return $out;
    }

    /**
     * The faces on the ring: men and women interleaved, man first, exactly as
     * the preview's team() orders them, each at an equal angle.
     *
     * @return list<array{g:string, a:int|float}>
     */
    public static function faces(int $women, int $men): array
    {
        $women = max(0, min(3, $women));
        $men = max(0, min(3, $men));
        $order = [];

        while ($women > 0 || $men > 0) {
            if ($men > 0) {
                $order[] = 'M';
                $men--;
            }

            if ($women > 0) {
                $order[] = 'W';
                $women--;
            }
        }

        $n = count($order);

        return array_map(
            static fn (string $g, int $i): array => ['g' => $g, 'a' => round($i * 360 / $n, 2)],
            $order,
            array_keys($order)
        );
    }

    /**
     * The side tab's label for this page's language — lines()'s rule, for one
     * more line: an emptied English box hides it on both shops.
     */
    public function tabLabel(?array $c = null, ?bool $arabic = null): string
    {
        $c ??= $this->all();
        $arabic ??= self::arabic();
        $english = trim((string) ($c['tab_label'] ?? ''));

        if (! $arabic || $english === '') {
            return $english;
        }

        $own = trim((string) ($c['tab_label_ar'] ?? ''));

        return $own !== '' ? $own : trim((string) __(self::KEYS['tab_label']));
    }

    /**
     * The side tab, for a page that asked for it, or null when it is off.
     *
     * The tab is a phone-only replacement for the round button, so it follows
     * the button's own phone switch as well as its own: off on phones means no
     * WhatsApp on a phone at all. Every number is clamped and every word comes
     * from a map here, again, whatever the row holds — the style attribute and
     * --kbtw carry nothing else.
     *
     * @return array{class:string, style:string, w:int, right:bool, label:string, aria:string, href:string}|null
     */
    public function tab(array $c, bool $arabic, bool $mirror, string $href): ?array
    {
        if (! ($c['tab_on'] ?? true) || ! ($c['show_phone'] ?? true)) {
            return null;
        }

        $w = max(self::TAB_MIN, min(self::TAB_MAX, (int) ($c['tab_size'] ?? self::TAB_BASE)));
        $y = max(0, min(100, (int) ($c['tab_y'] ?? 50)));
        $palette = (string) ($c['tab_palette'] ?? 'blush');

        if ($palette === 'custom') {
            $one = self::cleanLight($c['tab_c1'] ?? null) ?? self::SCHEMA['tab_c1']['default'];
            $two = self::cleanLight($c['tab_c2'] ?? null) ?? self::SCHEMA['tab_c2']['default'];
            $stops = [$one, $two, $one];
        } else {
            $stops = self::TAB_PALETTES[$palette] ?? self::TAB_PALETTES['blush'];
        }

        $label = $this->tabLabel($c, $arabic);
        $open = (string) __(self::KEYS['open']);

        return [
            'class' => 'kbt-z'.($mirror ? ' kbt-rt' : '').(($c['tab_anim'] ?? true) ? '' : ' kbt-still'),
            'style' => '--q:'.rtrim(rtrim(number_format($w / self::TAB_BASE, 4, '.', ''), '0'), '.')
                .';--y:'.$y.';--c1:'.$stops[0].';--c2:'.$stops[1].';--c3:'.$stops[2],
            'w' => $w,
            'right' => $mirror,
            'space' => (bool) ($c['tab_space'] ?? false),
            'label' => $label,
            // The visible words first, so the accessible name contains them.
            'aria' => $label === '' ? $open : $label.' · '.$open,
            'href' => $href,
        ];
    }

    /**
     * Everything the partial prints, or null when nothing is to be printed.
     *
     * Null — and therefore not one byte on the page — when the button is off,
     * or when it is switched off on BOTH devices.
     *
     * @return array<string, mixed>|null
     */
    public function view(?array $c = null, ?bool $arabic = null, bool $tabPage = false): ?array
    {
        $c ??= $this->all();
        $arabic ??= self::arabic();

        if (! ($c['enabled'] ?? false) || (! ($c['show_phone'] ?? false) && ! ($c['show_desktop'] ?? false))) {
            return null;
        }

        $design = isset(self::DESIGNS[$c['design'] ?? '']) ? (string) $c['design'] : 'G';
        $size = max(self::SIZE_MIN, min(self::SIZE_MAX, (int) ($c['size'] ?? self::BASE)));
        $mirror = ($c['ar_side'] ?? 'mirror') === 'mirror' && Locale::isRtl();
        $place = self::placement($c, $mirror);
        $lines = $this->lines($c, $arabic);

        $class = 'kbw kbw-'.$design
            .($place['m']['left'] ? ' kbw-ml' : '').($place['m']['top'] ? ' kbw-mt' : '')
            .($place['d']['left'] ? ' kbw-dl' : '').($place['d']['top'] ? ' kbw-dt' : '')
            .(($c['show_phone'] ?? true) ? '' : ' kbw-mo')
            .(($c['show_desktop'] ?? true) ? '' : ' kbw-do');

        $px = static fn (?int $v): string => $v === null ? 'auto' : $v.'px';

        $style = '--k:'.rtrim(rtrim(number_format($size / self::BASE, 4, '.', ''), '0'), '.');

        foreach (['m', 'd'] as $dev) {
            foreach (['t', 'r', 'b', 'l'] as $s) {
                $style .= ';--'.$dev.$s.':'.$px($place[$dev][$s]);
            }
        }

        $faces = [];
        $symbols = '';

        if ($design === 'G') {
            $style .= ';--spd:'.(self::SPEEDS[$c['speed'] ?? 'calm'] ?? self::SPEEDS['calm']);
            $faces = self::faces((int) ($c['women'] ?? 2), (int) ($c['men'] ?? 3));

            foreach (['M', 'W'] as $g) {
                if (in_array($g, array_column($faces, 'g'), true)) {
                    $symbols .= self::SYMBOLS[$g];
                }
            }
        }

        $bubble = ($c['bubble'] ?? 'once') === 'once' && ($lines['welcome'] !== '' || $lines['support'] !== '');
        $href = $this->link($c, $lines);
        $tab = $tabPage ? $this->tab($c, $arabic, $mirror, $href) : null;
        $squeezed = app(CartPage::class)->squeezed();

        return [
            'css' => self::css($design, $bubble, $squeezed)
                .($tab === null ? '' : self::TAB_CSS.($tab['space']
                    ? sprintf(self::TAB_PHONE, $tab['right'] ? 'right' : 'left').($squeezed ? self::TAB_SQUEEZE : '')
                    : self::TAB_PHONE_FLOAT)),
            'tab' => $tab,
            'guard' => $tab === null ? '' : self::TAB_BUBBLE_GUARD,
            'class' => $class,
            'style' => $style,
            'design' => $design,
            'rings' => self::RINGS[$design],
            'faces' => $faces,
            'symbols' => $symbols,
            'icon' => self::ICON,
            'href' => $href,
            'label' => (string) __(self::KEYS['open']),
            'close' => (string) __(self::KEYS['close']),
            // The capsule is G's; E draws its own label from line 1.
            'capsule' => $design === 'G' && ($c['capsule'] ?? true) && ($lines['cap1'] !== '' || $lines['cap2'] !== ''),
            'tag' => $design === 'E' && $lines['cap1'] !== '',
            'bubble' => $bubble,
            'lines' => $lines,
            'bubble_key' => self::BUBBLE_KEY,
        ];
    }
}
