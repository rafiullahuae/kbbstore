<?php

declare(strict_types=1);

/**
 * Hand-made product cut-outs (Lane CUT). The owner, 6 November 2026, on the
 * automatic cut-outs 2.60.649 put in the Occasion banner and the Free gifts
 * strip: "the auto cutout is completely shit results" -- the flood fill ran
 * through white packaging, so a white tube (Dr.Althea 345, AXIS-Y) came out
 * as floating text with its body gone.
 *
 * What is pinned: a hand-made cut-out (shipped in resources/cutouts, or the
 * owner's upload) is what the shop serves, a later "Prepare cut-outs" /
 * "Re-cut" / "Remake" puts it back instead of cutting over it, only "Use
 * automatic" replaces it, and an upload is a real transparent PNG/WebP of at
 * most 4 MB or nothing at all.
 */

use App\Http\Controllers\Admin\OccasionBannerController;
use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Cutouts\ProductCutouts;
use App\Services\FreeGifts;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\OccasionBanner;
use Illuminate\Http\Request;

/** Put a file in the Media Library's folder (public/uploads/cutouts) and return its library URL. */
function pcohLib(string $src, string $name): string
{
    $dir = public_path('uploads/cutouts');
    @mkdir($dir, 0755, true);
    $name = uniqid('pcoh-') . '-' . $name;
    copy($src, $dir . '/' . $name);

    return '/uploads/cutouts/' . $name;
}
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GiftStripAdminRoutes;

const PCOH_DIR = 'uploads/pcoh-test';

function pcohProduct(string $name): Product
{
    $dir = public_path(PCOH_DIR);
    @mkdir($dir, 0775, true);
    $file = Str::slug($name) . '-' . Str::random(5) . '.jpg';
    $im = imagecreatetruecolor(200, 300);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 70, 40, 130, 260, imagecolorallocate($im, 200, 40, 90));
    imagejpeg($im, $dir . '/' . $file, 92);
    imagedestroy($im);

    return Product::create([
        'slug' => Str::slug($name) . '-' . Str::random(6), 'name' => $name, 'status' => 'publish',
        'is_visible' => true, 'price' => 2500, 'stock_status' => 'instock', 'manage_stock' => false,
        'image' => '/' . PCOH_DIR . '/' . $file,
    ]);
}

/** A transparent PNG: a green bottle 220x320 on a clear 300x400, wider than any automatic cut of the photo above. */
function pcohPng(string $path, bool $transparent = true): string
{
    $im = imagecreatetruecolor(300, 400);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, $transparent ? imagecolorallocatealpha($im, 0, 0, 0, 127) : imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 40, 40, 259, 359, imagecolorallocatealpha($im, 20, 160, 80, 0));
    // a clear window in the bottle, so transparency inside the trimmed box can be checked
    if ($transparent) {
        imagefilledrectangle($im, 120, 150, 179, 249, imagecolorallocatealpha($im, 0, 0, 0, 127));
    }
    imagepng($im, $path);
    imagedestroy($im);

    return $path;
}

/** Put a "shipped" hand-made cut-out for $image in resources/cutouts, as a package would. */
function pcohShip(string $image): array
{
    $rel = ProductCutouts::relative($image);
    $files = [];
    foreach ([480 => 200, 240 => 100] as $h => $w) {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledellipse($im, intdiv($w, 2), intdiv($h, 2), $w - 4, $h - 4, imagecolorallocatealpha($im, 240, 220, 120, 0));
        $f = resource_path('cutouts/' . $h . '/' . sha1($rel) . '.webp');
        @mkdir(dirname($f), 0755, true);
        imagewebp($im, $f, 86);
        imagedestroy($im);
        $files[$h] = $f;
    }

    return $files;
}

function pcohServed(string $image, int $h = 480): string
{
    return public_path(ProductCutouts::DIR . '/' . $h . '/' . ProductCutouts::relative($image) . '.webp');
}

function pcohAdmin(): AdminUser
{
    return AdminUser::create(['name' => 'PCOH', 'email' => 'pcoh-' . uniqid() . '@example.test', 'password' => 'secret-secret', 'role' => 'owner']);
}

function pcohStrip(Product $p): void
{
    $id = DB::table('free_gift_ranges')->insertGetId([
        'min_fils' => 0, 'max_fils' => null, 'label_en' => 'Gifts', 'label_ar' => 'هدايا', 'sort' => 0, 'enabled' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $p->id, 'quantity' => 1, 'sort' => 0]);
    app(FreeGifts::class)->compile();
    app(SettingsService::class)->set(FreeGifts::KEY, ['on' => true, 'show_value' => false, 'countries' => 'all', 'basis' => 'after_discounts']);
    test()->actingAs(pcohAdmin(), 'admin')->postJson('/admin-api/gift-strip', ['settings' => ['on' => true, 'style' => 'S2']])->assertOk();
    SettingsService::forgetMemo();
    Setting::flushMap();
}

/** The strip's <img> src on the homepage, as a path under the web root (query dropped). */
function pcohStripSrc(): string
{
    $html = test()->get('/')->assertOk()->getContent();
    expect(preg_match('#<section class="kgs-sec[^"]*".*?<img [^>]*src="([^"]+)"#s', $html, $m))->toBe(1);
    $path = (string) parse_url(html_entity_decode($m[1]), PHP_URL_PATH);
    $base = \App\Support\Url::base();

    return public_path(rawurldecode(ltrim($base !== '' && str_starts_with($path, $base) ? substr($path, strlen($base)) : $path, '/')));
}

beforeEach(function () {
    GiftStripAdminRoutes::wire($this->app);
    DB::table('free_gift_ranges')->delete();
    app(FreeGifts::class)->compile();
});

afterEach(function () {
    foreach (Product::query()->where('image', 'like', '/' . PCOH_DIR . '/%')->pluck('image') as $img) {
        $key = sha1((string) ProductCutouts::relative((string) $img));
        foreach ([240, 480] as $h) {
            @unlink(resource_path('cutouts/' . $h . '/' . $key . '.webp'));
            @unlink(storage_path('app/cutouts/' . $h . '/' . $key . '.webp'));
        }
        @unlink(storage_path('app/cutouts/auto/' . $key));
    }
    foreach ([public_path(PCOH_DIR), public_path(ProductCutouts::DIR . '/240/' . PCOH_DIR), public_path(ProductCutouts::DIR . '/480/' . PCOH_DIR)] as $dir) {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
});

it('serves the hand-made cut-out on the shop, and Re-cut / Prepare put it back instead of cutting over it', function () {
    /*
     * The defect: the owner's complaint was the automatic cut-out on the live
     * homepage. A hand-made one that the next "Prepare cut-outs" or "Re-cut"
     * click silently cut over would put the bitten white tube straight back.
     * MUTATION: drop the ownFiles() branch at the top of ProductCutouts::make()
     * and the served file is the automatic cut again -- red.
     */
    $p = pcohProduct('Dr.Althea 345 Relief Cream');
    expect(ProductCutouts::make((string) $p->image)['ok'])->toBeTrue();    // 2.60.649's automatic one
    $auto = md5_file(pcohServed($p->image));

    $own = pcohShip($p->image);
    expect(ProductCutouts::installShipped([ProductCutouts::relative($p->image)]))->toContain(ProductCutouts::relative($p->image));
    pcohStrip($p);
    expect(md5_file(pcohStripSrc()))->toBe(md5_file($own[480]))->not->toBe($auto);

    // "Re-cut" in Appearance -> Free gifts strip, then the occasion banner's "Remake"
    $body = $this->actingAs(pcohAdmin(), 'admin')->postJson('/admin-api/gift-strip/cutout', ['id' => $p->id])->assertOk()->json();
    expect($body['own'])->toBeTrue()->and($body['cut']['w'])->toBe(200);
    app(OccasionBannerController::class)->cutout(Request::create('/x', 'POST', ['id' => $p->id, 'remake' => true]));
    expect(md5_file(pcohStripSrc()))->toBe(md5_file($own[480]))
        ->and(md5_file(pcohServed($p->image, 240)))->toBe(md5_file($own[240]))
        ->and(ProductCutouts::isOwn($p->image))->toBeTrue();

    // Only "Use automatic" replaces it, and a later install leaves his choice alone.
    $this->actingAs(pcohAdmin(), 'admin')->postJson('/admin-api/gift-strip/cutout/automatic', ['id' => $p->id])->assertOk()->assertJson(['own' => false]);
    expect(md5_file(pcohServed($p->image)))->toBe($auto)
        ->and(ProductCutouts::installShipped([ProductCutouts::relative($p->image)]))->not->toContain(ProductCutouts::relative($p->image))
        ->and(md5_file(pcohStripSrc()))->toBe($auto);
});

it('takes the owner\'s transparent PNG, re-encodes it as WebP at 480 and 240 tall, and serves it with a new version', function () {
    /*
     * The defect this guards: a cut-out he cannot replace himself. And the
     * served path does not change when a cut-out does, while img-cache is sent
     * with a year's max-age -- without ?v= a visitor who had the automatic one
     * kept it. MUTATION: drop $q from ProductCutouts::for() and the version
     * check is red; store under the uploaded name and the name check is red.
     */
    $p = pcohProduct('AXIS-Y Dark Spot Correcting Glow Serum');
    ProductCutouts::make((string) $p->image);
    touch(pcohServed($p->image), time() - 60);
    touch(pcohServed($p->image, 240), time() - 60);
    clearstatcache();
    $before = ProductCutouts::for($p->image)['src'];

    // He uploads (or picks) it in the shared Media Library; the screen sends its URL.
    $url = pcohLib(pcohPng(sys_get_temp_dir() . '/my-cut-' . uniqid() . '.png'), 'my-cut.png');
    $body = $this->actingAs(pcohAdmin(), 'admin')->postJson('/admin-api/gift-strip/cutout/from-media', ['id' => $p->id, 'url' => $url])
        ->assertOk()->json();

    $served = pcohServed($p->image);
    $info = getimagesize($served);
    expect($body['own'])->toBeTrue()
        ->and($info[2])->toBe(IMAGETYPE_WEBP)->and($info[1])->toBe(480)->and($info[0])->toBe(330)   // trimmed to the bottle: 220x320 -> 330x480
        ->and(getimagesize(pcohServed($p->image, 240))[1])->toBe(240)
        ->and($body['cut']['src'])->toContain('?v=')->not->toBe($before)
        ->and(glob(storage_path('app/cutouts/*/*my-cut*')) ?: [])->toBe([])
        ->and(ProductCutouts::isOwn($p->image))->toBeTrue();
    // the window is clear, the bottle is solid: transparency survived GD
    $im = imagecreatefromwebp($served);
    expect((imagecolorat($im, 164, 240) >> 24) & 127)->toBe(127)->and((imagecolorat($im, 30, 240) >> 24) & 127)->toBe(0);
});

it('refuses an upload that is not an image, and leaves the cut-out as it was', function () {
    /*
     * The defect: an endpoint that stored whatever arrived under an image name
     * would put a script or a text file in the web root. Checked from the
     * BYTES, not the name. MUTATION: drop the getimagesize() type check in
     * ProductCutouts::upload() and GD's refusal is the only line left; drop
     * both and this is a 200.
     */
    $p = pcohProduct('Not An Image');
    ProductCutouts::make((string) $p->image);
    $was = md5_file(pcohServed($p->image));
    $fake = sys_get_temp_dir() . '/pcoh-' . uniqid() . '.png';
    file_put_contents($fake, "<?php echo 'x'; ?>\n" . str_repeat('A', 2000));
    $admin = pcohAdmin();

    $r = $this->actingAs($admin, 'admin')->postJson('/admin-api/gift-strip/cutout/from-media', ['id' => $p->id, 'url' => pcohLib($fake, 'cut.png')]);
    $r->assertStatus(422);
    expect($r->json('error'))->toContain('not a PNG or WebP');

    // A real image, but a photo on white -- it would ship as a white box.
    $flat = pcohLib(pcohPng(sys_get_temp_dir() . '/pcoh-flat-' . uniqid() . '.png', false), 'flat.png');
    $this->actingAs($admin, 'admin')->postJson('/admin-api/gift-strip/cutout/from-media', ['id' => $p->id, 'url' => $flat])
        ->assertStatus(422)->assertJsonPath('ok', false);

    // Only a Media Library file: never a path outside it, the cache, another host, or "..".
    foreach (['/img-cache/cut/480/x.webp', '/uploads/../.env', 'https://evil.example/uploads/a.png', '/build/assets/a.png', '/storage/x.png'] as $bad) {
        $this->actingAs($admin, 'admin')->postJson('/admin-api/occasion-banner/cutout/from-media', ['id' => $p->id, 'url' => $bad])->assertStatus(422);
        $this->actingAs($admin, 'admin')->postJson('/admin-api/gift-strip/cutout/from-media', ['id' => $p->id, 'url' => $bad])->assertStatus(422);
    }

    expect(md5_file(pcohServed($p->image)))->toBe($was)->and(ProductCutouts::isOwn($p->image))->toBeFalse();
    @unlink($fake);
});

it('refuses a library file larger than 4 MB on both screens', function () {
    /*
     * The defect: a 20 MB PNG decoded by GD on a shared host is a worker gone
     * for seconds and a memory limit hit. MUTATION: drop UPLOAD_MAX_BYTES's
     * check in ProductCutouts::upload() and both requests are accepted -- red.
     */
    $p = pcohProduct('Too Big');
    $admin = pcohAdmin();
    $tmp = sys_get_temp_dir() . '/pcoh-big-' . uniqid() . '.png';
    file_put_contents($tmp, str_repeat("\0", ProductCutouts::UPLOAD_MAX_BYTES + 1));
    $big = pcohLib($tmp, 'big.png');
    $this->actingAs($admin, 'admin')->postJson('/admin-api/gift-strip/cutout/from-media', ['id' => $p->id, 'url' => $big])
        ->assertStatus(422)->assertJsonPath('error', 'Not used: the file is larger than 4 MB');
    $this->actingAs($admin, 'admin')->postJson('/admin-api/occasion-banner/cutout/from-media', ['id' => $p->id, 'url' => $big])
        ->assertStatus(422);
    expect(ProductCutouts::isOwn($p->image))->toBeFalse();
    @unlink($tmp);

    // and the service's own limit, for any caller that skips the validator
    $huge = sys_get_temp_dir() . '/pcoh-huge-' . uniqid() . '.png';
    file_put_contents($huge, str_repeat("\0", ProductCutouts::UPLOAD_MAX_BYTES + 1));
    expect(ProductCutouts::upload($p->image, $huge)['ok'])->toBeFalse();
    @unlink($huge);
});

it('keeps the occasion banner\'s stored snapshot in step with an upload, so the homepage draws its real width', function () {
    /*
     * The banner draws from a snapshot of each cut-out's widths, not the disk.
     * The defect: an upload of a different shape drawn at the automatic one's
     * width -- squashed -- and from the visitor's cached copy. MUTATION: drop
     * resnap() from OccasionBannerController::fromMedia() and the width is red.
     */
    $p = pcohProduct('Medicube Deodorant');
    ProductCutouts::make((string) $p->image);
    app(SettingsService::class)->set(OccasionBanner::KEY, OccasionBanner::sanitize(['display' => 'banner', 'pool' => [['id' => $p->id, 'cm' => 12]]]));
    SettingsService::forgetMemo();
    Setting::flushMap();

    $url = pcohLib(pcohPng(sys_get_temp_dir() . '/pcoh-b-' . uniqid() . '.png'), 'b.png');
    $j = $this->actingAs(pcohAdmin(), 'admin')->postJson('/admin-api/occasion-banner/cutout/from-media', ['id' => $p->id, 'url' => $url])
        ->assertOk()->json();
    SettingsService::forgetMemo();
    Setting::flushMap();
    $stored = OccasionBanner::config([OccasionBanner::KEY => app(SettingsService::class)->get(OccasionBanner::KEY)]);

    expect($j['product']['cut_own'])->toBeTrue()
        ->and($stored['pool'][0]['cut']['w'][480])->toBe(330)
        ->and($stored['pool'][0]['cut']['v'])->toBeGreaterThan(0)
        ->and(OccasionBanner::cutUrls($stored['pool'][0]['cut'])['src'])->toEndWith('.jpg.webp?v=' . $stored['pool'][0]['cut']['v']);
});

it('puts both new endpoints behind each screen\'s manage capability', function () {
    // Fails closed: a path no rule names is refused. MUTATION: move the routes
    // outside the two prefixes and these resolve to null.
    expect(AdminCapabilities::forPath('POST', 'admin-api/gift-strip/cutout/from-media'))->toBe('giftstrip.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/gift-strip/cutout/automatic'))->toBe('giftstrip.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/occasion-banner/cutout/from-media'))->toBe('occasionbanner.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/occasion-banner/cutout/automatic'))->toBe('occasionbanner.manage');
});

it('ships hand-made cut-outs that are real 480 and 240 tall WebPs with transparency, one for every listed photo', function () {
    // The files in resources/cutouts are what the migration installs. A wrong
    // height draws the product at the wrong size; a missing one leaves the
    // automatic cut in place. MUTATION: delete one file and this is red.
    $rels = json_decode((string) file_get_contents(resource_path('cutouts/index.json')), true);
    expect($rels)->toBeArray()->not->toBeEmpty();
    foreach ($rels as $rel) {
        expect(ProductCutouts::relative('/' . $rel))->toBe($rel);
        foreach ([480, 240] as $h) {
            $f = resource_path('cutouts/' . $h . '/' . sha1($rel) . '.webp');
            $info = getimagesize($f);
            expect($info[2])->toBe(IMAGETYPE_WEBP)->and($info[1])->toBe($h);
            $im = imagecreatefromwebp($f);
            $clear = 0;
            $n = 0;
            for ($y = 0; $y < imagesy($im); $y += 4) {
                for ($x = 0; $x < imagesx($im); $x += 4, $n++) {
                    $clear += ((imagecolorat($im, $x, $y) >> 24) & 127) >= 120 ? 1 : 0;
                }
            }
            expect($clear)->toBeGreaterThan(0); // a cut-out, not a photo on white (the peel-pad jar fills 99% of its box)
        }
    }
});
