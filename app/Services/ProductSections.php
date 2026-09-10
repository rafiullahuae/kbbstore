<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Which modules render on the product page, and on which device.
 *
 * Same approach as the homepage: visibility is applied with CSS classes so a
 * cached page is correct for every visitor, and a module switched off for both
 * devices is not rendered at all.
 */
class ProductSections
{
    /** key => [label, description, default on] */
    public const REGISTRY = [
        'badge'       => ['Sale / offer badge', 'The corner label on the gallery image.', true],
        'wishlist'    => ['Wishlist button', 'The heart on the gallery image.', true],
        'capsule'     => ['Rating capsule', 'The pink pill with the average score.', true],
        'rating'      => ['Inline rating line', 'Stars, score, review count and units sold.', true],
        'vat'         => ['VAT line', 'Inclusive of 5% VAT · Authentic, sourced direct.', true],
        'short'       => ['Short description', 'The summary above the options.', true],
        'options'     => ['Options / bundles', 'Variants and quantity bundles.', true],
        'stockline'   => ['Stock line', 'In stock, low stock or sold out.', true],
        'cutoff'      => ['Dispatch countdown', 'Order within … for delivery by ….', true],
        'quantity'    => ['Quantity stepper', 'The − 1 + control beside Add to cart.', true],
        'buynow'      => ['Buy it now button', 'Skips the cart and goes straight to checkout.', false],
        'trust'       => ['Trust badges', 'Authentic, delivery, returns, pay later.', true],
        'paychips'    => ['Payment chips', 'Tabby, Tamara, Visa, Mastercard, Apple Pay, COD.', true],
        'fbt'         => ['Frequently bought together', 'The companion products block.', true],
        'tabs'        => ['Detail tabs', 'Description, ingredients, how to use.', true],
        'reviews'     => ['Reviews', 'Score summary, filters and review cards.', true],
        'related'     => ['You may also like', 'Related products at the foot of the page.', true],
    ];

    public function __construct(private SettingsService $settings) {}

    /** Saved configuration merged over the defaults. */
    public function all(): array
    {
        $saved = $this->settings->get('product_sections');
        $saved = is_array($saved) ? $saved : [];

        $out = [];

        foreach (self::REGISTRY as $key => [$label, $desc, $default]) {
            $row = is_array($saved[$key] ?? null) ? $saved[$key] : [];

            $out[$key] = [
                'key' => $key,
                'label' => $label,
                'description' => $desc,
                'desktop' => (bool) ($row['desktop'] ?? $default),
                'mobile' => (bool) ($row['mobile'] ?? $default),
            ];
        }

        return $out;
    }

    public function hidden(string $key): bool
    {
        $s = $this->all()[$key] ?? null;

        return $s !== null && ! $s['desktop'] && ! $s['mobile'];
    }

    public function classFor(string $key): string
    {
        $s = $this->all()[$key] ?? null;

        if ($s === null) {
            return '';
        }

        return trim(($s['desktop'] ? '' : 'd-off ') . ($s['mobile'] ? '' : 'm-off'));
    }

    public function save(array $sections): void
    {
        $clean = [];

        foreach ($sections as $key => $row) {
            if (! isset(self::REGISTRY[$key])) {
                continue;
            }

            $clean[$key] = [
                'desktop' => (bool) ($row['desktop'] ?? true),
                'mobile' => (bool) ($row['mobile'] ?? true),
            ];
        }

        $this->settings->set('product_sections', $clean);
    }
}
