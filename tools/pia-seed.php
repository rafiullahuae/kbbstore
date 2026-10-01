<?php
/*
 * Seed for the Lane PI-A preview: imported WooCommerce descriptions and the
 * search dropdown's "Popular right now" list.                      (Lane PI-A)
 *
 * Run AFTER tools/bg-preview.sh has booted, against the same database, so the
 * preview carries both that harness's catalogue and these two products:
 *
 *   php artisan tinker tools/pia-seed.php   (with the preview's env exported)
 *
 * THE COLUMNS ARE WRITTEN THE WAY THE IMPORTER WRITES THEM. The raw strings
 * below are the shape WooCommerce keeps in post_content / post_excerpt --
 * classic-editor text with bare newlines, a <strong> line, a <ul>, a run of
 * "- " lines -- and they go through RichText::clean() exactly as
 * ProductImporter::cleanHtml() does before they reach the row. So the preview
 * shows what an already-imported product on the live shop holds, not what a
 * hand-written fixture would.
 *
 * A <script> and an onerror attribute ride along in both columns. The importer
 * strips them on the way in; they are here so the screenshots can show that the
 * render path adds nothing back.
 */
use App\Support\RichText;

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'medicube'], ['name' => 'Medicube']);
$category = \App\Models\Category::updateOrCreate(['slug' => 'serums'], ['name' => 'Serums']);

$pic = function (string $a, string $b): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300">'
        .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        .'<stop offset="0" stop-color="'.$a.'"/><stop offset="1" stop-color="'.$b.'"/>'
        .'</linearGradient></defs><rect width="300" height="300" fill="url(#g)"/></svg>';

    return 'data:image/svg+xml;base64,'.base64_encode($svg);
};

// post_content, classic editor, as WooCommerce stores it: no <p> anywhere.
$withList = "<strong>WHAT'S INCLUDED IN THE SET :</strong>\r\n\r\n"
    ."<strong>1. MEDICUBE AGE-R BOOSTER PRO X2 PINK</strong>\r\n"
    ."The Age-R Booster Pro is a 6-in-1 device that combines booster, microcurrent, derma air, electroporation and other functions.\r\n\r\n"
    ."<strong>2. Medicube – PDRN Pink Collagen Capsule Cream</strong>\r\n"
    ."This elasticity cream delivers PDRN and collagen in capsules that melt into the skin as it is applied.\r\n\r\n"
    ."<strong>Benefits:</strong>\r\n"
    ."<ul>\r\n \t<li>Boosts absorption of the serums and creams used after it</li>\r\n \t<li>Improves elasticity and firmness</li>\r\n \t<li>Visibly brightens dull, tired skin</li>\r\n</ul>\r\n"
    ."Use the booster on clean skin, then apply the cream.\r\nFinish with sunscreen in the morning.\r\n\r\n"
    ."<h3>About brand: medicube</h3>\r\n"
    ."Medicube is a Korean dermatology-led brand known for at-home devices.\r\n"
    .'<script>window.__piaXss = "script"</script>'
    .'<img src="/pia-missing.jpg" alt="" onerror="window.__piaXss = \'onerror\'">'
    ."\r\n<a href=\"javascript:window.__piaXss='href'\">Brand page</a>";

// The same copy written with "- " lines instead of a list, which is what a
// supplier sheet pasted into the classic editor looks like.
$withDashes = "WHAT'S INCLUDED IN THE SET :\n\n"
    ."<b>1. MEDICUBE AGE-R BOOSTER PRO X2 PINK</b>\n"
    ."The Age-R Booster Pro is a 6-in-1 device.\n\n"
    ."Benefits:\n- Boosts absorption\n- Improves elasticity\n- Brightens dull skin\n\n"
    ."About brand: medicube";

$short = 'This set targets elasticity, fine lines and dryness. <div>This set is ideal for anyone who wants '
    .'a salon-style lift at home without a salon visit, and pairs the device with a cream made for it.</div>'
    .'<script>window.__piaXss = "short"</script>';

$rows = [
    ['pia-pdrn-glow-booster-set', 'Medicube PDRN Glow Booster Set', $withList, $short, 39900, 34900, 9000],
    ['pia-pdrn-dashes', 'Medicube PDRN Booster Set (plain lines)', $withDashes, "Lift & glow in two steps.\nSecond line, after a newline.", 8000, null, 8999],
];

foreach ($rows as [$slug, $name, $description, $shortDescription, $price, $sale, $sales]) {
    \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name,
        'price' => $price,
        'sale_price' => $sale,
        'status' => 'publish',
        'is_visible' => true,
        'type' => 'simple',
        'stock_status' => 'instock',
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'image' => $pic('#F7C6D4', '#E0567B'),
        // Exactly ProductImporter::cleanHtml().
        'description' => RichText::clean($description),
        'short_description' => RichText::clean($shortDescription),
        'total_sales' => $sales,
    ]);
}

/*
 * AND ONE ROW THAT NEVER SAW THE SANITISER. AdminController's quick edit
 * stores `description` and `short_description` exactly as sent, so a row can
 * hold markup the importer would have stripped. Written here with a direct
 * update, the same bytes that endpoint would write, so the render path is
 * tested on its own: whatever is in the column, nothing in it may run.
 */
$raw = \App\Models\Product::updateOrCreate(['slug' => 'pia-raw-unsanitised'], [
    'name' => 'Unsanitised row (render-path proof)',
    'price' => 5000, 'status' => 'publish', 'is_visible' => true, 'type' => 'simple',
    'stock_status' => 'instock', 'brand_id' => $brand->id, 'category_id' => $category->id,
    'total_sales' => 0,
]);
\Illuminate\Support\Facades\DB::table('products')->where('id', $raw->id)->update([
    'description' => "First paragraph.\n\nSecond <b onmouseover=\"window.__piaXss='hover'\">bold</b>.\n"
        .'<script>window.__piaXss = "desc-script"</script>'
        .'<img src="/pia-missing.jpg" onerror="window.__piaXss = \'desc-onerror\'">'
        .'<a href="javascript:window.__piaXss=\'href\'">x</a>'
        .'<p style="position:fixed;inset:0;z-index:99999;background:red">overlay</p>',
    'short_description' => 'Blurb <img src="/pia-missing.jpg" onerror="window.__piaXss = \'short-onerror\'">'
        .'<script>window.__piaXss = "short-script"</script>',
]);

\Illuminate\Support\Facades\Cache::flush();

echo "pia seed done\n";
