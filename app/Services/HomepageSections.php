<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\GridSkins;

/**
 * Which homepage sections render, and how.
 *
 * Each section can be switched off independently for desktop and for mobile,
 * and product sections carry their own grid skin.
 *
 * Visibility is applied with CSS classes rather than by sniffing the user
 * agent, so a cached page stays correct on every device. A section switched
 * off for both is not rendered at all, which also skips its queries.
 */
class HomepageSections
{
    /** key => [label, description, has a product grid, default skin] */
    public const REGISTRY = [
        'hero'        => ['Hero slider', 'The rotating banners at the top.', false, null],
        'delivery'    => ['Delivery strip', '1-3 days delivery, free over AED 199.', false, null],
        'ticker'      => ['Promo ticker', 'The scrolling discount-code line.', false, null],
        'categories'  => ['Category circles', 'Shop by category, scrollable.', false, null],
        'bundles'     => ['Big savings bundles', 'Skincare sets and routines.', true, 'classic'],
        'recommended' => ['Recommended for you', 'Handpicked essentials.', true, 'soft'],
        'routine'     => ['Build your routine', 'The six-step routine.', false, null],
        'quiz'        => ['Skin quiz', 'The two-minute routine finder.', false, null],
        'brands'      => ['Top brands', 'Brand tiles with product counts.', false, null],
        'spotted'     => ['#KBeautyBliss spotted', 'Shoppable community photos.', false, null],
        'bestsellers' => ['Best sellers', 'Ranked by sales this month.', true, 'luxe'],
        'flash'       => ['Flash sale', 'Discounted, with stock remaining.', true, 'ribbon'],
        'blog'        => ['Skincare guide', 'Latest journal articles.', false, null],
        'about'       => ['About us', 'Story and proof numbers.', false, null],
        'reviews'     => ['Customer reviews', 'Score summary and review cards.', false, null],
        'trust'       => ['Trust row', 'Shipping, payments, authenticity, support.', false, null],
        'newsletter'  => ['Newsletter', 'Ten percent off the first order.', false, null],
    ];

    public function __construct(private SettingsService $settings) {}

    /**
     * The saved configuration, merged over the defaults.
     *
     * Merging rather than replacing means a section added in a later release
     * appears immediately and switched on, instead of vanishing because an
     * older saved payload never mentioned it.
     */
    public function all(): array
    {
        $saved = $this->settings->get('homepage_sections');
        $saved = is_array($saved) ? $saved : [];

        $out = [];
        $order = 0;

        foreach (self::REGISTRY as $key => [$label, $desc, $hasGrid, $defaultSkin]) {
            $row = is_array($saved[$key] ?? null) ? $saved[$key] : [];
            $skin = (string) ($row['skin'] ?? '');

            $out[$key] = [
                'key' => $key,
                'label' => $label,
                'description' => $desc,
                'has_grid' => $hasGrid,
                'skin' => $hasGrid ? (GridSkins::exists($skin) ? $skin : $defaultSkin) : null,
                'desktop' => (bool) ($row['desktop'] ?? true),
                'mobile' => (bool) ($row['mobile'] ?? true),
                'order' => (int) ($row['order'] ?? $order),
            ];

            $order++;
        }

        uasort($out, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $out;
    }

    /** True when the section is off on both, so it need not render at all. */
    public function hidden(string $key): bool
    {
        $s = $this->all()[$key] ?? null;

        return $s !== null && ! $s['desktop'] && ! $s['mobile'];
    }

    /**
     * The visibility class for a section wrapper.
     * d-off hides it above the mobile breakpoint, m-off at or below it.
     */
    public function classFor(string $key): string
    {
        $s = $this->all()[$key] ?? null;

        if ($s === null) {
            return '';
        }

        // The divider class is added here rather than in the template: all
        // seventeen sections already call this, so none can be missed and none
        // of them had to change.
        $divider = app(SectionDividers::class);
        $mark = $key === array_key_first(self::REGISTRY) && ! $divider->showAboveFirst()
            ? ''
            : $divider->classFor($key);

        return trim(($s['desktop'] ? '' : 'd-off ') . ($s['mobile'] ? '' : 'm-off ') . $mark);
    }

    public function skinFor(string $key): ?string
    {
        return $this->all()[$key]['skin'] ?? null;
    }

    /** Persist a validated payload. */
    public function save(array $sections): void
    {
        $clean = [];
        $order = 0;

        foreach ($sections as $key => $row) {
            if (! isset(self::REGISTRY[$key])) {
                continue;
            }

            $hasGrid = self::REGISTRY[$key][2];
            $defaultSkin = self::REGISTRY[$key][3];
            $skin = (string) ($row['skin'] ?? '');

            $clean[$key] = [
                'desktop' => (bool) ($row['desktop'] ?? true),
                'mobile' => (bool) ($row['mobile'] ?? true),
                'order' => (int) ($row['order'] ?? $order),
                'skin' => $hasGrid ? (GridSkins::exists($skin) ? $skin : $defaultSkin) : null,
            ];

            $order++;
        }

        $this->settings->set('homepage_sections', $clean);
    }
}
