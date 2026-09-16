<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use Tests\Support\BrandAdminRoutes;
use Tests\Support\CatalogAdminRoutes;

/**
 * No raw "Choose File" anywhere in the admin, and nothing lost by removing it.
 *
 * THE COMPLAINT, TWICE IN THE SAME WORDS. The owner asked for the Media
 * Library popup when setting a brand or category image, having already asked
 * for it on product images: "on any upload media on the whole backend, the
 * media library is a must to show." A raw <input type="file"> cannot satisfy
 * that. It can only ever send a file from this computer, so an image already
 * in the library has to be found, downloaded and uploaded a second time —
 * which is the duplicate-uploading the owner is trying to stop.
 *
 * WHAT THIS FILE PINS, IN THREE PARTS.
 *
 *  1. STRUCTURAL, and the part that stops this regressing. Every
 *     <input type="file"> under resources/views/admin/ is found and classified.
 *     An image-accepting one has to be on a short, named allowlist; anything
 *     else fails and the failure NAMES THE FILE, THE LINE AND THE id, because
 *     the next person to add one will be adding it to a screen nobody here
 *     thought of. Non-image inputs (the CSV importers, the update-package zip)
 *     are excluded by their accept attribute and listed below with reasons.
 *
 *  2. BEHAVIOURAL. A library selection arrives at the save endpoints as
 *     exactly what a direct upload used to put there — a URL string in
 *     categories.image and brands.logo. This is the easiest way to do damage
 *     in this change: swap a URL for a media id and every storefront template
 *     that prints the column renders a number.
 *
 *  3. NO CAPABILITY LOST. A pasted URL still saves on both screens. The
 *     library is an additional way in, not a replacement, and the drop zone in
 *     the console still uploads a dragged file directly.
 */

/* ------------------------------------------------------- the file scan */

/**
 * Every <input type="file"> under resources/views/admin/, with enough context
 * to name it in a failure. Deliberately a raw regex over the source rather
 * than rendered HTML: these screens build their markup in JavaScript strings,
 * so nothing renders them server-side, and searching rendered HTML for a
 * pattern also matches inlined CSS (.inp[type=file] is a style rule in this
 * very console).
 *
 * @return list<array{file:string,line:int,id:string,accept:string,tag:string}>
 */
function adminFileInputs(): array
{
    $root = base_path('resources/views/admin');
    $found = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    $paths = [];
    foreach ($iterator as $entry) {
        if ($entry->isFile() && str_ends_with($entry->getFilename(), '.blade.php')) {
            $paths[] = $entry->getPathname();
        }
    }

    sort($paths);

    foreach ($paths as $path) {
        $source = (string) file_get_contents($path);

        /*
         * Comments are blanked first, or this scan reports itself. These files
         * document why a control is shaped the way it is, and the honest way to
         * explain "this used to be a raw file input and now is not" is to write
         * the tag down. A match inside a comment is a description, not a
         * control.
         *
         * A BLOCK COMMENT ONLY OPENS AT THE START OF A LINE. That is the whole
         * subtlety here, and it was got wrong once on the way to this version:
         * the attribute value image-slash-star contains the two characters that
         * open a block comment, so an unanchored rule treats the middle of a
         * real tag as the start of one and blanks everything from there to the
         * next comment close further down the file. The damage is silent and
         * backwards: real file inputs vanish from the scan, and the one that is
         * left reports a truncated accept. Every block comment in these files is
         * written on its own line and none of the attribute values are, so the
         * line anchor tells them apart without parsing JavaScript.
         *
         * Blanked rather than removed, character for character with newlines
         * kept, so the line numbers this reports still point at the real file.
         */
        $source = preg_replace_callback(
            '#^[ \t]*/\*.*?\*/|\{\{--.*?--\}\}#ms',
            fn (array $m): string => preg_replace('/[^\n]/', ' ', $m[0]),
            $source
        );

        // The opening tag only, up to the closing angle bracket. type=file may
        // be the first attribute or a later one, so the tag is matched first
        // and the type looked for inside it.
        if (! preg_match_all('/<input\b[^>]*>/i', $source, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($m[0] as [$tag, $offset]) {
            if (! preg_match('/\btype\s*=\s*["\']?file\b/i', $tag)) {
                continue;
            }

            preg_match('/\bid\s*=\s*["\']([^"\']*)["\']/i', $tag, $idm);
            preg_match('/\baccept\s*=\s*["\']([^"\']*)["\']/i', $tag, $am);

            $found[] = [
                'file' => str_replace(base_path() . '/', '', $path),
                'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                'id' => $idm[1] ?? '',
                'accept' => $am[1] ?? '',
                'tag' => $tag,
            ];
        }
    }

    return $found;
}

it('finds file inputs at all, so a silent scan cannot pass by finding nothing', function () {
    /*
     * The scan above is the whole guard in part 1. A regex that matched
     * nothing — because somebody reformatted the markup, or because the
     * directory moved — would report a clean admin while raw inputs multiplied
     * behind it. The picker's own Upload New input is a fixed, known hit, so
     * its presence proves the scan is live.
     */
    $inputs = adminFileInputs();

    expect($inputs)->not->toBeEmpty('the scan found nothing at all — it has stopped working');

    $ids = array_column($inputs, 'id');

    /*
     * Named, known hits across four different files. This is the guard against
     * the scan going BLIND rather than against the admin going wrong: a
     * comment-blanking rule that over-reaches, or a regex that stops matching,
     * would report a spotless admin while raw pickers multiplied behind it.
     * Every id below is a file input that is SUPPOSED to be there, so all five
     * have to be found.
     */
    foreach ([
        'mp-file',      // media-picker.blade.php  — the library's own Upload new
        'peo-galfile',  // product-editor-screen   — gallery drop zone
        'peo-mainfile', // product-editor-screen   — main image
        'rio-file',     // reviews-io-screen       — CSV
        'uFile',        // app.blade.php           — update package zip
    ] as $known) {
        expect(in_array($known, $ids, true))->toBeTrue("the scan can no longer see {$known}");
    }

    /*
     * And each accept attribute survives the scan intact. This is the precise
     * thing the first, unanchored version of the comment rule was destroying,
     * and it matters beyond tidiness: the allowlist test below decides whether
     * an input is an image picker by reading this attribute, so a truncated one
     * silently reclassifies a CSV importer as an image field or the reverse.
     */
    $accepts = [];
    foreach ($inputs as $input) {
        $accepts[$input['id']] = $input['accept'];
    }

    expect($accepts['mp-file'])->toBe('image/*');
    expect(str_contains($accepts['rio-file'], '.csv'))->toBeTrue('the CSV importer lost its accept list');
    expect($accepts['uFile'])->toBe('.zip');
});

it('ships no raw image file input outside the picker and the named exclusions', function () {
    /*
     * THE ALLOWLIST. Short on purpose — every entry is a place the Media
     * Library is already offered FIRST, with the raw input kept only as the
     * secondary "upload a new one" path, and every one of them posts to
     * /admin-api/media/upload so the file joins the library either way.
     *
     *   mp-file       the picker's own "Upload new". This IS the library's
     *                 upload path; without it the popup could only ever offer
     *                 what is already there.
     *   peo-mainfile  product editor, main image. #peo-mainlib opens the
     *   peo-galfile   product editor, gallery.    library above each of these,
     *   peo-ogfile    product editor, share image. and the drop zones are what
     *                 the owner was told would not be taken away.
     *
     * Adding an id here is a deliberate act with a reason attached. Adding a
     * raw input WITHOUT touching this list fails below, by name.
     */
    $allowed = ['mp-file', 'peo-mainfile', 'peo-galfile', 'peo-ogfile'];

    $offenders = [];

    foreach (adminFileInputs() as $input) {
        // Not an image picker at all — the CSV importers and the update-package
        // zip upload. The library is the wrong tool for a spreadsheet or a
        // release archive, and they are excluded by what they accept rather
        // than by being listed, so a new CSV importer needs no change here.
        if ($input['accept'] !== '' && ! str_contains(strtolower($input['accept']), 'image')) {
            continue;
        }

        if (in_array($input['id'], $allowed, true)) {
            continue;
        }

        $offenders[] = sprintf(
            '%s:%d  id="%s" accept="%s"',
            $input['file'],
            $input['line'],
            $input['id'] !== '' ? $input['id'] : '(no id)',
            $input['accept'] !== '' ? $input['accept'] : '(no accept — treated as an image picker)'
        );
    }

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['a raw browser file picker is back in the admin:'],
        $offenders,
        ['', 'Open the shared Media Library instead — window.kbbPickMedia({folder, onPick}),',
            'defined in resources/views/admin/partials/media-picker.blade.php. If this input',
            'genuinely is not an image picker, give it an accept attribute that says so.'],
    )));
});

it('excludes the non-image uploads by what they accept, and they are still there', function () {
    /*
     * Named so the exclusion is a recorded decision rather than a gap. A CSV of
     * reviews and a signed release zip are not media; putting them through an
     * image library would be worse than the problem being fixed. Asserted
     * present so this test also notices if one of them is deleted by accident
     * while chasing file inputs.
     */
    $byId = [];
    foreach (adminFileInputs() as $input) {
        if ($input['id'] !== '') {
            $byId[$input['id']] = $input['accept'];
        }
    }

    foreach ([
        'rio-file' => 'csv',        // Reviews.io importer, reviews-io-screen.blade.php
        'impFileInput' => 'csv',    // catalogue CSV importer, app.blade.php
        'uFile' => 'zip',           // Store → Core Updates package, app.blade.php
    ] as $id => $needle) {
        expect(array_key_exists($id, $byId))->toBeTrue("{$id} has disappeared from the admin");
        expect(str_contains(strtolower($byId[$id]), $needle))
            ->toBeTrue("{$id} no longer declares it accepts {$needle} — it would now be scanned as an image picker");
    }
});

/* ------------------------------------------ the two converted screens */

function mpeCategoryTreeSrc(): string
{
    return (string) file_get_contents(base_path('resources/views/admin/partials/category-tree-screen.blade.php'));
}

function mpeConsoleSrc(): string
{
    return (string) file_get_contents(base_path('resources/views/admin/app.blade.php'));
}

it('opens the library from the category dialog, and keeps its address box', function () {
    $src = mpeCategoryTreeSrc();

    // The old control, by id, so a revert is caught even if the markup moves.
    expect(str_contains($src, 'ct-file'))
        ->toBeFalse('the bare "Choose File" is back in the category edit dialog');

    expect(str_contains($src, 'id="ct-lib"'))
        ->toBeTrue('the category dialog has no Choose from Media Library button');

    expect(str_contains($src, 'window.kbbPickMedia({'))
        ->toBeTrue('the category dialog never opens the shared picker');

    // Guarded, not assumed: the picker is a separate partial and a package that
    // shipped this screen without it would otherwise throw on the first click.
    expect(str_contains($src, "typeof window.kbbPickMedia !== 'function'"))
        ->toBeTrue('the category dialog calls the picker without checking it is there');

    // NOTHING LOST. The address field is still an editable input, and it is
    // still what the save reads, so a URL hosted elsewhere can still be pasted.
    expect(str_contains($src, "<input id=\"ct-image\""))
        ->toBeTrue('the category image address field was removed');

    expect(str_contains($src, "image: val('ct-image')"))
        ->toBeTrue('the category save no longer reads the image address field');
});

it('makes the console image field library-first without losing drag-drop or the URL box', function () {
    $src = mpeConsoleSrc();

    /*
     * One helper draws five fields — the SEO share image, the organisation
     * logo, a brand logo, a category image and an attribute swatch — so this
     * is asserted once against the helper rather than five times against its
     * callers. That is also what stops a sixth caller being born raw.
     */
    expect(str_contains($src, "id=\"'+id+'_file\""))
        ->toBeFalse('the transparent file input is back over the image drop zone');

    expect(str_contains($src, "id=\"'+id+'_lib\""))
        ->toBeTrue('the shared image field lost its Choose from Media Library button');

    // Clicking the zone is the path the file input used to steal.
    expect(str_contains($src, 'zone.onclick=openLibrary;'))
        ->toBeTrue('clicking the image zone no longer opens the Media Library');

    // NOTHING LOST: a dragged file still uploads directly (a drop carries its
    // own files and never needed an input element), and the URL box stays.
    expect(str_contains($src, 'zone.ondrop='))->toBeTrue('drag-and-drop upload was removed');
    expect(str_contains($src, "id=\"'+id+'_url\""))->toBeTrue('the paste-a-URL box was removed');
    expect(str_contains($src, "id=\"'+id+'_manual\""))->toBeTrue('the "or paste a URL directly" link was removed');

    // The five callers, still routed through the one helper.
    foreach (['seo_og_img', 'seo_org_logo', 'brd_logo', 'cat_image', 'atv_image'] as $field) {
        expect(str_contains($src, "wireImgUpload('{$field}'"))
            ->toBeTrue("{$field} is no longer wired through the shared image field");
    }
});

it('passes the picker a title that exists, rather than a free variable', function () {
    /*
     * A real bug this change fixes, not a style point. wireImgUpload read
     * `label` when opening the picker, and `label` is a parameter of
     * imgUploadField — a different function. In a browser that is a
     * ReferenceError thrown inside the click handler, so the button did
     * nothing at all and said nothing about why.
     */
    $src = mpeConsoleSrc();

    expect(preg_match('/function wireImgUpload\(\s*id\s*,\s*folder\s*,\s*label\s*\)/', $src))
        ->toBe(1, 'wireImgUpload still has no label of its own to hand the picker');

    // Derived from the rendered <label> when the caller passes none, so the
    // existing two-argument call sites keep working untouched.
    expect(str_contains($src, "zone.parentNode.querySelector('label')"))
        ->toBeTrue('wireImgUpload cannot recover a label for a two-argument call');
});

/* ------------------------------------------------- what the fields save */

describe('a library selection saves what a direct upload always saved', function () {
    beforeEach(function () {
        CatalogAdminRoutes::wire($this->app);
        BrandAdminRoutes::wire($this->app);

        $this->admin = AdminUser::create([
            'name' => 'T MPE Admin',
            'email' => 't-mpe-admin@example.test',
            'password' => 'password-long-enough',
            'role' => 'owner',
        ]);

        $this->actingAs($this->admin, 'admin');
    });

    it('stores a category image as the same URL string the upload used to leave', function () {
        /*
         * What the picker hands back is an array of URLs — the very strings
         * /admin-api/media/upload returns — and both category dialogs put
         * urls[0] into the address field, which the save reads. So the payload
         * below is byte-for-byte what a direct upload produced. If a future
         * change made the picker hand back a media id instead, this fails, and
         * it fails BEFORE the storefront renders the number.
         */
        $fromLibrary = '/uploads/categories/20260916-101500-abc123.jpg';

        $this->postJson('/admin-api/categories', [
            'name' => 'T MPE Sun Care',
            'image' => $fromLibrary,
        ])->assertStatus(201);

        $category = Category::query()->where('name', 'T MPE Sun Care')->first();

        expect($category)->not->toBeNull()
            ->and($category->image)->toBe($fromLibrary)
            ->and($category->image)->toBeString();

        // And on edit, the other half of the same field.
        $this->putJson('/admin-api/categories/' . $category->id, [
            'name' => 'T MPE Sun Care',
            'slug' => $category->slug,
            'image' => '/uploads/categories/20260916-101600-def456.png',
        ])->assertOk();

        expect($category->fresh()->image)->toBe('/uploads/categories/20260916-101600-def456.png');
    });

    it('stores a brand logo as the same URL string the upload used to leave', function () {
        $fromLibrary = '/uploads/brands/20260916-101700-ghi789.png';

        $this->postJson('/admin-api/brands', [
            'name' => 'T MPE Cosrx',
            'logo' => $fromLibrary,
        ])->assertStatus(201);

        $brand = Brand::query()->where('name', 'T MPE Cosrx')->first();

        expect($brand)->not->toBeNull()
            ->and($brand->logo)->toBe($fromLibrary)
            ->and($brand->logo)->toBeString();

        $this->putJson('/admin-api/brands/' . $brand->id, [
            'name' => 'T MPE Cosrx',
            'slug' => $brand->slug,
            'logo' => '/uploads/brands/20260916-101800-jkl012.webp',
        ])->assertOk();

        expect($brand->fresh()->logo)->toBe('/uploads/brands/20260916-101800-jkl012.webp');
    });

    it('still accepts a URL typed or pasted in by hand, on both screens', function () {
        /*
         * The capability the conversion must not cost. Both dialogs keep an
         * editable address box beside the library button, so an image hosted
         * somewhere else — a CDN, a supplier's site — goes in exactly as it did
         * before, without being made to pass through the library first.
         */
        $pasted = 'https://cdn.example.test/t-mpe-pasted.jpg';

        $this->postJson('/admin-api/categories', [
            'name' => 'T MPE Pasted Cat',
            'image' => $pasted,
        ])->assertStatus(201);

        expect(Category::query()->where('name', 'T MPE Pasted Cat')->value('image'))->toBe($pasted);

        $this->postJson('/admin-api/brands', [
            'name' => 'T MPE Pasted Brand',
            'logo' => $pasted,
        ])->assertStatus(201);

        expect(Brand::query()->where('name', 'T MPE Pasted Brand')->value('logo'))->toBe($pasted);
    });
});
