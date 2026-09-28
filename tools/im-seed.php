<?php
/*
 * Seed the Lane IM preview: two products, and the difference between them is
 * the whole point of the measurement.
 *
 *   im-full-gallery    six real 1000x1000 photographs, and the variant batch
 *                      is run over it — the shop AFTER the owner has clicked
 *                      Media Library -> Image Sizes.
 *   im-no-variants     three real 1000x1000 photographs and DELIBERATELY no
 *                      copies at all — an old import, a file that arrived by
 *                      FTP, anything that predates the pipeline. The fallback
 *                      must draw a photograph here, not a broken image.
 *
 * The photographs are already on disk under the preview web root; this only
 * points products at them. tools/im-photos.php wrote them.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'im-anua'], ['name' => 'Anua']);
$category = \App\Models\Category::updateOrCreate(['slug' => 'im-cleansers'], ['name' => 'Cleansers']);

$make = function (string $slug, string $name, array $images) use ($brand, $category) {
    $p = \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name,
        'brand_id' => $brand->id,
        'status' => 'publish',
        'is_visible' => true,
        'type' => 'simple',
        'price' => 9900,
        'stock_status' => 'instock',
        'manage_stock' => true,
        'stock' => 24,
        'image' => $images[0],
        'images' => $images,
        'description' => '<p>A gentle daily cleansing oil.</p>',
    ]);

    $p->categories()->syncWithoutDetaching([$category->id]);

    return $p;
};

$make('im-full-gallery', 'Heartleaf Pore Control Cleansing Oil 200ml', [
    '/uploads/im/shot-1.jpg', '/uploads/im/shot-2.jpg', '/uploads/im/shot-3.jpg',
    '/uploads/im/shot-4.jpg', '/uploads/im/shot-5.jpg',
]);

/* THE BATCH IS RUN THE WAY THE OWNER RUNS IT, and not by calling
   ImageVariants::generate() over a list this script picked. The measurement is
   partly ABOUT which photographs that screen reaches: a harness that sized the
   gallery itself would hide the defect it is here to photograph. So the real
   controller, driven with the real cursor, until it says done. */
$runner = new \App\Http\Controllers\Admin\ImageSizesApiController();
$cursor = '';
$made = 0;

for ($i = 0; $i < 200; $i++) {
    $payload = json_decode($runner->run(\Illuminate\Http\Request::create('/', 'GET', ['after' => $cursor]))->getContent(), true);

    if (! ($payload['ok'] ?? false)) {
        echo "batch refused: ".($payload['message'] ?? '?')."\n";
        break;
    }

    $made += (int) $payload['made'];
    $cursor = (string) $payload['cursor'];

    if ($payload['done'] ?? false) {
        break;
    }
}

echo "batch made {$made} copies\n";

/* AND ONLY NOW the second product, so its photographs have genuinely never
   been through the batch — the ordinary case of an image added since the last
   run, and the one the fallback has to draw. */
$make('im-no-variants', 'Rice 70 Glow Milky Toner 250ml', [
    '/uploads/im/shot-6.jpg', '/uploads/im/shot-7.jpg', '/uploads/im/shot-8.jpg',
]);

// Demo content tops a short gallery up with placeholder shots, which would put
// captioned squares in the strip and make the request count mean something
// else. Off, so what is counted is photographs.
\App\Models\Setting::updateOrCreate(['key' => 'demo_content'], ['value' => '0']);

echo "seeded\n";

/* A BASKET, so the sweep's other half can be photographed too. The cart page
   and the drawer both draw a 42px square from a product photograph, as a CSS
   background — which cannot carry a srcset, so the width is picked on the
   server. The token is printed for the screenshot harness to set as a cookie.
   A uuid() because carts.token is char(36) and MySQL enforces that width. */
$cart = \App\Models\Cart::create(['token' => (string) \Illuminate\Support\Str::uuid(), 'currency' => 'AED']);

foreach (['im-full-gallery', 'im-no-variants'] as $slug) {
    $cart->items()->create([
        'product_id' => \App\Models\Product::where('slug', $slug)->value('id'),
        'quantity' => 1,
        'unit_price' => 9900,
    ]);
}

echo "cart-token: ".$cart->token."\n";
