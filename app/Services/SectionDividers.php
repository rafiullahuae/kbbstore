<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Session;

/**
 * The marks that separate homepage sections.
 *
 * The sections were flattened on phones so the product grid could have the
 * width back, which left nothing between them. This puts a mark there without
 * bringing the frame back — every style below costs no horizontal space.
 *
 * Applied through HomepageSections::classFor(), which every section on the page
 * already calls, so no template changed and no section can be missed.
 */
class SectionDividers
{
    /** The concrete styles a random pick can land on. */
    public const STYLES = [
        'ticks'    => 'Corner ticks',
        'hairline' => 'Fading hairline',
        'petal'    => 'Petal on a hairline',
        'stitch'   => 'Stitched dashes',
        'drift'    => 'Drifting petals',
        'breathe'  => 'Breathing hairline',
        'gradient' => 'Drifting gradient edge',
    ];

    public const SCHEMA = [
        'style'      => ['select', 'Divider', 'ticks', 'Random picks one of the styles above it.', [
            'off' => 'None',
            'ticks' => 'Corner ticks',
            'hairline' => 'Fading hairline',
            'petal' => 'Petal on a hairline',
            'stitch' => 'Stitched dashes',
            'drift' => 'Drifting petals · moves',
            'breathe' => 'Breathing hairline · moves',
            'gradient' => 'Drifting gradient edge · moves',
            'random_page' => 'Random — a new one on every page load',
            'random_session' => 'Random — one per visit, kept until they leave',
        ]],
        'scope'      => ['select', 'Where', 'all', '', [
            'all' => 'Between every section',
            'chosen' => 'Only above the sections ticked below',
        ]],
        'sections'   => ['sections', 'Sections', '', 'Used only when Where is set to the ticked sections.'],
        'first'      => ['bool', 'Also above the first section', false, 'Off means the hero is not preceded by a mark.'],
        'desktop'    => ['bool', 'Show on desktop too', false, 'Sections still have their card frame above 680px, so a divider there is usually one mark too many.'],
        'colour'     => ['colour', 'Colour', '#C13E63', ''],
        'length'     => ['range', 'Tick length', 26, 'Corner ticks only.', ['min' => 12, 'max' => 70, 'step' => 2, 'unit' => 'px']],
        'thickness'  => ['range', 'Thickness', 2, '', ['min' => 1, 'max' => 4, 'step' => 1, 'unit' => 'px']],
        'inset'      => ['range', 'Distance from the edge', 12, '', ['min' => 0, 'max' => 40, 'step' => 2, 'unit' => 'px']],
    ];

    public const TABS = [
        'style' => ['Style', 'Which mark, and whether it changes.', ['style', 'first', 'desktop']],
        'where' => ['Where', 'Between every section, or only the ones you choose.', ['scope', 'sections']],
        'look'  => ['Look', 'Colour and size.', ['colour', 'length', 'thickness', 'inset']],
    ];

    /** Resolved once per request: a random style must not differ between two reads. */
    private ?string $resolved = null;

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $saved = $this->settings->get('divider_' . $key, null);
            $out[$key] = $saved === null ? $def[2] : $this->cast($key, $saved);
        }

        return $out;
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key])) {
                $this->settings->set('divider_' . $key, $this->cast($key, $value));
            }
        }
    }

    private function cast(string $key, mixed $value): mixed
    {
        $def = self::SCHEMA[$key];

        return match ($def[0]) {
            'bool' => (bool) $value,
            'range' => max((int) $def[4]['min'], min((int) $def[4]['max'], (int) $value)),
            'colour' => \App\Support\Color::isValidHex((string) $value) ? strtoupper((string) $value) : $def[2],
            'select' => isset($def[4][$value]) ? (string) $value : $def[2],
            // A comma list of section keys, filtered to ones that exist.
            'sections' => implode(',', array_values(array_intersect(
                array_filter(array_map('trim', explode(',', (string) $value))),
                array_keys(HomepageSections::REGISTRY),
            ))),
            default => mb_substr(trim((string) $value), 0, 200),
        };
    }

    /**
     * The style actually in force for this request.
     *
     * Random-per-page picks fresh each time. Random-per-visit picks once and
     * keeps it in the session, so a shopper does not see the page change under
     * them as they move between pages.
     */
    public function style(): string
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $chosen = (string) $this->all()['style'];
        $pool = array_keys(self::STYLES);

        if ($chosen === 'random_page') {
            $chosen = $pool[array_rand($pool)];
        } elseif ($chosen === 'random_session') {
            $chosen = (string) Session::get('kbb_divider', '');

            if (! isset(self::STYLES[$chosen])) {
                $chosen = $pool[array_rand($pool)];
                Session::put('kbb_divider', $chosen);
            }
        }

        return $this->resolved = isset(self::STYLES[$chosen]) ? $chosen : 'off';
    }

    /** Body class carrying the style and whether desktop shows it. */
    public function bodyClass(): string
    {
        $style = $this->style();

        if ($style === 'off') {
            return '';
        }

        return 'dvs-' . $style . ($this->all()['desktop'] ? ' dvs-wide' : '');
    }

    /** The class a given section gets, if it is due a divider above it. */
    public function classFor(string $key): string
    {
        if ($this->style() === 'off') {
            return '';
        }

        $c = $this->all();

        if ($c['scope'] === 'chosen') {
            $picked = array_filter(explode(',', (string) $c['sections']));

            return in_array($key, $picked, true) ? 'dv' : '';
        }

        // Between every section: the first one has nothing above it to be
        // separated from, unless that was asked for.
        return 'dv';
    }

    public function showAboveFirst(): bool
    {
        return (bool) $this->all()['first'];
    }

    public function cssVariables(): string
    {
        $c = $this->all();

        return implode(';', [
            '--dv-col:' . $c['colour'],
            '--dv-len:' . $c['length'] . 'px',
            '--dv-w:' . $c['thickness'] . 'px',
            '--dv-in:' . $c['inset'] . 'px',
        ]);
    }
}
