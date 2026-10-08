<?php

declare(strict_types=1);

/*
 * Catalog → Products → edit: replace a picture, and the old one leaves the
 * server (Lane RPL).
 *
 * The owner: "if any product has 5 pictures, and 2 of them i replaced which
 * has more text on image ... upon replacement of those specific pictures, the
 * old pictures should get deleted from the server ... and the new one will have
 * properly alt name ... the goal is to remove the texty images from the server."
 *
 * Every picture here is a REAL file made with GD under a public root of the
 * test's own, with REAL img-cache copies from ImageVariants::generate(), so
 * "removed from the server" is a claim about a disk and not about a mock.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Media;
use App\Models\Post;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Services\ImageSeo\AltText;
use App\Services\ImageSeo\ImageNamer;
use App\Services\Mail\Kit\MailImage;
use App\Services\Mail\Kit\MailKit;
use App\Services\Media\PictureTrash;
use App\Support\ImageVariants;
use App\Support\LegacyImageRedirect;
use App\Support\MediaRegistrar;
use App\Support\MediaUsageWriter;
use App\Support\Url;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductPhotoAdminRoutes;

/* ------------------------------------------------------------ fixtures */

function rplWipe(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && ! $f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }

    @rmdir($dir);
}

beforeEach(function () {
    $root = kbbTempDir().'/rpl-pub-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    app()->usePublicPath($root);
    $GLOBALS['rplRoot'] = $root;
    rplWipe(storage_path(PictureTrash::DIR));
    Cache::flush();
    ProductPhotoAdminRoutes::wire(app());
});

afterEach(function () {
    rplWipe((string) ($GLOBALS['rplRoot'] ?? ''));
    rplWipe(storage_path(PictureTrash::DIR));
    unset($GLOBALS['rplRoot']);
});

/** A real JPEG, tinted so each picture differs. */
function rplPicture(string $rel, int $tint = 0): string
{
    $img = imagecreatetruecolor(900, 900);
    imagefill($img, 0, 0, imagecolorallocate($img, (40 + $tint * 37) % 256, (120 + $tint * 53) % 256, 160));
    $file = public_path($rel);
    @mkdir(dirname($file), 0777, true);
    imagejpeg($img, $file, 85);
    imagedestroy($img);

    MediaRegistrar::record($rel);
    ImageVariants::generate('/'.$rel);

    return $file;
}

function rplUrl(string $rel): string
{
    return rtrim((string) config('app.url'), '/').'/'.$rel;
}

/** Every img-cache copy of a picture on disk now. @return list<string> */
function rplCopies(string $rel): array
{
    $out = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(public_path(ImageVariants::DIR), FilesystemIterator::SKIP_DOTS)) as $f) {
        if (str_contains($f->getPathname(), '/'.$rel)) {
            $out[] = $f->getPathname();
        }
    }

    return $out;
}

/**
 * Anua Heartleaf Toner with a main picture and three in the gallery. g3 is
 * also the gallery picture of a second product, so it is SHARED.
 *
 * @return array{p: Product, q: Product, rels: array<string, string>}
 */
function rplShop(): array
{
    $brand = Brand::create(['name' => 'Anua', 'slug' => 'rpl-anua']);
    $cat = Category::create(['name' => 'Toner', 'slug' => 'rpl-toner']);

    $rels = [
        'm' => 'uploads/products/anua-toner-main.jpg',
        'g1' => 'uploads/products/anua-toner-texture.jpg',
        'g2' => 'uploads/products/anua-toner-texty-banner.jpg',
        'g3' => 'uploads/products/anua-shared-shelf.jpg',
        'new' => 'uploads/products/anua-toner-clean-front.jpg',
    ];

    $i = 0;

    foreach ($rels as $rel) {
        rplPicture($rel, $i++);
    }

    $p = Product::create([
        'name' => 'Anua Heartleaf 77% Soothing Toner', 'slug' => 'rpl-anua-toner', 'status' => 'publish', 'is_visible' => 1,
        'brand_id' => $brand->id, 'category_id' => $cat->id, 'price' => 7600, 'type' => 'simple',
        'image' => rplUrl($rels['m']),
        'images' => [rplUrl($rels['g1']), rplUrl($rels['g2']), '/'.$rels['g3']],
        'image_alts' => [rplUrl($rels['g2']) => 'Anua toner with sale text on it'],
    ]);

    $q = Product::create([
        'name' => 'Anua Heartleaf Cleansing Oil', 'slug' => 'rpl-anua-oil', 'status' => 'publish', 'is_visible' => 1,
        'brand_id' => $brand->id, 'price' => 8400, 'type' => 'simple',
        'image' => null, 'images' => [rplUrl($rels['g3'])],
    ]);

    MediaUsageWriter::rebuild();

    return ['p' => $p->fresh(), 'q' => $q->fresh(), 'rels' => $rels];
}

function rplAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'RPL '.$role, 'email' => 'rpl-'.$role.'-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

function rplSave(Product $p, array $body, ?AdminUser $as = null): \Illuminate\Testing\TestResponse
{
    test()->actingAs($as ?? rplAdmin(), 'admin');

    return test()->postJson('/admin-api/product-editor-save/'.$p->id, $body);
}

/** Replace gallery position $at (0-based) with $newUrl, the way the editor sends it. */
function rplReplace(array $shop, int $at, string $newUrl): \Illuminate\Testing\TestResponse
{
    $p = $shop['p'];
    $images = $p->images;
    $old = $images[$at];
    $images[$at] = $newUrl;

    return rplSave($p, ['image' => $p->image, 'images' => $images, 'image_alts' => $p->image_alts ?? [], 'replaced' => [['from' => $old, 'to' => $newUrl]]]);
}

/* ------------------------------------------------- replace keeps the slot */

it('puts the replacement in the SAME position and leaves every other picture where it was', function () {
    $shop = rplShop();
    $new = rplUrl($shop['rels']['new']);

    rplReplace($shop, 1, $new)->assertOk()->assertJsonPath('ok', true);

    $p = $shop['p']->fresh();

    // The texty picture was gallery #2 (position 3 on screen); the clean one is there now.
    expect($p->images)->toBe([rplUrl($shop['rels']['g1']), $new, '/'.$shop['rels']['g3']])
        ->and($p->image)->toBe(rplUrl($shop['rels']['m']));

    // And the editor writes INTO the slot it was opened for, never appends.
    $view = (string) file_get_contents(resource_path('views/admin/partials/product-editor-screen.blade.php'));
    expect($view)->toContain('if (isMain) setMainImage(u); else model.images[at] = u;')
        ->and(substr_count($view, 'data-repl="'))->toBe(2);
    // MUTATION: change `model.images[at] = u` to `model.images.push(u)` in
    // replaceSlot() and the picker's choice lands at the end of the gallery --
    // the pin above is red, and so is the 1280px shot in docs/lane-rpl-shots.
});

/* -------------------------------------- trashed when unused, kept when shared */

it('takes a replaced picture nothing else uses off the server, with all its copies, into the trash', function () {
    $shop = rplShop();
    $g2 = $shop['rels']['g2'];
    expect(rplCopies($g2))->not->toBe([]);

    $r = rplReplace($shop, 1, rplUrl($shop['rels']['new']))->assertOk();

    // Gone from the web root, copies and all; the Library row with it.
    expect(is_file(public_path($g2)))->toBeFalse()
        ->and(rplCopies($g2))->toBe([])
        ->and(Media::query()->where('path', $g2)->exists())->toBeFalse();

    // In the trash, outside the web root, for 30 days.
    $row = DB::table('picture_trash')->where('old_path', $g2)->first();
    expect($row->status)->toBe('trashed')
        ->and($row->replacement_path)->toBe($shop['rels']['new'])
        ->and($row->slot)->toBe('gallery')->and((int) $row->position)->toBe(1)
        ->and(substr((string) $row->purge_after, 0, 10))->toBe(now()->addDays(30)->toDateString())
        ->and(is_file(storage_path(PictureTrash::DIR.'/'.$row->id.'/'.basename($g2))))->toBeTrue();

    // The editor's note: replaced, Undo, and the date it goes for good.
    $r->assertJsonPath('photos.trashed.0.old', basename($g2))
        ->assertJsonPath('photos.trashed.0.replacement', rplUrl($shop['rels']['new']))
        ->assertJsonPath('product.picture_trash.0.id', (int) $row->id);
    // MUTATION: return null at the top of PictureTrash::trashOne() and the
    // file is still in the web root -- the first expectation is red.
});

it('KEEPS a replaced picture another product still shows, and says who', function () {
    $shop = rplShop();
    $g3 = $shop['rels']['g3'];

    $r = rplReplace($shop, 2, rplUrl($shop['rels']['new']))->assertOk();

    expect(is_file(public_path($g3)))->toBeTrue()
        ->and(rplCopies($g3))->not->toBe([])
        ->and(DB::table('picture_trash')->where('old_path', $g3)->exists())->toBeFalse();

    $r->assertJsonPath('photos.kept.0.old', basename($g3));
    expect(implode(' ', $r->json('photos.kept.0.by')))->toContain('product “Anua Heartleaf Cleansing Oil” (gallery)');
    // MUTATION: make PictureTrash::usedBy() return [] and the shared picture
    // leaves the server under the second product -- red.
});

it('keeps it when a variant, a blog post or a site setting is the other user', function (string $where) {
    $shop = rplShop();
    $g2 = $shop['rels']['g2'];

    match ($where) {
        'variant' => ProductVariant::create(['product_id' => $shop['q']->id, 'sku' => 'RPL-V', 'price' => 100, 'image' => rplUrl($g2)]),
        'post' => Post::create(['title' => 'Toner routine', 'slug' => 'rpl-routine', 'status' => 'publish', 'body' => '<p><img src="/'.$g2.'"></p>']),
        'setting' => Setting::query()->updateOrCreate(['key' => 'og_default_image'], ['value' => rplUrl($g2)]),
    };

    $r = rplReplace($shop, 1, rplUrl($shop['rels']['new']))->assertOk();

    expect(is_file(public_path($g2)))->toBeTrue();
    expect(implode(' ', $r->json('photos.kept.0.by')))->toContain(match ($where) {
        'variant' => 'product variant', 'post' => 'blog post “Toner routine”', 'setting' => 'site setting “og_default_image”',
    });
})->with(['variant', 'post', 'setting']);

it('only ever cleans up THIS product\'s own pictures: a hint naming another product\'s picture does nothing', function () {
    $shop = rplShop();
    $g2 = $shop['rels']['g2'];

    // Saving the SECOND product with a forged hint at the first one's picture.
    rplSave($shop['q'], ['images' => [rplUrl($shop['rels']['new'])], 'replaced' => [['from' => rplUrl($g2), 'to' => rplUrl($shop['rels']['new'])]]])
        ->assertOk();

    expect(is_file(public_path($g2)))->toBeTrue()
        ->and(DB::table('picture_trash')->where('old_path', $g2)->exists())->toBeFalse();
    // MUTATION: build PictureTrash::afterSave()'s candidates from $hints
    // instead of from $before and the first product's picture is trashed -- red.
});

/* ------------------------------------------------------------------ undo */

it('Undo puts the picture back on the server, in its slot, with its description', function () {
    $shop = rplShop();
    $g2 = $shop['rels']['g2'];
    $new = rplUrl($shop['rels']['new']);
    rplReplace($shop, 1, $new)->assertOk();
    $id = (int) DB::table('picture_trash')->where('old_path', $g2)->value('id');

    test()->postJson('/admin-api/product-editor-photo-undo/'.$shop['p']->id, ['trash_id' => $id])
        ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('product.picture_trash', []);

    $p = $shop['p']->fresh();
    expect(is_file(public_path($g2)))->toBeTrue()
        ->and(rplCopies($g2))->not->toBe([])
        ->and(Media::query()->where('path', $g2)->exists())->toBeTrue()
        ->and($p->images[1])->toBe(rplUrl($g2))
        ->and($p->image_alts[rplUrl($g2)] ?? null)->toBe('Anua toner with sale text on it')
        ->and(DB::table('picture_trash')->where('id', $id)->value('status'))->toBe('restored')
        // and the 301 is retired: the address is the picture again.
        ->and(LegacyImageRedirect::targetFor($g2))->toBeNull();

    // Twice is refused, not repeated.
    test()->postJson('/admin-api/product-editor-photo-undo/'.$shop['p']->id, ['trash_id' => $id])->assertStatus(409);
    // MUTATION: drop the move in PictureTrash::putBack() and the file is not back -- red.
});

it('refuses an Undo addressed through another product', function () {
    $shop = rplShop();
    rplReplace($shop, 1, rplUrl($shop['rels']['new']))->assertOk();
    $id = (int) DB::table('picture_trash')->value('id');

    test()->postJson('/admin-api/product-editor-photo-undo/'.$shop['q']->id, ['trash_id' => $id])->assertStatus(409);

    expect(is_file(public_path($shop['rels']['g2'])))->toBeFalse()
        ->and(DB::table('picture_trash')->where('id', $id)->value('status'))->toBe('trashed');
});

/* ----------------------------------------------------------------- purge */

it('deletes the file for good after 30 days, and not a day before', function () {
    $shop = rplShop();
    rplReplace($shop, 1, rplUrl($shop['rels']['new']))->assertOk();
    $row = DB::table('picture_trash')->first();
    $file = storage_path(PictureTrash::DIR.'/'.$row->id.'/'.basename($row->old_path));

    $this->travel(29)->days();
    expect(PictureTrash::purgeDue())->toBe(['purged' => 0, 'restored' => 0])
        ->and(is_file($file))->toBeTrue();

    $this->travel(2)->days();
    $this->artisan('kbb:picture-trash-purge')->assertSuccessful();

    expect(is_file($file))->toBeFalse()
        ->and(is_dir(dirname($file)))->toBeFalse()
        ->and(DB::table('picture_trash')->where('id', $row->id)->value('status'))->toBe('purged');
    // MUTATION: drop `->where('purge_after', '<=', now())` from purgeDue() and
    // the day-29 run deletes it -- red.
});

it('never purges a picture that picked up a user while it was in the trash: it goes back instead', function () {
    $shop = rplShop();
    $g2 = $shop['rels']['g2'];
    rplReplace($shop, 1, rplUrl($shop['rels']['new']))->assertOk();

    // Somebody pasted the old address into a blog post meanwhile.
    Post::create(['title' => 'Old toner photo', 'slug' => 'rpl-old', 'status' => 'publish', 'body' => '<img src="'.rplUrl($g2).'">']);

    $this->travel(31)->days();

    expect(PictureTrash::purgeDue())->toBe(['purged' => 0, 'restored' => 1])
        ->and(is_file(public_path($g2)))->toBeTrue()
        ->and(DB::table('picture_trash')->value('note'))->toContain('blog post “Old toner photo”');
    // MUTATION: skip the usedBy() check in purgeDue() and the post's picture is deleted -- red.
});

it('purges lazily when the Media Library is opened, at most every six hours, for a shop with no cron', function () {
    $shop = rplShop();
    rplReplace($shop, 1, rplUrl($shop['rels']['new']))->assertOk();
    $this->travel(31)->days();

    test()->getJson('/admin-api/media')->assertOk();

    expect(DB::table('picture_trash')->value('status'))->toBe('purged');
});

/* ------------------------------------------------------- 301 and 410 */

it('answers the old address, and its phone copy, with a 301 to the picture that took its slot', function () {
    $shop = rplShop();
    $g2 = $shop['rels']['g2'];
    $new = $shop['rels']['new'];
    rplReplace($shop, 1, rplUrl($new))->assertOk();

    test()->get('/'.$g2)->assertStatus(301)->assertHeader('Location', 'http://localhost/'.$new);

    $copy = ImageVariants::DIR.'/400/'.$g2;
    expect(is_file(public_path(ImageVariants::DIR.'/400/'.$new)))->toBeTrue();
    test()->get('/'.$copy)->assertStatus(301)->assertHeader('Location', 'http://localhost/'.ImageVariants::DIR.'/400/'.$new);

    // Still after the purge: the redirect is the ledger, not the trash file.
    $this->travel(31)->days();
    PictureTrash::purgeDue();
    test()->get('/'.$g2)->assertStatus(301)->assertHeader('Location', 'http://localhost/'.$new);
    // MUTATION: drop the image_renames insert in PictureTrash::trashOne() and
    // the old address is a 404 -- red.
});

it('answers a removed picture with no replacement 404 while Undo is possible and 410 Gone once purged', function () {
    $shop = rplShop();
    $g1 = $shop['rels']['g1'];
    $p = $shop['p'];

    // The ✕ on gallery #1, then Update.
    rplSave($p, ['image' => $p->image, 'images' => array_slice($p->images, 1)])->assertOk()
        ->assertJsonPath('photos.trashed.0.replacement', null);

    test()->get('/'.$g1)->assertNotFound();

    $this->travel(31)->days();
    PictureTrash::purgeDue();

    test()->get('/'.$g1)->assertStatus(410);
    test()->get('/'.ImageVariants::DIR.'/400/'.$g1)->assertStatus(410);
    // A different missing picture is still a plain 404, not a 410.
    test()->get('/uploads/products/never-existed.jpg')->assertNotFound();
    // MUTATION: remove the PictureTrash::respond() line from the 404 handler
    // in AppServiceProvider and the purged address is a 404 -- red.
});

/* ------------------------------------------------- a new picture's name and alt */

it('gives a new picture with a generic upload name its Image SEO name and its ALT, and leaves typed ALT alone', function () {
    $shop = rplShop();
    $p = $shop['p'];
    $generic = 'uploads/products/img-4821.jpg';
    $owners = 'uploads/products/anua-toner-in-hand.jpg';
    rplPicture($generic, 7);
    rplPicture($owners, 8);

    $images = array_merge($p->images, [rplUrl($generic), rplUrl($owners)]);
    $alts = ($p->image_alts ?? []) + [rplUrl($owners) => 'Toner held in a hand'];

    $r = rplSave($p, ['image' => $p->image, 'images' => $images, 'image_alts' => $alts])->assertOk();

    $p = $p->fresh();
    $renamed = $p->images[3];
    $expected = ImageNamer::names('Anua', 'Anua Heartleaf 77% Soothing Toner', 6)[4];

    // Renamed to the slot's variation name; the generic file is gone and 301s to it.
    expect(basename($renamed))->toBe($expected.'.jpg')
        ->and(is_file(public_path('uploads/products/'.$expected.'.jpg')))->toBeTrue()
        ->and(is_file(public_path($generic)))->toBeFalse()
        ->and(is_file(public_path(ImageVariants::DIR.'/400/uploads/products/'.$expected.'.jpg')))->toBeTrue()
        ->and(LegacyImageRedirect::targetFor($generic))->toBe('uploads/products/'.$expected.'.jpg');

    // ALT proposed for ITS position (picture 5 of 6); the typed one untouched;
    // and the owner's own file name is not renamed.
    $proposed = AltText::propose('Anua', 'Anua Heartleaf 77% Soothing Toner', 'Toner', 6);
    expect($p->image_alts[$renamed] ?? null)->toBe($proposed[4])
        ->and($p->image_alts[rplUrl($owners)])->toBe('Toner held in a hand')
        ->and($p->images[4])->toBe(rplUrl($owners));

    $r->assertJsonPath('photos.renamed.0', $expected.'.jpg');
    // MUTATION: drop the namePictures() call from save() and the file keeps
    // img-4821.jpg -- red; drop proposeAlts() and the ALT is missing -- red.
});

/* ----------------------------------------------------------- security */

it('refuses to move anything outside the upload folders, however the path is spelled', function () {
    $shop = rplShop();
    $p = $shop['p'];
    file_put_contents(public_path('secret.jpg'), 'x');
    @mkdir(public_path('uploads/products'), 0777, true);

    // A traversal written straight into the row, the way a hand-edited database would hold it.
    DB::table('products')->where('id', $p->id)->update(['images' => json_encode(['/uploads/products/../../secret.jpg', '/uploads/../secret.jpg'])]);
    $p = $p->fresh();

    rplSave($p, ['images' => [rplUrl($shop['rels']['new'])], 'replaced' => [['from' => '/uploads/products/../../secret.jpg', 'to' => rplUrl($shop['rels']['new'])]]])
        ->assertOk();

    expect(is_file(public_path('secret.jpg')))->toBeTrue()
        ->and(DB::table('picture_trash')->count())->toBe(0);

    // And a tampered trash row cannot point the purge or Undo outside the trash root.
    $canary = storage_path('app/rpl-canary.txt');
    file_put_contents($canary, 'keep me');
    DB::table('picture_trash')->insert([
        'product_id' => $p->id, 'slot' => 'gallery', 'position' => 0, 'old_path' => '../../.env', 'old_url' => '/x.jpg',
        'files' => json_encode([['rel' => '../../.env', 'trash' => '1/../../rpl-canary.txt']]),
        'status' => 'trashed', 'purge_after' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $id = (int) DB::table('picture_trash')->value('id');

    // A well-shaped name that is a symlink out of the trash root: the shape
    // passes, the realpath containment must not.
    $second = storage_path('app/rpl-canary-2.txt');
    file_put_contents($second, 'keep me too');
    @mkdir(storage_path(PictureTrash::DIR.'/99'), 0777, true);
    symlink($second, storage_path(PictureTrash::DIR.'/99/linked.jpg'));
    DB::table('picture_trash')->insert([
        'product_id' => $p->id, 'slot' => 'gallery', 'position' => 0, 'old_path' => 'uploads/products/linked.jpg', 'old_url' => '/x.jpg',
        'files' => json_encode([['rel' => 'uploads/products/linked.jpg', 'trash' => '99/linked.jpg']]),
        'status' => 'trashed', 'purge_after' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    PictureTrash::purgeDue();
    test()->postJson('/admin-api/product-editor-photo-undo/'.$p->id, ['trash_id' => $id]);

    expect(is_file($canary))->toBeTrue()
        ->and(is_file($second))->toBeTrue()
        ->and(is_file(public_path('uploads/products/linked.jpg')))->toBeFalse();
    @unlink($canary);
    @unlink($second);
    // MUTATION: make PictureTrash::trashFile() return the realpath without the
    // `str_starts_with($file, $root.'/')` containment and the purge unlinks
    // the symlinked canary -- red. ("..": refused by the shape check AND the
    // containment, so either alone holds.)
});

it('lets only an admin with the product edit capability Undo', function () {
    $shop = rplShop();
    rplReplace($shop, 1, rplUrl($shop['rels']['new']))->assertOk();
    $id = (int) DB::table('picture_trash')->value('id');
    $url = '/admin-api/product-editor-photo-undo/'.$shop['p']->id;

    auth('admin')->logout();
    test()->postJson($url, ['trash_id' => $id])->assertStatus(401);

    test()->actingAs(rplAdmin('support'), 'admin');
    test()->postJson($url, ['trash_id' => $id])->assertStatus(403);

    expect(ProductPhotoAdminRoutes::registered())->toHaveCount(1)
        ->and(\App\Support\AdminCapabilities::for(ProductPhotoAdminRoutes::registered()[0]))->toBe('catalog.manage')
        ->and(DB::table('picture_trash')->where('id', $id)->value('status'))->toBe('trashed');

    test()->actingAs(rplAdmin('editor'), 'admin');
    test()->postJson($url, ['trash_id' => $id])->assertOk();
    // MUTATION: map the route to null in AdminCapabilities and `support` --
    // fail-closed for non-owners -- still 403, but an `editor` is refused too: red.
});

/* ------------------------------------------- emails and old orders */

it('keeps old order emails showing a picture: the product\'s current one, or the replacement', function () {
    config(['app.url' => 'https://extrabeauty.ae', 'kbb.base_path' => '']);
    Url::forgetBase();
    MailImage::resetBudget();
    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => 'https://extrabeauty.ae']);
    Setting::flushMap();

    $shop = rplShop();
    $p = $shop['p'];
    $old = $shop['rels']['m'];
    $new = $shop['rels']['new'];

    // An email sent before the change printed the old main picture's JPEG copy.
    $sent = MailKit::image(rplUrl($old), 200);
    expect($sent)->toContain('/img-cache/mail/');
    $sentPath = substr((string) parse_url($sent, PHP_URL_PATH), 1);

    // The owner replaces the MAIN image (the Replace button -- no hint needed).
    rplSave($p, ['image' => rplUrl($new), 'images' => $p->images])->assertOk();
    expect(is_file(public_path($old)))->toBeFalse();

    // A new email for that old order prints the product's current picture...
    $items = \App\Services\Mail\Kit\KitProducts::forIds([$p->id]);
    expect($items[$p->id]['img'])->toContain(basename($new));

    // ...a stored old address still resolves to a picture, not the placeholder...
    expect(MailImage::src(rplUrl($old), 128))->toContain(basename($new));

    // ...and the JPEG already sitting in an inbox 301s to the new one.
    test()->get('/'.$sentPath)->assertStatus(301);
    expect((string) test()->get('/'.$sentPath)->headers->get('Location'))->toContain(basename($new));
    // MUTATION: drop the `mail` branch in LegacyImageRedirect::copyTarget()
    // and the inbox copy is a 404 -- red.
});
