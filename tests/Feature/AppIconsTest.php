<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\AppIcons;
use App\Services\OwnerApp\OwnerAppPath;
use App\Services\SettingsService;
use App\Services\SiteApp;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\Support\OwnerAppRoutes as OA;
use Tests\Support\SiteAppRoutes;

/*
 * The owner's own app icon and favicon (Lane IC): App → Site App → App icon,
 * and App → Owner App → App icon.
 *
 * The owner, 5 October: "for the app, allow me to upload our own icon and
 * advise the icon size etc and guide. also site favicon option i need in main
 * site and also in apps sites too."
 *
 * Pinned: one square image in, every size out at its exact pixels, opaque
 * where phones need it, the artwork inside Android's safe circle; anything
 * that is not a PNG/JPEG/WebP of 512-4096 px, square-ish and under 5 MB is
 * refused with a sentence; nothing he uploaded is served, only what GD drew;
 * a manager can change the shop's icon and cannot touch the owner app's; no
 * upload is no tag and no query; the owner app's files answer under its own
 * address, on its own host.
 */

beforeEach(function () {
    SiteAppRoutes::wire($this->app);
    OA::wire($this->app);
    File::deleteDirectory(storage_path('app/app-icons'));
    SiteApp::forgetHashes();
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/app-icons'));
});

/**
 * A lotus-like test icon: pink petals on white, the artwork in the middle 75%,
 * like the owner's own 1050 x 1050 file.
 */
function icLotus(int $w = 1050, ?int $h = null, string $type = 'png', bool $transparent = false): string
{
    $h ??= $w;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, $transparent ? imagecolorallocatealpha($im, 0, 0, 0, 127) : imagecolorallocate($im, 255, 255, 255));
    imagealphablending($im, true);
    $cx = intdiv($w, 2);
    $cy = intdiv($h, 2);
    $r = (int) (min($w, $h) * 0.375);
    $pink = imagecolorallocate($im, 236, 72, 153);
    $light = imagecolorallocate($im, 249, 168, 212);
    foreach ([0, 45, 90, 135] as $deg) {
        $a = deg2rad($deg);
        imagefilledellipse($im, (int) ($cx + cos($a) * $r * 0.35), (int) ($cy - sin($a) * $r * 0.35), (int) ($r * 0.7), (int) ($r * 1.3), $light);
        imagefilledellipse($im, (int) ($cx - cos($a) * $r * 0.35), (int) ($cy - sin($a) * $r * 0.35), (int) ($r * 0.7), (int) ($r * 1.3), $light);
    }
    imagefilledellipse($im, $cx, $cy, (int) ($r * 0.9), (int) ($r * 1.5), $pink);
    ob_start();
    match ($type) {
        'jpeg' => imagejpeg($im, null, 90),
        'webp' => imagewebp($im, null, 90),
        default => imagepng($im),
    };

    return (string) ob_get_clean();
}

function icUpload(string $bytes, string $name = 'lotus.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

function icAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'IC '.$role, 'email' => 'ic-'.$role.'-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

function icFresh(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    SiteApp::forgetHashes();
}

function icPx(string $path): array
{
    $i = getimagesize($path);

    return [$i[0], $i[1], $i['mime']];
}

/* ============================================================ drawing */

it('makes every size from one upload, at its exact pixels, opaque where the phone needs it', function () {
    /* (Opaque on a CLEAR source is the next test: MUTATION make 'apple-180' mode 'any' in
       AppIcons::APP_SET -> its flattened-on-white line is red; iOS paints a clear pixel black.)
       MUTATION: set AppIcons::SAFE to 1.0 -> the maskable padding line is red (Android's
       circle crops the petals). */
    $this->actingAs(icAdmin(), 'admin')
        ->post('/admin-api/site-app/icon', ['kind' => 'app', 'file' => icUpload(icLotus())], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('icon.app', true)->assertJsonPath('icon.bg', '#FFFFFF')
        ->assertJsonPath('icon.favicon_from', 'app');
    icFresh();

    foreach (AppIcons::APP_SET + AppIcons::FAVICON_SET as $key => [$px]) {
        $path = AppIcons::file('site', $key);
        expect($path)->not->toBeNull($key)
            ->and(icPx($path))->toBe([$px, $px, 'image/png'], $key)
            ->and(dirname($path))->toMatch('#/app-icons/site/app-[0-9a-f]{10}$#')
            ->and(basename($path))->toBe($key.'.png');
    }

    foreach (['apple-180' => 180, 'maskable-512' => 512] as $key => $px) {
        $im = imagecreatefrompng(AppIcons::file('site', $key));
        foreach ([[0, 0], [$px - 1, 0], [0, $px - 1], [$px - 1, $px - 1]] as [$x, $y]) {
            expect(imagecolorsforindex($im, imagecolorat($im, $x, $y))['alpha'])->toBe(0, "$key corner $x,$y");
        }
    }

    // The maskable icon: white padding where the source's petals reached
    // (12.5% from the edge in the source = still white at 8% from the edge
    // after the 80% shrink, which is outside Android's 80% circle), pink in
    // the middle.
    $m = imagecreatefrompng(AppIcons::file('site', 'maskable-512'));
    $src = imagecreatefromstring(icLotus());
    $at = fn ($im, $x, $y) => imagecolorsforindex($im, imagecolorat($im, $x, $y));
    expect($at($m, 256, 256)['green'])->toBeLessThan(120)            // pink centre
        ->and($at($m, 256, (int) (512 * 0.105)))->toMatchArray(['red' => 255, 'green' => 255, 'blue' => 255]); // padding
    // ...where the unpadded 512 still has a petal close to the top.
    $any = imagecreatefrompng(AppIcons::file('site', 'icon-512'));
    $topPetal = null;
    for ($y = 0; $y < 512; $y++) {
        if ($at($any, 256, $y)['green'] < 220) {
            $topPetal = $y;
            break;
        }
    }
    $topMask = null;
    for ($y = 0; $y < 512; $y++) {
        if ($at($m, 256, $y)['green'] < 220) {
            $topMask = $y;
            break;
        }
    }
    expect($topMask)->toBe((int) round(51.2 + $topPetal * 0.8), 'the maskable artwork is the icon shrunk to 80%, centred');
});

it('accepts JPEG and WebP, centre-crops a nearly square image, and serves only PNGs it drew itself', function () {
    /* MUTATION: copy the upload instead of re-encoding it in AppIcons::draw() -> the
       payload line is red (a tEXt chunk, an EXIF location, anything, served from the shop). */
    $owner = icAdmin();

    // A PNG carrying a text chunk: the bytes after re-encoding carry no trace of it.
    $png = icLotus();
    $payload = 'tEXtComment'."\0".'<script>alert(1)</script>';
    $chunk = pack('N', strlen($payload) - 4).$payload.pack('N', crc32($payload));
    $hostile = substr($png, 0, 33).$chunk.substr($png, 33);
    $this->actingAs($owner, 'admin')->post('/admin-api/site-app/icon', ['kind' => 'app', 'file' => icUpload($hostile, '../../evil.php.png')], ['Accept' => 'application/json'])->assertOk();
    icFresh();
    foreach (array_keys(AppIcons::APP_SET + AppIcons::FAVICON_SET) as $key) {
        $bytes = (string) file_get_contents(AppIcons::file('site', $key));
        expect($bytes)->not->toContain('<script>')->and($bytes)->not->toContain('tEXt');
    }
    expect(glob(storage_path('app/app-icons/site/*/*')))->each->not->toContain('evil');

    foreach (['jpeg', 'webp'] as $type) {
        $this->actingAs($owner, 'admin')->post('/admin-api/site-app/icon', ['kind' => 'app', 'file' => icUpload(icLotus(1024, 1024, $type), 'x.'.$type)], ['Accept' => 'application/json'])
            ->assertOk();
        icFresh();
        expect(icPx(AppIcons::file('site', 'icon-512')))->toBe([512, 512, 'image/png'], $type);
    }

    // 1100 x 1000 is within 1.2 : 1 -> the middle 1000 x 1000 is used, and the answer says so.
    $r = $this->actingAs($owner, 'admin')->post('/admin-api/site-app/icon', ['kind' => 'app', 'file' => icUpload(icLotus(1100, 1000))], ['Accept' => 'application/json'])->assertOk();
    expect($r->json('message'))->toContain('the middle 1000 × 1000 was used');
    icFresh();
    expect(icPx(AppIcons::file('site', 'apple-180')))->toBe([180, 180, 'image/png']);

    // A transparent background is flattened on white for iPhone, kept clear for "any".
    // MUTATION: make 'apple-180' mode 'any' in AppIcons::APP_SET -> the apple line is red.
    $this->actingAs($owner, 'admin')->post('/admin-api/site-app/icon', ['kind' => 'app', 'file' => icUpload(icLotus(800, 800, 'png', true))], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('icon.bg', '#FFFFFF');
    icFresh();
    $apple = imagecreatefrompng(AppIcons::file('site', 'apple-180'));
    $any = imagecreatefrompng(AppIcons::file('site', 'icon-192'));
    expect(imagecolorsforindex($apple, imagecolorat($apple, 0, 0)))->toMatchArray(['red' => 255, 'green' => 255, 'blue' => 255, 'alpha' => 0])
        ->and(imagecolorsforindex($any, imagecolorat($any, 0, 0))['alpha'])->toBe(127);

    // Only the current set is kept on disk.
    expect(glob(storage_path('app/app-icons/site/*'), GLOB_ONLYDIR))->toHaveCount(1);
});

it('refuses an SVG, a non-image, an oversized file, a too-small image and a banner, each with a sentence, changing nothing', function () {
    /* MUTATION: drop the SVG sniff in AppIcons::draw() -> still refused (getimagesize cannot
       read SVG), but the message no longer says why; the toContain('SVG') line is red.
       MUTATION: lower AppIcons::MIN_SIDE to 256 -> the 400 px line is green-for-upload, red here. */
    $owner = icAdmin();
    $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024"><script>alert(document.cookie)</script><rect width="1024" height="1024" fill="pink"/></svg>';

    $cases = [
        'svg named .png' => [icUpload($svg, 'icon.png'), 'SVG'],
        'svg named .svg' => [icUpload($svg, 'icon.svg'), 'SVG'],
        'text' => [icUpload("<?php echo 'hi';", 'icon.png'), 'not a PNG, JPEG or WebP'],
        'gif' => [icUpload((function () { $im = imagecreatetruecolor(600, 600); ob_start(); imagegif($im); return (string) ob_get_clean(); })(), 'icon.gif'), 'not a PNG, JPEG or WebP'],
        'oversized' => [icUpload(icLotus().str_repeat("\0", AppIcons::MAX_BYTES), 'big.png'), 'the limit is 5 MB'],
        'too small' => [icUpload(icLotus(400)), 'at least 512 × 512'],
        'banner' => [icUpload(icLotus(1200, 600)), 'not square'],
    ];
    foreach ($cases as $why => [$file, $says]) {
        $r = $this->actingAs($owner, 'admin')->post('/admin-api/site-app/icon', ['kind' => 'app', 'file' => $file], ['Accept' => 'application/json']);
        expect($r->status())->toBe(422, $why)
            ->and((string) $r->json('message'))->toContain($says)
            ->and($r->json('icon.app'))->toBeFalse();
    }
    // A kind that is not one of the two, and no file at all.
    $this->actingAs($owner, 'admin')->post('/admin-api/site-app/icon', ['kind' => 'badge', 'file' => icUpload(icLotus())], ['Accept' => 'application/json'])->assertStatus(422);
    $this->actingAs($owner, 'admin')->post('/admin-api/site-app/icon', ['kind' => 'app'], ['Accept' => 'application/json'])->assertStatus(422);

    icFresh();
    expect(Setting::query()->where('key', 'site_app_icon')->exists())->toBeFalse()
        ->and(is_dir(storage_path('app/app-icons/site')) ? glob(storage_path('app/app-icons/site/*')) : [])->toBe([]);
});

/* ============================================================ the shop */

it('changes nothing on a page until an icon is uploaded, and goes back byte for byte when it is removed', function () {
    /* MUTATION: make SiteApp::favicon() return the shipped icon when nothing is uploaded ->
       the first not->toContain line is red (and StorefrontEnglishUnchangedTest with it). */
    $before = (string) $this->get('/')->assertOk()->getContent();
    expect($before)->not->toContain('rel="icon"')->and(app(SiteApp::class)->favicon())->toBe([]);
    $this->get('/site-app/icons/favicon-48.png')->assertNotFound();

    $owner = icAdmin();
    $this->actingAs($owner, 'admin')->post('/admin-api/site-app/icon', ['kind' => 'app', 'file' => icUpload(icLotus())], ['Accept' => 'application/json'])->assertOk();
    $this->actingAs($owner, 'admin')->post('/admin-api/site-app/icon', ['kind' => 'favicon', 'file' => icUpload(icLotus(600))], ['Accept' => 'application/json'])->assertOk();
    $this->actingAs($owner, 'admin')->postJson('/admin-api/site-app/icon/reset', ['kind' => 'favicon'])->assertOk()->assertJsonPath('icon.favicon_from', 'app');
    $this->actingAs($owner, 'admin')->postJson('/admin-api/site-app/icon/reset', ['kind' => 'app'])->assertOk()->assertJsonPath('icon.favicon_from', 'none');
    icFresh();
    \Illuminate\Support\Facades\Auth::guard('admin')->logout();

    expect(Setting::query()->where('key', 'site_app_icon')->exists())->toBeFalse();
    $after = (string) $this->get('/')->assertOk()->getContent();
    expect($after)->toBe($before);
});

it('puts the favicon and the uploaded apple-touch-icon on every storefront page, and the manifest names the uploaded files', function () {
    /* MUTATION: make SiteApp::iconPath() ignore AppIcons::file() -> the manifest bytes line is
       red (the phone installs the KB icon although he uploaded his lotus).
       MUTATION: drop the favicon block from partials/site-app-head -> the three-links line is red. */
    $shippedVersion = SiteApp::version();
    $this->actingAs(icAdmin(), 'admin')->post('/admin-api/site-app/icon', ['kind' => 'app', 'file' => icUpload(icLotus())], ['Accept' => 'application/json'])->assertOk();
    icFresh();
    \Illuminate\Support\Facades\Auth::guard('admin')->logout();

    $html = (string) $this->get('/')->assertOk()->getContent();
    preg_match_all('#<link rel="icon" type="image/png" sizes="(\d+)x\1" href="(/site-app/icons/favicon-\1\.png\?v=[0-9a-f]{10})">\n#', $html, $m);
    expect($m[1])->toBe(['48', '96', '192'])
        ->and(substr_count($html, 'rel="apple-touch-icon"'))->toBe(1)
        ->and(strpos($html, 'rel="icon"'))->toBeLessThan(strpos($html, '</head>'));

    foreach ($m[2] as $i => $href) {
        $r = $this->get($href)->assertOk();
        $px = (int) $m[1][$i];
        expect($r->headers->get('Content-Type'))->toBe('image/png')
            ->and($r->headers->get('Cache-Control'))->toContain('immutable')
            ->and(getimagesizefromstring((string) $r->getFile()->getContent())[0])->toBe($px);
    }

    preg_match('#<link rel="apple-touch-icon" href="([^"]+)">#', $html, $a);
    expect((string) $this->get($a[1])->assertOk()->getFile()->getContent())->toBe(file_get_contents(AppIcons::file('site', 'apple-180')));

    $manifest = $this->get('/manifest.webmanifest')->assertOk()->json();
    expect($manifest['name'])->toBe('K-Beauty Bliss');
    foreach ($manifest['icons'] as $icon) {
        $key = basename(parse_url($icon['src'], PHP_URL_PATH), '.png');
        expect((string) $this->get($icon['src'])->assertOk()->getFile()->getContent())->toBe(file_get_contents(AppIcons::file('site', $key)), $key);
    }

    // Installed copies update: the worker's version follows the icon.
    expect(SiteApp::version())->not->toBe($shippedVersion);

    // The same tags on a standalone document (the blog uses the partial too).
    // And with the app switched off the tab icon stays, and brings its own apple-touch-icon.
    Setting::query()->updateOrCreate(['key' => SiteApp::SETTING], ['value' => json_encode(['on' => false, 'name' => 'K-Beauty Bliss']), 'autoload' => true]);
    icFresh();
    $off = (string) $this->get('/')->assertOk()->getContent();
    expect(substr_count($off, '<link rel="icon"'))->toBe(3)
        ->and(substr_count($off, 'rel="apple-touch-icon"'))->toBe(1)
        ->and($off)->not->toContain('rel="manifest"');
});

it('uses a separate favicon for the tab when one is uploaded, and the app icon everywhere else', function () {
    $owner = icAdmin();
    $this->actingAs($owner, 'admin')->post('/admin-api/site-app/icon', ['kind' => 'app', 'file' => icUpload(icLotus())], ['Accept' => 'application/json'])->assertOk();
    icFresh();
    $appFav = (string) file_get_contents(AppIcons::file('site', 'favicon-48'));
    $apple = (string) file_get_contents(AppIcons::file('site', 'apple-180'));

    $this->actingAs($owner, 'admin')->post('/admin-api/site-app/icon', ['kind' => 'favicon', 'file' => icUpload(icLotus(512, 512, 'jpeg'))], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('icon.favicon_from', 'own');
    icFresh();
    expect(AppIcons::file('site', 'favicon-48'))->toContain('/favicon-')
        ->and((string) file_get_contents(AppIcons::file('site', 'favicon-48')))->not->toBe($appFav)
        ->and((string) file_get_contents(AppIcons::file('site', 'apple-180')))->toBe($apple)
        ->and(AppIcons::file('site', 'icon-192'))->toContain('/app-');
});

it('adds no query to a storefront page with an icon uploaded, at three products or forty', function () {
    /* The favicon rides the autoloaded settings snapshot. MUTATION: save site_app_icon with
       autoload false in AppIcons::put() -> the counts differ by the settings query. */
    $count = function (): int {
        $this->get('/'); // warm
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $without = $count();
    $this->actingAs(icAdmin(), 'admin')->post('/admin-api/site-app/icon', ['kind' => 'app', 'file' => icUpload(icLotus(512))], ['Accept' => 'application/json'])->assertOk();
    icFresh();
    \Illuminate\Support\Facades\Auth::guard('admin')->logout();
    $with = $count();

    expect($with)->toBe($without);
    expect(Setting::query()->where('key', 'site_app_icon')->value('autoload'))->toBeTruthy();
});

/* ============================================================ who may */

it('lets an owner or manager change the shop icon, and only the owner touch the owner app icon', function () {
    /* MUTATION: delete ['*', 'admin-api/site-app/**', ...] from AdminCapabilities::RULES ->
       the manager's site upload is a 403 (fails closed, owner only).
       MUTATION: route the owner-app endpoints through the site-app path -> the manager
       lines on owner-app are 200, red. */
    $manager = icAdmin('manager');
    $this->actingAs($manager, 'admin')->post('/admin-api/site-app/icon', ['kind' => 'app', 'file' => icUpload(icLotus(600))], ['Accept' => 'application/json'])->assertOk();

    $this->actingAs($manager, 'admin')->getJson('/admin-api/owner-app/icon')->assertForbidden();
    $this->actingAs($manager, 'admin')->get('/admin-api/owner-app/icon/icon-192.png')->assertForbidden();
    $this->actingAs($manager, 'admin')->post('/admin-api/owner-app/icon', ['kind' => 'app', 'file' => icUpload(icLotus(600))], ['Accept' => 'application/json'])->assertForbidden();
    $this->actingAs($manager, 'admin')->postJson('/admin-api/owner-app/icon/reset', ['kind' => 'app'])->assertForbidden();

    foreach (['editor', 'support'] as $role) {
        $u = icAdmin($role);
        $this->actingAs($u, 'admin')->post('/admin-api/site-app/icon', ['kind' => 'app', 'file' => icUpload(icLotus(600))], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($u, 'admin')->postJson('/admin-api/site-app/icon/reset', ['kind' => 'app'])->assertForbidden();
    }
    \Illuminate\Support\Facades\Auth::guard('admin')->logout();
    expect($this->post('/admin-api/site-app/icon', ['kind' => 'app', 'file' => icUpload(icLotus(600))], ['Accept' => 'application/json'])->status())->toBeIn([401, 403, 419]);
    expect($this->getJson('/admin-api/owner-app/icon')->status())->toBeIn([401, 403]);

    icFresh();
    expect(AppIcons::state('owner')['app'])->toBeNull()->and(AppIcons::state('site')['app'])->not->toBeNull();
});

/* ============================================================ owner app */

it('serves the owner app icon under the app\'s own address, in its shell and manifest, apart from the shop\'s', function () {
    /* MUTATION: point AppController::assets() at Vite for every icon -> the manifest line is red.
       MUTATION: move the icons route out of the owner-app group -> the own-host request is a 404. */
    $owner = icAdmin();
    $base = OA::base();

    // Before any upload the shell keeps its one shipped tab icon line.
    $shell = (string) $this->get($base)->assertOk()->getContent();
    expect(substr_count($shell, '<link rel="icon"'))->toBe(1)->and($shell)->toMatch('#<link rel="icon" type="image/png" href="[^"]*icon-192[^"]*">\n<link rel="apple-touch-icon"#');
    $this->get($base.'/icons/icon-192.png')->assertNotFound();

    $this->actingAs($owner, 'admin')->getJson('/admin-api/owner-app/icon')->assertOk()->assertJsonPath('icon.app', false)->assertJsonPath('icon.name', 'KBB Owner');
    $this->actingAs($owner, 'admin')->post('/admin-api/owner-app/icon', ['kind' => 'app', 'file' => icUpload(icLotus())], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('icon.app', true);
    $preview = $this->actingAs($owner, 'admin')->get('/admin-api/owner-app/icon/maskable-512.png')->assertOk();
    expect(getimagesizefromstring((string) $preview->getFile()->getContent())[0])->toBe(512);
    icFresh();
    \Illuminate\Support\Facades\Auth::guard('admin')->logout();

    // The shop is not changed by the owner app's icon.
    expect(AppIcons::state('site')['app'])->toBeNull()->and(app(SiteApp::class)->favicon())->toBe([]);

    $shell = (string) $this->get($base)->assertOk()->getContent();
    preg_match_all('#<link rel="icon" type="image/png" sizes="(\d+)x\1" href="('.preg_quote($base, '#').'/icons/favicon-\1\.png\?v=[0-9a-f]{10})">#', $shell, $m);
    expect($m[1])->toBe(['48', '96', '192'])
        ->and($shell)->toMatch('#<link rel="apple-touch-icon" href="'.preg_quote($base, '#').'/icons/apple-180\.png\?v=[0-9a-f]{10}">#')
        ->and($shell)->toContain('<meta name="apple-mobile-web-app-title" content="KBB Owner">');

    $manifest = $this->get($base.'/manifest.webmanifest')->assertOk()->json();
    expect($manifest['short_name'])->toBe('KBB Owner');
    foreach ($manifest['icons'] as $icon) {
        expect($icon['src'])->toStartWith($base.'/icons/');
        $r = $this->get($icon['src'])->assertOk();
        expect($r->headers->get('Content-Type'))->toBe('image/png')
            ->and($r->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow, noarchive')
            ->and((string) $r->getFile()->getContent())->toBe(file_get_contents(AppIcons::file('owner', basename(parse_url($icon['src'], PHP_URL_PATH), '.png'))));
    }
    expect((string) $this->get($base.'/sw.js')->getContent())->toContain($base.'/icons/icon-192.png?v=');

    // Allowlisted names only: the badge stays the shipped one, anything else is the app's 404.
    foreach (['badge-96', 'sw', 'x'] as $n) {
        $this->get($base.'/icons/'.$n.'.png')->assertNotFound();
    }

    // On the app's own host: the icon answers there, and not on the shop's.
    try {
        OwnerAppPath::setHost('owner.example.test');
        icRewireOwnerApp();
        $this->get('http://owner.example.test'.$base.'/icons/icon-192.png')->assertOk();
        $this->get('http://localhost'.$base.'/icons/icon-192.png')->assertNotFound();
    } finally {
        OwnerAppPath::setHost('');
        icRewireOwnerApp();
    }
});

/** Register the owner app's routes again after its host moved (as OwnerAppHardeningTest does). */
function icRewireOwnerApp(): void
{
    $router = Route::getFacadeRoot();
    $kept = new RouteCollection();
    foreach ($router->getRoutes() as $r) {
        if (! str_starts_with((string) $r->getName(), 'owner-app.')) {
            $kept->add($r);
        }
    }
    $router->setRoutes($kept);
    Route::middleware('web')->group(base_path('routes/owner-app.php'));
    $router->getRoutes()->refreshNameLookups();
    $router->getRoutes()->refreshActionLookups();
}

/* ============================================================ wiring */

it('ships its card once, inside the Site App screen, and the migration its routes need', function () {
    $site = (string) file_get_contents(resource_path('views/admin/partials/site-app-screen.blade.php'));
    $owner = (string) file_get_contents(resource_path('views/admin/partials/owner-app-screen.blade.php'));
    $card = (string) file_get_contents(resource_path('views/admin/partials/app-icon-card.blade.php'));
    expect(substr_count($site, "@include('admin.partials.app-icon-card')"))->toBe(1)
        ->and(substr_count($owner, "@include('admin.partials.app-icon-card')"))->toBe(0)
        ->and(substr_count($site, 'window.kbbAppIconCard('))->toBe(1)
        ->and(substr_count($owner, 'window.kbbAppIconCard('))->toBe(1)
        // Server text is set as text, never as markup.
        ->and($card)->not->toContain('innerHTML')
        ->and(glob(database_path('migrations/*_clear_caches_app_icons.php')))->toHaveCount(1);
});
