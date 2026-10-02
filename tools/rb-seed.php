<?php
/*
 * Seed the Lane RB preview ("Buy these together").
 *
 * Written into the PREVIEW's database and web root only; nothing here reaches a
 * package. The demo catalogue the migrations draw (Cleansers, Toners, Serums,
 * Moisturisers, Sunscreens, Masks; 24 products filed by `i % 6`) is RE-FILED
 * under the owner's own shelf names and by what each product actually is, so
 * the screenshots show the pairing he described on names he would recognise:
 * Skincare › Sunscreens, Moisturizers, Toners, Cleansing Oils, Face Masks,
 * Face Serums, Face Washes, Lip Care.
 *
 * Pictures are small SVG bottles written into the preview's web root, one tint
 * per shelf, so a screenshot shows a moisturiser next to a toner rather than
 * five identical gradients.
 *
 *   php artisan tinker --execute="require 'tools/rb-seed.php';"   (from rb-preview.sh)
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Str;

$root = (string) env('KBB_PUBLIC_PATH', public_path());
@mkdir($root.'/uploads/rb', 0755, true);

$picture = function (string $slug, string $ground, string $body, string $cap, string $label, string $shape = 'bottle') use ($root): string {
    $form = match ($shape) {
        'jar' => '<rect x="250" y="360" width="400" height="300" rx="60" fill="'.$body.'"/><rect x="236" y="300" width="428" height="90" rx="26" fill="'.$cap.'"/>',
        'tube' => '<path d="M360 170h180l40 470H320z" fill="'.$body.'"/><rect x="390" y="640" width="120" height="70" rx="12" fill="'.$cap.'"/>',
        'sachet' => '<rect x="250" y="190" width="400" height="520" rx="30" fill="'.$body.'"/><rect x="250" y="190" width="400" height="60" rx="30" fill="'.$cap.'"/>',
        'pump' => '<rect x="320" y="290" width="260" height="420" rx="50" fill="'.$body.'"/><rect x="410" y="200" width="80" height="100" rx="10" fill="'.$cap.'"/><rect x="400" y="170" width="150" height="40" rx="12" fill="'.$cap.'"/>',
        default => '<rect x="392" y="150" width="116" height="74" rx="14" fill="'.$cap.'"/><rect x="330" y="214" width="240" height="512" rx="44" fill="'.$body.'"/>',
    };
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="900" viewBox="0 0 900 900">'
        .'<rect width="900" height="900" fill="'.$ground.'"/>'
        .'<ellipse cx="450" cy="745" rx="210" ry="26" fill="rgba(0,0,0,.07)"/>'
        .$form
        .'<rect x="350" y="430" width="200" height="110" rx="12" fill="rgba(255,255,255,.9)"/>'
        .'<text x="450" y="503" font-family="Helvetica,Arial,sans-serif" font-size="52" font-weight="700" fill="'.$cap.'" text-anchor="middle">'.$label.'</text>'
        .'</svg>';
    file_put_contents($root.'/uploads/rb/'.$slug.'.svg', $svg);

    return '/uploads/rb/'.$slug.'.svg';
};

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

/* ── his shelves ─────────────────────────────────────────────────────────── */
$skin = Category::updateOrCreate(['slug' => 'skincare'], ['name' => 'Skincare', 'depth' => 0, 'position' => 0, 'path' => 'skincare']);

$shelf = function (string $slug, string $name, int $pos, ?string $old = null) use ($skin): Category {
    $c = $old ? Category::where('slug', $old)->first() : null;
    $c ??= Category::firstOrNew(['slug' => $slug]);
    $c->fill(['slug' => $slug, 'name' => $name, 'parent_id' => $skin->id, 'depth' => 1, 'position' => $pos, 'path' => 'skincare/'.$slug])->save();

    return $c;
};

$cats = [
    'sun' => $shelf('sunscreens', 'Sunscreens', 1, 'sunscreens'),
    'moist' => $shelf('moisturizers', 'Moisturizers', 2, 'moisturisers'),
    'toner' => $shelf('toners', 'Toners', 3, 'toners'),
    'oil' => $shelf('cleansing-oils', 'Cleansing Oils', 4),
    'mask' => $shelf('face-masks', 'Face Masks', 5, 'masks'),
    'serum' => $shelf('face-serums', 'Face Serums', 6, 'serums'),
    'wash' => $shelf('face-washes', 'Face Washes', 7, 'cleansers'),
    'lip' => $shelf('lip-care', 'Lip Care', 8),
];

$look = [
    'sun' => ['#FFF6E5', '#FFD66B', '#C9821B', 'SPF', 'tube'],
    'moist' => ['#EEF4FB', '#BFD7F2', '#3D6FB3', 'CREAM', 'jar'],
    'toner' => ['#EEF7F1', '#BFE3CC', '#2F8A5A', 'TONER', 'bottle'],
    'oil' => ['#FBF3E6', '#F1D29A', '#A8701F', 'OIL', 'pump'],
    'mask' => ['#F5F0FB', '#D9C8F2', '#6E4FB8', 'MASK', 'sachet'],
    'serum' => ['#FBEFF3', '#F4C6D4', '#C13E63', 'SERUM', 'pump'],
    'wash' => ['#EFF8F8', '#BDE5E4', '#24857F', 'WASH', 'tube'],
    'lip' => ['#FCEFEF', '#F2B9B9', '#B5424A', 'LIP', 'tube'],
];

$kindOf = function (string $name): string {
    $n = strtolower($name);

    return match (true) {
        str_contains($n, 'sun') || str_contains($n, 'spf') => 'sun',
        str_contains($n, 'mask') => 'mask',
        str_contains($n, 'toner') || str_contains($n, 'essence water') => 'toner',
        str_contains($n, 'cleanser') || str_contains($n, 'foam') => 'wash',
        str_contains($n, 'cream') || str_contains($n, 'moistur') => 'moist',
        default => 'serum',
    };
};

/* ── the demo catalogue, re-filed and given pictures ─────────────────────── */
$sales = 9000;

foreach (Product::query()->orderBy('id')->get() as $p) {
    $k = $kindOf($p->name);
    [$g, $b, $c, $l, $s] = $look[$k];
    $p->forceFill([
        'category_id' => $cats[$k]->id,
        'image' => $picture($p->slug, $g, $b, $c, $l, $s),
        'stock_status' => 'instock',
        'total_sales' => $sales -= 250,
    ])->save();
    $p->categories()->sync([$cats[$k]->id]);
}

/* ── the shelves the demo does not have ──────────────────────────────────── */
$extra = [
    ['oil', 'Heartleaf Pore Control Cleansing Oil', 'Anua', 4600, 6200],
    ['oil', 'Ginseng Cleansing Oil 210ml', 'Beauty of Joseon', 3900, 5100],
    ['oil', 'Low pH Pore Clear Cleansing Balm', 'COSRX', 0, 4800],
    ['lip', 'Lip Sleeping Mask Berry', 'Laneige', 0, 3300],
    ['moist', 'Dynasty Cream 50ml', 'Beauty of Joseon', 0, 8400],
    ['toner', 'Heartleaf 77% Soothing Toner 250ml', 'Anua', 2600, 8800],
];

foreach ($extra as [$k, $name, $brandName, $saleOff, $sold]) {
    $brand = Brand::firstOrCreate(['slug' => Str::slug($brandName)], ['name' => $brandName]);
    [$g, $b, $c, $l, $s] = $look[$k];
    $slug = Str::slug($name);
    $price = $k === 'oil' ? 9500 : 8900;
    $p = Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'brand_id' => $brand->id, 'category_id' => $cats[$k]->id,
        'type' => 'simple', 'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
        'price' => $price, 'sale_price' => $saleOff ? $price - $saleOff : null,
        'total_sales' => $sold, 'image' => $picture($slug, $g, $b, $c, $l, $s),
    ]);
    $p->categories()->sync([$cats[$k]->id]);
}

/* ── the section on, as the migration makes it on his shop ───────────────── */
$settings = app(\App\Services\SettingsService::class);
$settings->set('bt_on', true);

/* ── the cart page's Recommended rail, so the two can be laid side by side ── */
$settings->set('cartpage_layout', 'squeeze');
$settings->set('cartpage_rec_on', true);
$settings->set('cartpage_rec_ids', implode(',', Product::query()->where('stock_status', 'instock')->orderByDesc('total_sales')->limit(8)->pluck('id')->all()));

\App\Models\Setting::flushMap();
\Illuminate\Support\Facades\Cache::flush();

echo 'rb seed done: '.Product::count()." products\n";
