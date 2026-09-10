<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The cart panel — size, density and what each line shows.
 *
 * Everything here is emitted as CSS custom properties on the panel itself, or as
 * classes on it, so the stylesheet stays static and cacheable and a shop that
 * never opens this screen renders exactly what it does today. Every default
 * below is the value that was hard-coded before.
 *
 * There is no on/off switch for the panel. The cart is not optional.
 */
class CartPanel
{
    public const SCHEMA = [
        // ── Size ──
        'panel_width'      => ['range',  'Width · desktop', 380, '', ['min' => 300, 'max' => 520, 'step' => 10, 'unit' => 'px']],
        'panel_width_m'    => ['range',  'Width · phone', 77, 'Share of the screen the panel covers.', ['min' => 60, 'max' => 100, 'step' => 1, 'unit' => '%']],

        // ── Density ──
        'row_pad'          => ['range',  'Space above and below each line', 9, '', ['min' => 4, 'max' => 18, 'step' => 1, 'unit' => 'px']],
        'thumb_size'       => ['range',  'Thumbnail · desktop', 42, '', ['min' => 30, 'max' => 64, 'step' => 2, 'unit' => 'px']],
        'thumb_size_m'     => ['range',  'Thumbnail · phone', 38, '', ['min' => 28, 'max' => 56, 'step' => 2, 'unit' => 'px']],
        'name_size'        => ['range',  'Product name size', 13, '', ['min' => 11, 'max' => 16, 'step' => 1, 'unit' => 'px']],
        'name_lines'       => ['range',  'Product name · maximum lines', 2, 'Longer names are cut off, so every line is the same height.', ['min' => 1, 'max' => 3, 'step' => 1, 'unit' => '']],
        'stepper_size'     => ['range',  'Quantity buttons', 22, '', ['min' => 18, 'max' => 32, 'step' => 2, 'unit' => 'px']],
        'list_pad'         => ['range',  'Padding around the list', 16, '', ['min' => 6, 'max' => 24, 'step' => 2, 'unit' => 'px']],

        // ── What each line shows ──
        'show_thumb'       => ['bool',   'Thumbnail', true, ''],
        'show_qty'         => ['bool',   'Quantity buttons', true, 'Off leaves the line read-only; quantities are then changed on the cart page.'],
        'show_remove'      => ['bool',   'Remove button', true, ''],
        'show_price'       => ['bool',   'Line price', true, ''],
        'show_ship_bar'    => ['bool',   'Free-delivery progress bar', true, ''],
        'show_promo'       => ['bool',   'Promotion line', true, ''],
        'show_browsed'     => ['bool',   'Browsed tab', true, 'Recently viewed products, with one-tap add.'],

        // ── Behaviour ──
        'open_on_add'      => ['bool',   'Open the panel when something is added', true, ''],
        'added_note'       => ['bool',   'Show “Added” beside a product added from Browsed', true, ''],
        'note_ms'          => ['range',  'How long that note stays', 1400, '', ['min' => 400, 'max' => 4000, 'step' => 100, 'unit' => 'ms']],

        // ── Wording ──
        // Every string the panel prints, so none of it needs a code change.
        'txt_tab_cart'     => ['text', 'Cart tab', 'Cart', ''],
        'txt_tab_browsed'  => ['text', 'Browsed tab', 'Browsed', ''],
        'txt_ship_away'    => ['text', 'Free delivery · still to go', "You're {amount} away from free delivery", 'Use {amount} where the figure should appear.'],
        'txt_ship_done'    => ['text', 'Free delivery · reached', "🎉 You've unlocked free delivery!", ''],
        'txt_subtotal'     => ['text', 'Subtotal label', 'Subtotal', ''],
        'txt_btn_cart'     => ['text', 'Left button', 'Cart', ''],
        'txt_btn_checkout' => ['text', 'Right button', 'Checkout', ''],
        'txt_empty'        => ['text', 'Empty bag · line one', 'Your bag is empty.', ''],
        'txt_empty_sub'    => ['text', 'Empty bag · line two', 'Add something glowy ✨', ''],
        'txt_browsed_none' => ['text', 'Nothing browsed yet', 'Nothing browsed yet.', ''],

        // ── Colour ──
        'accent'           => ['colour', 'Prices and the active tab', '#C13E63', ''],
        'checkout_bg'      => ['colour', 'Checkout button', '#C13E63', ''],
        'checkout_fg'      => ['colour', 'Checkout button text', '#FFFFFF', ''],
    ];

    public const TABS = [
        'size'     => ['Size', 'How much of the screen the panel takes.',
                       ['panel_width', 'panel_width_m']],
        'density'  => ['Density', 'How tightly the lines are packed. Smaller values fit more products.',
                       ['row_pad', 'thumb_size', 'thumb_size_m', 'name_size', 'name_lines', 'stepper_size', 'list_pad']],
        'content'  => ['Content', 'What each line and the panel show.',
                       ['show_thumb', 'show_qty', 'show_remove', 'show_price', 'show_ship_bar', 'show_promo', 'show_browsed']],
        'behaviour'=> ['Behaviour', 'What happens when something is added.',
                       ['open_on_add', 'added_note', 'note_ms']],
        'wording'  => ['Wording', 'Every word the panel prints.',
                       ['txt_tab_cart', 'txt_tab_browsed', 'txt_ship_away', 'txt_ship_done',
                        'txt_subtotal', 'txt_btn_cart', 'txt_btn_checkout',
                        'txt_empty', 'txt_empty_sub', 'txt_browsed_none']],
        'colour'   => ['Colour', 'Prices, tabs and the checkout button.',
                       ['accent', 'checkout_bg', 'checkout_fg']],
    ];

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $saved = $this->settings->get('cartpanel_' . $key, null);
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
                $this->settings->set('cartpanel_' . $key, $this->cast($key, $value));
            }
        }
    }

    /**
     * Cast and clamp on the way in, so a bad value is rejected once at save
     * rather than defended against on every page render.
     */
    private function cast(string $key, mixed $value): mixed
    {
        $def = self::SCHEMA[$key];

        return match ($def[0]) {
            'bool' => (bool) $value,
            'range' => max((int) $def[4]['min'], min((int) $def[4]['max'], (int) $value)),
            'colour' => \App\Support\Color::isValidHex((string) $value)
                ? strtoupper((string) $value)
                : $def[2],
            default => mb_substr(trim((string) $value), 0, 120),
        };
    }

    /** Inline custom properties for the panel element. */
    public function cssVariables(): string
    {
        $c = $this->all();

        return implode(';', [
            '--cp-w:' . $c['panel_width'] . 'px',
            '--cp-w-m:' . $c['panel_width_m'] . 'vw',
            '--cp-rowpad:' . $c['row_pad'] . 'px',
            '--cp-thumb:' . $c['thumb_size'] . 'px',
            '--cp-thumb-m:' . $c['thumb_size_m'] . 'px',
            '--cp-name:' . $c['name_size'] . 'px',
            '--cp-lines:' . $c['name_lines'],
            '--cp-step:' . $c['stepper_size'] . 'px',
            '--cp-pad:' . $c['list_pad'] . 'px',
            '--cp-accent:' . $c['accent'],
            '--cp-cta-bg:' . $c['checkout_bg'],
            '--cp-cta-fg:' . $c['checkout_fg'],
        ]);
    }

    /** Structural switches, as classes rather than variables. */
    public function bodyClass(): string
    {
        $c = $this->all();

        return trim(implode(' ', array_filter([
            $c['show_thumb'] ? '' : 'cp-nothumb',
            $c['show_qty'] ? '' : 'cp-noqty',
            $c['show_remove'] ? '' : 'cp-norm',
            $c['show_price'] ? '' : 'cp-noprice',
            $c['show_ship_bar'] ? '' : 'cp-noship',
            $c['show_promo'] ? '' : 'cp-nopromo',
            $c['show_browsed'] ? '' : 'cp-nobrowsed',
        ])));
    }

    /** The handful of values the front-end script needs. */
    public function jsConfig(): array
    {
        $c = $this->all();

        return ['openOnAdd' => $c['open_on_add'], 'note' => $c['added_note'], 'noteMs' => $c['note_ms']];
    }
}
