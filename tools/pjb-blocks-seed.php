<?php
/*
 * Seed for the Lane PJ-B preview: the owner's Anua foam, whose description
 * opens with `[rey_global_section id="18159"]`.                   (Lane PJ-B)
 *
 *   PJB_IMPORT unset   BEFORE: the product only, the shortcode in its copy and
 *                      no section imported -- run against the base tree, this
 *                      is the page the owner reported.
 *   PJB_IMPORT=1       AFTER: content_blocks.csv (Tests\Support\
 *                      ContentBlocksFixture, the contract's exact columns)
 *                      through the REAL ImportRunner, the three ingredient
 *                      pictures dropped into wp-content/uploads the way the
 *                      owner copies them, and the REAL picture pass
 *                      (DocumentMediaRewrite) re-pointing the block at them.
 *   PJB_FILES=1        also puts nine export files on Store -> Import, for the
 *                      "Remove all files" shot.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
$category = \App\Models\Category::updateOrCreate(['slug' => 'cleansers'], ['name' => 'Cleansers']);

$root = getenv('KBB_PUBLIC_PATH') ?: public_path();
@mkdir($root.'/uploads/products', 0775, true);

/* A picture with a soft disc and a label, so a shot reads as a picture. */
$draw = function (string $path, int $size, string $bg, string $fg, string $label): void {
    $hex = static fn (string $h): array => [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    $im = imagecreatetruecolor($size, $size);
    imagefilledrectangle($im, 0, 0, $size, $size, imagecolorallocate($im, ...$hex($bg)));
    imagefilledellipse($im, intdiv($size, 2), intdiv($size, 2), (int) ($size * .62), (int) ($size * .62), imagecolorallocate($im, ...$hex($fg)));
    $ink = imagecolorallocate($im, 255, 255, 255);
    $w = imagefontwidth(5) * strlen($label);
    imagestring($im, 5, intdiv($size - $w, 2), intdiv($size, 2) - 8, $label, $ink);
    @mkdir(dirname($path), 0775, true);
    imagejpeg($im, $path, 88);
};

$draw($root.'/uploads/products/anua-foam.jpg', 800, 'F4F7EC', '9DBF7A', 'ANUA FOAM');

$description = "[rey_global_section id=\"18159\"]\r\n"
    ."Anua's Heartleaf Quercetinol Pore Deep Cleansing Foam lifts oil, sebum and the day's sunscreen in one wash, "
    ."without the tight, squeaky feel of a harsh cleanser.\r\n\r\n"
    ."<strong>How to use</strong>\r\n"
    ."Work a pea-sized amount into a lather with lukewarm water.\r\n"
    ."Massage over damp skin for 30 seconds, then rinse.";

$product = \App\Models\Product::updateOrCreate(['slug' => 'anua-heartleaf-quercetinol-pore-deep-cleansing-foam-150ml'], [
    'name' => 'Anua Heartleaf Quercetinol Pore Deep Cleansing Foam 150ml',
    'wc_id' => 30412, 'price' => 8900, 'brand_id' => $brand->id, 'category_id' => $category->id,
    'image' => '/uploads/products/anua-foam.jpg', 'status' => 'publish', 'is_visible' => true,
    'stock_status' => 'instock', 'type' => 'simple',
    'short_description' => '<p>A low-pH foam that deep-cleans pores while heartleaf and quercetinol keep skin calm.</p>',
    'description' => $description,
]);
$product->categories()->sync([$category->id]);

if (getenv('PJB_IMPORT')) {
    $dir = storage_path('framework/testing/pjb-preview-export');
    \Tests\Support\ContentBlocksFixture::write($dir, [\Tests\Support\ContentBlocksFixture::row()]);

    $report = (new \App\Services\Import\ImportRunner)->run(new \App\Services\Import\ImportOptions(
        directory: $dir, only: ['content-blocks'], runKey: 'pjb-preview', restart: true,
    ));
    $r = $report->for('content-blocks');
    echo "content-blocks: created {$r->created}, updated {$r->updated}, unchanged {$r->unchanged}, rejected {$r->rejectedCount()}\n";

    $palette = [['F6E3E8', 'E0567B'], ['E8EEF6', '6B8FC7'], ['F3EEE2', 'C29A4A']];
    foreach (\Tests\Support\ContentBlocksFixture::ingredients() as $i => $item) {
        $draw($root.'/wp-content/uploads/'.$item['file'], 300, $palette[$i][0], $palette[$i][1], $item['name']);
    }

    $rewrite = new \App\Services\Import\DocumentMediaRewrite;
    $proposals = $rewrite->propose(['kbeautybliss.com']);
    $mine = array_values(array_filter($proposals, static fn ($p) => $p['owner_type'] === \App\Models\Block::class));
    echo 'picture pass: '.count($mine).' block address(es) proposed, '.$rewrite->apply($mine)." document(s) re-pointed\n";
}

if (getenv('PJB_FILES')) {
    $ws = new \App\Services\ImportConsole\ImportWorkspace;
    foreach (['categories', 'brands', 'products', 'tags', 'attributes', 'variations', 'reviews', 'seo', 'content-blocks'] as $entity) {
        $name = \App\Services\ImportConsole\ImportWorkspace::meta($entity)['file'];
        $tmp = sys_get_temp_dir().'/pjb-'.bin2hex(random_bytes(4)).'-'.$name;
        copy(base_path('tests/Fixtures/woo/'.$name), $tmp);
        $ws->accept(new \Illuminate\Http\UploadedFile($tmp, $name, 'text/csv', null, true));
    }
    echo 'import files on the card: '.count(array_filter($ws->files(), static fn ($f) => $f['present']))."\n";
}

\Illuminate\Support\Facades\Cache::flush();

echo "pjb seed: product #{$product->id} /product/{$product->slug}/, blocks ".\App\Models\Block::query()->count()."\n";
