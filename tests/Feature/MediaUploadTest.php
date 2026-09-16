<?php

declare(strict_types=1);

use App\Models\AdminUser;

/**
 * POST /admin-api/media/upload — the ONE image-upload endpoint.  (kept by Lane AT)
 *
 * WHERE THESE CAME FROM, AND WHY THEY ARE NOT IN THE BIN.
 *
 * Lane AT retired the duplicate product screens: Lane AK's create form and its
 * /catalog-product-* endpoints, and Lane AF's inline detail panel. Their test
 * file, tests/Feature/AdminCatalogProductCreateTest.php, went with them —
 * except for these assertions, which never tested that lane's endpoints at all.
 * They test Admin\MediaUploadController, which is registered in routes/web.php,
 * is used by the surviving product editor, the brand logo, the category image
 * and the SEO share image, and is going nowhere.
 *
 * AdminCatalogProductCreateTest was the ONLY coverage of these rules in the
 * repository. Deleting the file wholesale would have silently removed the
 * regression test for a stored-XSS defect that has already been fixed once —
 * the exact shape CLAUDE.md's landmine list is made of. They are carried over
 * verbatim in substance, with the retired lane's route-wiring fixture dropped
 * because this endpoint needs none.
 *
 * THE DEFECT THE SVG TEST CLOSES. The accept check read the file's BYTES; the
 * stored extension and the decision to run the SVG safety scan both read the
 * file's NAME. A hostile SVG uploaded as `photo.png` passed the accept check as
 * image/svg+xml, skipped the scan because the name did not end .svg, and was
 * written into the public web root.
 */
function muAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'MU Owner',
        'email' => 'mu-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function asMuAdmin(): void
{
    test()->actingAs(muAdmin(), 'admin');
}

/**
 * A real UploadedFile over real bytes, NOT UploadedFile::fake().
 *
 * Illuminate\Http\Testing\File::getMimeType() returns
 * `MimeType::from($this->name)` — the type implied by the FILENAME. A test
 * built on the fake therefore cannot tell a content check from a name check,
 * and would pass just as happily against the defect this endpoint had. These
 * files have genuine bytes on disk and genuine, sometimes lying, names.
 */
function muUpload(string $name, string $bytes): \Illuminate\Http\UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'muup');
    file_put_contents($path, $bytes);

    return new \Illuminate\Http\UploadedFile($path, $name, null, null, true);
}

/**
 * Empty public/uploads/products, and answer what is in it.
 *
 * The upload endpoint writes straight into the real public web root — there is
 * no storage:link on this host, see MediaUploadController's class comment — so
 * a test that uploads a file leaves a file behind. Every upload test below
 * starts from empty and asserts against a count taken in the same test, rather
 * than against "the directory is empty", which is only true until some other
 * test in the same run has uploaded something legitimately.
 *
 * @return list<string>
 */
function muUploads(bool $clear = false): array
{
    $dir = public_path('uploads/products');

    $files = array_values(array_filter(glob($dir.'/*') ?: [], 'is_file'));

    if ($clear) {
        foreach ($files as $file) {
            @unlink($file);
        }

        return [];
    }

    return $files;
}

function muPngBytes(): string
{
    $image = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('has exactly one image upload endpoint in the whole application', function () {
    /*
     * The other two are not image paths and are deliberately named here rather
     * than filtered out by a looser pattern: admin-api/import/upload takes the
     * WooCommerce export files and admin/updates/upload takes a signed update
     * package. Listing them means this assertion goes red when a THIRD kind of
     * upload appears, which is the event worth being told about — a regex that
     * merely excluded them would not notice.
     *
     * Lane AT's removal of the /catalog-product-* endpoints did not change this
     * list, because that lane never added an upload route either: the create
     * form posted the URL this endpoint returned, never a file.
     */
    $uploads = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains($r->uri(), 'upload'))
        ->map(fn ($r) => $r->uri())
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($uploads)->toBe([
        'admin-api/import/upload',      // WooCommerce export files
        'admin-api/media/upload',       // every image in the admin
        'admin/updates/upload',         // signed update packages
    ]);
});

it('accepts a real image and stores it under the extension its bytes say it is', function () {
    asMuAdmin();

    muUploads(true);

    // A genuine PNG that the operator happened to name .jpg — a completely
    // ordinary mistake, and the file must still be stored as, and served as,
    // what it really is.
    $response = test()->post('/admin-api/media/upload', [
        'file' => muUpload('holiday-photo.jpg', muPngBytes()),
        'folder' => 'products',
    ])->assertOk();

    expect($response->json('ok'))->toBeTrue()
        ->and($response->json('filename'))->toEndWith('.png')
        ->and($response->json('url'))->toContain('/uploads/products/');

    muUploads(true);
});

it('runs the SVG safety scan on the bytes, not on the filename', function () {
    asMuAdmin();

    muUploads(true);

    $hostile = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';

    $response = test()->post('/admin-api/media/upload', [
        'file' => muUpload('innocent-photo.png', $hostile),
        'folder' => 'products',
    ])->assertStatus(422);

    expect($response->json('ok'))->toBeFalse()
        // The message says what was wrong, not merely that something was.
        ->and($response->json('message'))->toContain('script element');

    // Nothing was written into the web root.
    expect(muUploads())->toBe([], 'a hostile SVG reached the public web root');

    // The same scan still catches it under its own name, which is the case
    // that already worked and must keep working.
    test()->post('/admin-api/media/upload', [
        'file' => muUpload('hostile.svg', $hostile),
        'folder' => 'products',
    ])->assertStatus(422);

    // And a clean SVG is still accepted — the rule is about scripting, not
    // about the format.
    $clean = test()->post('/admin-api/media/upload', [
        'file' => muUpload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"><rect width="8" height="8"/></svg>'),
        'folder' => 'products',
    ])->assertOk();

    expect($clean->json('filename'))->toEndWith('.svg')
        ->and(muUploads())->toHaveCount(1);

    muUploads(true);
});

it('refuses a file that is not an image and says what it actually was', function () {
    asMuAdmin();
    muUploads(true);

    $response = test()->post('/admin-api/media/upload', [
        'file' => muUpload('price-list.png', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\n"),
        'folder' => 'products',
    ])->assertStatus(422);

    expect($response->json('message'))->toContain('application/pdf')
        ->and($response->json('message'))->toContain('JPG, PNG, WebP, GIF or SVG');

    expect(muUploads())->toBe([], 'a refused file still reached the public web root');
});

it('caps the upload size and says the cap in the units the operator sees', function () {
    asMuAdmin();
    muUploads(true);

    // 6MB of a genuine PNG header followed by filler: over the 5MB cap.
    // The Accept header matters: without it a validation failure is a 302 back
    // to a form this API has no concept of, and the operator's screen sees a
    // redirect instead of a reason.
    $response = test()->post('/admin-api/media/upload', [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('huge.png', 6 * 1024, 'image/png'),
        'folder' => 'products',
    ], ['Accept' => 'application/json'])->assertStatus(422);

    expect($response->json('errors.file.0'))->toContain('5MB');

    expect(muUploads())->toBe([], 'an oversized file still reached the public web root');
});
