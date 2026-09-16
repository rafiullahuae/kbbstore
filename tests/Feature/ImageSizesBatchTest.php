<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Support\AdminCapabilities;
use App\Support\ImageVariants;
use Illuminate\Support\Facades\Route;

/**
 * Lane DK — the batch that gives the photographs already in the shop their
 * smaller copies, and the guard around it.
 *
 * WHY A BATCH AND NOT A COMMAND. There is no shell on this host, so an Artisan
 * command is a backlog nobody can drain; there is no queue worker, so a job is
 * a resize inside whatever request dispatched it. What is left is a screen the
 * owner clicks, which is what this covers. The full argument, and what else was
 * weighed, is in ImageSizesApiController's own header.
 *
 * WHY THE ROUTE IS MOUNTED HERE. CLAUDE.md forbids this lane from editing
 * routes/web.php, so routes/image-sizes-admin.php ships for the integrator to
 * require inside the existing admin-api group. Every test below mounts it
 * exactly as that file's header says it must be mounted — `web`, `auth:admin`,
 * NoStoreAdminApi, prefix admin-api — so these assertions are a test OF the
 * wiring instruction and keep passing unchanged once it is followed. Laravel's
 * route collection is keyed on method and URI, so this replaces rather than
 * duplicates the mounted one.
 */
function isMount(): void
{
    /*
     * One middleware() call, not two: RouteRegistrar::middleware() REPLACES
     * the attribute rather than appending to it, which is how a lane once
     * dropped auth:admin off its own routes while believing it had added to
     * them.
     */
    Route::middleware(['web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class])
        ->prefix('admin-api')
        ->group(base_path('routes/image-sizes-admin.php'));
}

function isOwner(): AdminUser
{
    return AdminUser::create([
        'name' => 'Owner',
        'email' => 'dk-batch-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** A 1000px JPEG in the test's own public root, and a product wearing it. */
function isPhotographedProduct(string $name): Product
{
    $path = public_path('uploads/products/'.$name);
    @mkdir(dirname($path), 0755, true);

    $im = imagecreatetruecolor(1000, 1000);

    for ($y = 0; $y < 1000; $y += 4) {
        imagefilledrectangle($im, 0, $y, 1000, $y + 3, (int) imagecolorallocate($im, ($y * 7) % 255, ($y * 13) % 255, ($y * 3) % 255));
    }

    imagejpeg($im, $path, 90);
    imagedestroy($im);

    return Product::query()->firstOrFail()->forceFill(['image' => '/uploads/products/'.$name]);
}

beforeEach(function () {
    if (! ImageVariants::available()) {
        test()->markTestSkipped('this PHP has no GD, which is the thing under test');
    }
});

/* ----------------------------------------------------------------- the guard */

it('refuses the batch to a caller who is not signed in', function () {
    isMount();

    $status = test()->postJson('/admin-api/media/image-sizes/run')->status();

    expect($status)->not->toBe(200);

    /*
     * The endpoint writes files into the owner's web root and spends his
     * host's CPU doing it. Anonymous, it is a loop somebody else can run at
     * his expense — which is why it is inside the admin-api group and not in
     * routes/api.php, where CLAUDE.md records that everything is public.
     */
    expect(test()->getJson('/admin-api/media/image-sizes')->status())->not->toBe(200);
});

it('is covered by the capability map, like every other admin route', function () {
    isMount();

    // The map is closed by default, so an unmapped route is refused to
    // everyone but the owner and AdminCapabilityMapTest goes red. This asserts
    // the intent rather than that accident: these paths are media work, and
    // they resolve to the capability the Media Library already uses.
    expect(AdminCapabilities::forPath('POST', 'admin-api/media/image-sizes/run'))
        ->toBe('content.manage');
    expect(AdminCapabilities::forPath('GET', 'admin-api/media/image-sizes'))
        ->toBe('content.manage');
});

/* ------------------------------------------------------------------ the work */

it('makes the copies for a photograph that was already in the shop', function () {
    isMount();
    test()->seed(\Database\Seeders\DatabaseSeeder::class);

    Product::query()->update(['image' => null]);
    isPhotographedProduct('old.jpg')->save();

    $body = test()->actingAs(isOwner(), 'admin')
        ->postJson('/admin-api/media/image-sizes/run')
        ->assertOk()
        ->json();

    expect($body['made'])->toBe(2);
    expect($body['sized'])->toBe(1);
    expect($body['done'])->toBeTrue('one photograph did not exhaust the catalogue');

    foreach (ImageVariants::WIDTHS as $width) {
        expect(is_file(public_path(ImageVariants::DIR.'/'.$width.'/uploads/products/old.jpg')))
            ->toBeTrue('no '.$width.'px copy was written');
    }
});

it('can be run again over a finished catalogue without redoing the work', function () {
    isMount();
    test()->seed(\Database\Seeders\DatabaseSeeder::class);

    Product::query()->update(['image' => null]);
    isPhotographedProduct('old.jpg')->save();

    $owner = isOwner();
    test()->actingAs($owner, 'admin')->postJson('/admin-api/media/image-sizes/run')->assertOk();

    // The owner clicks twice, or reloads, or the tab was left open overnight.
    // Nothing is decoded a second time.
    $again = test()->actingAs($owner, 'admin')
        ->postJson('/admin-api/media/image-sizes/run')
        ->assertOk()
        ->json();

    expect($again['made'])->toBe(0);
    expect($again['sized'])->toBe(0);
    expect($again['done'])->toBeTrue();
});

it('hands back a cursor that moves the next batch forward', function () {
    isMount();
    test()->seed(\Database\Seeders\DatabaseSeeder::class);

    Product::query()->update(['image' => null]);

    $products = Product::query()->orderBy('id')->take(2)->get();
    $products[0]->forceFill(['image' => '/uploads/products/aaa.jpg'])->save();
    $products[1]->forceFill(['image' => '/uploads/products/zzz.jpg'])->save();
    isPhotographedProduct('aaa.jpg');
    isPhotographedProduct('zzz.jpg');

    $owner = isOwner();

    $first = test()->actingAs($owner, 'admin')
        ->postJson('/admin-api/media/image-sizes/run')
        ->assertOk()
        ->json();

    /*
     * The cursor is the image reference, not an offset. An offset shifts under
     * an import that adds a product mid-run, and whatever crossed the boundary
     * is skipped without anything noticing.
     */
    expect($first['cursor'])->toBe('/uploads/products/zzz.jpg');

    // Handing the cursor back walks past everything already done, which is what
    // makes a long run a sequence of short requests rather than one that dies.
    $second = test()->actingAs($owner, 'admin')
        ->postJson('/admin-api/media/image-sizes/run', ['after' => '/uploads/products/aaa.jpg'])
        ->assertOk()
        ->json();

    expect($second['examined'])->toBe(1, 'the cursor did not skip the photograph before it');
});

it('counts what is left, and counts what can never be done separately', function () {
    isMount();
    test()->seed(\Database\Seeders\DatabaseSeeder::class);

    Product::query()->update(['image' => null]);

    $products = Product::query()->orderBy('id')->take(2)->get();
    $products[0]->forceFill(['image' => '/uploads/products/mine.jpg'])->save();
    $products[1]->forceFill(['image' => 'https://cdn.example.com/theirs.jpg'])->save();
    isPhotographedProduct('mine.jpg');

    $owner = isOwner();

    $before = test()->actingAs($owner, 'admin')->getJson('/admin-api/media/image-sizes')->assertOk()->json();

    expect($before['total'])->toBe(2);
    expect($before['remaining'])->toBe(1);
    /*
     * The photograph on the other domain is not work. Counting it as remaining
     * would leave the screen reporting a backlog that never goes down however
     * long the owner leaves the tab open, which is the shape of lie this
     * project has repeatedly paid for elsewhere.
     */
    expect($before['not_ours'])->toBe(1);
    expect($before['done'])->toBe(0);

    test()->actingAs($owner, 'admin')->postJson('/admin-api/media/image-sizes/run')->assertOk();

    $after = test()->actingAs($owner, 'admin')->getJson('/admin-api/media/image-sizes')->assertOk()->json();

    expect($after['remaining'])->toBe(0);
    expect($after['done'])->toBe(1);
    expect($after['not_ours'])->toBe(1);
});

it('says so plainly when this PHP cannot resize anything', function () {
    // Not reachable on a box with GD, and that is the point of asserting the
    // shape rather than the behaviour: the endpoint's contract is that it
    // refuses with a message the owner can act on rather than reporting a
    // successful run that wrote nothing. CLAUDE.md's standing complaint about
    // this codebase is filters that match no rows and report success.
    $source = (string) file_get_contents(app_path('Http/Controllers/Admin/ImageSizesApiController.php'));
    $source = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

    expect(str_contains($source, 'ImageVariants::available()'))
        ->toBeTrue('the batch no longer checks whether this PHP can resize at all');
    expect(preg_match('/\b422\b/', $source))
        ->toBe(1, 'a server that cannot resize must refuse, not answer 200 having done nothing');
});

/* --------------------------------------------------------------- packaging */

it('keeps the generated copies out of every package', function () {
    /*
     * They are gitignored, so --since can never select one; this is the second
     * lock, for --file. It matters because a package that CARRIES a generated
     * file is a package that can DELETE it on the next install, and deleting
     * files the product pages depend on is exactly what the withdrawn packages
     * 2.60.102-.106 did to this shop.
     */
    $never = (new ReflectionClass(\App\Console\Commands\BuildPackage::class))->getConstant('NEVER_SHIP');

    expect(in_array('public/'.ImageVariants::DIR.'/', $never, true))
        ->toBeTrue('public/'.ImageVariants::DIR.'/ is shippable again');

    $ignored = (string) file_get_contents(base_path('.gitignore'));

    expect(str_contains($ignored, '/public/'.ImageVariants::DIR.'/'))
        ->toBeTrue('the generated copies are no longer gitignored, so they can be committed as if they were source');
});
