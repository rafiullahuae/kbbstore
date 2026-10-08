<?php

declare(strict_types=1);

/*
 * Emails: every picture is a file that exists, as a JPEG, on the shop's own
 * https origin.                                                     (Lane EM)
 *
 * THE OWNER, 8 October, over order 56171's confirmation with the alt text
 * ("NIDA - Salmon PDRN…", "Arencia - Vitamin C Booster…") standing in the pink
 * 64px box for every product under YOUR ITEMS: "the images are failing to load
 * in the emails ... must not failed in any case."
 *
 * WHAT THE INBOX GOT, before this lane, for a product stored the way the live
 * catalogue stores it (root-relative, with the shop tile's 200px copy on disk):
 *   https://extrabeauty.ae/wp-content/uploads/img-cache/200/wp-content/uploads/….webp
 * MailKit::image() took variantUrl()'s root-relative answer and ran it through
 * Url::media(), which prefixes /wp-content/uploads/ to anything not already
 * under it. A 404 for every product a shopper had ever viewed — which is
 * every product anyone has bought. An admin upload (/uploads/products/x.webp)
 * went out as /wp-content/uploads/uploads/… and 404'd with or without a copy.
 *
 * Every test here renders through the code the queue worker runs, and PHPUnit
 * IS a console process (runningInConsole() is true), so these are the
 * worker's answers, not a web request's. Each one checks the printed URL
 * against THE DISK — the only check that would have caught the 404.
 */

use App\Mail\OrderConfirmation;
use App\Models\Brand;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Mail\Kit\MailImage;
use App\Services\Mail\Kit\MailKit;
use App\Support\Url;
use Illuminate\Support\Facades\DB;

function emPicRoot(): string
{
    $root = kbbTempDir().'/em-pub-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    app()->usePublicPath($root);
    $GLOBALS['emPicRoot'] = $root;

    return $root;
}

/** A real picture made with GD: a coloured square, optionally with a transparent corner. */
function emPicPut(string $rel, string $type = 'webp', int $size = 300, bool $alphaCorner = false): string
{
    $file = public_path($rel);
    @mkdir(dirname($file), 0777, true);
    $im = imagecreatetruecolor($size, $size);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefilledrectangle($im, 0, 0, $size, $size, (int) imagecolorallocate($im, 200, 40, 90));

    if ($alphaCorner) {
        imagefilledrectangle($im, 0, 0, (int) ($size / 3), (int) ($size / 3), (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
    }

    match ($type) {
        'png' => imagepng($im, $file),
        'jpg' => imagejpeg($im, $file),
        default => imagewebp($im, $file),
    };
    imagedestroy($im);

    return $file;
}

/** The file a printed https URL addresses in this web root, or null. */
function emPicFileFor(string $url): ?string
{
    $prefix = 'https://extrabeauty.ae/';

    if (! str_starts_with($url, $prefix)) {
        return null;
    }

    $file = public_path(rawurldecode(substr($url, strlen($prefix))));

    return is_file($file) ? $file : null;
}

function emPicOrder(string $image): Order
{
    $b = Brand::firstOrCreate(['slug' => 'nida'], ['name' => 'NIDA']);
    $p = Product::create([
        'name' => 'NIDA - Salmon PDRN Ampoule', 'slug' => 'nida-salmon-'.bin2hex(random_bytes(3)), 'brand_id' => $b->id,
        'status' => 'publish', 'is_visible' => 1, 'price' => 9900, 'stock_status' => 'instock', 'type' => 'simple',
        'image' => $image,
    ]);
    $address = ['first_name' => 'A', 'last_name' => 'K', 'address_1' => 'X', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971501234567'];
    $order = Order::create([
        'order_number' => '56171', 'email' => 'a@example.com', 'phone' => '+971501234567', 'status' => 'processing',
        'currency' => 'AED', 'billing_address' => $address, 'shipping_address' => $address, 'subtotal' => 9900,
        'discount_total' => 0, 'shipping_total' => 0, 'fee_total' => 0, 'tax_total' => 0, 'total' => 9900,
        'shipping_method' => 'Free UAE delivery', 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
    ]);
    $order->items()->create(['product_id' => $p->id, 'name' => $p->name, 'brand' => 'NIDA', 'quantity' => 1,
        'unit_price' => 9900, 'subtotal' => 9900, 'total' => 9900]);

    return $order->fresh('items');
}

/** The src of the YOUR ITEMS picture in the real order confirmation. */
function emPicItemSrc(Order $order): ?string
{
    $html = (new OrderConfirmation($order))->render();

    return preg_match('/<img class="pimg" src="([^"]+)"/', $html, $m) === 1 ? html_entity_decode($m[1]) : null;
}

function emPicSetting(string $key, string $value): void
{
    Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    Setting::flushMap();
}

beforeEach(function () {
    // The live shop: CUTOVER-EXTRABEAUTY.md, APP_URL and an empty base path.
    config(['app.url' => 'https://extrabeauty.ae', 'kbb.base_path' => '']);
    Url::forgetBase();
    MailImage::resetBudget();
    emPicRoot();
});

afterEach(function () {
    $root = $GLOBALS['emPicRoot'] ?? null;

    if (is_string($root) && is_dir($root)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($root);
    }
    unset($GLOBALS['emPicRoot']);
});

/*
 * Each stored spelling a product row can hold, rendered through the order
 * confirmation in a console process. MUTATION: put MailKit::image()'s old
 * body back (variantUrl() then Url::externalise(Url::media(...))) and the
 * first two rows print /wp-content/uploads/img-cache/200/… and the third
 * /wp-content/uploads/uploads/… — emPicFileFor() finds no file, red.
 */
it('prints a JPEG that exists on the https shop for every stored form of a product picture', function (string $stored, string $onDisk, bool $tileCopy) {
    emPicPut($onDisk);

    if ($tileCopy) {
        // What the shop makes the first time the product's tile is viewed.
        emPicPut('img-cache/200/'.$onDisk, 'webp', 200);
    }

    $src = emPicItemSrc(emPicOrder($stored));
    $file = $src === null ? null : emPicFileFor($src);

    expect($src)->toStartWith('https://extrabeauty.ae/img-cache/mail/128/')
        ->and($src)->not->toContain('/wp-content/uploads/img-cache/')
        ->and($src)->not->toContain('uploads/uploads/')
        ->and($file)->not->toBeNull();

    $info = getimagesize((string) $file);
    expect($info[2])->toBe(IMAGETYPE_JPEG)->and($info[0])->toBe(128);
})->with([
    'root-relative WebP with the shop tile copy (the live shape, order 56171)' => ['/wp-content/uploads/2026/09/nida-salmon.webp', 'wp-content/uploads/2026/09/nida-salmon.webp', true],
    'root-relative WebP, no copies yet (straight after an import)' => ['/wp-content/uploads/2026/09/arencia-c.webp', 'wp-content/uploads/2026/09/arencia-c.webp', false],
    'admin upload' => ['/uploads/products/arencia-c.webp', 'uploads/products/arencia-c.webp', true],
    'absolute on the shop itself' => ['https://extrabeauty.ae/wp-content/uploads/2026/09/x.webp', 'wp-content/uploads/2026/09/x.webp', true],
    'absolute on the www twin' => ['https://www.extrabeauty.ae/uploads/products/x.webp', 'uploads/products/x.webp', false],
    'old WordPress host, picture already brought across' => ['https://kbeautybliss.com/wp-content/uploads/2019/03/x.webp', 'wp-content/uploads/2019/03/x.webp', false],
    'old staging host with its subfolder' => ['https://easywebsol.com/kbb-upgrade/wp-content/uploads/2024/01/x.webp', 'wp-content/uploads/2024/01/x.webp', false],
    'stale staging base path on a root-relative row' => ['/kbb-upgrade/uploads/products/x.webp', 'uploads/products/x.webp', false],
    'percent-encoded name' => ['/wp-content/uploads/2026/09/snail%20mucin.webp', 'wp-content/uploads/2026/09/snail mucin.webp', false],
]);

/*
 * Outlook for Windows does not draw WebP. MUTATION: write the copy with
 * imagewebp() (or skip the white fill) and the type / corner pixel assertions
 * go red — GD's truecolor canvas starts black, which is what a cut-out would
 * have been printed on.
 */
it('turns a transparent WebP into a JPEG on white, once, and remakes it only when the original changes', function () {
    $orig = emPicPut('wp-content/uploads/2026/09/cut.webp', 'webp', 400, true);

    $src = MailKit::image('/wp-content/uploads/2026/09/cut.webp', 400);
    $file = emPicFileFor((string) $src);

    expect($file)->not->toBeNull()->and(getimagesize((string) $file)[2])->toBe(IMAGETYPE_JPEG);

    $im = imagecreatefromjpeg((string) $file);
    $rgb = imagecolorat($im, 2, 2);
    expect(($rgb >> 16) & 0xFF)->toBeGreaterThan(240)->and($rgb & 0xFF)->toBeGreaterThan(240);

    // Idempotent: a second send does not re-encode.
    touch($orig, time() - 100);
    touch((string) $file, time() - 50);
    clearstatcache();
    $before = filemtime((string) $file);
    MailKit::image('/wp-content/uploads/2026/09/cut.webp', 400);
    clearstatcache();
    expect(filemtime((string) $file))->toBe($before);

    // A replaced original (newer than the copy) is re-made, not served stale.
    touch($orig, time() + 5);
    clearstatcache();
    MailKit::image('/wp-content/uploads/2026/09/cut.webp', 400);
    clearstatcache();
    expect(filemtime((string) $file))->toBeGreaterThan($before);
});

/*
 * A row whose file is gone. MUTATION: return the URL when inside() finds no
 * file (the old behaviour) and the src is the missing picture — red.
 */
it('prints a placeholder that exists, never a broken picture, when the file is missing', function () {
    $src = emPicItemSrc(emPicOrder('/wp-content/uploads/2026/09/gone.webp'));

    expect($src)->toBe('https://extrabeauty.ae/img-cache/mail/placeholder-128.jpg')
        ->and(emPicFileFor((string) $src))->not->toBeNull()
        ->and(getimagesize((string) emPicFileFor((string) $src))[2])->toBe(IMAGETYPE_JPEG);
});

/*
 * Catalog → Image SEO renamed the file; something still holds the old name.
 * MUTATION: drop the LegacyImageRedirect::targetFor() step and this is the
 * placeholder instead of the product — red.
 */
it('follows an Image SEO rename to the file that shows the picture today', function () {
    emPicPut('uploads/products/nida-salmon-pdrn-ampoule-30ml.webp');
    DB::table('image_renames')->insert(['old_path' => 'uploads/products/IMG_0042.webp', 'new_path' => 'uploads/products/nida-salmon-pdrn-ampoule-30ml.webp',
        'status' => 'done', 'created_at' => now(), 'updated_at' => now()]);

    expect(MailKit::image('/uploads/products/IMG_0042.webp', 200))
        ->toBe('https://extrabeauty.ae/img-cache/mail/128/uploads/products/nida-salmon-pdrn-ampoule-30ml.webp.jpg');
});

/*
 * The origin. MUTATION: build it from SiteUrl::externalOrigin() alone (the
 * old origin) and the first case prints http://localhost/…, the second
 * http://… — red.
 */
it('builds every picture on the shop\'s public https address, never localhost and never http', function () {
    emPicPut('uploads/products/x.jpg', 'jpg');

    config(['app.url' => 'http://localhost']);
    expect(MailKit::image('/uploads/products/x.jpg', 200))->toBeNull();

    emPicSetting('canonical_host', 'extrabeauty.ae');
    expect(MailKit::image('/uploads/products/x.jpg', 200))->toStartWith('https://extrabeauty.ae/img-cache/mail/128/');

    emPicSetting('canonical_host', '');
    config(['app.url' => 'http://extrabeauty.ae']);
    expect(MailKit::image('/uploads/products/x.jpg', 200))->toStartWith('https://extrabeauty.ae/');

    // Settings → Site address wins over a stale APP_URL, as the canonical does.
    config(['app.url' => 'https://easywebsol.com/kbb-upgrade']);
    emPicSetting('canonical_host', 'extrabeauty.ae');
    expect(MailKit::image('/uploads/products/x.jpg', 200))->toStartWith('https://extrabeauty.ae/');
});

/*
 * The old WordPress host. MUTATION: make foreignHttps() return null and the
 * first case becomes a placeholder; make it accept http and the second
 * prints an http:// picture — red either way.
 */
it('keeps an https picture on another host the shop itself shows, and never prints http', function () {
    expect(MailKit::image('https://kbeautybliss.com/wp-content/uploads/2019/03/only-there.jpg', 200))
        ->toBe('https://kbeautybliss.com/wp-content/uploads/2019/03/only-there.jpg')
        ->and(MailKit::image('http://kbeautybliss.com/wp-content/uploads/2019/03/only-there.jpg', 200))
        ->toBe('https://extrabeauty.ae/img-cache/mail/placeholder-128.jpg')
        ->and(MailKit::image('https://127.0.0.1/wp-content/uploads/x.jpg', 200))
        ->toBe('https://extrabeauty.ae/img-cache/mail/placeholder-128.jpg');
});

/*
 * Nothing a row can hold walks out of the web root. MUTATION: drop the
 * segment check in clean() and the traversal resolves to the secret beside
 * the web root.
 */
it('refuses a path that would leave the web root', function () {
    $root = public_path();
    file_put_contents(dirname($root).'/secret.jpg', 'x');
    emPicPut('uploads/products/ok.jpg', 'jpg');

    foreach (['/uploads/../../secret.jpg', '/uploads/%2e%2e/%2e%2e/secret.jpg', '/uploads/products/..%2f..%2f..%2fsecret.jpg', "/uploads/a\\..\\secret.jpg"] as $bad) {
        expect(MailKit::image($bad, 200))->toBe('https://extrabeauty.ae/img-cache/mail/placeholder-128.jpg');
    }

    @unlink(dirname($root).'/secret.jpg');
});

/*
 * The header logo goes through the same rules. MUTATION: print a WebP logo
 * as-is and the type assertion is red; print a missing one and the wordmark
 * assertion is red.
 */
it('gives a WebP logo a PNG copy and prints the wordmark when the logo file is gone', function () {
    emPicPut('uploads/brand/logo.webp', 'webp', 500, true);

    $k = MailKit::for(['logoUrl' => '/uploads/brand/logo.webp', 'storeName' => 'K Beauty Bliss']);
    $file = emPicFileFor((string) ($k['logo'][0] ?? ''));

    expect($file)->not->toBeNull()
        ->and(getimagesize((string) $file)[2])->toBe(IMAGETYPE_PNG)
        ->and($k['logo'][2])->toBe(170);

    expect(MailKit::for(['logoUrl' => '/uploads/brand/missing.png', 'storeName' => 'K Beauty Bliss'])['logo'])->toBeNull();
});
