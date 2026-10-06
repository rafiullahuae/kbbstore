<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The mobile menu sheet: appearance and behaviour, all editable.
 *
 * Everything a merchant might reasonably want to change is a setting rather
 * than a code change — height, rule, card, columns, search, ordering. Defaults
 * match the approved design (80% sheet, search first, cream card, rule under
 * the parent, two-column children).
 */
class MobileMenu
{
    /** key => [type, label, default, help, options] */
    public const SCHEMA = [
        // --- panel ---
        /*
         * WHERE THE MENU COMES FROM. (Lane MN, 6 October)
         *
         * The owner: "i need the same dual columns design which we have it
         * already and live, we just need to open from left side, that's it" —
         * and then "the panel design i need glossy glass type, which we have in
         * the footer for app install capsule". So it ships ON at 'left', as he
         * asked: a full-height glass panel sliding in from the left edge (the
         * right in Arabic), with everything inside — rows, the two columns,
         * search, account, support — exactly as before.
         *
         * 'bottom' is the undo: the sheet that rises from the bottom, with its
         * markup and behaviour byte for byte what it was. The only thing that
         * differs between the two in the HTML is the `mm-left` class
         * bodyClass() adds; resources/css/kbb/kbb.css and initMobileChrome() in
         * resources/js/kbb/home.js key everything side-only off that class.
         * MobileMenuOpensFromTest pins both halves.
         */
        'open_from'     => ['select', 'Menu opens from',  'left', 'Left slides a glass panel in from the side (from the right in Arabic). Bottom is the sheet that rises from the bottom, as before.', ['left' => 'Left (glass panel)', 'bottom' => 'Bottom (sheet)']],
        'height'        => ['range',  'Sheet height',        80, 'Percentage of the screen the sheet covers. Bottom sheet only; the side panel is always full height.', ['min' => 40, 'max' => 100, 'step' => 5, 'unit' => '%']],
        'radius'        => ['range',  'Corner radius',       20, '', ['min' => 0, 'max' => 34, 'step' => 2, 'unit' => 'px']],
        'slide_speed'   => ['range',  'Slide duration',     380, 'The side panel runs at about two thirds of this (380 ms → 260 ms): it travels a shorter way.', ['min' => 150, 'max' => 700, 'step' => 10, 'unit' => 'ms']],
        'scrim'         => ['range',  'Backdrop darkness',   50, '', ['min' => 0, 'max' => 80, 'step' => 5, 'unit' => '%']],
        /*
         * 'icon_style' WAS HERE, AND IT HAD NEVER DONE ANYTHING.        Lane AD
         *
         * It was a select of seven — Cross, Spin cross, Down arrow, Collapse,
         * Slow pinch, Cross in pink, Tapered — whose values are the CSS classes
         * `ico-spin`, `ico-arrow` and so on. Those rules are real and are still
         * in kbb.css (`.burger.ico-spin span{...}`). What never existed is
         * anything that PUT one of those classes on an element: bodyClass()
         * below emits mm-card-*, mm-rule-* and mm-nocounts, and has never
         * emitted this one. So the owner could pick any of the seven and the
         * icon did exactly what it did before.
         *
         * REMOVED RATHER THAN WIRED, because the question it asks now has a
         * better answer somewhere else. partials/menu-icon.blade.php says it in
         * its own comment — "Deliberately not .burger: that class carries the
         * old bar styling" — and renders `kbbmi kbbmi-{menu_icon}` from
         * HeaderSettings instead. Appearance → Header → Icon is where the menu
         * icon is chosen now, and it is a richer control than this one: a
         * family (tiles, dots, bars), an effect speed, a size and three
         * colours, all of which reach the element. Wiring this back would put a
         * second, poorer answer to one question on a second screen, and the
         * `.burger` rules it targets are the old markup that partial was
         * written to stop using.
         */
        'show_grab'     => ['bool',   'Grab handle',       true, 'The small bar at the top edge. Bottom sheet only.'],
        'show_close'    => ['bool',   'Close button',      true, ''],

        // --- header of the sheet ---
        'show_search'   => ['bool',   'Search field',      true, 'Sits where a heading would be.'],
        'search_text'   => ['text',   'Search placeholder', 'Search the menu…', ''],
        'show_heading'  => ['bool',   'Heading row',      false, 'Off by default — the handle and close already do that job.'],
        'heading_text'  => ['text',   'Heading',          'Menu', ''],

        // --- rows ---
        'density'       => ['select', 'Row density',    'compact', '', ['compact' => 'Compact', 'regular' => 'Regular', 'roomy' => 'Roomy']],
        'child_columns' => ['select', 'Sub-item columns',     '2', '', ['1' => 'One', '2' => 'Two']],
        'show_counts'   => ['bool',   'Item counts',       true, 'The number beside a parent.'],
        'single_open'   => ['bool',   'One section at a time', true, 'Opening a parent closes its siblings.'],

        // --- highlight ---
        'card_style'    => ['select', 'Open section style', 'cream', '', ['cream' => 'Cream card', 'plain' => 'No card', 'tinted' => 'Pink tint', 'pill' => 'Dark pill']],
        'rule_position' => ['select', 'Vertical rule',  'children', '', ['children' => 'Under the parent only', 'full' => 'Whole section', 'edge' => 'On the card border', 'none' => 'None']],
        'rule_width'    => ['range',  'Rule thickness',       2, '', ['min' => 1, 'max' => 5, 'step' => 1, 'unit' => 'px']],
        'rule_colour'   => ['colour', 'Rule colour',   '#E0567B', ''],
        'card_bg'       => ['colour', 'Card background', '#FFF8F5', ''],
        'parent_bg'     => ['colour', 'Open parent background', '#FFF3F6', ''],
        'parent_colour' => ['colour', 'Open parent text', '#C13E63', ''],

        // --- footer ---
        'show_support'  => ['bool',   'Support bar',      false, 'A WhatsApp strip pinned at the bottom. Off by default.'],
        'support_text'  => ['text',   'Support wording',   '24/7 support', ''],
        'show_account'  => ['bool',   'Account links',     true, 'Sign in, register and wishlist.'],
        'account_label' => ['text',   'Account heading',   'Account', ''],

        /*
         * ── V4 "TWO-TONE" (Lane M4, 6 October) ─────────────────────────────
         *
         * The owner picked V4 from docs/mv-preview ("V4 is fine. please
         * proceed, but give full control of font sizes, row height, paddings,
         * upper custom links, panel size, on click sub menu opening animation
         * etc."), so it ships ON. 'classic' is the undo: the panel exactly as
         * 2.60.413 drew it — bodyClass() and cssVariables() answer byte for
         * byte what they answered before, and no quick-link row is printed.
         * MobileMenuTwoToneTest pins both halves.
         *
         * EVERY SIZE BELOW IS A BASE AT A 390px PHONE. The stylesheet scales it
         * with the screen (--u in kbb.css: x0.92 at 320, x1.08 at 430 and
         * above), and rows and quick links never go under a 44px tap height
         * whatever is chosen here. Only a value moved off its default reaches
         * the page, as one custom property on the panel.
         *
         * Appended, so every row ModuleSchemaEquivalenceTest recorded for the
         * keys above keeps its line.
         */
        'menu_style'    => ['select', 'Menu style', 'v4', 'V4 two-tone: a pink top band with search and quick links, the list on clearer glass. Classic is the panel exactly as 2.60.413 drew it — every control below this card does nothing in Classic.', ['v4' => 'V4 two-tone', 'classic' => 'Classic glass (2.60.413)']],
        'show_chips'    => ['bool',   'Quick links row', true, 'The row of links under the search field. Edit them in the Quick links card.'],
        'sale_fill'     => ['bool',   'Super Sale highlight', true, 'A menu row with a highlight colour (Super Sale) keeps its filled background, darkened so its white text passes AA. Off: red text, no fill.'],

        'panel_w_pct'   => ['range',  'Panel width',          88, 'Share of the screen the panel covers, between the two limits below.', ['min' => 60, 'max' => 100, 'step' => 1, 'unit' => '%']],
        'panel_w_min'   => ['range',  'Panel width · at least', 240, '', ['min' => 200, 'max' => 360, 'step' => 10, 'unit' => 'px']],
        'panel_w_max'   => ['range',  'Panel width · at most', 420, 'A tablet gets a panel, not a sheet across the room.', ['min' => 300, 'max' => 600, 'step' => 10, 'unit' => 'px']],
        'band_pad'      => ['range',  'Top band padding',      8, 'Space above the search field and below the quick links.', ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'search_h'      => ['range',  'Search field height',  44, 'Never under 44px: it is a tap target.', ['min' => 44, 'max' => 56, 'step' => 1, 'unit' => 'px']],
        'search_fs'     => ['range',  'Search text size',     13, '', ['min' => 11, 'max' => 18, 'step' => 1, 'unit' => 'px']],
        'chip_fs'       => ['range',  'Quick link text size', 12, '', ['min' => 10, 'max' => 16, 'step' => 1, 'unit' => 'px']],
        'chip_h'        => ['range',  'Quick link height',    32, 'The pill you see. Its tap area is never under 44px.', ['min' => 24, 'max' => 44, 'step' => 1, 'unit' => 'px']],
        'chip_gap'      => ['range',  'Quick link gap',        6, '', ['min' => 2, 'max' => 16, 'step' => 1, 'unit' => 'px']],
        'chip_radius'   => ['range',  'Quick link corners',   24, '24 is a full pill.', ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'row_h'         => ['range',  'Row height',           44, 'Never under 44px: that is a tap target.', ['min' => 44, 'max' => 64, 'step' => 1, 'unit' => 'px']],
        'row_fs'        => ['range',  'Row text size',        14, '', ['min' => 12, 'max' => 18, 'step' => 1, 'unit' => 'px']],
        'row_py'        => ['range',  'Row padding · top and bottom', 8, '', ['min' => 0, 'max' => 16, 'step' => 1, 'unit' => 'px']],
        'row_px'        => ['range',  'Row padding · sides',  14, '', ['min' => 8, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'sub_fs'        => ['range',  'Sub-item text size',   12, 'The two-column items inside an open section.', ['min' => 10, 'max' => 16, 'step' => 1, 'unit' => 'px']],
        'sub_h'         => ['range',  'Sub-item height',      32, '', ['min' => 28, 'max' => 48, 'step' => 1, 'unit' => 'px']],
        'col_gap'       => ['range',  'Two-column gap',        0, '', ['min' => 0, 'max' => 12, 'step' => 1, 'unit' => 'px']],
        'grp_fs'        => ['range',  'Section heading size', 10, 'ACCOUNT and the other small headings.', ['min' => 8, 'max' => 14, 'step' => 1, 'unit' => 'px']],
        'icon_size'     => ['range',  'Arrow size',           24, 'The round arrow beside a section.', ['min' => 18, 'max' => 36, 'step' => 1, 'unit' => 'px']],

        'sub_anim'      => ['select', 'How a section opens', 'slide', 'Panel open/close speed is Panel → Slide duration.', ['slide' => 'Slide down', 'fade' => 'Fade', 'expand' => 'Expand', 'none' => 'None (instant)']],
        'sub_ms'        => ['range',  'Opening duration',    220, '', ['min' => 0, 'max' => 600, 'step' => 20, 'unit' => 'ms']],
        'sub_ease'      => ['select', 'Opening easing', 'ease-out', '', ['ease-out' => 'Ease out', 'spring' => 'Spring', 'linear' => 'Linear']],
    ];

    /**
     * SCHEMA key => [custom property, suffix], printed on the panel only when
     * the value has moved off its default. Every value is an int from cast(),
     * so nothing a setting holds is printed as text.
     */
    private const SIZE_VARS = [
        'band_pad' => '--m-bp', 'search_h' => '--m-sh', 'search_fs' => '--m-sf',
        'chip_fs' => '--m-cf', 'chip_h' => '--m-ch', 'chip_gap' => '--m-cg',
        'row_h' => '--m-rh', 'row_fs' => '--m-rf', 'row_py' => '--m-ry', 'row_px' => '--m-rx',
        'sub_fs' => '--m-qf', 'sub_h' => '--m-qh', 'col_gap' => '--m-cc', 'grp_fs' => '--m-gf',
        'icon_size' => '--m-ic',
    ];

    /** sub_ease option => the timing function it prints. Constants, never a setting. */
    private const EASING = [
        'ease-out' => 'cubic-bezier(.2,.7,.3,1)',
        'spring' => 'cubic-bezier(.34,1.56,.64,1)',
        'linear' => 'linear',
    ];

    /** sub_anim option => panel class. 'none' prints none: the section snaps, as in Classic. */
    private const ANIM_CLASS = ['slide' => 'mm-an-sl', 'fade' => 'mm-an-fd', 'expand' => 'mm-an-ex'];

    /*
     * ── THE QUICK LINKS ("upper custom links") ─────────────────────────────
     *
     * Stored beside the SCHEMA values, under `chips` in the same `mobile_menu`
     * setting, so the shop reads them out of the map it already loaded — no
     * second key, no lookup that misses. Not a SCHEMA field because a list is
     * not one of ModuleSchema's types; cleanChips() is its whole cast.
     *
     * The five defaults are V4's, pointed at the routes the shop registers
     * (MenuTargets::COLLECTIONS names each one's route): /super-sale/,
     * /new-in/, /best-sellers/, /everything-under-54-aed/ and /brands/, the
     * brand directory (A to Z). Not /shop/?orderby=…, which the preview guessed.
     */
    public const CHIP_MAX = 8;

    public const CHIP_LABEL_MAX = 40;

    /** accent => label. A chip stores the key; kbb.css owns the colours. */
    public const CHIP_ACCENTS = ['' => 'White', 'sale' => 'Sale red', 'pink' => 'Pink', 'gold' => 'Gold', 'green' => 'Green', 'ink' => 'Dark'];

    public const CHIPS_DEFAULT = [
        ['label' => 'Super Sale', 'label_ar' => 'تخفيضات كبرى', 'url' => '/super-sale/', 'accent' => 'sale', 'on' => true],
        ['label' => 'New In', 'label_ar' => 'وصل حديثًا', 'url' => '/new-in/', 'accent' => '', 'on' => true],
        ['label' => 'Best Sellers', 'label_ar' => 'الأكثر مبيعًا', 'url' => '/best-sellers/', 'accent' => '', 'on' => true],
        ['label' => 'Under 54 AED', 'label_ar' => 'أقل من AED 54', 'url' => '/everything-under-54-aed/', 'accent' => '', 'on' => true],
        ['label' => 'Brands A–Z', 'label_ar' => 'الماركات أ–ي', 'url' => '/brands/', 'accent' => '', 'on' => true],
    ];

    /**
     * tab => [label, description, keys] — the shape ModuleSchema::tabs() reads.
     *
     * ── WHY THIS CONSTANT EXISTS AT ALL ─────────────────────────────────────
     *
     * These five groups were written inline in MobileMenuApiController::show(),
     * which made this the one module with a SCHEMA that could not join
     * ModuleFrameworkGuardTest — the guard's own note said so by name: "there is
     * no second list to check the first against."
     *
     * That guard is not a tidiness check. It went red once with
     * "cart_panel stores these with no control to write them: accent", a
     * setting the shop read and no screen could write, and it had been that way
     * silently. A module outside it is a module where the same thing happens
     * and nobody finds out. Moved here, the list is checked against the schema
     * on every run: a key added to SCHEMA and forgotten here fails, and a key
     * here that SCHEMA does not carry fails.
     *
     * CARRIED ACROSS VERBATIM — same five groups, same order, same labels, same
     * descriptions, same key order within each. The controller builds its
     * `groups` payload out of this constant and answers exactly the JSON it
     * answered before; ModuleScreenPayloadTest compares mobile-menu's `fields`
     * and `groups` outright, not loosely, so a single reordered key fails it.
     */
    public const TABS = [
        'panel' => ['Panel', 'Size and motion of the sheet.',
                    ['open_from', 'height', 'radius', 'slide_speed', 'scrim', 'show_grab', 'show_close']],
        'top' => ['Top of the sheet', 'What sits above the menu itself.',
                  ['show_search', 'search_text', 'show_heading', 'heading_text']],
        'rows' => ['Rows', 'Density and layout of the items.',
                   ['density', 'child_columns', 'show_counts', 'single_open']],
        'open' => ['Open section', 'How an expanded parent is marked.',
                   ['card_style', 'rule_position', 'rule_width', 'rule_colour', 'card_bg', 'parent_bg', 'parent_colour']],
        'foot' => ['Foot of the sheet', 'Support and account links.',
                   ['show_support', 'support_text', 'show_account', 'account_label']],
        // Lane M4. After the five above, so their payload keeps its order.
        'style' => ['Style', 'V4 two-tone or the classic glass panel.',
                    ['menu_style', 'show_chips', 'sale_fill']],
        'sizes' => ['Sizes', 'Every size is for a 390px phone; smaller and larger phones scale it automatically.',
                    ['panel_w_pct', 'panel_w_min', 'panel_w_max', 'band_pad', 'search_h', 'search_fs',
                     'chip_fs', 'chip_h', 'chip_gap', 'chip_radius', 'row_h', 'row_fs', 'row_py', 'row_px',
                     'sub_fs', 'sub_h', 'col_gap', 'grp_fs', 'icon_size']],
        'motion' => ['Sub-menu animation', 'How a section opens when it is tapped.',
                     ['sub_anim', 'sub_ms', 'sub_ease']],
    ];

    /** all(), once per instance: the sheet asks for it three times per page. */
    private ?array $memo = null;

    public function __construct(private SettingsService $settings) {}

    /** The stored `mobile_menu` value, from the map the request already holds. */
    private function saved(): array
    {
        $saved = $this->settings->get('mobile_menu');

        return is_array($saved) ? $saved : [];
    }

    /** Saved values merged over the defaults. */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $saved = $this->saved();

        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $out[$key] = array_key_exists($key, $saved)
                ? $this->cast($key, $saved[$key])
                : $def[2];
        }

        return $this->memo = $out;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** Validate by declared type, so a bad value can never reach the storefront. */
    /** This screen's point on ModuleSchema's four policy axes. Its colour arm
     *  already refused a hex with no `#` rather than storing one, so the shared
     *  cast changes nothing here except where the code lives. */
    public const POLICY = [
        'max' => 120,
        'blank' => 'default',
        'invalid' => 'default',
        'clamp' => true,
        'hex' => 'strict',
        'bool' => 'cast',
    ];

    public function cast(string $key, mixed $value): mixed
    {
        if (! isset(self::SCHEMA[$key])) {
            return null;
        }

        return ModuleSchema::cast(
            ModuleSchema::field($key, self::SCHEMA[$key], self::POLICY),
            $value,
        );
    }

    public function save(array $values): void
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key])) {
                $clean[$key] = $this->cast($key, $value);
            }
        }

        // The quick links travel with the same save, and a save that does not
        // carry them keeps the ones already stored.
        if (array_key_exists('chips', $values)) {
            $clean['chips'] = self::cleanChips($values['chips']);
        } elseif (array_key_exists('chips', $saved = $this->saved())) {
            $clean['chips'] = self::cleanChips($saved['chips']);
        }

        $this->settings->set('mobile_menu', $clean);
        $this->memo = null;
    }

    /** True while the owner's pick, V4 two-tone, is the menu style. */
    public function twoTone(): bool
    {
        return 'v4' === $this->all()['menu_style'];
    }

    /** The stored quick links, cleaned; the five defaults until any are saved. */
    public function chips(): array
    {
        $saved = $this->saved();

        return array_key_exists('chips', $saved) ? self::cleanChips($saved['chips']) : self::CHIPS_DEFAULT;
    }

    /**
     * What the top band prints: the switched-on quick links, label in the page's
     * language, address with the shop's base path. Empty in Classic and with the
     * row switched off, so neither prints a thing.
     *
     * @return list<array{label: string, href: string, accent: string}>
     */
    public function chipsForRender(): array
    {
        $c = $this->all();

        if ('v4' !== $c['menu_style'] || ! $c['show_chips']) {
            return [];
        }

        $ar = 'ar' === \App\Support\Locale::current();
        // The Brands module off takes /brands/ links out of the chrome, as
        // NavigationService::filterItems() does for the menu itself. Read from
        // the module map the page already loaded (no query of its own).
        $hideBrands = ! $this->settings->moduleEnabled('brands', true);
        $out = [];

        foreach ($this->chips() as $chip) {
            if (! $chip['on'] || ($hideBrands && \App\Support\BrandUrls::matches($chip['url']))) {
                continue;
            }

            $out[] = [
                'label' => $ar && '' !== $chip['label_ar'] ? $chip['label_ar'] : $chip['label'],
                'href' => \App\Support\Url::to($chip['url']),
                'accent' => $chip['accent'],
            ];
        }

        return $out;
    }

    /**
     * The quick links' whole cast. A chip with no label or with an address
     * that is neither a path on this shop nor an https URL is dropped, and
     * $errors says which and why (the console refuses the save with it);
     * javascript:, data:, http: and //host never survive. At most CHIP_MAX.
     *
     * @param  list<string>|null  $errors
     * @return list<array{label: string, label_ar: string, url: string, accent: string, on: bool}>
     */
    public static function cleanChips(mixed $raw, ?array &$errors = null): array
    {
        $errors = [];

        if (! is_array($raw)) {
            if (null !== $raw) {
                $errors[] = 'Quick links must be a list.';
            }

            return [];
        }

        $out = [];

        foreach (array_values($raw) as $i => $chip) {
            $n = $i + 1;

            if (count($out) >= self::CHIP_MAX) {
                $errors[] = 'At most '.self::CHIP_MAX.' quick links.';
                break;
            }

            if (! is_array($chip)) {
                $errors[] = "Quick link {$n} is not a link.";
                continue;
            }

            $label = self::chipText($chip['label'] ?? '');
            $url = trim(is_string($chip['url'] ?? null) ? $chip['url'] : '');

            if ('' === $label) {
                $errors[] = "Quick link {$n} needs a label.";
                continue;
            }

            if (! \App\Support\MenuTargets::customUrlAllowed($url)) {
                $errors[] = "Quick link {$n} ({$label}): the link must be a path on this shop, like /new-in/, or an https:// address.";
                continue;
            }

            $accent = is_string($chip['accent'] ?? null) && array_key_exists($chip['accent'], self::CHIP_ACCENTS) ? $chip['accent'] : '';

            $out[] = [
                'label' => $label,
                'label_ar' => self::chipText($chip['label_ar'] ?? ''),
                'url' => $url,
                'accent' => $accent,
                'on' => ! in_array($chip['on'] ?? true, [false, 0, '0', '', 'false', 'off', null], true),
            ];
        }

        return $out;
    }

    /** One line of plain text, at most CHIP_LABEL_MAX characters. */
    private static function chipText(mixed $raw): string
    {
        if (! is_string($raw)) {
            return '';
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($raw)));

        return mb_substr($text, 0, self::CHIP_LABEL_MAX);
    }

    /**
     * The settings that affect appearance, as CSS custom properties.
     *
     * Emitting them as variables means the stylesheet stays static and
     * cacheable; only this short block changes per shop.
     */
    public function cssVariables(): string
    {
        $c = $this->all();

        $pad = match ($c['density']) {
            'roomy' => ['12px', '14px'],
            'regular' => ['10px', '13.5px'],
            default => ['8px', '13px'],
        };

        return implode(';', [
            '--mm-top:' . (100 - (int) $c['height']) . '%',
            '--mm-radius:' . (int) $c['radius'] . 'px',
            '--mm-speed:' . (int) $c['slide_speed'] . 'ms',
            '--mm-scrim:rgba(42,34,40,' . round($c['scrim'] / 100, 2) . ')',
            '--mm-rule:' . $c['rule_colour'],
            '--mm-rule-w:' . (int) $c['rule_width'] . 'px',
            '--mm-card:' . $c['card_bg'],
            '--mm-parent-bg:' . $c['parent_bg'],
            '--mm-parent-fg:' . $c['parent_colour'],
            '--mm-pad:' . $pad[0],
            '--mm-size:' . $pad[1],
            '--mm-cols:' . ('1' === $c['child_columns'] ? '1fr' : '1fr 1fr'),
            ...$this->twoToneVariables($c),
        ]);
    }

    /**
     * V4's sizes, only those moved off their default — so a shop on the
     * defaults (and every shop on Classic) gets exactly the attribute it got
     * before. kbb.css holds every default as the same custom property.
     *
     * @return list<string>
     */
    private function twoToneVariables(array $c): array
    {
        if ('v4' !== $c['menu_style']) {
            return [];
        }

        $d = static fn (string $k) => self::SCHEMA[$k][2];
        $out = [];

        if ($c['panel_w_pct'] !== $d('panel_w_pct') || $c['panel_w_min'] !== $d('panel_w_min') || $c['panel_w_max'] !== $d('panel_w_max')) {
            $max = (int) $c['panel_w_max'];
            $min = min((int) $c['panel_w_min'], $max);
            $out[] = '--m-w:min(100vw,clamp('.$min.'px,'.(int) $c['panel_w_pct'].'vw,'.$max.'px))';
        }

        foreach (self::SIZE_VARS as $key => $var) {
            if ($c[$key] !== $d($key)) {
                $out[] = $var.':'.(int) $c[$key];
            }
        }

        if ($c['chip_radius'] !== $d('chip_radius')) {
            $out[] = '--m-cr:'.(int) $c['chip_radius'].'px';
        }

        if ($c['sub_ms'] !== $d('sub_ms')) {
            $out[] = '--m-sd:'.(int) $c['sub_ms'].'ms';
        }

        if ($c['sub_ease'] !== $d('sub_ease')) {
            $out[] = '--m-se:'.(self::EASING[$c['sub_ease']] ?? self::EASING['ease-out']);
        }

        return $out;
    }

    /** Classes that switch structural behaviour. */
    public function bodyClass(): string
    {
        $c = $this->all();

        return trim(implode(' ', array_filter([
            'mm-card-' . $c['card_style'],
            'mm-rule-' . $c['rule_position'],
            $c['show_counts'] ? '' : 'mm-nocounts',
            // Last, so 'bottom' renders the class list it always rendered.
            'left' === $c['open_from'] ? 'mm-left' : '',
            // Lane M4: after everything above, so Classic is the 2.60.413 list.
            ...('v4' === $c['menu_style'] ? [
                'mm-v4',
                self::ANIM_CLASS[$c['sub_anim']] ?? '',
                $c['sale_fill'] ? '' : 'mm-nohl',
            ] : []),
        ])));
    }
}
