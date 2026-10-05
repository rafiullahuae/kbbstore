<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use App\Services\SiteLayout;
use App\Support\BrandLogo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BrandAdminRoutes;
use Tests\Support\StorefrontAdminRoutes;

/*
 * The brand page's header, Lane BH. The owner, 4 October, with a screenshot of
 * Anua on a phone -- a big circle, then the name, then the description, each on
 * its own line:
 *
 *   "i don't like the heighted banner for brand, the content should be
 *    sqeezed. logo + name in one row, then description in another, that's it.
 *    need same less heighted banner in mobile as like on desktop. provide
 *    facility to upload the brand logo, and it will be auto circled with outer
 *    brand color border. the brand color the system can fetch from the logo
 *    image itself."
 */

/** @var list<string> */
$GLOBALS['bhFiles'] = [];

function bhBrand(array $extra = []): Brand
{
    return Brand::create(array_merge(['name' => 'Aurabh', 'slug' => 'aurabh'], $extra));
}

function bhProduct(Brand $brand): void
{
    Product::create([
        'slug' => 'bh-prod-'.$brand->id, 'name' => 'BH Toner', 'brand_id' => $brand->id,
        'price' => 1000, 'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple',
    ]);
}

function bhSave(array $values): void
{
    app(SiteLayout::class)->save($values);
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();
}

/**
 * A logo file under public/uploads/brands/, drawn with GD. $paint gets the
 * image (truecolour, alpha on, fully transparent) and its size.
 */
function bhLogo(string $name, Closure $paint, int $size = 120, string $type = 'png'): string
{
    $dir = public_path('uploads/brands');
    @mkdir($dir, 0777, true);

    $img = imagecreatetruecolor($size, $size);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, imagecolorallocatealpha($img, 0, 0, 0, 127));
    imagealphablending($img, true);
    $paint($img, $size);

    $file = $dir.'/'.$name.'.'.$type;
    $type === 'jpg' ? imagejpeg($img, $file, 90) : imagepng($img, $file);
    $GLOBALS['bhFiles'][] = $file;

    return '/uploads/brands/'.$name.'.'.$type;
}

/** A red disc on a transparent square: Anua's own logo is roughly this. */
function bhRedLogo(string $name = 'bh-red'): string
{
    return bhLogo($name, function ($img, $s) {
        imagefilledellipse($img, intdiv($s, 2), intdiv($s, 2), $s - 10, $s - 10, imagecolorallocate($img, 206, 32, 48));
    });
}

function bhHex(string $hex): array
{
    return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
}

afterEach(function () {
    foreach ($GLOBALS['bhFiles'] as $file) {
        @unlink($file);
    }
    $GLOBALS['bhFiles'] = [];
});

/* ================================================================ the shape */

describe('the compact header', function () {
    it('puts the logo and the name in one row and the description under them, when Compact is picked', function () {
        /*
         * THE DEFECT, in the owner's screenshot: on a phone the hero was
         * `flex-direction:column` -- a 72px circle, then the name, then the
         * description, three bands where he wanted two. MUTATION: ship
         * brand_hero at 'classic' and every line here is red.
         */
        $brand = bhBrand(['description' => 'Anua is a Korean skincare brand built around the heartleaf plant.']);
        bhProduct($brand);
        // Compact was the default until Lane BR2 shipped the Panel header, as
        // the owner asked; it stays one pick away and draws exactly as before.
        bhSave(['brand_hero' => 'compact']);

        $html = $this->get('/brands/aurabh/')->assertOk()->getContent();

        expect(SiteLayout::SCHEMA['brand_hero'][2])->toBe('panel')
            ->and($html)->toContain('<div class="brw-hero brw-hero--compact">')
            ->and($html)->not->toContain('class="brw-hero-txt"');

        // Row 1: the circle, then the h1, inside .brw-hero-row and nothing else.
        expect(preg_match('#<div class="brw-hero-row">\s*<span class="brw-logo brw-logo--lg[^"]*"[^>]*><span class="brw-initial">A</span></span>\s*<h1 class="brw-h1">Aurabh</h1>\s*</div>#', $html))->toBe(1);

        // Row 2: the description, AFTER the row closes -- full width, not
        // beside the logo.
        expect(preg_match('#</h1>\s*</div>\s*<div class="brw-sub brw-desc">Anua is a Korean#', $html))->toBe(1);
    });

    it('has no column-stacking rule for the compact header at any width, and a smaller circle on phones', function () {
        /*
         * "need same less heighted banner in mobile as like on desktop". The
         * classic header's phone rule is `.brw-hero{flex-direction:column}`;
         * the compact one is a block whose row stays a row. MUTATION: add
         * `.brw-hero--compact{flex-direction:column}` (or drop the
         * `display:block`, which lets the classic phone rule stack it) and
         * this is red.
         */
        bhProduct(bhBrand());
        bhSave(['brand_hero' => 'compact']);
        $html = $this->get('/brands/aurabh/')->assertOk()->getContent();

        preg_match_all('#\.brw-hero--compact[^{]*\{[^}]*\}|\.brw-hero-row\{[^}]*\}#', $html, $m);
        $rules = implode("\n", $m[0]);

        expect($rules)->toContain('.brw-hero--compact{display:block')
            ->and($rules)->toContain('.brw-hero-row{display:flex;align-items:center')
            ->and($rules)->not->toContain('column')
            ->and($html)->toContain('.brw-hero--compact .brw-logo--lg{width:56px;height:56px}')
            ->and($html)->toMatch('#@media \(max-width:520px\)\{[^@]*\.brw-hero--compact \.brw-logo--lg\{width:44px;height:44px\}#');
    });

    it('puts the classic header back, exactly, when the owner picks Classic', function () {
        $brand = bhBrand(['description' => 'Desc']);
        bhProduct($brand);
        bhSave(['brand_hero' => 'classic']);

        $html = $this->get('/brands/aurabh/')->assertOk()->getContent();

        expect($html)->toContain('<div class="brw-hero">')
            ->and($html)->toContain('<div class="brw-hero-txt">')
            ->and($html)->not->toContain('brw-hero--compact"');
    });

    it('stores only one of its own options', function () {
        bhSave(['brand_hero' => 'stacked;color:red']);

        expect(app(SiteLayout::class)->get('brand_hero'))->toBe('panel');
    });

    it('keeps the logo row when a banner or title header prints the heading', function () {
        /*
         * A title header owns the page's <h1>; the compact row then holds the
         * circle alone and prints no second heading.
         */
        $brand = bhBrand(['header_image' => '/uploads/brands/bh-header.jpg', 'header_title' => 'Aurabh']);
        bhProduct($brand);
        bhSave(['cat_header' => true, 'cat_header_brands' => true, 'brand_hero' => 'compact']);

        $html = $this->get('/brands/aurabh/')->assertOk()->getContent();

        expect($html)->toContain('<div class="brw-hero-row">')
            ->and(substr_count($html, '<h1'))->toBe(1);
    });

    it('mirrors in Arabic, where the row reads right to left on its own', function () {
        $brand = bhBrand();
        bhProduct($brand);
        bhSave(['brand_hero' => 'compact']);

        \App\Models\Setting::query()->updateOrCreate(['key' => \App\Support\Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
        \App\Models\Setting::query()->updateOrCreate(['key' => \App\Support\Locale::SETTING_RTL], ['value' => '1', 'autoload' => true]);
        \App\Models\Setting::flushMap();
        \App\Services\SettingsService::forgetMemo();
        app(\App\Services\SettingsService::class)->flush();
        \App\Services\Translation\TranslationStore::flush();

        $html = $this->get('/ar/brands/aurabh/')->assertOk()->getContent();

        // A flex row under dir="rtl" is the mirror; nothing in the compact
        // rules pins a physical side.
        expect($html)->toContain('dir="rtl"')->and($html)->toContain('brw-hero--compact');
        preg_match_all('#\.brw-hero[^{]*\{[^}]*\}#', $html, $m);
        expect(implode('', $m[0]))->not->toMatch('#margin-left|margin-right|padding-left|padding-right|float:#');
    });

    it('costs the brand page no query: compact and classic, ring on or off, read the same', function () {
        /*
         * Rule 4. The ring colour is read off the brand row the page already
         * loads; nothing is decoded and nothing is fetched on the way out.
         * MUTATION: compute BrandLogo::colourOf() in BrandController::show()
         * and nothing here moves -- but load the brand again for the ring and
         * the count goes up by one.
         */
        $brand = bhBrand(['logo' => bhRedLogo(), 'logo_color' => '#ce2030']);
        bhProduct($brand);
        bhSave(['brand_hero' => 'compact']);
        $this->get('/brands/aurabh/')->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/brands/aurabh/')->assertOk();
        $compact = count(DB::getQueryLog());

        bhSave(['brand_hero' => 'classic', 'brand_ring' => false]);
        $this->get('/brands/aurabh/')->assertOk();
        DB::flushQueryLog();
        $this->get('/brands/aurabh/')->assertOk();
        $classic = count(DB::getQueryLog());

        expect($compact)->toBe($classic);
    });
});

/* ================================================================== colour */

describe('the colour taken from the logo', function () {
    it('finds red in a red logo, and the ring on the page is that red', function () {
        $logo = bhRedLogo();
        $hex = BrandLogo::colourOf($logo);

        expect($hex)->toMatch('/^#[0-9a-f]{6}$/');
        [$r, $g, $b] = bhHex((string) $hex);
        expect($r)->toBeGreaterThan(150)->and($g)->toBeLessThan(80)->and($b)->toBeLessThan(90);
    });

    it('reads a JPEG and finds the brand colour beside black lettering on white', function () {
        // A white tile, black wordmark, and a green leaf: the leaf is the colour.
        $logo = bhLogo('bh-leaf', function ($img, $s) {
            imagefilledrectangle($img, 0, 0, $s - 1, $s - 1, imagecolorallocate($img, 255, 255, 255));
            imagefilledrectangle($img, 10, 70, $s - 10, 100, imagecolorallocate($img, 10, 10, 10));
            imagefilledellipse($img, 60, 35, 50, 40, imagecolorallocate($img, 46, 160, 67));
        }, 120, 'jpg');

        [$r, $g, $b] = bhHex((string) BrandLogo::colourOf($logo));

        expect($g)->toBeGreaterThan($r + 50)->and($g)->toBeGreaterThan($b + 40);
    });

    it('answers null for a white, a transparent, a black or a grey logo', function () {
        /*
         * Near white, near black, grey and see-through pixels do not vote; a
         * logo with nothing else in it has no colour, and its ring is the
         * shop pink. The defect this pins is a picker that averages every
         * visible pixel: the white logo answers #ffffff (a white ring on a
         * white page) and the black one a near-black ring. The filters back
         * each other up (a near-white pixel is also grey by its spread), so
         * deleting one alone stays green; MUTATION: return the plain
         * average of the visible pixels from colourOfFile() and all four are
         * red.
         */
        $white = bhLogo('bh-white', fn ($img, $s) => imagefilledrectangle($img, 0, 0, $s - 1, $s - 1, imagecolorallocate($img, 255, 255, 255)));
        $clear = bhLogo('bh-clear', fn () => null);
        $black = bhLogo('bh-black', fn ($img, $s) => imagefilledellipse($img, 60, 60, 100, 100, imagecolorallocate($img, 12, 12, 12)));
        $grey = bhLogo('bh-grey', fn ($img, $s) => imagefilledellipse($img, 60, 60, 100, 100, imagecolorallocate($img, 128, 130, 129)));

        expect(BrandLogo::colourOf($white))->toBeNull()
            ->and(BrandLogo::colourOf($clear))->toBeNull()
            ->and(BrandLogo::colourOf($black))->toBeNull()
            ->and(BrandLogo::colourOf($grey))->toBeNull();
    });

    it('never fetches a remote logo, and never reads outside the public uploads', function () {
        /*
         * SSRF: an admin form that makes the server request any URL typed into
         * it. BrandLogo has no HTTP client at all, and a remote address maps to
         * no file. MUTATION: resolve absolute URLs with file_get_contents() or
         * Http::get() and the stray-request guard is red.
         */
        Http::preventStrayRequests();
        Http::fake();

        $red = bhRedLogo('bh-own');

        expect(BrandLogo::colourOf('https://evil.example/uploads/brands/bh-own.png'))->toBeNull()
            ->and(BrandLogo::colourOf('http://169.254.169.254/latest/meta-data/'))->toBeNull()
            ->and(BrandLogo::colourOf('//evil.example/uploads/brands/bh-own.png'))->toBeNull()
            ->and(BrandLogo::localFile('/uploads/../.env'))->toBeNull()
            ->and(BrandLogo::localFile('/uploads/brands/%2e%2e/%2e%2e/.env'))->toBeNull()
            ->and(BrandLogo::localFile('/index.php'))->toBeNull()
            ->and(BrandLogo::localFile('file:///etc/passwd'))->toBeNull()
            // This shop's own absolute URL is a local file, read from disk.
            ->and(BrandLogo::localFile(rtrim((string) config('app.url'), '/').$red))->toBe(realpath(public_path(ltrim($red, '/'))))
            ->and(BrandLogo::colourOf($red))->not->toBeNull();

        Http::assertNothingSent();
    });

    it('refuses an image too large to decode safely, without reading it', function () {
        $file = public_path('uploads/brands/bh-huge.png');
        @mkdir(dirname($file), 0777, true);
        // A PNG header claiming 6000 x 6000: getimagesize() reads that much
        // and the pixel cap refuses it before GD allocates anything.
        file_put_contents($file, "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 6000, 6000)."\x08\x06\x00\x00\x00".pack('N', 0));
        $GLOBALS['bhFiles'][] = $file;

        expect(BrandLogo::colourOfFile($file))->toBeNull();
    });
});

/* ==================================================================== ring */

describe('the ring', function () {
    it('rings the logo in the colour taken from it, and in the owner\'s own colour when he sets one', function () {
        $brand = bhBrand(['logo' => bhRedLogo(), 'logo_color' => '#ce2030']);
        bhProduct($brand);

        expect($this->get('/brands/aurabh/')->getContent())
            ->toContain('class="brw-logo brw-logo--lg brw-logo--ring" style="--brw-ring:#ce2030"');

        // Override wins. MUTATION: swap the two in BrandLogo::ring() and this is red.
        $brand->forceFill(['ring_color' => '#1E6FD9'])->save();

        expect($this->get('/brands/aurabh/')->getContent())
            ->toContain('style="--brw-ring:#1e6fd9"')
            ->not->toContain('--brw-ring:#ce2030');
    });

    it('prints nothing but a validated hex, whatever the column holds', function () {
        /*
         * Rule 5: the value printed into style="" is BrandLogo::clean()'s
         * #rrggbb or nothing. A column written by hand, an import, or a future
         * bug must not become CSS. MUTATION: print $brand->logo_color raw in
         * the partial and this is red.
         */
        // Seven characters each: the columns are VARCHAR(7), and MySQL's strict
        // mode refuses anything longer before the page is ever asked -- these
        // are what a hand-written row can actually hold there.
        $brand = bhBrand([
            'logo_color' => '"><b>xx',
            'ring_color' => 'red;x:y',
        ]);
        bhProduct($brand);

        $html = $this->get('/brands/aurabh/')->getContent();

        expect($html)->not->toContain('"><b>xx')
            ->and($html)->not->toContain('red;x:y')
            ->and($html)->not->toContain('--brw-ring:')
            // No colour at all: the ring class, no style -- the shop pink.
            ->and($html)->toContain('<span class="brw-logo brw-logo--lg brw-logo--ring"><span class="brw-initial">A</span></span>');
    });

    it('draws the plain circle, as before, with the ring switched off', function () {
        bhProduct(bhBrand(['logo_color' => '#ce2030']));
        bhSave(['brand_ring' => false]);

        $html = $this->get('/brands/aurabh/')->getContent();

        expect($html)->not->toContain('brw-logo--ring"')
            ->and($html)->not->toContain('--brw-ring:#');
    });
});

/* ========================================================= Catalog → Brands */

describe('Catalog → Brands → Edit', function () {
    beforeEach(function () {
        BrandAdminRoutes::wire($this->app);
        $this->actingAs(AdminUser::create([
            'name' => 'BH Owner', 'email' => 'bh-owner@example.test', 'password' => 'password-long-enough', 'role' => 'owner',
        ]), 'admin');
    });

    it('takes the ring colour from the logo on save, and keeps the owner\'s colour apart from it', function () {
        $logo = bhRedLogo('bh-api');

        $id = $this->postJson('/admin-api/brands', ['name' => 'Aurabh', 'logo' => $logo])
            ->assertStatus(201)->json('brand.id');

        $brand = Brand::query()->findOrFail($id);
        expect($brand->logo_color)->toMatch('/^#[0-9a-f]{6}$/')->and($brand->ring_color)->toBeNull();

        $this->putJson("/admin-api/brands/{$id}", ['name' => 'Aurabh', 'slug' => 'aurabh', 'logo' => $logo, 'ring_color' => '#0A0'])
            ->assertOk();
        expect($brand->fresh()->ring_color)->toBe('#00aa00');

        // A save that does not send the box (the banner dialog) leaves it.
        $this->putJson("/admin-api/brands/{$id}", ['name' => 'Aurabh', 'slug' => 'aurabh', 'logo' => $logo])->assertOk();
        expect($brand->fresh()->ring_color)->toBe('#00aa00');

        // Blank is "from the logo".
        $this->putJson("/admin-api/brands/{$id}", ['name' => 'Aurabh', 'slug' => 'aurabh', 'logo' => $logo, 'ring_color' => ''])->assertOk();
        expect($brand->fresh()->ring_color)->toBeNull();

        // And the screen gets both.
        $row = collect($this->getJson('/admin-api/brands')->assertOk()->json('brands'))->firstWhere('id', $id);
        expect($row)->toHaveKeys(['logo_color', 'ring_color']);
    });

    it('refuses a ring colour that is not #rgb or #rrggbb', function () {
        /*
         * MUTATION: drop the regex from the ring_color rule and these save.
         */
        $id = $this->postJson('/admin-api/brands', ['name' => 'Aurabh'])->assertStatus(201)->json('brand.id');

        foreach (['red', '#12345', '#ggg', '#fff;background:url(x)', 'rgb(1,2,3)'] as $bad) {
            $this->putJson("/admin-api/brands/{$id}", ['name' => 'Aurabh', 'slug' => 'aurabh', 'ring_color' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors('ring_color');
        }

        expect(Brand::query()->findOrFail($id)->ring_color)->toBeNull();
    });

    it('draws the Ring colour box in the brand editor and sends it with the save', function () {
        $src = file_get_contents(resource_path('views/admin/partials/brands-editor-screen.blade.php'));

        expect($src)->toContain('Ring colour (blank = from the logo)')
            ->and($src)->toContain("ring_color: val('bz-ring')");
    });
});

/* ===================================================== the quick edit's logo */

describe('the quick edit on the brand page', function () {
    beforeEach(function () {
        StorefrontAdminRoutes::wire($this->app);
        $this->actingAs(AdminUser::create([
            'name' => 'BH Owner', 'email' => 'bh-qe@example.test', 'password' => 'password-long-enough', 'role' => 'owner',
        ]), 'admin');
    });

    it('offers the logo, saves it, recolours the ring and hands back the circle the page draws', function () {
        /*
         * "provide facility to upload the brand logo" -- on the page where he
         * types the description. MUTATION: take 'logo' out of
         * StorefrontAdminController::BRAND_KEYS and the save is a 422.
         */
        $brand = bhBrand();
        $logo = bhRedLogo('bh-qe');

        $ctx = $this->getJson('/admin-api/storefront/context?path='.rawurlencode('/brands/aurabh/'))->assertOk();
        expect($ctx->json('edit.keys'))->toContain('logo')->and($ctx->json('edit.fields'))->toHaveKey('logo');

        $r = $this->postJson("/admin-api/storefront/quick-edit/brand/{$brand->id}", [
            'logo' => $logo, 'header_description' => 'Heartleaf.', 'path' => '/brands/aurabh/',
        ])->assertOk();

        $fresh = $brand->fresh();
        expect($fresh->logo)->toBe($logo)
            ->and($fresh->logo_color)->toMatch('/^#[0-9a-f]{6}$/')
            ->and($r->json('fields.logo'))->toBe($logo)
            ->and($r->json('hero.logo'))->toContain('src="'.$logo.'"')
            ->and($r->json('hero.logo'))->toContain('--brw-ring:'.$fresh->logo_color)
            ->and($r->json('hero.desc'))->toContain('Heartleaf.');

        // The preview draws the ring for an UNSAVED logo, and saves nothing.
        $other = bhLogo('bh-qe-blue', fn ($img, $s) => imagefilledellipse($img, 60, 60, 100, 100, imagecolorallocate($img, 30, 90, 220)));
        $pv = $this->postJson("/admin-api/storefront/quick-edit/brand/{$brand->id}/preview", ['logo' => $other, 'path' => '/brands/aurabh/'])->assertOk();
        expect($pv->json('hero.logo'))->toContain('src="'.$other.'"')->and($brand->fresh()->logo)->toBe($logo);
    });

    it('refuses an unsafe logo address with the brand editor\'s own sentence', function () {
        $brand = bhBrand(['logo' => '/uploads/brands/kept.png']);

        foreach (['data:image/svg+xml;base64,PHN2Zz48c2NyaXB0PmFsZXJ0KDEpPC9zY3JpcHQ+PC9zdmc+', 'javascript:alert(1)', '//evil.example/x.png', '/uploads/../../.env'] as $bad) {
            $this->postJson("/admin-api/storefront/quick-edit/brand/{$brand->id}", ['logo' => $bad, 'path' => '/brands/aurabh/'])
                ->assertStatus(422)->assertJsonValidationErrors('logo');
        }

        expect($brand->fresh()->logo)->toBe('/uploads/brands/kept.png');
    });

    it('ships the Logo field in the built bundle the page loads', function () {
        $manifest = json_decode((string) file_get_contents(base_path('public/build/manifest.json')), true);
        $built = file_get_contents(base_path('public/build/'.$manifest['resources/js/kbb/admin/storefront-admin.js']['file']));

        expect($built)->toContain('Drop the logo here or click to choose')->and($built)->toContain('kbb-qe__logo-pv');
    });
});

/* =============================================================== migration */

describe('the migration', function () {
    it('backfills every logo it can read and shrugs at one it cannot', function () {
        /*
         * A deleted file, a remote logo and a corrupt upload are a NULL colour
         * (the pink ring), never a failed update. MUTATION: drop the per-brand
         * try/catch and let colourOfFile() throw, and up() fails here.
         */
        $red = bhRedLogo('bh-mig');
        $corrupt = public_path('uploads/brands/bh-corrupt.png');
        file_put_contents($corrupt, 'not a png at all');
        $GLOBALS['bhFiles'][] = $corrupt;

        $migration = require database_path('migrations/2027_08_01_100000_add_logo_colour_to_brands.php');
        $migration->down();
        expect(Schema::hasColumn('brands', 'logo_color'))->toBeFalse();

        // Brands written while the column does not exist: the writer asks first.
        BrandLogo::forgetColumns();
        expect(BrandLogo::columnsReady())->toBeFalse();

        $ok = Brand::create(['name' => 'Red', 'slug' => 'red', 'logo' => $red]);
        $missing = Brand::create(['name' => 'Gone', 'slug' => 'gone', 'logo' => '/uploads/brands/bh-deleted-long-ago.png']);
        $remote = Brand::create(['name' => 'Far', 'slug' => 'far', 'logo' => 'https://old-shop.example/logo.png']);
        $broken = Brand::create(['name' => 'Bad', 'slug' => 'bad', 'logo' => '/uploads/brands/bh-corrupt.png']);
        $none = Brand::create(['name' => 'None', 'slug' => 'none']);

        ob_start();
        $migration->up();
        ob_end_clean();

        expect(BrandLogo::columnsReady())->toBeTrue()
            ->and($ok->fresh()->logo_color)->toMatch('/^#[0-9a-f]{6}$/')
            ->and($missing->fresh()->logo_color)->toBeNull()
            ->and($remote->fresh()->logo_color)->toBeNull()
            ->and($broken->fresh()->logo_color)->toBeNull()
            ->and($none->fresh()->logo_color)->toBeNull();

        // A second run is a no-op, not a "duplicate column".
        ob_start();
        $migration->up();
        ob_end_clean();
        expect(Schema::hasColumn('brands', 'ring_color'))->toBeTrue();
    });
});
