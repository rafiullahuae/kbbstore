<?php

declare(strict_types=1);

/*
 * Content -> Media Library -> WebP images (Lane WP).
 *
 * The owner: "whenever we upload jpg or png images, can we convert auto into
 * webp ... also need function to look for jpg and [png] images and convert auto
 * in bulk, and also replace them where the images are actually used."
 *
 * Every fixture is a REAL image made with GD in the test, never a fake: a
 * converter tested against a file that only has the right name proves nothing.
 * Every test runs against its own public root (usePublicPath), so nothing here
 * can see, convert or delete another test's uploads.
 */

use App\Models\AdminUser;
use App\Models\Media;
use App\Services\Media\WebpBulk;
use App\Services\Media\WebpConverter;
use App\Services\Media\WebpReferences;
use App\Services\Media\WebpSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\WebpAdminRoutes;

/* ------------------------------------------------------------ fixtures */

function wpRoot(): string
{
    $root = kbbTempDir().'/wp-pub-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    app()->usePublicPath($root);
    $GLOBALS['wpRootDir'] = $root;

    return $root;
}

function wpRemoveTree(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && ! $f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }

    @rmdir($dir);
}

afterEach(function () {
    if (isset($GLOBALS['wpRootDir'])) {
        wpRemoveTree($GLOBALS['wpRootDir']);
        unset($GLOBALS['wpRootDir']);
    }
});

function wpPut(string $relative, string $bytes): string
{
    $file = public_path($relative);
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, $bytes);

    return $file;
}

/** A noisy photograph: JPEG is ~210 KB, its WebP ~80 KB. */
function wpJpeg(int $w = 600, int $h = 400): string
{
    mt_srand(7);
    $img = imagecreatetruecolor($w, $h);

    for ($y = 0; $y < $h; $y += 2) {
        for ($x = 0; $x < $w; $x += 2) {
            $c = imagecolorallocate($img, (int) ($x * 255 / $w + mt_rand(0, 40)) % 256, (int) ($y * 255 / $h + mt_rand(0, 40)) % 256, mt_rand(80, 160));
            imagefilledrectangle($img, $x, $y, $x + 1, $y + 1, $c);
        }
    }

    ob_start();
    imagejpeg($img, null, 90);

    return (string) ob_get_clean();
}

/** Left half fully transparent, right half noisy and opaque. */
function wpAlphaPng(int $w = 300, int $h = 200): string
{
    mt_srand(11);
    $img = imagecreatetruecolor($w, $h);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));

    for ($y = 0; $y < $h; $y++) {
        for ($x = (int) ($w / 2); $x < $w; $x++) {
            imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), 0));
        }
    }

    ob_start();
    imagepng($img);

    return (string) ob_get_clean();
}

/** A 1px checkerboard: PNG is ~155 bytes, its WebP ~14 KB. */
function wpCheckerPng(): string
{
    $img = imagecreate(256, 256);
    imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);

    for ($y = 0; $y < 256; $y++) {
        for ($x = 0; $x < 256; $x++) {
            if (($x + $y) % 2) {
                imagesetpixel($img, $x, $y, $black);
            }
        }
    }

    ob_start();
    imagepng($img, null, 9);

    return (string) ob_get_clean();
}

/**
 * 80x40, left half red and right half blue, with an EXIF APP1 segment saying
 * Orientation = $orientation — what a phone held upright writes.
 */
function wpOrientedJpeg(int $orientation): string
{
    $img = imagecreatetruecolor(80, 40);
    imagefilledrectangle($img, 0, 0, 39, 39, imagecolorallocate($img, 255, 0, 0));
    imagefilledrectangle($img, 40, 0, 79, 39, imagecolorallocate($img, 0, 0, 255));
    ob_start();
    imagejpeg($img, null, 95);
    $jpeg = (string) ob_get_clean();

    $tiff = "MM\x00\x2A\x00\x00\x00\x08"."\x00\x01"
        ."\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientation)."\x00\x00"
        ."\x00\x00\x00\x00";
    $payload = "Exif\x00\x00".$tiff;
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
}

/** A PNG whose header claims $w x $h. getimagesize() believes it; nothing else is in it. */
function wpBombPng(int $w, int $h): string
{
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1A\n"
        .$chunk('IHDR', pack('NN', $w, $h)."\x08\x02\x00\x00\x00")
        .$chunk('IDAT', (string) gzcompress("\x00\x00\x00\x00"))
        .$chunk('IEND', '');
}

function wpAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'WP '.$role,
        'email' => 'wp-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

function wpUploadFile(string $name, string $bytes): UploadedFile
{
    $path = tempnam(kbbTempDir(), 'wpup');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

/* ------------------------------------------------------- the converter */

it('converts a real JPEG to a smaller WebP and keeps its size', function () {
    wpRoot();
    $src = wpPut('uploads/products/photo.jpg', wpJpeg());
    $dest = WebpConverter::reserveName($src);

    $r = WebpConverter::convert($src, $dest, 82, 2400);

    expect($r['ok'])->toBeTrue()
        ->and($dest)->toEndWith('/uploads/products/photo.webp')
        ->and(WebpConverter::sniff($dest))->toBe('webp')
        ->and($r['bytes_after'])->toBeLessThan($r['bytes_before'])
        ->and([$r['width'], $r['height']])->toBe([600, 400]);
    // Mutation: return the source type from sniff() for RIFF -> the 'webp' assertion is red.
});

it('keeps PNG transparency', function () {
    wpRoot();
    $src = wpPut('uploads/logos/cut-out.png', wpAlphaPng());
    $dest = WebpConverter::reserveName($src);

    $r = WebpConverter::convert($src, $dest, 82, 0);
    $out = imagecreatefromwebp($dest);

    // The shop showed a black box behind every cut-out logo when alpha was lost.
    expect($r['ok'])->toBeTrue()
        ->and((imagecolorat($out, 10, 10) >> 24) & 0x7F)->toBeGreaterThan(120)
        ->and((imagecolorat($out, 250, 100) >> 24) & 0x7F)->toBe(0);
    // Mutation: drop imagealphablending(false)/imagesavealpha(true) -> the first pixel reads opaque.
});

it('turns a phone photo the way its EXIF orientation says', function () {
    wpRoot();
    $src = wpPut('uploads/products/sideways.jpg', wpOrientedJpeg(6));

    expect(WebpConverter::jpegOrientation($src))->toBe(6);

    $dest = WebpConverter::reserveName($src);
    $r = WebpConverter::convert($src, $dest, 90, 0);
    $out = imagecreatefromwebp($dest);
    $top = imagecolorat($out, 20, 5);
    $bottom = imagecolorat($out, 20, 75);

    // Orientation 6 = rotate 90° clockwise: the stored image's left (red)
    // becomes the top. Unturned, the product photo sat on its side.
    expect([$r['width'], $r['height']])->toBe([40, 80])
        ->and(($top >> 16) & 0xFF)->toBeGreaterThan(200)
        ->and($top & 0xFF)->toBeLessThan(60)
        ->and($bottom & 0xFF)->toBeGreaterThan(200);
    // Mutation: make orient() return its input unchanged -> width/height read [80, 40].
});

it('refuses a decompression bomb without decoding it', function () {
    wpRoot();
    $src = wpPut('uploads/products/bomb.png', wpBombPng(20000, 20000));
    $before = memory_get_peak_usage(true);

    $r = WebpConverter::convert($src, null, 82, 2400);

    // 400 MP decoded would be ~1.6 GB; refused from the header alone.
    expect($r['ok'])->toBeFalse()
        ->and($r['reason'])->toBe('too_many_pixels')
        ->and(memory_get_peak_usage(true) - $before)->toBeLessThan(16 * 1024 * 1024);
    // Mutation: remove the isBomb() check -> GD is asked for 20000x20000 and the reason changes.
});

it('skips a file whose WebP would be larger, and leaves it untouched', function () {
    wpRoot();
    $bytes = wpCheckerPng();
    $src = wpPut('uploads/products/pattern.png', $bytes);
    $dest = WebpConverter::reserveName($src);

    $r = WebpConverter::convert($src, $dest, 82, 0);

    expect($r['ok'])->toBeFalse()
        ->and($r['reason'])->toBe('webp_not_smaller')
        ->and(file_get_contents($src))->toBe($bytes);
    // Mutation: drop the bytes_after >= bytes_before check -> ok is true and a 14 KB file replaces 155 bytes.
});

it('caps the width and never upscales', function () {
    wpRoot();
    $big = wpPut('uploads/products/big.jpg', wpJpeg(3000, 600));
    $small = wpPut('uploads/products/small.jpg', wpJpeg(500, 300));

    $a = WebpConverter::convert($big, WebpConverter::reserveName($big), 82, 2400);
    $b = WebpConverter::convert($small, WebpConverter::reserveName($small), 82, 2400);

    expect([$a['width'], $a['height']])->toBe([2400, 480])
        ->and([$b['width'], $b['height']])->toBe([500, 300]);
});

it('never overwrites an existing file when naming the WebP', function () {
    wpRoot();
    $src = wpPut('uploads/products/dup.jpg', wpJpeg());
    wpPut('uploads/products/dup.webp', 'somebody else\'s picture');

    $dest = WebpConverter::reserveName($src);

    expect($dest)->toEndWith('/dup-1.webp')
        ->and(file_get_contents(public_path('uploads/products/dup.webp')))->toBe('somebody else\'s picture');
});

it('identifies files by their bytes, not their names', function () {
    wpRoot();
    $png = wpPut('uploads/x/named-like-a.jpg', wpAlphaPng(20, 20));
    $svg = wpPut('uploads/x/drawing.png', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>');

    expect(WebpConverter::sniff($png))->toBe('png')
        ->and(WebpConverter::sniff($svg))->toBe('svg')
        ->and(WebpConverter::convert($svg, null)['reason'])->toBe('not_jpeg_or_png');
});

/* ----------------------------------------------------------- on upload */

it('turns an uploaded JPEG into WebP, records the WebP and drops the unpublished original', function () {
    wpRoot();
    test()->actingAs(wpAdmin(), 'admin');

    $res = test()->post('/admin-api/media/upload', [
        'file' => wpUploadFile('Snail Serum.jpg', wpJpeg()),
        'folder' => 'products',
    ])->assertOk();

    $filename = $res->json('filename');
    $row = Media::query()->where('filename', $filename)->first();

    expect($filename)->toEndWith('.webp')
        ->and($res->json('url'))->toEndWith('/uploads/products/'.$filename)
        ->and($res->json('webp.converted'))->toBeTrue()
        ->and($res->json('webp.bytes_after'))->toBeLessThan($res->json('webp.bytes_before'))
        ->and($row?->mime)->toBe('image/webp')
        ->and(glob(public_path('uploads/products/*.jpg')))->toBe([])
        ->and(DB::table('webp_conversions')->where('origin', 'upload')->value('status'))->toBe('removed')
        // No absolute server path in what the browser is handed.
        ->and($res->getContent())->not->toContain(public_path());
    // Mutation: delete the WebpBulk::onUpload() call in MediaUploadController -> filename ends .jpg.
});

it('keeps the uploaded original when the setting says so', function () {
    wpRoot();
    test()->actingAs(wpAdmin(), 'admin');
    WebpSettings::save(['keep_original' => true]);

    $res = test()->post('/admin-api/media/upload', ['file' => wpUploadFile('a.png', wpAlphaPng()), 'folder' => 'products'])->assertOk();

    expect($res->json('filename'))->toEndWith('.webp')
        ->and(glob(public_path('uploads/products/*.png')))->toHaveCount(1);
});

it('uploads exactly as before when the switch is off', function () {
    wpRoot();
    test()->actingAs(wpAdmin(), 'admin');
    WebpSettings::save(['enabled' => false]);

    $res = test()->post('/admin-api/media/upload', ['file' => wpUploadFile('a.jpg', wpJpeg()), 'folder' => 'products'])->assertOk();

    expect($res->json('filename'))->toEndWith('.jpg')
        ->and($res->json())->not->toHaveKey('webp');
});

it('refuses a decompression bomb at upload with the numbers in the sentence', function () {
    wpRoot();
    test()->actingAs(wpAdmin(), 'admin');

    $res = test()->post('/admin-api/media/upload', ['file' => wpUploadFile('huge.png', wpBombPng(9000, 9000)), 'folder' => 'products']);

    $res->assertStatus(422);
    expect($res->json('message'))->toContain('9000 × 9000')
        ->and(glob(public_path('uploads/products/*')) ?: [])->toBe([]);
});

it('leaves GIF and WebP uploads alone', function () {
    wpRoot();
    test()->actingAs(wpAdmin(), 'admin');
    $img = imagecreatetruecolor(30, 30);
    ob_start();
    imagegif($img);
    $gif = (string) ob_get_clean();

    $res = test()->post('/admin-api/media/upload', ['file' => wpUploadFile('a.gif', $gif), 'folder' => 'products'])->assertOk();

    expect($res->json('filename'))->toEndWith('.gif')
        ->and($res->json())->not->toHaveKey('webp');
});

/* ---------------------------------------------------- references, pure */

it('rewrites only real references to the file, on this shop', function () {
    $map = ['uploads/products/a.jpg' => 'uploads/products/a.webp'];
    $hosts = ['shop.test', 'www.shop.test'];
    $n = 0;

    $in = implode(' | ', [
        'https://shop.test/uploads/products/a.jpg',          // own host: yes
        '/kbb-upgrade/uploads/products/a.jpg?v=2',           // path: yes
        '["https:\/\/shop.test\/uploads\/products\/a.jpg"]', // JSON-escaped: yes
        'https://kbeautybliss.com/uploads/products/a.jpg',   // old site: NO
        '/uploads/products/a.jpg.webp',                       // another file: NO
        '/myuploads/products/a.jpg',                          // another folder: NO
        '/uploads/products/aa.jpg',                           // another name: NO
    ]);

    $out = WebpReferences::replaceIn($in, $map, $hosts, $n);

    expect($n)->toBe(3)->and($out)->toBe(implode(' | ', [
        'https://shop.test/uploads/products/a.webp',
        '/kbb-upgrade/uploads/products/a.webp?v=2',
        '["https:\/\/shop.test\/uploads\/products\/a.webp"]',
        'https://kbeautybliss.com/uploads/products/a.jpg',
        '/uploads/products/a.jpg.webp',
        '/myuploads/products/a.jpg',
        '/uploads/products/aa.jpg',
    ]));
    // Mutation: make isOwnReference() return true -> the old site's URL is rewritten and $n is 4.
});

/* ---------------------------------------------------------------- bulk */

/**
 * A small shop: two convertible files used across the catalogue, a page, a
 * setting and a translation; a review photo; an order; and one hot-link to the
 * old site that shares a path with a local file.
 */
function wpShop(): array
{
    wpRoot();
    config(['app.url' => 'http://shop.test']);

    wpPut('uploads/products/a.jpg', wpJpeg());
    wpPut('wp-content/uploads/2024/01/b.png', wpAlphaPng());
    wpPut('uploads/reviews/r.jpg', wpJpeg(200, 200));      // a customer's: never converted
    wpPut('uploads/products/pattern.png', wpCheckerPng()); // WebP larger: skipped

    $a = 'http://shop.test/uploads/products/a.jpg';
    $b = '/wp-content/uploads/2024/01/b.png';

    $productId = DB::table('products')->insertGetId([
        'name' => 'Serum', 'slug' => 'serum', 'sku' => 'S1', 'type' => 'simple', 'status' => 'publish',
        'price' => 100, 'image' => $a,
        'images' => json_encode([$a, 'http://shop.test'.$b]),
        'image_alts' => json_encode([$a => 'Snail serum bottle']),
        'description' => '<p><img src="'.$a.'" alt=""></p>',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('pages')->insert(['slug' => 'wp-about', 'title' => 'About', 'content' => '<img src="'.$b.'">', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('settings')->insert(['key' => 'og_default_image', 'value' => $a, 'autoload' => 1]);
    DB::table('settings')->insert(['key' => 'old_banner', 'value' => 'https://kbeautybliss.com/wp-content/uploads/2024/01/b.png', 'autoload' => 1]);
    $mediaId = DB::table('media')->insertGetId(['filename' => 'a.jpg', 'path' => 'uploads/products/a.jpg', 'mime' => 'image/jpeg', 'alt' => '', 'created_at' => now(), 'updated_at' => now()]);

    // Records of what happened. Must come out byte-identical.
    $reviewId = DB::table('reviews')->insertGetId(['product_id' => $productId, 'author_name' => 'Cus', 'author_email' => 'c@x.test',
        'rating' => 5, 'content' => 'Look: '.$b, 'images' => json_encode(['/uploads/reviews/r.jpg']), 'status' => 'approved',
        'created_at' => now(), 'updated_at' => now()]);
    $orderId = DB::table('orders')->insertGetId(['order_number' => 'KBB-1', 'email' => 'c@x.test', 'status' => 'processing',
        'currency' => 'AED', 'customer_note' => 'like '.$a, 'created_at' => now(), 'updated_at' => now()]);

    return compact('productId', 'reviewId', 'orderId', 'mediaId');
}

it('plans without changing anything: files, bytes and the references that would move', function () {
    $ids = wpShop();
    $before = DB::table('products')->where('id', $ids['productId'])->first();

    $plan = WebpBulk::plan();

    expect($plan['done'])->toBeTrue()
        ->and(collect($plan['files'])->pluck('path')->all())->toBe([
            'uploads/products/a.jpg', 'uploads/products/pattern.png', 'wp-content/uploads/2024/01/b.png',
        ])
        ->and($plan['convert'])->toBe(2)
        ->and($plan['skip'])->toBe(1)
        ->and($plan['bytes_after'])->toBeLessThan($plan['bytes_before'])
        ->and($plan['references']['columns'])->toMatchArray([
            'products.image' => 1, 'products.images' => 2, 'products.image_alts' => 1,
            'products.description' => 1, 'pages.content' => 1, 'settings.value' => 1,
        ])
        ->and(glob(public_path('uploads/products/*.webp')))->toBe([])
        ->and(DB::table('products')->where('id', $ids['productId'])->first())->toEqual($before)
        ->and(DB::table('webp_conversions')->count())->toBe(0);
});

it('converts in bulk, re-points every allowlisted use, and is idempotent', function () {
    $ids = wpShop();
    $review = DB::table('reviews')->where('id', $ids['reviewId'])->first();
    $order = DB::table('orders')->where('id', $ids['orderId'])->first();

    $run = WebpBulk::run();

    $p = DB::table('products')->where('id', $ids['productId'])->first();

    expect($run['converted'])->toBe(2)
        ->and($run['skipped'])->toBe(1)
        ->and($run['done'])->toBeTrue()
        ->and($p->image)->toBe('http://shop.test/uploads/products/a.webp')
        ->and(json_decode($p->images, true))->toBe(['http://shop.test/uploads/products/a.webp', 'http://shop.test/wp-content/uploads/2024/01/b.webp'])
        ->and(json_decode($p->image_alts, true))->toBe(['http://shop.test/uploads/products/a.webp' => 'Snail serum bottle'])
        ->and($p->description)->toContain('uploads/products/a.webp')
        ->and(DB::table('pages')->where('slug', 'wp-about')->value('content'))->toBe('<img src="/wp-content/uploads/2024/01/b.webp">')
        ->and(DB::table('settings')->where('key', 'og_default_image')->value('value'))->toBe('http://shop.test/uploads/products/a.webp')
        // The old site has no b.webp: its URL is not ours to change.
        ->and(DB::table('settings')->where('key', 'old_banner')->value('value'))->toBe('https://kbeautybliss.com/wp-content/uploads/2024/01/b.png')
        ->and(Media::query()->find($ids['mediaId'])->only(['path', 'filename', 'mime']))->toBe(['path' => 'uploads/products/a.webp', 'filename' => 'a.webp', 'mime' => 'image/webp'])
        // Orders and reviews are records; a customer's photo is not converted.
        ->and(DB::table('reviews')->where('id', $ids['reviewId'])->first())->toEqual($review)
        ->and(DB::table('orders')->where('id', $ids['orderId'])->first())->toEqual($order)
        ->and(is_file(public_path('uploads/reviews/r.webp')))->toBeFalse()
        // Originals stay until the owner removes them, so old links keep working.
        ->and(is_file(public_path('uploads/products/a.jpg')))->toBeTrue()
        ->and(is_file(public_path('uploads/products/a.webp')))->toBeTrue();

    $snapshot = DB::table('products')->get()->toArray();
    $again = WebpBulk::run();

    expect($again['converted'] + $again['skipped'] + $again['failed'])->toBe(0)
        ->and($again['references']['rows'])->toBe(0)
        ->and(DB::table('products')->get()->toArray())->toEqual($snapshot)
        ->and(glob(public_path('uploads/products/a-*.webp')))->toBe([]);
    // Mutation: exclude nothing in candidates() (drop the $known check) -> the second run converts a.jpg to a-1.webp.
});

it('finishes the re-pointing a killed batch left undone', function () {
    $ids = wpShop();
    WebpBulk::run();
    // Simulate a batch that died after converting and before re-pointing.
    DB::table('products')->where('id', $ids['productId'])->update(['image' => 'http://shop.test/uploads/products/a.jpg']);
    DB::table('webp_conversions')->where('status', 'converted')->update(['refs_done' => false]);

    WebpBulk::run();

    expect(DB::table('products')->where('id', $ids['productId'])->value('image'))->toBe('http://shop.test/uploads/products/a.webp')
        ->and(DB::table('webp_conversions')->where('status', 'converted')->where('refs_done', false)->count())->toBe(0);
});

it('removes originals only when confirmed, and keeps one a review still shows', function () {
    wpShop();
    WebpBulk::run();

    $r = WebpBulk::removeOriginals();

    // b.png is quoted in a review's text; the review is not ours to rewrite,
    // so its original stays.
    expect($r['removed'])->toBe(1)
        ->and($r['kept'])->toBe(1)
        ->and(is_file(public_path('uploads/products/a.jpg')))->toBeFalse()
        ->and(is_file(public_path('wp-content/uploads/2024/01/b.png')))->toBeTrue()
        ->and(DB::table('webp_conversions')->where('from_path', 'uploads/products/a.jpg')->value('status'))->toBe('removed');
    // Mutation: skip the WebpReferences::referenced() guard -> b.png is deleted and the review shows a broken image.
});

it('undoes a conversion: references back, WebP gone', function () {
    $ids = wpShop();
    WebpBulk::run();

    $undo = WebpBulk::restore();

    expect($undo['restored'])->toBe(2)
        ->and(DB::table('products')->where('id', $ids['productId'])->value('image'))->toBe('http://shop.test/uploads/products/a.jpg')
        ->and(Media::query()->find($ids['mediaId'])->path)->toBe('uploads/products/a.jpg')
        ->and(is_file(public_path('uploads/products/a.webp')))->toBeFalse()
        ->and(DB::table('webp_conversions')->where('status', 'converted')->count())->toBe(0);
});

it('never touches a path outside the upload roots', function () {
    wpRoot();
    wpPut('secret.png', wpAlphaPng());

    expect(WebpBulk::absolute('../secret.png'))->toBeNull()
        ->and(WebpBulk::absolute('uploads/../secret.png'))->toBeNull()
        ->and(WebpBulk::absolute('secret.png'))->toBeNull()
        ->and(WebpBulk::candidates()['files'])->toBe([]);
});

/* ------------------------------------------------------- admin endpoints */

it('guards every WebP endpoint with media.optimize, owner and manager only', function () {
    WebpAdminRoutes::wire(app());
    wpShop();

    test()->postJson('/admin-api/media/webp/run')->assertStatus(401);

    test()->actingAs(wpAdmin('editor'), 'admin');
    test()->getJson('/admin-api/media/webp')->assertStatus(403);
    test()->postJson('/admin-api/media/webp/run')->assertStatus(403);
    test()->postJson('/admin-api/media/webp/remove-originals', ['confirm' => 'REMOVE'])->assertStatus(403);
    expect(is_file(public_path('uploads/products/a.webp')))->toBeFalse();

    test()->actingAs(wpAdmin('manager'), 'admin');
    test()->getJson('/admin-api/media/webp')->assertOk()->assertJsonPath('available', true);

    // Content editors keep the Media Library itself.
    expect(\App\Support\AdminCapabilities::forPath('GET', 'admin-api/media/webp'))->toBe('media.optimize')
        ->and(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/media/webp/remove-originals'))->toBe('media.optimize')
        ->and(\App\Support\AdminCapabilities::forPath('GET', 'admin-api/media'))->toBe('content.manage');
    // Mutation: delete the two media/webp rules -> media/** answers content.manage and the editor gets 200.
});

it('runs the screen flow: plan, run, then remove only with the typed confirmation', function () {
    WebpAdminRoutes::wire(app());
    wpShop();
    test()->actingAs(wpAdmin(), 'admin');

    $plan = test()->postJson('/admin-api/media/webp/plan', ['after' => ''])->assertOk();
    expect($plan->json('convert'))->toBe(2)->and($plan->getContent())->not->toContain(public_path());

    test()->postJson('/admin-api/media/webp/run')->assertOk()->assertJsonPath('converted', 2);

    test()->postJson('/admin-api/media/webp/remove-originals', [])->assertStatus(422);
    expect(is_file(public_path('uploads/products/a.jpg')))->toBeTrue();

    test()->postJson('/admin-api/media/webp/remove-originals', ['confirm' => 'REMOVE'])->assertOk()->assertJsonPath('removed', 1);

    $status = test()->getJson('/admin-api/media/webp')->assertOk();
    expect($status->json('counts.removed'))->toBe(1)
        ->and($status->json('bytes_saved'))->toBeGreaterThan(0)
        ->and($status->getContent())->not->toContain(public_path());
});

it('saves settings within range and never as typed', function () {
    WebpAdminRoutes::wire(app());
    test()->actingAs(wpAdmin(), 'admin');

    test()->postJson('/admin-api/media/webp/settings', ['quality' => 500])->assertStatus(422);
    test()->postJson('/admin-api/media/webp/settings', ['enabled' => false, 'quality' => 75, 'max_width' => 1600])
        ->assertOk()
        ->assertJsonPath('settings', ['enabled' => false, 'quality' => 75, 'max_width' => 1600, 'keep_original' => false]);

    expect(WebpSettings::normalise('max_width', 100))->toBe(WebpSettings::WIDTH_MIN)
        ->and(WebpSettings::normalise('max_width', 0))->toBe(0);
});

it('ships ON, because the owner asked for it', function () {
    expect(WebpSettings::all())->toBe(['enabled' => true, 'quality' => 82, 'max_width' => 2400, 'keep_original' => false]);
});

it('does not let the Media Library rescan catalogue a kept original as a new picture', function () {
    wpShop();
    WebpBulk::run();

    \App\Support\MediaBackfill::runReport();

    expect(Media::query()->where('path', 'uploads/products/a.jpg')->exists())->toBeFalse()
        ->and(Media::query()->where('path', 'uploads/products/a.webp')->exists())->toBeTrue();
});

/* ------------------------------------------------------------ wiring */

it('is wired exactly once: the route file and the screen partial', function () {
    $web = (string) file_get_contents(base_path('routes/web.php'));
    $media = (string) file_get_contents(resource_path('views/admin/partials/media-library-screen.blade.php'));

    expect(substr_count($web, "require __DIR__.'/webp-admin.php';"))->toBe(1)
        ->and(substr_count($media, "@include('admin.partials.webp-screen')"))->toBe(1)
        ->and(substr_count($media, 'id="mlib-webp"'))->toBe(1);
});

/* ------------------------------------------- 2.60.388: "must converted" */

/*
 * THE OWNER, 5 October, after 2.60.387: "i enabled the setting to convert the
 * media auto to webp upon upload, but as i can see it's not converting auto
 * and i'm getting still jpg image in media" and "from anywhere i upload any
 * image, it should be must converted to webp".
 *
 * What he saw: the library and every picker caption the ORIGINAL file name
 * ("…banner.jpg") even on a converted upload, and an upload that was not
 * converted said nothing at all about why.
 */

it('lists a converted upload under its real .webp name, not the .jpg it was sent as', function () {
    wpRoot();
    test()->actingAs(wpAdmin(), 'admin');

    $res = test()->post('/admin-api/media/upload', ['file' => wpUploadFile('Glow Set Banner.jpg', wpJpeg()), 'folder' => 'banners'])->assertOk();

    expect(Media::query()->where('filename', $res->json('filename'))->value('original_name'))->toBe('Glow Set Banner.webp');
    // Mutation: pass getClientOriginalName() unchanged to record() -> 'Glow Set Banner.jpg'.
});

it('makes every upload WebP even when the first WebP is not smaller, trying lossless for a PNG', function () {
    wpRoot();
    $bytes = wpCheckerPng();
    $src = wpPut('uploads/products/pattern.png', $bytes);

    // The bulk rule is unchanged: only when smaller.
    expect(WebpConverter::convert($src, WebpConverter::reserveName($src), 82, 0)['reason'])->toBe('webp_not_smaller');

    // An upload always ends as WebP, the smallest one tried.
    $dest = WebpConverter::reserveName($src);
    $r = WebpConverter::convert($src, $dest, 82, 0, true);

    expect($r['ok'])->toBeTrue()
        ->and(WebpConverter::sniff($dest))->toBe(WebpConverter::TYPE_WEBP)
        ->and(glob(dirname($dest).'/.*.part'))->toBe([]);   // no temporary left behind
    // Mutation: drop `$always &&` handling (the `! $always &&` guard back to plain) -> reason webp_not_smaller.
});

it('says why an upload stayed JPG, so a switch left off is never mistaken for a broken converter', function () {
    wpRoot();
    test()->actingAs(wpAdmin(), 'admin');
    WebpSettings::save(['enabled' => false]);

    $res = test()->post('/admin-api/media/upload', ['file' => wpUploadFile('a.jpg', wpJpeg()), 'folder' => 'products'])->assertOk();

    expect($res->json('webp_note'))->toContain('switched off');

    // Every uploader shows it: the shared kit says the outcome after each upload.
    $kit = (string) file_get_contents(resource_path('views/admin/partials/upload-kit.blade.php'));
    expect(substr_count($kit, "stage('done');\n        webpSay(body);"))->toBe(1)
        ->and($kit)->toContain("'Saved as WebP · '")->toContain("'Not converted to WebP: '");
});

it('converts an upload whose uploads folder is a link to elsewhere on the server', function () {
    $root = wpRoot();
    $elsewhere = kbbTempDir().'/wp-shared-'.bin2hex(random_bytes(4));
    mkdir($elsewhere.'/products', 0777, true);
    symlink($elsewhere, $root.'/uploads');
    file_put_contents($elsewhere.'/products/linked.jpg', wpJpeg());

    try {
        expect(WebpBulk::absolute('uploads/products/linked.jpg'))->not->toBeNull()
            ->and(WebpBulk::absolute('uploads/../../etc/passwd'))->toBeNull();
    } finally {
        @unlink($root.'/uploads');
        wpRemoveTree($elsewhere);
    }
    // Mutation: remove the realpath(public_path($root)) branch in absolute() -> null, and the upload is silently not converted.
});

it('renames the captions of pictures converted before 2.60.388, and only those', function () {
    $id = DB::table('media')->insertGetId(['path' => 'uploads/banners/x.webp', 'filename' => 'x.webp', 'original_name' => 'Glow Banner.jpg', 'mime' => 'image/webp', 'created_at' => now(), 'updated_at' => now()]);
    $keep = DB::table('media')->insertGetId(['path' => 'uploads/banners/y.jpg', 'filename' => 'y.jpg', 'original_name' => 'Kept.jpg', 'mime' => 'image/jpeg', 'created_at' => now(), 'updated_at' => now()]);

    ob_start();
    (require database_path('migrations/2027_08_18_100100_webp_names_and_caches.php'))->up();
    ob_end_clean();

    expect(DB::table('media')->where('id', $id)->value('original_name'))->toBe('Glow Banner.webp')
        ->and(DB::table('media')->where('id', $keep)->value('original_name'))->toBe('Kept.jpg');
});

/* ------------------------------ 2.60.390: the owner's own file name */

/*
 * THE OWNER: "upon conversion of emage to webp, i don't want to change the file
 * name at all, bcz i renamed before upload as per the product and seo, so
 * please only the file extension need to be changed." He saw
 * /uploads/banners/20261005-105831-ooHTOJKn.webp for a file he had named.
 */

it('keeps the uploaded file name, made safe, and changes only the extension', function () {
    wpRoot();
    test()->actingAs(wpAdmin(), 'admin');

    $one = test()->post('/admin-api/media/upload', ['file' => wpUploadFile('Anua PDRN Glow Set-Banner.jpg', wpJpeg()), 'folder' => 'banners'])->assertOk();
    $two = test()->post('/admin-api/media/upload', ['file' => wpUploadFile('Anua PDRN Glow Set-Banner.jpg', wpJpeg()), 'folder' => 'banners'])->assertOk();

    expect($one->json('filename'))->toBe('anua-pdrn-glow-set-banner.webp')
        // Never onto a name in use: the second takes -2, the first is untouched.
        ->and($two->json('filename'))->toBe('anua-pdrn-glow-set-banner-2.webp')
        ->and(is_file(public_path('uploads/banners/anua-pdrn-glow-set-banner.webp')))->toBeTrue();
    // Mutation: put back `date('Ymd-His').'-'.Str::random(8)` as the name -> the first line is red.
});

it('never reuses a name an /img-cache/ copy still answers at, and never takes a path from the browser', function () {
    wpRoot();
    test()->actingAs(wpAdmin(), 'admin');
    wpPut('img-cache/400/uploads/banners/glow.webp', 'stale');

    $res = test()->post('/admin-api/media/upload', ['file' => wpUploadFile('glow.jpg', wpJpeg()), 'folder' => 'banners'])->assertOk();
    expect($res->json('filename'))->toBe('glow-2.webp');

    $evil = test()->post('/admin-api/media/upload', ['file' => wpUploadFile('../../etc/Evil Name.jpg', wpJpeg()), 'folder' => 'banners'])->assertOk();
    expect($evil->json('filename'))->toBe('evil-name.webp');

    // A name of symbols only falls back to the dated random name.
    $sym = test()->post('/admin-api/media/upload', ['file' => wpUploadFile('###.jpg', wpJpeg()), 'folder' => 'banners'])->assertOk();
    expect($sym->json('filename'))->toMatch('#^\d{8}-\d{6}-[A-Za-z0-9]{8}\.webp$#');
    // Mutation: drop the img-cache glob from nameTaken() -> 'glow.webp', and the cached copy would be served for the new picture.
});
