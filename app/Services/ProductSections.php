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
        // Described, not quoted. This used to read "Inclusive of 5% VAT ·
        // Authentic, sourced direct." — a copy of the storefront sentence that
        // asserted a basis this shop can now set per country, plus a trust claim
        // whose one home is App\Support\TrustClaims. The sentence itself is
        // App\Support\VatDisplay::shelfNote()'s to write.
        'vat'         => ['VAT line', 'The tax line under the price. Its wording follows the rule set for the shopper\'s country on Store → Ecommerce → Tax.', false],
        'short'       => ['Short description', 'The summary above the options.', true],
        'options'     => ['Options / bundles', 'Variants and quantity bundles.', true],
        'stockline'   => ['Stock line', 'In stock, low stock or sold out.', true],
        'cutoff'      => ['Dispatch countdown', 'Order within … for delivery by ….', true],
        'quantity'    => ['Quantity stepper', 'The − 1 + control beside Add to cart.', true],
        'buynow'      => ['Buy it now button', 'Skips the cart and goes straight to checkout.', false],
        'trust'       => ['Trust badges', 'Authentic, delivery, returns, pay later.', true],
        'paychips'    => ['Payment chips', 'Tabby, Tamara, Visa, Mastercard, Apple Pay, COD.', true],
        // Lane RB: the old Frequently-bought-together block's slot, now "Buy
        // these together". The section's own switch and options are on the
        // Buy these together tab; this row is still its laptop switch.
        'fbt'         => ['Buy these together', 'The product plus its matches, a tick on each, one pink button.', true],
        'tabs'        => ['Detail tabs', 'Description, ingredients, how to use.', true],
        'reviews'     => ['Reviews', 'Score summary, filters and review cards.', true],
        'related'     => ['You may also like', 'Related products at the foot of the page.', true],
    ];

    public function __construct(private SettingsService $settings) {}

    /**
     * Saved configuration merged over the defaults.
     *
     * ── ▲ EIGHT MOBILE SWITCHES ARE ANSWERED BY ANOTHER SCREEN (Lane QA) ────
     *
     * Appearance → Product page → Mobile sections orders and switches the
     * phone page by SECTION, and eight of these modules ARE a section — the
     * short description, the options, the trust lines, the payment chips,
     * frequently bought together, the tabs, the reviews and the related
     * carousel (ProductMobileSections::MODULE). Two switches for one thing on
     * one device is two answers, so for those eight `mobile` is read from that
     * screen and `mobile_owner` says so; the Sections tab draws a pointer
     * there instead of a toggle. `desktop` is still this registry's, for all
     * seventeen. A switch that only hides PART of a section — the capsule, the
     * VAT line, the countdown, the stepper, Buy it now — stays here, per
     * device, nested inside its section's switch.
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $saved = $this->settings->get('product_sections');
        $saved = is_array($saved) ? $saved : [];
        $mobile = app(ProductMobileSections::class);

        $out = [];

        foreach (self::REGISTRY as $key => [$label, $desc, $default]) {
            $row = is_array($saved[$key] ?? null) ? $saved[$key] : [];
            $owned = $mobile->moduleMobile($key);

            $out[$key] = [
                'key' => $key,
                'label' => $label,
                'description' => $desc,
                'desktop' => (bool) ($row['desktop'] ?? $default),
                'mobile' => $owned ?? (bool) ($row['mobile'] ?? $default),
                'mobile_owner' => $owned === null ? null : 'Mobile sections',
            ];
        }

        return $this->memo = $out;
    }

    /** @var array<string, array<string, mixed>>|null */
    private ?array $memo = null;

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

        /* A module whose phone switch belongs to Mobile sections is hidden on
           a phone by that screen's own `pm-off-*` rule, at the product page's
           own breakpoint (880px). Emitting `m-off` as well would hide it a
           second time at kbb.css's 900px — on the 881–900px laptop layout,
           where the Desktop switch is meant to decide. */
        $mobileOff = $s['mobile'] || ($s['mobile_owner'] ?? null) !== null ? '' : 'm-off';

        return trim(($s['desktop'] ? '' : 'd-off ') . $mobileOff);
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
        $this->memo = null;
    }
}
