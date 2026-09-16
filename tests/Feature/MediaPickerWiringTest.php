<?php

declare(strict_types=1);

/**
 * Every image field in the console opens the SAME picker.
 *
 * The owner's complaint was uploading one photograph again for every field that
 * wanted it. A picker built into one screen would have left the others doing
 * exactly that, so the thing worth pinning is not that the dialog exists — it
 * is that there is only one of it and that every field reaches it.
 */
function pickerSrc(): string
{
    return file_get_contents(base_path('resources/views/admin/partials/media-picker.blade.php'));
}

function consoleSrc(): string
{
    return file_get_contents(base_path('resources/views/admin/app.blade.php'));
}

function editorSrc(): string
{
    return file_get_contents(base_path('resources/views/admin/partials/product-editor-screen.blade.php'));
}

it('ships the picker as one shared module, included before the screens', function () {
    $console = consoleSrc();

    expect(pickerSrc())->toContain('window.kbbPickMedia = function(options)');

    /*
     * Order is load-bearing, not tidiness. The screen partials call
     * window.kbbPickMedia; a partial cannot call a global that a LATER partial
     * defines, so the picker's include has to come first. If somebody moves it
     * down, every Choose button in the console stops working at once and the
     * only symptom is a button that does nothing.
     */
    $picker = strpos($console, "@include('admin.partials.media-picker')");
    expect($picker)->not->toBeFalse('the picker is not included at all');

    foreach ([
        'admin.partials.product-editor-screen',
        'admin.partials.media-library-screen',
        'admin.partials.html-blocks-screen',
    ] as $later) {
        $at = strpos($console, "@include('{$later}')");
        if ($at === false) {
            continue;
        }
        expect($picker)->toBeLessThan($at, "the picker is included after {$later}");
    }
});

it('reaches every image field through that one module', function () {
    /*
     * Five screens (SEO share image, organisation logo, brand logo, category
     * image, attribute swatch) share imgUploadField/wireImgUpload, so wiring
     * the picker there once covers all five. The product editor has three
     * fields of its own plus the rich-text boxes.
     */
    expect(consoleSrc())->toContain("id=\"'+id+'_lib\"")
        ->and(consoleSrc())->toContain('window.kbbPickMedia({');

    $editor = editorSrc();

    foreach (['peo-mainlib', 'peo-gallib', 'peo-oglib'] as $id) {
        expect(str_contains($editor, $id))->toBeTrue("{$id} is missing from the editor");
    }

    // The rich-text boxes gained an insert button that is NOT an execCommand.
    expect($editor)->toContain("['image', '🖼'")
        ->and($editor)->toContain("b.dataset.cmd === 'code' || b.dataset.cmd === 'image'");
});

it('leaves every direct upload path alone', function () {
    /*
     * "Without disturbing anything existing" was explicit. Choosing from the
     * library is an ADDITION: the drop zones, the file inputs and the upload
     * endpoint all still work, because an owner with a photograph that is not
     * in the library yet must not be forced through a dialog to add it.
     */
    $editor = editorSrc();

    foreach (['peo-mainfile', 'peo-galfile', 'peo-galdrop', 'peo-ogfile'] as $id) {
        expect(str_contains($editor, $id))->toBeTrue("{$id} was removed");
    }

    expect(consoleSrc())->toContain("id=\"'+id+'_file\"")
        ->and(consoleSrc())->toContain('zone.ondrop');
});

it('uses only endpoints that already existed', function () {
    /*
     * The picker is a pure consumer: the media grid and the upload endpoint it
     * calls both predate it. That is what keeps it from colliding with work on
     * the Media Library screen, and it means this feature adds no new public
     * surface to audit.
     */
    $picker = pickerSrc();

    expect($picker)->toContain("api('/media?page=")
        ->and($picker)->toContain("'/media/upload'");

    // Nothing invented, and nothing reaching past the admin-api prefix.
    expect(preg_match_all("/apiBase\(\) \+ '\/[a-z\-\/]+/", $picker, $m))->toBeGreaterThan(0);
    foreach ($m[0] as $call) {
        expect(str_contains($call, '/media'))->toBeTrue("picker calls {$call}");
    }
});

it('always hands the caller an array, single select included', function () {
    /*
     * A single-select call gets an array of one rather than a bare string, so a
     * caller written against the wrong shape cannot work by accident and then
     * break the day somebody passes multiple: true.
     */
    $picker = pickerSrc();

    expect($picker)->toContain('var picked = chosen.slice();')
        ->and($picker)->toContain('if (typeof cb === \'function\') cb(picked);');

    // Single select replaces rather than appends.
    expect($picker)->toContain('chosen = (at === -1) ? [url] : [];');
});

it('puts a new upload in the library first, then selects it', function () {
    /*
     * The owner asked for this order specifically: the file joins the Media
     * Library and is then chosen from it, rather than being attached straight
     * to whatever opened the dialog. The reload is what makes the new row real
     * — without it the tile would be a local object that no other field could
     * ever find again.
     */
    $picker = pickerSrc();

    $upload = strpos($picker, 'if (fresh.length) {');
    expect($upload)->not->toBeFalse();

    $tail = substr($picker, $upload, 600);

    expect(str_contains($tail, 'await load(false);'))->toBeTrue('the grid is not reloaded after an upload');
    expect(strpos($tail, 'await load(false);'))
        ->toBeLessThan(strpos($tail, 'chosen ='), 'the upload is selected before the library has it');
});
