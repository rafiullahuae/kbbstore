<?php

/**
 * The first sentence on the Media Library, against the code that fills it.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * THE DEFECT. The blurb read *"Every file uploaded through the admin — product
 * photos, brand logos, category images, the SEO share image, and the clips and
 * covers from Shoppable video all land here."* That was true when it was
 * written and had stopped being true by the time it was read: three more
 * writers now call MediaRegistrar::record(), and two of them are not admin
 * uploads at all.
 *
 *   Import\MediaSideloader   an image pulled in by a store import
 *   Store\ReviewController   a photograph A CUSTOMER attached to a review
 *   Instagram\InstagramSync  whatever the Instagram sync downloaded
 *
 * A screen's own first sentence being wrong about where its contents come from
 * is not cosmetic here, for one reason: THE MEDIA LIBRARY IS WHERE FILES GET
 * DELETED FROM. An owner who believes the grid holds only what he uploaded has
 * no reason to look for a shopper's photograph in it, and no reason to expect
 * that deleting a row he does not recognise takes a live review photo with it.
 * The same sentence is also the only place the console ever tells him that
 * customer-submitted images are kept at all.
 *
 * WHAT THIS FILE ASSERTS, and why it is two halves.
 *
 *   1. The sentence names each source. Text against text, which catches a
 *      rewrite that drops one.
 *   2. THE LIST OF WRITERS IS PINNED AGAINST THE CALL SITES. Every file in app/
 *      that actually calls MediaRegistrar::record() has to be accounted for
 *      here, so a SEVENTH writer added later cannot reach the library without
 *      this going red and making somebody decide what the sentence should say.
 *      That is the half that will still be working in six months; the first half
 *      only pins today's wording.
 *
 * Comments are stripped before anything is matched — php_strip_whitespace()
 * compiles each file and returns it without them — because four files in this
 * tree NAME `MediaRegistrar::record()` in prose while not calling it, including
 * this very paragraph's counterpart in MediaBackfill. A guard that read its own
 * explanation as code is a shape this project has been bitten by repeatedly.
 */

/** The blurb, from the screen that draws it. */
function mlbBlurb(): string
{
    $screen = file_get_contents(
        base_path('resources/views/admin/partials/media-library-screen.blade.php'),
    );

    expect($screen)->toBeString();

    // The one `mlib-sub` in the file: the head card's subtitle. Captured rather
    // than searched for as a whole-file substring so a match cannot come from a
    // comment somewhere else in the screen.
    preg_match('/mlib-sub">(.*?)<\/div>/s', (string) $screen, $match);

    // The sentence is assembled from adjacent single-quoted JavaScript string
    // literals, so the concatenation between them has to come out before the
    // words can be read as a sentence.
    return preg_replace("/'\s*\+\s*'/", '', $match[1] ?? '') ?? '';
}

it('names every writer that puts a file in the media library', function () {
    /*
     * ONE ROW PER FILE THAT CALLS record(), and the words the blurb has to carry
     * for it. The admin upload endpoint is one writer serving four fields, which
     * is why its row lists four phrases: the sentence has always named them
     * separately because that is how the owner thinks about them.
     *
     * MUTATION NOTE. Delete "photos customers attach to their reviews" from the
     * blurb — which is the exact state this lane found it in — and this is red on
     * ReviewController. Put the old sentence back wholesale and it is red on
     * three rows at once. RUN: red.
     */
    $writers = [
        'app/Http/Controllers/Admin/MediaUploadController.php' => [
            'product photos', 'brand logos', 'category images', 'SEO share image',
        ],
        'app/Services/UgcMedia.php' => ['Shoppable'],
        'app/Services/UgcTranscoder.php' => ['covers'],
        'app/Services/Import/MediaSideloader.php' => ['store import'],
        'app/Http/Controllers/Store/ReviewController.php' => ['reviews'],
        'app/Services/Instagram/InstagramSync.php' => ['Instagram'],
    ];

    $blurb = mlbBlurb();

    expect($blurb)->not->toBe('');

    foreach ($writers as $file => $phrases) {
        foreach ($phrases as $phrase) {
            expect($blurb)->toContain($phrase);
        }
    }

    /*
     * AND IT NO LONGER CLAIMS AN ORIGIN IT CANNOT PROMISE. Two of the six
     * writers above are not uploads and not admin: a shopper posts a review
     * photo from the storefront, and the Instagram sync runs on a schedule with
     * nobody logged in. "Every file uploaded through the admin" was the part of
     * the old sentence that was actively misleading rather than merely short, so
     * it is pinned out.
     */
    expect($blurb)->not->toContain('uploaded through the admin');
});

it('goes red when a seventh writer starts filling the library', function () {
    /*
     * THE HALF THAT KEEPS WORKING. The case above pins today's wording; this one
     * pins the SET, so the next lane that teaches a new subsystem to register its
     * files has to come back to this sentence.
     *
     * MUTATION NOTE. Add `MediaRegistrar::record($p);` to any other file under
     * app/ — a live statement, not a comment — and this is red naming that file.
     * Delete the ReviewController row from the list in the case above and this is
     * red too, which is what stops the two cases being weakened independently.
     * RUN: red.
     */
    $accounted = [
        'app/Http/Controllers/Admin/MediaUploadController.php',
        'app/Services/UgcMedia.php',
        'app/Services/UgcTranscoder.php',
        'app/Services/Import/MediaSideloader.php',
        'app/Http/Controllers/Store/ReviewController.php',
        'app/Services/Instagram/InstagramSync.php',
    ];

    $found = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        /*
         * COMMENTS OUT FIRST. php_strip_whitespace() runs the file through the
         * tokeniser and returns it with every comment and docblock gone, so the
         * four files that discuss record() without calling it — MediaBackfill and
         * UgcDerivedFiles among them — are not counted, and neither is this
         * file's own prose if it is ever moved under app/.
         */
        if (! str_contains(php_strip_whitespace($file->getPathname()), 'MediaRegistrar::record(')) {
            continue;
        }

        $found[] = str_replace(base_path() . '/', '', $file->getPathname());
    }

    sort($found);
    sort($accounted);

    // The scan has to actually be finding something: an empty result would make
    // every assertion in this file vacuous.
    expect($found)->not->toBe([]);
    expect($found)->toBe($accounted);
});
