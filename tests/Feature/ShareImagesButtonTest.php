<?php

declare(strict_types=1);

/**
 * "Make all share pictures now" on Appearance → Product page →
 * Share · Link preview card.                                    (2.60.367)
 *
 * THE DEFECT ON THE LIVE SHOP: `php artisan kbb:share-images` over SSH as the
 * server's master user answered "0 made … 770 skipped" — "could not write" and
 * "cache directory not writable" — because the share-picture folder belongs to
 * the website's own user. The owner asked for it "inside admin … make sure it
 * don't disturb anything else". The button runs the same work AS THE WEBSITE.
 *
 * MUTATION NOTES, RUN:
 *   · drop the `['POST', 'admin-api/share-images', …]` rule → the support case
 *     is RED (an unmapped admin route fails closed for every role but owner,
 *     so the editor case goes red too).
 *   · `->limit(self::BATCH)` removed → the slicing case is RED (done on call 1).
 */

use App\Http\Controllers\Admin\ShareImagesApiController;
use App\Models\AdminUser;
use App\Models\Product;
use App\Support\AdminCapabilities;
use App\Support\ShareImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

function sibAdmin(string $role): AdminUser
{
    return AdminUser::create(['name' => 'SIB '.$role, 'email' => 'sib-'.$role.'-'.Str::random(6).'@example.test',
        'password' => Hash::make('secret-secret'), 'role' => $role]);
}

/** @return list<string> the root-relative images made */
function sibProducts(int $n): array
{
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $rel = 'media/products/sib-'.Str::lower(Str::random(8)).'.webp';
        @mkdir(\dirname(public_path($rel)), 0755, true);
        $im = imagecreatetruecolor(60, 60);
        imagewebp($im, public_path($rel), 80);
        imagedestroy($im);
        Product::create(['slug' => 'sib-'.Str::lower(Str::random(8)), 'name' => 'SIB '.$i, 'type' => 'simple',
            'status' => 'publish', 'is_visible' => true, 'price' => 1000, 'stock_status' => 'instock', 'image' => '/'.$rel]);
        $out[] = '/'.$rel;
    }

    return $out;
}

function sibCleanup(array $images): void
{
    foreach ($images as $img) {
        ShareImage::forget($img);
        @unlink(public_path(ltrim($img, '/')));
    }
}

it('makes every product’s share picture in slices, and changes nothing else', function () {
    $images = sibProducts(ShareImagesApiController::BATCH + 3);
    $this->actingAs(sibAdmin('owner'), 'admin');
    $settings = DB::table('settings')->count();
    $stamps = Product::query()->orderBy('id')->pluck('updated_at', 'id')->all();

    try {
        $first = $this->postJson('/admin-api/share-images', ['after' => 0])->assertOk()->json();
        expect($first['ok'])->toBeTrue()
            ->and($first['made'])->toBe(ShareImagesApiController::BATCH)
            ->and($first['done'])->toBeFalse()
            ->and($first['total'])->toBe(ShareImagesApiController::BATCH + 3);

        $second = $this->postJson('/admin-api/share-images', ['after' => $first['after']])->assertOk()->json();
        expect($second['made'])->toBe(3)->and($second['done'])->toBeTrue()->and($second['total'])->toBeNull();

        foreach ($images as $img) {
            expect(ShareImage::find($img))->not->toBeNull();
        }

        // Again: everything already up to date, nothing made.
        $again = $this->postJson('/admin-api/share-images', ['after' => 0])->assertOk()->json();
        expect($again['made'])->toBe(0)->and($again['fresh'])->toBe(ShareImagesApiController::BATCH);

        // "don't disturb anything else": no setting written, no product touched.
        expect(DB::table('settings')->count())->toBe($settings)
            ->and(Product::query()->orderBy('id')->pluck('updated_at', 'id')->all())->toEqual($stamps);
    } finally {
        sibCleanup($images);
    }
});

it('has its own capability, open to the Product page roles and closed to support', function () {
    expect(AdminCapabilities::forPath('POST', 'admin-api/share-images'))->toBe('shareimages.make');

    $this->actingAs(sibAdmin('support'), 'admin');
    $this->postJson('/admin-api/share-images', ['after' => 0])->assertForbidden();

    $this->actingAs(sibAdmin('editor'), 'admin');
    $this->postJson('/admin-api/share-images', ['after' => 0])->assertOk();
});

it('refuses a visitor who is not signed in', function () {
    expect($this->postJson('/admin-api/share-images', ['after' => 0])->status())->toBeIn([401, 403, 419]);
});

it('is wired once and drawn on the share card tab', function () {
    $web = (string) file_get_contents(base_path('routes/web.php'));
    expect(substr_count($web, "require __DIR__.'/share-images-admin.php';"))->toBe(1);

    $screen = (string) file_get_contents(resource_path('views/admin/partials/product-trust-share-screen.blade.php'));
    expect(substr_count($screen, 'id="ptsShareMake"'))->toBe(1)
        ->and($screen)->toContain("tab.key === 'ts_card' ? '<div class=\"mmrow\"")
        ->and($screen)->toContain("replace(/\\/product-page$/, '/share-images')");
});
