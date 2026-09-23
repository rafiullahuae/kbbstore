<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The slim footer — one bar, on the cart page and the checkout.
 *
 * ── WHY THIS IS NOT THE SITE FOOTER ─────────────────────────────────────────
 *
 * partials/footer.blade.php is the shop's footer: columns of links, a
 * newsletter box, payment marks, several hundred pixels of it. The checkout
 * declares `bare` and the cart declares `no-footer`, so neither has ever drawn
 * it, and that is deliberate — a page asking for money should not offer twenty
 * ways to leave.
 *
 * The owner asked for something else: "i need a seperate footer ... overall
 * height of the new footer i want very less, like a bar type." So this is a
 * second, much smaller thing, with its own words and its own switches, and it
 * does not touch the site footer or either of those two sections.
 *
 * ── WHERE THE WORDS LIVE, AND WHY NOT IN InterfaceStrings ───────────────────
 *
 * In `settings`. App\Services\Translation\InterfaceStrings is for the fixed
 * wording of the interface; its own header names the exclusion this falls
 * under — "anything the owner types into an admin setting ... the header's
 * support label, the cart panel's wording, the trust claims". A support number
 * and a company name are that: they change without a release, and giving them
 * a second English source would mean two places to correct and one of them
 * silently wrong.
 *
 * ── THE TWO SWITCHES ARE SEPARATE ON PURPOSE ────────────────────────────────
 *
 * `co_on` ships ON and `cart_on` ships OFF. "don't disturb anything in cart
 * page" — so the cart renders exactly as it does today until somebody turns
 * this on, and the checkout gains the bar the owner asked for.
 */
class SlimFooter
{
    /**
     * key => [type, label, default, help, options]
     *
     * Types: text, bool, range, select — the same four the checkout screen
     * already draws, so this screen needs no renderer of its own.
     */
    public const SCHEMA = [
        // ── Where it shows ──
        'co_on'      => ['bool', 'Show it on the checkout', true,
                         'The bar under the Payment section, at the foot of the checkout.'],
        'cart_on'    => ['bool', 'Show it on the cart page', false,
                         'Off, so the cart page is exactly as it is today until you turn this on. It is the same bar with the same words — there is no second set to keep in step.'],

        // ── Layout ──
        /*
         * THREE SHAPES OF THE SAME CONTENT, and the order is by height. The
         * owner asked for "a bar type" and for "some options to choose from",
         * so `bar` ships and the other two are there for a shop that wants the
         * contact details to carry more weight.
         */
        /*
         * SHAPE AND ALIGNMENT ARE TWO CONTROLS, NOT ONE LIST OF SIX.
         *
         * The obvious way to offer more looks is more entries in this list --
         * "one line centred", "one line justified", and so on. That is the
         * same two decisions written out four times, and every future option
         * doubles it again. Shape is the STRUCTURE (one row, two columns, a
         * column, one block per row) and Align is where that structure sits on
         * its axis, so four by four is sixteen looks from eight words.
         */
        'variant'    => ['select', 'Shape', 'bar',
                         'The structure. All four carry the same words; what changes is how they are grouped. Pair it with Alignment below.', [
                             'bar'   => 'One line — everything on a single row',
                             'split' => 'Two columns — brand on one side, contact on the other',
                             'stack' => 'Stacked — brand, then help, then the links',
                             'rows'  => 'Ruled rows — each block on its own line, with a hairline between',
                         ]],
        'align'      => ['select', 'Alignment', 'between',
                         'Where the content sits across the bar. "Spread to both edges" pushes the first block hard left and the last hard right, with the gap taken up in the middle — the classic footer look, and the one that reads worst when there are only two blocks left.', [
                             'start'   => 'Left (right in Arabic)',
                             'center'  => 'Centred',
                             'end'     => 'Right (left in Arabic)',
                             'between' => 'Spread to both edges',
                         ]],
        'tone'       => ['select', 'Background', 'cream',
                         'Tones from the shop\'s own palette rather than a colour box: a footer that can be set to anything is a footer that can be set to something unreadable.', [
                             'cream' => 'Cream — the page\'s own ground',
                             'white' => 'White',
                             'ink'   => 'Dark',
                             'pink'  => 'Blush — the shop\'s own soft pink',
                             'clear' => 'None — the page shows through',
                         ]],
        'divider'    => ['bool', 'Line above the bar', true,
                         'A hairline between the page and the footer. Off on the dark tone usually reads better, because the colour already separates them.'],
        'line_w'     => ['range', 'Line thickness', 1,
                         'Only while the line above is on.',
                         ['min' => 1, 'max' => 6, 'step' => 1, 'unit' => 'px']],
        'radius'     => ['range', 'Rounded top corners', 0,
                         'Rounds the two top corners, which lifts the bar off the page rather than sitting it flush. Works best with a tone that is not the page\'s own.',
                         ['min' => 0, 'max' => 32, 'step' => 2, 'unit' => 'px']],
        'shadow'     => ['bool', 'Lift it off the page', false,
                         'A soft shadow above the bar. It reads as a separate surface rather than as the end of the page — worth it with a rounded or contrasting tone, and usually not otherwise.'],
        'max_w'      => ['range', 'Content width', 1240,
                         'The band the words are laid out in. The bar itself always runs the full width of the window; this is where its content stops.',
                         ['min' => 600, 'max' => 1600, 'step' => 20, 'unit' => 'px']],
        'sep'        => ['select', 'Separator between blocks', 'none',
                         'A mark in the space between the brand, the help line, the contacts and the links. Drawn only on the one-line shapes, where blocks sit side by side and a separator has somewhere to go.', [
                             'none'  => 'None — the gap alone',
                             'dot'   => 'Middle dot ·',
                             'pipe'  => 'Vertical bar |',
                             'slash' => 'Slash /',
                         ]],
        'upper'      => ['bool', 'Brand in capitals', true,
                         'On is the wordmark as it reads today. Off leaves whatever capitalisation is typed into the brand field.'],
        /*
         * THE PHONE GLYPH IS ALREADY WHATSAPP'S, and always has been — the
         * path in the partial is the speech bubble with the handset in it, not
         * a telephone. What it was not is RECOGNISABLE: drawn in currentColor
         * it reads as a generic contact icon at 15px, which is why the owner
         * asked for "the whatsapp icon" beside a number that already had one.
         *
         * So this colours it rather than swapping it. `mono` is what it does
         * today and is the default; `brand` is WhatsApp's own #25D366, which
         * is a constant here and not a colour box for the same reason the
         * payment marks are constants.
         */
        'phone_mark' => ['select', 'The mark beside the phone', 'brand',
                         'The glyph is WhatsApp\'s either way. This is whether it is drawn in the bar\'s own ink or in WhatsApp green.', [
                             'mono'  => 'The bar\'s own colour — what it does today',
                             'brand' => 'WhatsApp green',
                         ]],
        'icons_on'   => ['bool', 'Marks beside the phone and email', true,
                         'The small WhatsApp and envelope glyphs. Off leaves the number and the address as plain text, which is shorter and quieter.'],
        'pad_y'      => ['range', 'Height', 12,
                         'Padding above and below. With "One line" this IS the height of the bar — 12 gives about 44px in total.',
                         ['min' => 2, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'pad_x'      => ['range', 'Side padding', 20,
                         'Between the screen edge and the first word.',
                         ['min' => 0, 'max' => 48, 'step' => 2, 'unit' => 'px']],
        'gap'        => ['range', 'Space between blocks', 18,
                         'Between the brand, the help line, the contacts and the links. On a phone they wrap, and this is the gap on both axes.',
                         ['min' => 4, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'font'       => ['range', 'Text size', 100,
                         'Everything in the bar except the brand, which has its own size below. A multiplier on 12px.',
                         ['min' => 70, 'max' => 140, 'step' => 5, 'unit' => '%']],
        'brand_size' => ['range', 'Brand size', 100,
                         'The wordmark alone. A multiplier on 14px.',
                         ['min' => 70, 'max' => 190, 'step' => 5, 'unit' => '%']],
        'top_on'     => ['bool', 'Back-to-top arrow', true,
                         'A small arrow at the far end of the bar. It scrolls the page rather than jumping, and it is hidden from screen readers only if it has no label.'],
        'top_style'  => ['select', 'Arrow style', 'ring', 'How the arrow is drawn.', [
                             'ring'  => 'Outlined circle',
                             'solid' => 'Filled circle',
                             'plain' => 'Just the arrow',
                         ]],

        /*
         * ── THE PHONE GETS ITS OWN SHAPE ──────────────────────────────────
         *
         * The owner's pick, in his words: "06 Ruled rows is final and for
         * desktop 03" — ruled rows on a phone, spread-to-both-edges on a
         * desktop. One `variant` cannot be both.
         *
         * ONE SWITCH AND THEN SIX OVERRIDES, rather than six "same as desktop"
         * sentinels. A sentinel value in a select is a value the stylesheet has
         * to know is not a value; a switch is a switch, and while it is off NOT
         * ONE mobile class or property is emitted, so a shop that never touches
         * it renders exactly the bar it rendered before.
         *
         * 900px AND NOT THIS FILE'S OWN 640. The bar is a checkout bar now, and
         * `CheckoutPage::MOBILE_MAX` is 900: two screens disagreeing about
         * where a phone stops is how an owner ends up with a footer in one
         * shape and the page above it in the other. The 640 rules already in
         * the partial are the bar's own wrapping and are left alone.
         */
        'mobile_on'  => ['bool', 'Give the phone its own shape', true,
                         'On, because a bar that reads as one line on a desktop reads as a scramble at 390px. Everything below is read only while this is on; with it off the phone gets the desktop\'s settings exactly as it always did.'],
        'm_variant'  => ['select', 'Shape on a phone', 'rows',
                         'At 900px and below.', [
                             'bar'   => 'One line — everything on a single row',
                             'split' => 'Two columns — brand on one side, contact on the other',
                             'stack' => 'Stacked — brand, then help, then the links',
                             'rows'  => 'Ruled rows — each block on its own line, with a hairline between',
                         ]],
        'm_align'    => ['select', 'Alignment on a phone', 'start', 'At 900px and below.', [
                             'start'   => 'Left (right in Arabic)',
                             'center'  => 'Centred',
                             'end'     => 'Right (left in Arabic)',
                             'between' => 'Spread to both edges',
                         ]],
        'm_pad_y'    => ['range', 'Height on a phone', 10,
                         'Padding above and below, at 900px and below.',
                         ['min' => 2, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'm_pad_x'    => ['range', 'Side padding on a phone', 20,
                         'Between the screen edge and the first word. 20 lines the bar up with the checkout\'s own page padding.',
                         ['min' => 0, 'max' => 48, 'step' => 2, 'unit' => 'px']],
        'm_gap'      => ['range', 'Space between blocks on a phone', 14,
                         'Between the brand, the help line, the contacts and the links.',
                         ['min' => 4, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'm_font'     => ['range', 'Text size on a phone', 100,
                         'A multiplier on 12px, at 900px and below.',
                         ['min' => 70, 'max' => 140, 'step' => 5, 'unit' => '%']],

        // ── Content ──
        'brand'      => ['text', 'Brand name', 'K-BEAUTY BLISS',
                         'The wordmark at the start of the bar. Leave it empty to draw no brand at all.'],
        'byline'     => ['text', 'Under the brand', 'by FUSION DISTRICT GROUP.',
                         'The company line. Empty draws nothing.'],
        'help_title' => ['text', 'Help heading', 'Need Help?',
                         'Empty draws nothing.'],
        'help_sub'   => ['text', 'Help line', '24/7 Customer Support',
                         'Empty draws nothing.'],
        'phone'      => ['text', 'Phone shown', '+971 58 505 2611',
                         'Exactly as it should read. Empty removes the phone and its mark.'],
        'phone_url'  => ['text', 'Phone link', 'https://wa.me/971585052611',
                         'Where tapping the number goes — a wa.me link for WhatsApp, or tel: to dial. Only http, https, tel and mailto links are accepted; anything else is drawn as plain text rather than as a link.'],
        'email'      => ['text', 'Email shown', 'info@kbeautybliss.com',
                         'Empty removes the email and its mark. It is linked with mailto: automatically.'],
        'l1_text'    => ['text', 'First link', 'Shipping policy',
                         'Empty removes the link.'],
        'l1_url'     => ['text', 'First link goes to', '/shipping-policy',
                         'A path on this shop, or a full https:// address.'],
        'l2_text'    => ['text', 'Second link', 'Terms of service', 'Empty removes the link.'],
        'l2_url'     => ['text', 'Second link goes to', '/terms-of-service',
                         'A path on this shop, or a full https:// address.'],
        'l3_text'    => ['text', 'Third link', '', 'Empty, so no third link is drawn. Returns and refunds is the usual one.'],
        'l3_url'     => ['text', 'Third link goes to', '',
                         'A path on this shop, or a full https:// address.'],
        'copy'       => ['text', 'Small print', '',
                         'A last line under the rest — a copyright, a licence number, a registered address. Empty draws nothing at all.'],
        'top_label'  => ['text', 'Arrow label', 'Back to top',
                         'Read out by a screen reader; never shown. Empty hides the arrow from screen readers entirely, which is right only if the page has another way back up.'],

        // ── Payment marks ──
        /*
         * THE ARTWORK IS A CONSTANT AND THESE ARE SWITCHES OVER IT.
         *
         * App\Support\PaymentMarkArt holds six drawings as a hardcoded
         * constant with no setting, no database read and no interpolation
         * anywhere in it, and its own header says why: they are printed
         * unescaped, so an SVG assembled from a setting would be a stored-XSS
         * sink on the page every order is placed from. Nothing here changes
         * that -- these decide WHICH of the six constants is printed, and that
         * is all they can do.
         *
         * Off by default: a row of marks is a claim about what the shop
         * accepts, and a claim nobody asked for is one nobody has checked.
         */
        'pay_on'     => ['bool', 'Show the payment marks', false,
                         'A row of scheme marks in the bar. Off by default — it is a claim about what this shop accepts, so it is worth turning on deliberately and switching off the ones that are not true.'],
        'pay_visa'   => ['bool', 'Visa', true, 'Only drawn while the row above is on.'],
        'pay_mc'     => ['bool', 'Mastercard', true, 'Only drawn while the row above is on.'],
        'pay_apple'  => ['bool', 'Apple Pay', true, 'Only drawn while the row above is on.'],
        'pay_google' => ['bool', 'Google Pay', true, 'Only drawn while the row above is on.'],
        'pay_tabby'  => ['bool', 'Tabby', false, 'Only drawn while the row above is on.'],
        'pay_tamara' => ['bool', 'Tamara', false, 'Only drawn while the row above is on.'],
    ];

    public const TABS = [
        'pages'   => ['Where it shows', 'One bar, two pages, two switches. The cart ships off so that page is untouched until you say otherwise.',
                      ['co_on', 'cart_on']],
        'phone'   => ['On a phone', 'The same bar at 900px and below, with its own shape. Ruled rows is what ships here and spread-to-both-edges is what ships on a desktop, which is the pair the owner chose; the switch at the top hands the phone back to the desktop\'s settings.',
                      ['mobile_on', 'm_variant', 'm_align', 'm_pad_y', 'm_pad_x', 'm_gap', 'm_font']],
        'layout'  => ['Shape & size', 'Four structures and four alignments, which is sixteen looks from two controls. "One line" left-aligned is the shortest, and is what ships.',
                      ['variant', 'align', 'tone', 'divider', 'line_w', 'radius', 'shadow',
                       'pad_y', 'pad_x', 'max_w', 'gap', 'font', 'brand_size', 'upper', 'phone_mark',
                       'sep', 'icons_on', 'top_on', 'top_style']],
        'content' => ['Content', 'Every word in the bar. Anything left empty is not drawn at all, rather than drawn empty — so the bar can be as short as a brand and a phone number.',
                      ['brand', 'byline', 'help_title', 'help_sub', 'phone', 'phone_url', 'email',
                       'l1_text', 'l1_url', 'l2_text', 'l2_url', 'l3_text', 'l3_url',
                       'copy', 'top_label']],
        'marks'   => ['Payment marks', 'Off by default. The drawings themselves are a hardcoded constant — these switches only decide which of the six is printed.',
                      ['pay_on', 'pay_visa', 'pay_mc', 'pay_apple', 'pay_google', 'pay_tabby', 'pay_tamara']],
    ];

    private const PREFIX = 'slimfooter_';

    /** key => the custom property it is emitted as, in px. */
    private const VARS = [
        'pad_y'  => '--sf-pady',
        'pad_x'  => '--sf-padx',
        'gap'    => '--sf-gap',
        'max_w'  => '--sf-max',
        'radius' => '--sf-r',
        'line_w' => '--sf-lw',
        'm_pad_y' => '--sf-m-pady',
        'm_pad_x' => '--sf-m-padx',
        'm_gap'   => '--sf-m-gap',
    ];

    /** key => the custom property it is emitted as, as a unitless factor. */
    private const RATIO_VARS = [
        'font'       => '--sf-f',
        'brand_size' => '--sf-bf',
        'm_font'     => '--sf-m-f',
    ];

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $saved = $this->settings->get(self::PREFIX.$key, null);
            $out[$key] = $saved === null ? $def[2] : $this->cast($key, $saved);
        }

        return $out;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key])) {
                $this->settings->set(self::PREFIX.$key, $this->cast($key, $value));
            }
        }
    }

    private function cast(string $key, mixed $value): mixed
    {
        $def = self::SCHEMA[$key] ?? null;

        if ($def === null) {
            return $value;
        }

        return match ($def[0]) {
            'bool' => (bool) $value,
            'range' => max((int) $def[4]['min'], min((int) $def[4]['max'], (int) $value)),
            /*
             * A SELECT MAY ONLY EVER HOLD ONE OF ITS OWN OPTIONS. Both of them
             * are printed into a class name, so a value from anywhere else
             * would be a class this stylesheet has never heard of at best.
             * Anything unrecognised falls back to the shipped default rather
             * than being stored.
             */
            'select' => isset($def[4][(string) $value]) ? (string) $value : (string) $def[2],
            /*
             * Trimmed and capped. These are printed into the page, escaped at
             * the point of use; the cap is so a paste accident cannot put a
             * novel in the footer of every order.
             */
            'text' => mb_substr(trim((string) $value), 0, 160),
            default => $value,
        };
    }

    /**
     * The scheme marks this shop says it takes, as ready-to-print drawings.
     *
     * Empty while the row is off, so the caller needs no second condition.
     * The artwork is App\Support\PaymentMarkArt's hardcoded constant and
     * nothing user-supplied reaches it — see the note on `pay_on`.
     *
     * @return list<string>
     */
    public function paymentMarks(): array
    {
        $c = $this->all();

        if (! $c['pay_on']) {
            return [];
        }

        $out = [];

        foreach (\App\Support\PaymentMarkArt::marks() as $key => $art) {
            if (! empty($c[$key])) {
                $out[] = $art;
            }
        }

        return $out;
    }

    /** True when this shop draws the bar on the page named. */
    public function onCheckout(): bool
    {
        return (bool) $this->get('co_on');
    }

    public function onCart(): bool
    {
        return (bool) $this->get('cart_on');
    }

    /**
     * A link the footer is allowed to draw, or null.
     *
     * ── WHY THIS EXISTS FOR A SETTING ONLY AN ADMIN CAN WRITE ───────────────
     *
     * `javascript:` in an href is script that runs as the shopper, on the page
     * they are paying from. The people who can reach this screen are trusted,
     * but "trusted" is a statement about intent and not about whether an
     * account has ever been taken. The cost of refusing three schemes here is
     * nothing, and the value on the day it matters is the whole checkout.
     *
     * A relative path is passed through Url::to() so a subdirectory
     * deployment cannot produce a link that escapes the app; an absolute
     * http(s), mailto or tel link is used as typed. Anything else returns
     * null, and the caller draws the words as plain text.
     */
    public function url(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, '/')) {
            return \App\Support\Url::to($raw);
        }

        $scheme = strtolower((string) parse_url($raw, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true) ? $raw : null;
    }

    /**
     * Structural choices as classes. Leading space included, or an empty
     * string — the view interpolates it straight after `kbb-slimfoot`.
     */
    public function bodyClass(): string
    {
        $c = $this->all();

        /*
         * ONLY WHAT IS NOT THE DEFAULT, for the three selects whose default
         * the stylesheet's base rules already are. `sf-a-start`, `sf-sep-none`
         * and `sf-top-ring` would be classes with no rules behind them, and a
         * class with no rules is a thing a future reader has to look up before
         * they can be sure it does nothing.
         *
         * `variant` and `tone` are different: every one of their values has
         * rules, including the shipped one, so they are always named.
         */
        $classes = array_filter([
            'sf-'.$c['variant'],
            'sf-t-'.$c['tone'],
            $c['align'] === 'start' ? '' : 'sf-a-'.$c['align'],
            $c['sep'] === 'none' ? '' : 'sf-sep-'.$c['sep'],
            $c['top_style'] === 'ring' ? '' : 'sf-top-'.$c['top_style'],
            $c['divider'] ? '' : 'sf-noline',
            $c['shadow'] ? 'sf-lift' : '',
            $c['upper'] ? '' : 'sf-nocaps',
            $c['icons_on'] ? '' : 'sf-noic',
            $c['phone_mark'] === 'brand' ? 'sf-wa' : '',
            /* Every mobile class is gated on the switch, so "off" emits none of
               them and the media query below has nothing to match. */
            $c['mobile_on'] ? 'sf-msplit' : '',
            $c['mobile_on'] ? 'sf-m-'.$c['m_variant'] : '',
            $c['mobile_on'] && $c['m_align'] !== 'start' ? 'sf-ma-'.$c['m_align'] : '',
        ]);

        return ' '.implode(' ', $classes);
    }

    /**
     * Only what the owner has actually moved, so the attribute is short enough
     * to read in a page source when something looks wrong.
     */
    public function cssVariables(): string
    {
        $c = $this->all();
        $out = [];

        foreach (self::VARS as $key => $prop) {
            if ($c[$key] !== self::SCHEMA[$key][2]) {
                $out[] = $prop.':'.$c[$key].'px';
            }
        }

        foreach (self::RATIO_VARS as $key => $prop) {
            if ($c[$key] !== self::SCHEMA[$key][2]) {
                $out[] = $prop.':'.rtrim(rtrim(number_format((int) $c[$key] / 100, 2, '.', ''), '0'), '.');
            }
        }

        return implode(';', $out);
    }

    /** `style="..."`, or nothing at all. */
    public function styleAttr(): string
    {
        $vars = $this->cssVariables();

        return $vars === '' ? '' : ' style="'.e($vars).'"';
    }
}
