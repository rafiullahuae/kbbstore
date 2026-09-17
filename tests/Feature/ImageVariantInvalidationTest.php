<?php

declare(strict_types=1);

/**
 * Lane EI — the variant cache's missing half: getting rid of one.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * App\Support\ImageVariants could make a resized copy and could not remove one.
 * generate() skips a width already on disk — correct, and what makes the batch
 * safe to re-run — so nothing in the application had any path to deleting a
 * variant, while the ORIGINALS had one: Media Library → delete unlinks the file
 * under public/uploads/ and forgets the row.
 *
 * The copies stayed. They are gitignored and on BuildPackage::NEVER_SHIP, so no
 * package could ever clear them; the host has no shell; no screen lists the
 * directory. An owner deleting a photograph had no way, from anywhere, to
 * remove the two files it left behind.
 *
 * The size of it, measured on real files rather than guessed: 121KB of variants
 * per 1000x1000 JPEG, 1.6MB per 1000x1000 PNG. Across a 671-product catalogue
 * the cache is ~79MB of JPEG variants, and every deletion permanently leaked
 * its share of that onto a shared plan with a file quota.
 *
 * ── WHAT IS PINNED ─────────────────────────────────────────────────────────
 *
 * That the disk is actually clean afterwards — asserted with is_file() against
 * the real paths, not against forget()'s return value, which would pass while
 * the files sat there. And that the three degraded states stay non-fatal, since
 * the whole design rests on a page tolerating a cache that is not there.
 */

use App\Models\AdminUser;
use App\Models\Media;
use App\Support\ImageVariants;
use Illuminate\Support\Facades\Route;

function ivPublic(string $relative): string
{
    return public_path(ltrim($relative, '/'));
}

/** A real 1000x1000 JPEG on disk, at a web-root-relative path. */
function ivPhotograph(string $relative): string
{
    $path = ivPublic($relative);
    @mkdir(\dirname($path), 0755, true);

    $im = imagecreatetruecolor(1000, 1000);

    // Noise, not flat colour: a flat image encodes to a few hundred bytes and
    // would make every size assertion here meaningless.
    for ($y = 0; $y < 1000; $y += 2) {
        for ($x = 0; $x < 1000; $x += 2) {
            imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, (int) imagecolorallocate(
                $im, ($x * 7 + $y) % 255, ($y * 13) % 255, ($x * 3 + $y * 5) % 255
            ));
        }
    }

    imagejpeg($im, $path, 85);
    imagedestroy($im);

    return '/'.ltrim($relative, '/');
}

/** Every variant path this photograph would have, whether or not it exists. */
function ivVariantPaths(string $relative): array
{
    $out = [];

    foreach (ImageVariants::WIDTHS as $width) {
        $out[$width] = ivPublic(ImageVariants::DIR.'/'.$width.'/'.ltrim($relative, '/'));
    }

    return $out;
}

function ivCleanup(string $relative): void
{
    @unlink(ivPublic($relative));

    foreach (ivVariantPaths($relative) as $path) {
        @unlink($path);
    }
}

beforeEach(function () {
    if (! ImageVariants::available()) {
        $this->markTestSkipped('GD is not available on this PHP.');
    }
});

/*
|------------------------------------------------------------------------------
| 1. forget() actually clears the disk
|------------------------------------------------------------------------------
*/

it('removes every cached copy of a photograph', function () {
    $relative = 'uploads/products/ei-forget-'.uniqid().'.jpg';
    $image = ivPhotograph($relative);

    expect(ImageVariants::generate($image)['made'])->toBe(count(ImageVariants::WIDTHS));

    foreach (ivVariantPaths($relative) as $width => $path) {
        expect(is_file($path))->toBeTrue('no '.$width.'w variant was generated to begin with');
    }

    expect(ImageVariants::forget($image))->toBe(count(ImageVariants::WIDTHS));

    // The disk, not the return value. A method that counted files it had not
    // unlinked would pass the assertion above and fail this one.
    foreach (ivVariantPaths($relative) as $width => $path) {
        expect(is_file($path))->toBeFalse('the '.$width.'w variant is still on disk');
    }

    ivCleanup($relative);
});

it('leaves the original alone', function () {
    // forget() takes the path of an original and must never be able to delete
    // one. It is called from a delete endpoint, but also, in principle, from a
    // replace path where the original is the file being kept.
    $relative = 'uploads/products/ei-keep-'.uniqid().'.jpg';
    $image = ivPhotograph($relative);

    ImageVariants::generate($image);
    ImageVariants::forget($image);

    expect(is_file(ivPublic($relative)))->toBeTrue('forget() deleted the original');

    ivCleanup($relative);
});

it('works after the original has already been deleted', function () {
    /*
     * The order the delete endpoint actually uses: the original is unlinked
     * first, then its copies. split() does not require the file to exist —
     * insidePublicRoot() does, and forget() deliberately does not go through it
     * — so this is the case that would silently no-op if that ever changed.
     */
    $relative = 'uploads/products/ei-gone-'.uniqid().'.jpg';
    $image = ivPhotograph($relative);

    ImageVariants::generate($image);
    @unlink(ivPublic($relative));

    expect(ImageVariants::forget($image))->toBe(count(ImageVariants::WIDTHS));

    foreach (ivVariantPaths($relative) as $path) {
        expect(is_file($path))->toBeFalse();
    }

    ivCleanup($relative);
});

it('prunes the directories it emptied but keeps the cache root', function () {
    $relative = 'uploads/products/ei-prune-'.uniqid().'/shot.jpg';
    $image = ivPhotograph($relative);

    ImageVariants::generate($image);
    ImageVariants::forget($image);

    foreach (ImageVariants::WIDTHS as $width) {
        expect(is_dir(ivPublic(ImageVariants::DIR.'/'.$width.'/'.\dirname(ltrim($relative, '/')))))
            ->toBeFalse('an empty mirrored directory was left behind at '.$width.'w');

        // Never the width directory itself: the next upload writes into it, and
        // an empty catalogue is not a reason to take the cache root apart.
        expect(is_dir(ivPublic(ImageVariants::DIR.'/'.$width)))->toBeTrue();
    }

    ivCleanup($relative);
});

it('does not prune a directory that still holds another photograph\'s copy', function () {
    // @rmdir only succeeds on an empty directory, so this cannot go wrong — but
    // it is the assertion that would catch a future rewrite reaching for a
    // recursive delete.
    $folder = 'uploads/products/ei-shared-'.uniqid();
    $keep = ivPhotograph($folder.'/keep.jpg');
    $drop = ivPhotograph($folder.'/drop.jpg');

    ImageVariants::generate($keep);
    ImageVariants::generate($drop);
    ImageVariants::forget($drop);

    foreach (ivVariantPaths($folder.'/keep.jpg') as $width => $path) {
        expect(is_file($path))->toBeTrue('forgetting one photograph took another\'s '.$width.'w copy');
    }

    ivCleanup($folder.'/keep.jpg');
    ivCleanup($folder.'/drop.jpg');
});

it('refuses anything that is not one of this site\'s own images', function () {
    // The same refusals split() already makes, asserted here because forget()
    // is the one caller that UNLINKS on the strength of them.
    foreach ([
        '',
        'relative/photo.jpg',
        '/uploads/../../etc/passwd.jpg',
        '/uploads/products/notes.txt',
        '/'.ImageVariants::DIR.'/400/uploads/products/photo.jpg',
        'https://old-woocommerce-site.example/wp-content/uploads/photo.jpg',
    ] as $reference) {
        expect(ImageVariants::forget($reference))->toBe(0, 'forget() acted on: '.$reference);
    }
});

/*
|------------------------------------------------------------------------------
| 2. The delete endpoint calls it
|------------------------------------------------------------------------------
|
| The half that makes the method matter. A cleanup nothing invokes is the same
| defect as a setting nothing reads.
*/

function ivMountLibrary(): void
{
    Route::middleware(['web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class])
        ->prefix('admin-api')
        ->group(base_path('routes/media-library-admin.php'));
}

it('clears a deleted photograph\'s copies through the Media Library', function () {
    ivMountLibrary();

    $relative = 'uploads/products/ei-endpoint-'.uniqid().'.jpg';
    $image = ivPhotograph($relative);

    ImageVariants::generate($image);

    $media = Media::create([
        'filename' => basename($relative),
        'path' => $relative,
        'mime' => 'image/jpeg',
        'size' => (int) filesize(ivPublic($relative)),
        'width' => 1000,
        'height' => 1000,
        'alt' => '',
    ]);

    $admin = AdminUser::create([
        'name' => 'Media Owner',
        'email' => 'ei-media-'.uniqid().'@example.test',
        'password' => bcrypt('secret-secret'),
        'role' => 'owner',
    ]);

    $response = $this->actingAs($admin, 'admin')
        ->deleteJson('/admin-api/media/'.$media->id)
        ->assertOk();

    expect($response->json('variants_removed'))->toBe(count(ImageVariants::WIDTHS));

    expect(is_file(ivPublic($relative)))->toBeFalse('the original survived the delete');

    foreach (ivVariantPaths($relative) as $width => $path) {
        expect(is_file($path))->toBeFalse('the '.$width.'w copy outlived the photograph');
    }

    ivCleanup($relative);
});

/*
|------------------------------------------------------------------------------
| 3. The degraded states stay non-fatal
|------------------------------------------------------------------------------
*/

it('generates nothing and says why when the cache directory cannot be written', function () {
    /*
     * The host answer to "what if img-cache is unwritable". write() returns
     * false — mkdir, encode or rename failed — and generate() reports `made: 0`
     * rather than throwing, which is what keeps an upload from failing because
     * a cached copy could not be made.
     */
    // Its own folder, so the blockers below are paths nothing else in this file
    // has already brought into existence as real directories.
    $folder = 'uploads/products/ei-readonly-'.uniqid();
    $relative = $folder.'/shot.jpg';
    $image = ivPhotograph($relative);

    // A FILE where each variant's directory has to be. mkdir() cannot create a
    // directory over it, which is the same refusal an unwritable parent gives
    // and does not depend on running as an unprivileged user — this suite runs
    // as root, where chmod 0500 is not enforced.
    $blockers = [];

    foreach (ImageVariants::WIDTHS as $width) {
        $blocker = ivPublic(ImageVariants::DIR.'/'.$width.'/'.$folder);
        @mkdir(\dirname($blocker), 0755, true);
        @file_put_contents($blocker, 'not a directory');
        $blockers[] = $blocker;
    }

    $result = ImageVariants::generate($image);

    // Nothing written, nothing thrown, and the caller is told plainly.
    expect($result['made'])->toBe(0);

    foreach (ivVariantPaths($relative) as $width => $path) {
        expect(is_file($path))->toBeFalse('a '.$width.'w variant was written anyway');
    }

    // And the page-level consequence: no srcset, so the tile loads `src`.
    expect(ImageVariants::srcsetFor($image))->toBe('');

    foreach ($blockers as $blocker) {
        @unlink($blocker);
    }

    ivCleanup($relative);
});

it('emits no srcset at all when nothing has been generated', function () {
    // The page-level consequence of every degraded state above, and the reason
    // none of them breaks a product page: the srcset is built from the disk, so
    // an empty cache means an ordinary <img> loading `src`.
    $relative = 'uploads/products/ei-nosrcset-'.uniqid().'.jpg';
    $image = ivPhotograph($relative);

    expect(ImageVariants::srcsetFor($image))->toBe('')
        ->and(ImageVariants::detailSrcsetFor($image))->toBe('');

    ivCleanup($relative);
});

it('never offers a variant wider than the original', function () {
    // The upscale refusal, which is also why isComplete() calls a small logo
    // finished: a srcset candidate whose width descriptor overstates the pixels
    // behind it is how a browser picks the blurriest file on offer.
    $relative = 'uploads/products/ei-small-'.uniqid().'.jpg';
    $path = ivPublic($relative);
    @mkdir(\dirname($path), 0755, true);

    $im = imagecreatetruecolor(300, 300);
    imagejpeg($im, $path, 85);
    imagedestroy($im);

    $image = '/'.ltrim($relative, '/');

    expect(ImageVariants::generate($image)['made'])->toBe(0)
        ->and(ImageVariants::isComplete($image))->toBeTrue()
        ->and(ImageVariants::srcsetFor($image))->toBe('');

    ivCleanup($relative);
});
