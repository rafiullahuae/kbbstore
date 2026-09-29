<?php
/*
 * Seed the Lane IM2 preview.
 *
 * WHAT IS IN IT, AND WHY EACH PIECE IS LOAD-BEARING:
 *
 *   im2-gallery        a five-shot gallery holding one square, one 9:16
 *                      PORTRAIT, one 16:9 LANDSCAPE, one 3:4 and one square,
 *                      plus four approved reviews carrying seven photographs
 *                      off a handset. The batch IS run over it -- this is the
 *                      shop after the owner has clicked Make phone-sized
 *                      copies.
 *
 *                      The portrait/landscape pair is the whole point of the
 *                      square-crop measurement: a strip of square originals
 *                      cannot show whether cropping changed anything, and a
 *                      catalogue of them cannot answer whether a square
 *                      derivative would be worth generating.
 *
 *   im2-no-variants    the same shapes, added AFTER the batch, so nothing in
 *                      it has a copy. An old import, a file that arrived by
 *                      FTP, anything that predates the pipeline. Its review
 *                      photograph has no copy either, which is the case that
 *                      governs everything this lane did: srcsetFor() must say
 *                      NOTHING there and the original must still draw.
 *
 *   36 media rows      a realistic Media Library, each pointing at its own
 *                      distinct file so the byte count is not a measurement of
 *                      the browser cache.
 *
 * THE BATCH IS DRIVEN THE WAY THE OWNER DRIVES IT -- the real controller, the
 * real cursor, until it says done -- and not by calling ImageVariants::
 * generate() over a list this script picked. The measurement is partly ABOUT
 * which photographs that screen reaches, so a harness that sized them itself
 * would hide the defect it is here to photograph. Round one's im-seed.php made
 * the same choice for the same reason.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'im2-anua'], ['name' => 'Anua']);
$category = \App\Models\Category::updateOrCreate(['slug' => 'im2-cleansers'], ['name' => 'Cleansers']);

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

$review = function (\App\Models\Product $p, string $who, array $photos, int $rating = 5) {
    \App\Models\Review::updateOrCreate(
        ['product_id' => $p->id, 'author_name' => $who],
        [
            'source' => 'sorina',
            'author_email' => strtolower($who).'@example.test',
            'rating' => $rating,
            'title' => 'Lovely on my skin',
            'content' => 'Cleared my skin in a fortnight and it smells of almost nothing, which is what I wanted.',
            'images' => $photos,
            'status' => 'approved',
            'verified' => true,
            'helpful' => 7,
        ]
    );
};

$gallery = [
    '/uploads/im2/shot-1.jpg', '/uploads/im2/shot-2.jpg', '/uploads/im2/shot-3.jpg',
    '/uploads/im2/shot-4.jpg', '/uploads/im2/shot-5.jpg',
];

$sized = $make('im2-gallery', 'Heartleaf Pore Control Cleansing Oil 200ml', $gallery);

// FOUR cards, and the shapes the wall draws are both present: one card with a
// single photograph (.sr-pp.one, a 266x160 box) and cards with several
// (.sr-pp.multi, a 131x76 half-cell). A measurement of only one of them would
// be a measurement of one CSS rule.
$review($sized, 'Noura', ['/uploads/reviews/rev-1.jpg']);
$review($sized, 'Hessa', ['/uploads/reviews/rev-2.jpg', '/uploads/reviews/rev-3.jpg']);
$review($sized, 'Mariam', ['/uploads/reviews/rev-4.jpg', '/uploads/reviews/rev-5.jpg'], 4);
$review($sized, 'Aisha', ['/uploads/reviews/rev-6.jpg']);

// A realistic library. Distinct files, because 36 tiles all pointing at one
// URL is a measurement of the browser's cache and not of this screen.
foreach (glob(public_path('uploads/im2/lib/*.jpg')) ?: [] as $file) {
    $name = basename($file);
    \App\Models\Media::updateOrCreate(['path' => 'uploads/im2/lib/'.$name], [
        'filename' => $name,
        'original_name' => $name,
        'mime' => 'image/jpeg',
        'size' => filesize($file),
        'width' => 800,
        'height' => 800,
    ]);
}

/* A PRODUCT FOR EVERY LIBRARY FILE, which is what puts them in the work list at
   all -- images() walks the places a photograph is REFERENCED, not media.path.
   These are hidden so they do not crowd the shop, and they exist only so the
   Media Library measurement is of a library that HAS been through the batch. */
foreach (glob(public_path('uploads/im2/lib/*.jpg')) ?: [] as $i => $file) {
    $name = pathinfo($file, PATHINFO_FILENAME);
    \App\Models\Product::updateOrCreate(['slug' => 'im2-lib-'.$name], [
        'name' => 'Library holder '.$name,
        'brand_id' => $brand->id,
        'status' => 'draft',
        'is_visible' => false,
        'type' => 'simple',
        'price' => 100,
        'stock_status' => 'instock',
        'image' => '/uploads/im2/lib/'.basename($file),
        'images' => [],
    ]);
}

/* THE BATCH, driven the way the screen drives it. */
$runner = new \App\Http\Controllers\Admin\ImageSizesApiController();
$cursor = '';
$made = 0;

for ($i = 0; $i < 600; $i++) {
    $payload = json_decode($runner->run(\Illuminate\Http\Request::create('/', 'GET', ['after' => $cursor]))->getContent(), true);

    if (! ($payload['ok'] ?? false)) {
        echo 'batch refused: '.($payload['message'] ?? '?')."\n";
        break;
    }

    $made += (int) $payload['made'];
    $cursor = (string) $payload['cursor'];

    if ($payload['done'] ?? false) {
        break;
    }
}

echo "batch made {$made} copies\n";

$status = json_decode($runner->status()->getContent(), true);
echo "work list: total {$status['total']}  done {$status['done']}  remaining {$status['remaining']}\n";

/* AND ONLY NOW the unsized product, so its photographs have genuinely never
   been through the batch -- the ordinary case of an image added since the last
   run, and the one the fallback has to draw. */
$cold = $make('im2-no-variants', 'Rice 70 Glow Milky Toner 250ml', [
    '/uploads/im2/cold-1.jpg', '/uploads/im2/cold-2.jpg', '/uploads/im2/cold-3.jpg',
]);

$review($cold, 'Fatima', ['/uploads/reviews/cold-rev.jpg']);

/* THE SITE'S OWN ADDRESS, which the Media Library depends on and the storefront
   does not. Media::urlFor() builds an ABSOLUTE url for anything under uploads/
   out of the `site_url` setting, so without this every library tile points at
   http://localhost and the measurement comes back as zero requests and zero
   bytes — which reads exactly like a fix that worked. It is also the setting
   MediaLibraryApiController::tileThumb() is written not to depend on. */
\App\Models\Setting::updateOrCreate(['key' => 'site_url'], [
    'value' => rtrim((string) (getenv('IM2_SITE_URL') ?: 'http://127.0.0.1:8979'), '/'),
]);

// Demo content tops a short gallery up with placeholder shots, which would put
// captioned squares in the strip and make the request count mean something
// else. Off, so what is counted is photographs.
\App\Models\Setting::updateOrCreate(['key' => 'demo_content'], ['value' => '0']);

echo "seeded\n";
