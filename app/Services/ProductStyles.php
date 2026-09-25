<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\GridSkins;

/**
 * How product cards look, everywhere they appear.
 *
 * Gathered onto one screen because these settings were previously spread
 * between the homepage screen and the ecommerce panel, which made it hard to
 * see what a change would affect.
 */
class ProductStyles
{
    public const SCHEMA = [
        // ── Layout ──
        'grid_skin'          => ['skin',   'Default card style', 'classic', 'Used wherever a grid does not choose its own.'],
        'grid_columns'       => ['range',  'Columns · desktop', 4, '', ['min' => 2, 'max' => 6, 'step' => 1, 'unit' => '']],
        'grid_columns_tablet'=> ['range',  'Columns · tablet', 3, '', ['min' => 2, 'max' => 4, 'step' => 1, 'unit' => '']],
        'grid_columns_mobile'=> ['range',  'Columns · phone', 2, 'Two is the most a narrow screen holds comfortably.', ['min' => 1, 'max' => 2, 'step' => 1, 'unit' => '']],
        'grid_gap'           => ['range',  'Gap between cards', 16, '', ['min' => 6, 'max' => 32, 'step' => 2, 'unit' => 'px']],
        'card_radius'        => ['range',  'Card roundness', 14, '', ['min' => 0, 'max' => 26, 'step' => 2, 'unit' => 'px']],
        'image_ratio'        => ['select', 'Image shape', 'portrait', '', ['square' => 'Square', 'portrait' => 'Portrait', 'tall' => 'Tall', 'landscape' => 'Landscape']],

        // ── What the card shows ──
        'show_brand'         => ['bool',   'Brand name', true, ''],
        'show_category'      => ['bool',   'Category label', true, ''],
        'show_rating'        => ['bool',   'Stars and review count', true, ''],
        'show_was_price'     => ['bool',   'Was price', true, 'The struck-through original.'],
        'show_discount'      => ['bool',   'Discount badge', true, ''],
        'show_new'           => ['bool',   'New badge', true, 'On products with no reviews yet.'],
        'show_cart'          => ['bool',   'Add to cart button', true, ''],
        'cart_label'         => ['text',   'Button wording', 'Add to cart', ''],
        'name_lines'         => ['range',  'Product name lines', 0, 'Zero shows the whole name, however long. One to four trims it.', ['min' => 0, 'max' => 4, 'step' => 1, 'unit' => '']],

        // ── Colour ──
        'sale_colour'        => ['colour', 'Sale badge', '#E23B57', ''],
        'new_colour'         => ['colour', 'New badge', '#1F9D55', ''],
        'price_colour'       => ['colour', 'Price', '#2A2228', ''],
        'star_colour'        => ['colour', 'Stars', '#E8A33D', ''],
        'cart_bg'            => ['colour', 'Button background', '#E0567B', ''],
        'cart_fg'            => ['colour', 'Button text', '#FFFFFF', ''],

        // ── Sticky add to cart ──
        // Off by default: this bar was absent from the product page for several
        // releases, so switching it on for every shop at once would be a visible
        // change nobody asked for.
        'sticky_show'        => ['bool',   'Show the sticky bar', false, 'A bar with the price and Add to cart, once the main button scrolls away.'],
        'sticky_devices'     => ['select', 'Show on', 'phone', '', ['phone' => 'Phone only', 'phone_tablet' => 'Phone and tablet', 'all' => 'Every screen']],
        'sticky_trigger'     => ['select', 'Appears', 'button', '', ['button' => 'When the Add button scrolls away', 'offset' => 'After a set distance']],
        'sticky_offset'      => ['range',  'Distance before it appears', 200, 'Used only when the trigger is a set distance.', ['min' => 0, 'max' => 900, 'step' => 20, 'unit' => 'px']],
        'sticky_thumb'       => ['bool',   'Show the thumbnail', true, ''],
        'sticky_name'        => ['bool',   'Show the product name', true, ''],
        'sticky_price'       => ['bool',   'Show the price', true, ''],
        'sticky_label'       => ['text',   'Button wording', 'Add to cart', ''],
        'sticky_bg'          => ['colour', 'Bar background', '#FFFFFF', ''],
        'sticky_btn_bg'      => ['colour', 'Button background', '#2A2228', ''],
        'sticky_btn_fg'      => ['colour', 'Button text', '#FFFFFF', ''],
        'sticky_radius'      => ['range',  'Button roundness', 99, '', ['min' => 0, 'max' => 99, 'step' => 3, 'unit' => 'px']],
    ];

    public const TABS = [
        'layout'  => ['Layout', 'Columns, spacing and card shape.',
                      ['grid_skin', 'grid_columns', 'grid_columns_tablet', 'grid_columns_mobile', 'grid_gap', 'card_radius', 'image_ratio']],
        'content' => ['Card content', 'What each card shows.',
                      ['show_brand', 'show_category', 'show_rating', 'show_was_price', 'show_discount', 'show_new', 'show_cart', 'cart_label', 'name_lines']],
        'colour'  => ['Colour', 'Badges, price and the button.',
                      ['sale_colour', 'new_colour', 'price_colour', 'star_colour', 'cart_bg', 'cart_fg']],
        'sticky'  => ['Sticky Add to Cart', 'The bar that follows the shopper down the product page.',
                      ['sticky_show', 'sticky_devices', 'sticky_trigger', 'sticky_offset',
                       'sticky_thumb', 'sticky_name', 'sticky_price', 'sticky_label',
                       'sticky_bg', 'sticky_btn_bg', 'sticky_btn_fg', 'sticky_radius']],
    ];

    public function __construct(private SettingsService $settings) {}

    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $saved = $this->settings->get($key, null);
            $out[$key] = $saved === null ? $def[2] : $this->cast($key, $saved);
        }

        return $out;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** This screen's point on ModuleSchema's four policy axes. */
    public const POLICY = [
        'max' => 60,
        'blank' => 'default',
        'invalid' => 'default',
        'clamp' => true,
        'hex' => 'strict',
        'bool' => 'cast',
    ];

    /**
     * The option set the positional SCHEMA has no slot for.
     *
     * `grid_skin` is a select in everything but name: it stores one of
     * GridSkins::ALL or the default. That set lived in another class and the
     * SCHEMA never named it, so nothing checking schemas could check this
     * control. Naming it here is what puts it under rule 5 with the rest.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function overrides(): array
    {
        return ['grid_skin' => ['options' => GridSkins::ALL]];
    }

    public function cast(string $key, mixed $value): mixed
    {
        if (! isset(self::SCHEMA[$key])) {
            return null;
        }

        return ModuleSchema::cast(
            ModuleSchema::normalise(self::SCHEMA, self::POLICY, self::overrides())[$key],
            $value,
        );
    }

    /**
     * Stored as individual settings rather than one blob, because grid_skin and
     * grid_columns are already read by name elsewhere and must keep working.
     */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key])) {
                $this->settings->set($key, $this->cast($key, $value));
            }
        }
    }

    public function cssVariables(): string
    {
        $c = $this->all();

        $ratio = match ($c['image_ratio']) {
            'square' => '1/1',
            'tall' => '1/1.25',
            'landscape' => '1.2/1',
            default => '1/1.02',
        };

        return implode(';', [
            '--kbb-cols:' . $c['grid_columns'],
            '--kbb-cols-t:' . $c['grid_columns_tablet'],
            '--kbb-cols-m:' . $c['grid_columns_mobile'],
            '--kbb-gap:' . $c['grid_gap'] . 'px',
            '--kbb-radius:' . $c['card_radius'] . 'px',
            '--kbb-ratio:' . $ratio,
            '--kbb-sale:' . $c['sale_colour'],
            '--kbb-new:' . $c['new_colour'],
            '--kbb-price:' . $c['price_colour'],
            '--kbb-star:' . $c['star_colour'],
            '--kbb-cart-bg:' . $c['cart_bg'],
            '--kbb-cart-fg:' . $c['cart_fg'],
            // 0 means "show it all". CSS has no keyword for an unlimited
            // line clamp, so a number no name will reach stands in for it, and
            // the reserved height drops to nothing.
            '--kbb-name-lines:' . ((int) $c['name_lines'] === 0 ? 99 : $c['name_lines']),
            '--kbb-name-min:' . ((int) $c['name_lines'] === 0 ? '0' : $c['name_lines'] . ' * 1.35em'),
        ]);
    }

    /**
     * Card variables that belong to the page rather than to one grid.
     *
     * Emitted on <body> so a shortcode grid, a homepage rail and a lone
     * <x-product-card> all trim their titles the same way. They were previously
     * declared in the stylesheet with a two-line fallback and emitted by
     * nothing, so the setting could not take effect anywhere.
     */
    public function cardVariables(): string
    {
        $lines = (int) $this->all()['name_lines'];

        return '--kbb-name-lines:' . ($lines === 0 ? 99 : $lines)
             . ';--kbb-name-min:' . ($lines === 0 ? '0' : $lines . ' * 1.35em');
    }

    public function bodyClass(): string
    {
        $c = $this->all();

        return trim(implode(' ', array_filter([
            $c['show_brand'] ? '' : 'pc-nobrand',
            $c['show_category'] ? '' : 'pc-nocat',
            $c['show_rating'] ? '' : 'pc-norate',
            $c['show_was_price'] ? '' : 'pc-nowas',
            $c['show_discount'] ? '' : 'pc-nodisc',
            $c['show_new'] ? '' : 'pc-nonew',
            $c['show_cart'] ? '' : 'pc-nocart',
        ])));
    }
}
