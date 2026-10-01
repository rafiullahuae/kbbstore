<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * `content_blocks.csv`, in the shape Lane PJ-A's exporter writes it. (Lane PJ-B)
 *
 * The contract, exactly: id, post_type, slug, title, status, modified (Y-m-d
 * H:i:s GMT), shortcode, referenced_by (pipe-joined product WC ids),
 * elementor_data (raw `_elementor_data` JSON), plain_html (post_content).
 *
 * section18159() is the owner's own example, rebuilt as Elementor saves it: a
 * section with one column holding the heading and an INNER section of three
 * columns, each with one image-box -- picture on the left, the bold uppercase
 * ingredient name, one sentence. It also carries what real Elementor data
 * carries and a converter has to survive: a spacer, styling keys nobody reads,
 * and a phone-only duplicate of the heading hidden on desktop.
 */
final class ContentBlocksFixture
{
    public const HEADER = ['id', 'post_type', 'slug', 'title', 'status', 'modified', 'shortcode', 'referenced_by', 'elementor_data', 'plain_html'];

    public const OLD_HOST = 'https://kbeautybliss.com';

    /** @return list<array{name: string, text: string, file: string}> */
    public static function ingredients(): array
    {
        return [
            ['name' => 'QUERCETINOL', 'text' => 'A heartleaf antioxidant that calms redness while the foam cleans.', 'file' => '2024/03/anua-quercetinol-300x300.jpg'],
            ['name' => 'ANTI-SEBUM P', 'text' => 'A sebum-control complex that keeps pores clear without stripping.', 'file' => '2024/03/anua-anti-sebum-300x300.jpg'],
            ['name' => '0.5% BHA', 'text' => 'Gently exfoliates inside the pore to lift blackheads and excess oil.', 'file' => '2024/03/anua-bha-300x300.jpg'],
        ];
    }

    /** The Elementor tree for Global Section 18159, as `_elementor_data` holds it. */
    public static function section18159(string $host = self::OLD_HOST): string
    {
        $columns = [];

        foreach (self::ingredients() as $i => $item) {
            $columns[] = [
                'id' => 'c' . $i . 'a1f2e',
                'elType' => 'column',
                'settings' => ['_column_size' => 33, '_inline_size' => null],
                'elements' => [[
                    'id' => 'w' . $i . 'b7c4d',
                    'elType' => 'widget',
                    'widgetType' => 'image-box',
                    'settings' => [
                        'image' => ['url' => $host . '/wp-content/uploads/' . $item['file'], 'id' => 18201 + $i, 'alt' => '', 'source' => 'library', 'size' => ''],
                        'title_text' => $item['name'],
                        'description_text' => $item['text'],
                        'position' => 'left',
                        'title_size' => 'h4',
                        'image_space' => ['unit' => 'px', 'size' => 15, 'sizes' => []],
                        'image_size' => ['unit' => '%', 'size' => 40, 'sizes' => []],
                        'title_typography_typography' => 'custom',
                        'title_typography_text_transform' => 'uppercase',
                    ],
                    'elements' => [],
                ]],
                'isInner' => true,
            ];
        }

        return (string) json_encode([[
            'id' => '5c1a2b3',
            'elType' => 'section',
            'settings' => ['gap' => 'extended', 'content_width' => ['unit' => 'px', 'size' => 1140, 'sizes' => []]],
            'elements' => [[
                'id' => '7d2e4f1',
                'elType' => 'column',
                'settings' => ['_column_size' => 100, '_inline_size' => null],
                'elements' => [
                    [
                        'id' => 'a91b2c3',
                        'elType' => 'widget',
                        'widgetType' => 'heading',
                        'settings' => ['title' => 'Gentle Yet Effective Ingredients', 'header_size' => 'h3', 'typography_typography' => 'custom'],
                        'elements' => [],
                    ],
                    [
                        'id' => 'a91b2c4',
                        'elType' => 'widget',
                        'widgetType' => 'heading',
                        'settings' => ['title' => 'Gentle Yet Effective Ingredients', 'header_size' => 'h3', 'hide_desktop' => 'hidden-desktop', 'hide_tablet' => 'hidden-tablet'],
                        'elements' => [],
                    ],
                    [
                        'id' => 'b12c3d4',
                        'elType' => 'widget',
                        'widgetType' => 'spacer',
                        'settings' => ['space' => ['unit' => 'px', 'size' => 10, 'sizes' => []]],
                        'elements' => [],
                    ],
                    [
                        'id' => 'c23d4e5',
                        'elType' => 'section',
                        'isInner' => true,
                        'settings' => ['structure' => '30'],
                        'elements' => $columns,
                    ],
                ],
            ]],
            'isInner' => false,
        ]], JSON_UNESCAPED_SLASHES);
    }

    /** Elementor's own text fallback for the same section, as post_content holds it. */
    public static function plain18159(string $host = self::OLD_HOST): string
    {
        $out = "<h3>Gentle Yet Effective Ingredients</h3>\n";

        foreach (self::ingredients() as $item) {
            $out .= '<figure><img src="' . $host . '/wp-content/uploads/' . $item['file'] . '" alt="" /></figure>'
                . "\n<h4>" . $item['name'] . "</h4>\n<p>" . $item['text'] . "</p>\n";
        }

        return $out;
    }

    /** One CSV row, keyed by the contract's columns. @return array<string, string> */
    public static function row(array $overrides = []): array
    {
        return array_merge([
            'id' => '18159',
            'post_type' => 'rey-global-sections',
            'slug' => 'gentle-yet-effective-ingredients',
            'title' => 'Anua Heartleaf — Gentle Yet Effective Ingredients',
            'status' => 'publish',
            'modified' => '2024-03-12 09:41:07',
            'shortcode' => 'rey_global_section',
            'referenced_by' => '30412|30419',
            'elementor_data' => self::section18159(),
            'plain_html' => self::plain18159(),
        ], $overrides);
    }

    /** @param list<array<string, string>> $rows */
    public static function write(string $dir, array $rows): string
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = rtrim($dir, '/') . '/content_blocks.csv';
        $handle = fopen($path, 'wb');
        fputcsv($handle, self::HEADER, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn (string $c): string => (string) ($row[$c] ?? ''), self::HEADER), ',', '"', '');
        }

        fclose($handle);

        return $path;
    }
}
