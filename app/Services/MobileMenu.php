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
        'height'        => ['range',  'Sheet height',        80, 'Percentage of the screen the sheet covers.', ['min' => 40, 'max' => 100, 'step' => 5, 'unit' => '%']],
        'radius'        => ['range',  'Corner radius',       20, '', ['min' => 0, 'max' => 34, 'step' => 2, 'unit' => 'px']],
        'slide_speed'   => ['range',  'Slide duration',     380, '', ['min' => 150, 'max' => 700, 'step' => 10, 'unit' => 'ms']],
        'scrim'         => ['range',  'Backdrop darkness',   50, '', ['min' => 0, 'max' => 80, 'step' => 5, 'unit' => '%']],
        'icon_style'    => ['select', 'Menu icon',      'default', 'How the icon animates when the menu opens.',
                             ['default' => 'Cross', 'ico-spin' => 'Spin cross', 'ico-arrow' => 'Down arrow',
                              'ico-collapse' => 'Collapse', 'ico-pinch' => 'Slow pinch', 'ico-pink' => 'Cross in pink',
                              'ico-taper' => 'Tapered']],
        'show_grab'     => ['bool',   'Grab handle',       true, 'The small bar at the top edge.'],
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
                    ['icon_style', 'height', 'radius', 'slide_speed', 'scrim', 'show_grab', 'show_close']],
        'top' => ['Top of the sheet', 'What sits above the menu itself.',
                  ['show_search', 'search_text', 'show_heading', 'heading_text']],
        'rows' => ['Rows', 'Density and layout of the items.',
                   ['density', 'child_columns', 'show_counts', 'single_open']],
        'open' => ['Open section', 'How an expanded parent is marked.',
                   ['card_style', 'rule_position', 'rule_width', 'rule_colour', 'card_bg', 'parent_bg', 'parent_colour']],
        'foot' => ['Foot of the sheet', 'Support and account links.',
                   ['show_support', 'support_text', 'show_account', 'account_label']],
    ];

    public function __construct(private SettingsService $settings) {}

    /** Saved values merged over the defaults. */
    public function all(): array
    {
        $saved = $this->settings->get('mobile_menu');
        $saved = is_array($saved) ? $saved : [];

        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $out[$key] = array_key_exists($key, $saved)
                ? $this->cast($key, $saved[$key])
                : $def[2];
        }

        return $out;
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

        $this->settings->set('mobile_menu', $clean);
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
        ]);
    }

    /** Classes that switch structural behaviour. */
    public function bodyClass(): string
    {
        $c = $this->all();

        return trim(implode(' ', array_filter([
            'mm-card-' . $c['card_style'],
            'mm-rule-' . $c['rule_position'],
            $c['show_counts'] ? '' : 'mm-nocounts',
        ])));
    }
}
