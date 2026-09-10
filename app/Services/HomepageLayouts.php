<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Homepage layouts — presets that set section order, visibility and grid skins
 * in one move.
 *
 * A layout is not a separate template. It writes into the same section
 * configuration the Homepage screen edits, so anything a preset does can be
 * adjusted afterwards, and nothing is locked away in code.
 */
class HomepageLayouts
{
    /**
     * key => [name, description, who it suits, [section => [order, desktop, mobile, skin]]]
     *
     * Sections omitted from a layout keep their registry defaults, so adding a
     * new section later does not silently disappear from every preset.
     */
    public const LAYOUTS = [
        'signature' => [
            'name' => 'Signature',
            'blurb' => 'The full store. Every section on, in the order the site uses today.',
            'suits' => 'A broad catalogue where discovery matters more than a single message.',
            'sections' => [
                'hero', 'delivery', 'ticker', 'categories', 'bundles', 'recommended',
                'routine', 'quiz', 'brands', 'spotted', 'bestsellers', 'flash',
                'blog', 'about', 'reviews', 'trust', 'newsletter',
            ],
            'skins' => ['bundles' => 'classic', 'recommended' => 'soft', 'bestsellers' => 'luxe', 'flash' => 'ribbon'],
            'off' => [],
        ],
        'conversion' => [
            'name' => 'Conversion',
            'blurb' => 'Offers first. Flash sale and bundles above the fold, editorial pushed down.',
            'suits' => 'Sale periods and paid traffic, where the visit has one job.',
            'sections' => [
                'hero', 'ticker', 'delivery', 'flash', 'bundles', 'categories',
                'bestsellers', 'recommended', 'quiz', 'reviews', 'trust',
                'brands', 'routine', 'spotted', 'newsletter', 'about', 'blog',
            ],
            'skins' => ['flash' => 'ribbon', 'bundles' => 'pricetag', 'bestsellers' => 'bold', 'recommended' => 'actions'],
            'off' => ['blog'],
        ],
        'editorial' => [
            'name' => 'Editorial',
            'blurb' => 'Content leads. Routine, quiz and journal early; products follow the story.',
            'suits' => 'Building trust with visitors who are researching rather than buying today.',
            'sections' => [
                'hero', 'delivery', 'routine', 'quiz', 'categories', 'bestsellers',
                'blog', 'brands', 'bundles', 'reviews', 'spotted', 'about',
                'recommended', 'flash', 'trust', 'newsletter', 'ticker',
            ],
            'skins' => ['bundles' => 'editorial', 'recommended' => 'magazine', 'bestsellers' => 'minimal', 'flash' => 'outline'],
            'off' => ['ticker'],
        ],
        'boutique' => [
            'name' => 'Boutique',
            'blurb' => 'Fewer, calmer sections. Generous spacing, no ticker, no flash sale.',
            'suits' => 'A curated range where restraint reads as quality.',
            'sections' => [
                'hero', 'categories', 'bestsellers', 'routine', 'brands',
                'reviews', 'about', 'trust', 'newsletter',
                'delivery', 'bundles', 'recommended', 'quiz', 'spotted', 'flash', 'blog', 'ticker',
            ],
            'skins' => ['bestsellers' => 'luxe', 'bundles' => 'frame', 'recommended' => 'soft', 'flash' => 'minimal'],
            'off' => ['ticker', 'flash', 'spotted', 'bundles', 'recommended'],
        ],
    ];

    public function __construct(private SettingsService $settings) {}

    /** Which layout was applied last, for highlighting in the admin. */
    public function current(): string
    {
        $key = (string) $this->settings->get('homepage_layout', 'signature');

        return isset(self::LAYOUTS[$key]) ? $key : 'signature';
    }

    public function exists(string $key): bool
    {
        return isset(self::LAYOUTS[$key]);
    }

    /** @return array<string,array> the section payload this layout implies */
    public function payloadFor(string $key): array
    {
        $layout = self::LAYOUTS[$key] ?? self::LAYOUTS['signature'];
        $out = [];
        $order = 0;

        foreach ($layout['sections'] as $section) {
            if (! isset(HomepageSections::REGISTRY[$section])) {
                continue;
            }

            $on = ! in_array($section, $layout['off'], true);
            $hasGrid = HomepageSections::REGISTRY[$section][2];

            $out[$section] = [
                'desktop' => $on,
                'mobile' => $on,
                'order' => $order++,
                'skin' => $hasGrid
                    ? ($layout['skins'][$section] ?? HomepageSections::REGISTRY[$section][3])
                    : null,
            ];
        }

        // Anything the preset did not mention keeps its default, placed last.
        foreach (HomepageSections::REGISTRY as $section => $meta) {
            if (! isset($out[$section])) {
                $out[$section] = ['desktop' => true, 'mobile' => true, 'order' => $order++, 'skin' => $meta[3]];
            }
        }

        return $out;
    }

    /** Apply a layout by writing its payload into the section configuration. */
    public function apply(string $key, HomepageSections $sections): void
    {
        $sections->save($this->payloadFor($key));
        $this->settings->set('homepage_layout', $key);
    }

    /** A compact description for the admin, including a section preview list. */
    public function summaries(): array
    {
        $out = [];

        foreach (self::LAYOUTS as $key => $layout) {
            $visible = array_values(array_filter(
                $layout['sections'],
                fn ($s) => ! in_array($s, $layout['off'], true) && isset(HomepageSections::REGISTRY[$s])
            ));

            $out[] = [
                'key' => $key,
                'name' => $layout['name'],
                'blurb' => $layout['blurb'],
                'suits' => $layout['suits'],
                'count' => count($visible),
                'order' => array_map(fn ($s) => HomepageSections::REGISTRY[$s][0], array_slice($visible, 0, 8)),
                'off' => array_map(
                    fn ($s) => HomepageSections::REGISTRY[$s][0] ?? $s,
                    array_values(array_filter($layout['off'], fn ($s) => isset(HomepageSections::REGISTRY[$s])))
                ),
            ];
        }

        return $out;
    }
}
