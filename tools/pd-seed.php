<?php
/*
 * Lane PD preview seed: the two products from the owner's report.
 *
 *  1. Anua Heartleaf Quercetinol Pore Deep Cleansing Foam, whose Description
 *     opens with [rey_global_section id="18159"] -- "Gentle Yet Effective
 *     Ingredients". The block is built the way ContentBlockImporter builds it:
 *     an Elementor document through App\Services\Import\ElementorToHtml. The
 *     tree is the one the owner's screenshot shows -- a row of three
 *     containers, each a picture beside a heading and a sentence (17px
 *     heading, body-size copy, 118px picture in an 820px panel) -- and the
 *     words are his, read off the screenshot.
 *  2. Medicube - Collagen Booster Set - Pink Edition, its description copied
 *     from storage/catalog/products.json (the real row). The export's literal
 *     "\n" pairs are removed: the owner's screenshot shows his live copy has
 *     none, and the two glued .webm addresses at the end are kept exactly.
 *
 * Pictures are drawn here with GD; the two clips are made by tools/pd-preview.sh
 * with ffmpeg at the SAME PATH WordPress had them, which is the state
 * `php artisan kbb:fetch-description-videos` leaves the live shop in.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$dir = public_path('wp-content/uploads/pd');
@mkdir($dir, 0o755, true);

foreach (['quercetinol' => [176, 205, 150], 'anti-sebum-p' => [150, 205, 190], 'bha' => [130, 200, 185], 'foam' => [225, 232, 220], 'medicube' => [245, 205, 215]] as $name => [$r, $g, $b]) {
    $im = imagecreatetruecolor(300, 300);
    imagefilledrectangle($im, 0, 0, 299, 299, imagecolorallocate($im, $r, $g, $b));
    for ($i = 0; $i < 9; $i++) {
        imagefilledellipse($im, 40 + ($i * 37) % 230, 50 + ($i * 61) % 210, 30 + $i * 4, 30 + $i * 4, imagecolorallocatealpha($im, 255, 255, 255, 80));
    }
    imagepng($im, $dir . '/' . $name . '-300x300.png');
}

$img = static fn (string $n): array => ['url' => '/wp-content/uploads/pd/' . $n . '-300x300.png', 'id' => 1, 'alt' => ''];
$pair = static fn (string $pic, string $title, string $text): array => [
    'elType' => 'container', 'settings' => ['flex_direction' => 'row'], 'elements' => [
        ['elType' => 'widget', 'widgetType' => 'image', 'settings' => ['image' => $img($pic)], 'elements' => []],
        ['elType' => 'container', 'settings' => ['flex_direction' => 'column'], 'elements' => [
            ['elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => $title, 'header_size' => 'h4'], 'elements' => []],
            ['elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => ['editor' => '<p>' . $text . '</p>'], 'elements' => []],
        ]],
    ],
];

$tree = [[
    'elType' => 'container', 'settings' => ['flex_direction' => 'column'], 'elements' => [
        ['elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => 'Gentle Yet Effective Ingredients', 'header_size' => 'h3'], 'elements' => []],
        ['elType' => 'container', 'settings' => ['flex_direction' => 'row'], 'elements' => [
            $pair('quercetinol', 'QUERCETINOL', 'Extracted from Heartleaf, known to effectively cure inflammation, quickly calms the skin.'),
            $pair('anti-sebum-p', 'ANTI-SEBUM P', 'Including 4 plant-derived extracts, tightens pores and controls excessive sebum production.'),
            $pair('bha', '0.5% BHA', 'Chemical exfoliants are a much more gentler alternative to physical exfoliants like scrubs.'),
        ]],
    ],
]];

$converted = (new \App\Services\Import\ElementorToHtml)->convert(json_encode($tree), '');

\App\Models\Block::query()->where('wc_id', 18159)->delete();
\App\Models\Block::query()->forceCreate([
    'wc_id' => 18159, 'slug' => 'gentle-yet-effective-ingredients', 'name' => 'Gentle Yet Effective Ingredients',
    'content' => $converted['html'], 'status' => 'published', 'source' => 'rey_global_section',
]);

$anua = \App\Models\Brand::updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
$medicube = \App\Models\Brand::updateOrCreate(['slug' => 'medicube'], ['name' => 'medicube']);

\App\Models\Product::updateOrCreate(['slug' => 'anua-heartleaf-quercetinol-pore-deep-cleansing-foam-150ml'], [
    'name' => 'Anua Heartleaf Quercetinol Pore Deep Cleansing Foam 150ml', 'brand_id' => $anua->id,
    'type' => 'simple', 'status' => 'publish', 'is_visible' => true, 'price' => 6500, 'stock_status' => 'instock',
    'image' => '/wp-content/uploads/pd/foam-300x300.png',
    'short_description' => 'A low-pH cleansing foam that clears pores without stripping.',
    'description' => "[rey_global_section id=\"18159\"]\n<strong>Benefits:</strong>\n<ul>\n<li>The cleansing foam contains Houttuynia Cordata Powder to remove dead skin and waste in pores.</li>\n<li>Contains Houttuynia Cordata Extract and Quercetin to calm the irritation caused by cleansing.</li>\n<li>Suitable for acne-prone skin.</li>\n</ul>\nMade in Korea\n\n<strong>How to use:</strong>\n<ul>\n<li>Disseminate an appropriate amount on face after lathering up. Massage the whole face gently and off afterwards.</li>\n</ul>\n<strong>About brand: Anua</strong>\n\nAnua believes that healthy skin does not depend solely on skin care products but also on having a relaxed mind and regulated lifestyle.",
]);

$rows = json_decode((string) file_get_contents(base_path('storage/catalog/products.json')), true);
$row = collect($rows)->firstWhere('slug', 'medicube-collagen-booster-set-pink-edition');

\App\Models\Product::updateOrCreate(['slug' => $row['slug']], [
    'name' => $row['name'], 'brand_id' => $medicube->id,
    'type' => 'simple', 'status' => 'publish', 'is_visible' => true, 'price' => 85000, 'stock_status' => 'instock',
    'image' => '/wp-content/uploads/pd/medicube-300x300.png',
    'short_description' => 'The AGE-R Booster Pro with the Collagen Jelly Cream and Night Wrapping Mask.',
    'description' => str_replace('\\n', '', (string) $row['description']),
]);

echo "pd seed: block 18159 + 2 products\n";
