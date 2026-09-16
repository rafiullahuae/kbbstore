<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Media;
use App\Models\Product;
use App\Models\Setting;
use App\Support\MediaUsage;

/**
 * An image used ONLY as a share image must not read as unused.
 *
 * The Media Library's delete guard asks MediaUsage whether anything is using a
 * file. That derivation read products.image, products.images, brands.logo and
 * categories.image — and nothing else. So an image set only as a product's
 * share image, or as the shop's default share image, or as the logo in its
 * Organization schema, was reported as unused and the guard let it be deleted.
 *
 * Seo.php publishes all three: og:image at line 161, the settings fallback at
 * 139 and 326, and the organisation logo at 369. Deleting one breaks every
 * Facebook and WhatsApp preview that depends on it — and nothing on screen
 * says so. The image simply stops loading in share cards, which is the kind of
 * breakage nobody notices for weeks.
 *
 * Found by Lane BF while building media_usages, and reported rather than fixed
 * because closing it meant changing the derivation.
 */
function siuAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Share Owner',
        'email' => 'share-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function siuMedia(string $file): Media
{
    return Media::create([
        'filename' => $file,
        'original_name' => $file,
        'path' => 'uploads/products/' . $file,
        'mime' => 'image/png',
        'size' => 2048,
        'width' => 800,
        'height' => 600,
        'alt' => '',
    ]);
}

it('counts a product share image as a use', function () {
    $media = siuMedia('share-only-' . uniqid() . '.png');

    Product::create([
        'slug' => 'share-' . uniqid(),
        'name' => 'Share Product',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 50.00,
        'stock_status' => 'instock',
        // Deliberately NO image and NO gallery: the share image is the only
        // reference, which is precisely the case that used to read as unused.
        'image' => null,
        'images' => [],
        'seo' => ['og_image' => '/' . $media->path],
    ]);

    $usage = MediaUsage::verify(MediaUsage::index(), $media->filename, $media->path);

    expect($usage)->not->toBe([]);
    expect($usage[0]['type'])->toBe('product');
    expect($usage[0]['field'])->toBe('Share image');
});

it('counts the site-wide share image and organisation logo as uses', function () {
    $share = siuMedia('site-share-' . uniqid() . '.png');
    $logo = siuMedia('site-logo-' . uniqid() . '.png');

    app(\App\Services\SettingsService::class)->set('og_default_image', '/' . $share->path);
    app(\App\Services\SettingsService::class)->set('org_logo', '/' . $logo->path);
    Setting::flushMap();

    $index = MediaUsage::index();

    foreach ([[$share, 'Default share image'], [$logo, 'Organisation logo']] as [$m, $label]) {
        $usage = MediaUsage::verify($index, $m->filename, $m->path);

        expect($usage)->not->toBe([], $label . ' still reads as unused');
        expect($usage[0]['type'])->toBe('site');
        expect($usage[0]['field'])->toBe($label);
    }
});

it('refuses to delete an image that is only a share image', function () {
    /*
     * The assertion that actually protects the owner. The two above prove the
     * derivation sees it; this proves the guard acts on it.
     */
    $media = siuMedia('guarded-share-' . uniqid() . '.png');

    Product::create([
        'slug' => 'guarded-' . uniqid(),
        'name' => 'Guarded Product',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 50.00,
        'stock_status' => 'instock',
        'image' => null,
        'images' => [],
        'seo' => ['og_image' => '/' . $media->path],
    ]);

    \Tests\Support\MediaLibraryRoutes::wire(app());

    test()->actingAs(siuAdmin(), 'admin')
        ->deleteJson('/admin-api/media/' . $media->id)
        ->assertStatus(409);

    expect(Media::find($media->id))->not->toBeNull('the image was deleted anyway');
});

it('still reports a genuinely unused image as unused', function () {
    /*
     * The other half. A guard widened until it refuses everything protects
     * nothing and makes the library impossible to tidy — and "unused" is the
     * line the owner relies on to know what is safe to remove.
     */
    $media = siuMedia('truly-unused-' . uniqid() . '.png');

    expect(MediaUsage::verify(MediaUsage::index(), $media->filename, $media->path))->toBe([]);
});
