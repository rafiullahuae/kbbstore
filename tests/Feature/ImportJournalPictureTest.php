<?php

declare(strict_types=1);

/*
 * ════════════════════════════════════════════════════════════════════════════
 * LANE U4 — an imported article silently lost its photograph
 * ════════════════════════════════════════════════════════════════════════════
 *
 * WHAT THE DEFECT LOOKED LIKE ON THE SHOP.
 *
 * An article whose body carried a `<picture>` block arrived on /skincare-guide/
 * with a hole where the photograph had been. Not a broken frame, not an alt
 * text, not a line in the import report saying a picture had gone — nothing at
 * all, because the markup was removed rather than broken. The owner's only way
 * to notice was to read the article on the old site and the new one side by
 * side.
 *
 * THE MECHANISM, MEASURED RATHER THAN ASSUMED.
 *
 * `RichText::clean()` parses with `DOMDocument::loadHTML()`, which is libxml's
 * HTML parser, which is HTML4. Its void-element set is the HTML4 one — area,
 * base, br, col, frame, hr, img, input, link, meta, param — and it contains
 * none of HTML5's additions. An unclosed `<source …>` is therefore opened as a
 * CONTAINER, and everything after it up to the close of its parent is parsed
 * as its child:
 *
 *     <picture><source srcset=…><img src=…></picture>
 *         => picture > source > img
 *
 * `source` sat in `RichText::DROP_WHOLE`, which removes the element AND its
 * subtree. `<source>` before `<img>` is the only valid ordering inside a
 * `<picture>`, so the `<img>` was always in that subtree, and every such block
 * resolved to the empty string.
 *
 * `track` and `embed` are the other two HTML5 void elements that were on
 * DROP_WHOLE, and they did the same thing to whatever followed them — up to
 * and including the rest of the article.
 *
 * THE FIX. Those three moved to `RichText::DROP_TAG_KEEP_CHILDREN`, which
 * removes the tag and every attribute on it and promotes the children the
 * parser misfiled underneath it. It is not a widened allowlist: none of the
 * three survives, `srcset` still cannot reach `posts.body`, and the promoted
 * children are sanitised on the way out like any others. The tests below that
 * carry hostile markup are the check on that claim, not decoration.
 *
 * MUTATIONS, RUN AND RECORDED — including the one that did NOT go red, because
 * a mutation note that only lists the convenient results is not evidence:
 *
 *   1. Move 'source' out of DROP_TAG_KEEP_CHILDREN and back into DROP_WHOLE.
 *      FOUR tests fail: "keeps the photograph", "promotes nothing a sanitiser
 *      would not have let through", "lands an imported article with its
 *      photograph in the column", and ImportJournalSrcsetTest's "strips the
 *      srcset from a <picture> while keeping the photograph".
 *   2. Move 'embed' and 'track' the same way. TWO fail: the "swallow the rest
 *      of the article" case and the hostile-markup case.
 *   3. Delete the DROP_TAG_KEEP_CHILDREN branch from `RichText::element()` and
 *      leave the const. ALL NINE STILL PASS, and that is the truth about this
 *      fix: with the three names absent from DROP_WHOLE they fall through to
 *      the ordinary unwrap path and produce the same output. The branch is not
 *      what makes the pictures survive.
 *   4. So what IS the branch for — add 'source' to DROP_WHOLE while LEAVING it
 *      in DROP_TAG_KEEP_CHILDREN. All nine still pass. That is the branch
 *      earning its place: it is checked BEFORE DROP_WHOLE, so the obvious
 *      future tidy-up — "source is a media tag, it belongs on the drop list" —
 *      cannot bring the silent loss back. It is a guard on the invariant, not
 *      the mechanism, and mutation 1 is what shows the invariant matters.
 */

use App\Models\Post;
use App\Services\Import\DocumentMediaRewrite;
use App\Services\Import\Entities\PostImporter;
use App\Services\ImportConsole\ImportWorkspace;
use App\Support\RichText;
use Illuminate\Support\Facades\File;

/** A `<picture>` exactly as a page builder or a WebP plugin stores one. */
const PICTURE_BODY = '<p>Heartleaf is everywhere.</p>'
    .'<figure class="wp-block-image">'
    .'<picture>'
    .'<source srcset="https://kbeautybliss.com/wp-content/uploads/2021/05/a-600.webp 600w" type="image/webp">'
    .'<img src="https://kbeautybliss.com/wp-content/uploads/2021/05/a.jpg" alt="Heartleaf leaves" width="600" height="400">'
    .'</picture>'
    .'<figcaption>Heartleaf, photographed in May.</figcaption>'
    .'</figure>'
    .'<p>And it works.</p>';

function pictureForget(): void
{
    $path = (new ImportWorkspace)->path('posts');

    if (is_file($path)) {
        @unlink($path);
    }
}

afterEach(fn () => pictureForget());

it('reproduces the parse that caused the loss, so the cause is checked and not quoted', function () {
    /*
     * THE ROOT CAUSE, ASSERTED DIRECTLY AGAINST THE PARSER. If a future libxml
     * learns HTML5's void set this goes red, and the right response is to
     * delete this test rather than to keep the workaround on faith.
     */
    $document = new DOMDocument('1.0', 'UTF-8');

    $previous = libxml_use_internal_errors(true);
    $document->loadHTML(
        '<?xml encoding="UTF-8"><div id="r"><picture><source srcset="a.webp"><img src="a.jpg" alt="a"></picture></div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $source = $document->getElementsByTagName('source')->item(0);

    expect($source)->not->toBeNull()
        // The whole defect in one assertion: the <img> is the <source>'s CHILD.
        ->and($source->getElementsByTagName('img')->length)->toBe(1);
});

it('keeps the photograph out of a <picture> block', function () {
    // THE DEFECT: this returned '' — figure, caption, picture and photograph.
    $clean = RichText::clean(PICTURE_BODY);

    expect($clean)->toContain('src="https://kbeautybliss.com/wp-content/uploads/2021/05/a.jpg"')
        ->and($clean)->toContain('alt="Heartleaf leaves"')
        // The caption survived too; it was inside the swallowed subtree.
        ->and($clean)->toContain('Heartleaf, photographed in May.')
        ->and($clean)->toContain('And it works.')
        // The tag that carried the candidate list is gone, list included.
        ->and($clean)->not->toContain('<source')
        ->and($clean)->not->toContain('srcset')
        ->and($clean)->not->toContain('a-600.webp');

    // And the address is now one the media rewrite and the audit can both see.
    expect(DocumentMediaRewrite::sources($clean))
        ->toBe(['https://kbeautybliss.com/wp-content/uploads/2021/05/a.jpg']);
});

it('does not let a <track> or an <embed> swallow the rest of the article', function () {
    /*
     * The same void-element parse, on the other two tags that were on
     * DROP_WHOLE. THE DEFECT: everything after the tag, to the close of its
     * parent, was removed — an `<embed>` in the first paragraph of an article
     * took the article.
     */
    expect(RichText::clean('<p>one</p><track kind="captions" src="c.vtt"><p>two</p><p>three</p>'))
        ->toBe('<p>one</p><p>two</p><p>three</p>');

    expect(RichText::clean('<p>one</p><embed src="https://old.test/x.swf"><p>two</p>'))
        ->toBe('<p>one</p><p>two</p>')
        // The embed itself is still gone, address and all.
        ->and(RichText::clean('<embed src="https://old.test/x.swf">'))->toBe('');
});

it('promotes nothing a sanitiser would not have let through anyway', function () {
    /*
     * THE SECURITY QUESTION THE FIX HAS TO ANSWER. Children of these three tags
     * are promoted, so the only safe version of the fix is one where a promoted
     * child is sanitised exactly like any other node. `element()` walks before
     * it unwraps, which is what makes that true; these are the cases that would
     * catch it being reordered.
     */
    expect(RichText::clean('<source srcset="a.webp"><script>alert(1)</script><p>hi</p>'))
        ->toBe('<p>hi</p>')
        ->and(RichText::clean('<source><style>body{display:none}</style><p>hi</p>'))
        ->toBe('<p>hi</p>')
        ->and(RichText::clean('<source><svg onload="alert(1)"><use href="#x"/></svg><p>hi</p>'))
        ->toBe('<p>hi</p>')
        ->and(RichText::clean('<embed src="x"><iframe src="//evil.test"></iframe><p>hi</p>'))
        ->toBe('<p>hi</p>')
        ->and(RichText::clean('<track src="c.vtt"><form action="//evil.test"><input name="pw"></form><p>hi</p>'))
        ->toBe('<p>hi</p>');

    // A promoted <img> is an ordinary <img>: handler stripped, scheme checked.
    expect(RichText::clean('<source srcset="a.webp"><img src="a.jpg" onerror="alert(1)" srcset="a-2x.jpg 2x">'))
        ->toBe('<img src="a.jpg">')
        ->and(RichText::clean('<source srcset="a.webp"><img src="javascript:alert(1)">'))
        ->toBe('');

    // And the tag itself keeps nothing, including a src or an event handler.
    expect(RichText::clean('<source srcset="a.webp" type="image/webp" src="a.webp" onload="alert(1)">'))
        ->toBe('');
});

it('is idempotent, because the import is re-runnable', function () {
    /*
     * `kbb:import` is resumable and is re-run on the same files. A second pass
     * over an already-cleaned body must be a no-op or the column changes every
     * time the owner presses the button — and the two doors clean on every
     * write, so the editor re-cleans what the importer wrote.
     */
    $once = RichText::clean(PICTURE_BODY);

    expect(RichText::clean($once))->toBe($once)
        ->and(RichText::clean(RichText::clean($once)))->toBe($once);
});

it('lands an imported article with its photograph in the column', function () {
    /*
     * END TO END THROUGH THE REAL IMPORTER, because the thing that was lost was
     * lost in `posts.body`, not in a function. `PostImporter::cleanBodyReported()`
     * is the import-side door; `PostEditorApiController::save()` is the other,
     * and both call the same `RichText::clean()`.
     */
    $path = (new ImportWorkspace)->path('posts');

    File::ensureDirectoryExists(dirname($path));

    $handle = fopen($path, 'wb');
    fputcsv($handle, ['id', 'type', 'slug', 'status', 'title', 'excerpt', 'content', 'date_created_gmt']);
    fputcsv($handle, [
        '7101', PostImporter::ARTICLE_TYPE, 'heartleaf-picture', 'publish',
        'Heartleaf picture', 'An excerpt.', PICTURE_BODY, '2021-05-04 09:00:00',
    ]);
    fclose($handle);

    $this->artisan('kbb:import', [
        '--dir' => (new ImportWorkspace)->directory(),
        '--only' => ['posts'],
        '--run' => 'picture-test',
    ])->run();

    $body = (string) Post::query()->where('source_post_id', 7101)->value('body');

    // THE DEFECT: this was '<p>Heartleaf is everywhere.</p>' and nothing else.
    expect($body)->toContain('src="https://kbeautybliss.com/wp-content/uploads/2021/05/a.jpg"')
        ->and($body)->not->toContain('srcset')
        ->and(DocumentMediaRewrite::sources($body))
        ->toBe(['https://kbeautybliss.com/wp-content/uploads/2021/05/a.jpg']);

    // And a second run over the same file leaves the column byte-identical.
    $this->artisan('kbb:import', [
        '--dir' => (new ImportWorkspace)->directory(),
        '--only' => ['posts'],
        '--run' => 'picture-test-again',
    ])->run();

    expect((string) Post::query()->where('source_post_id', 7101)->value('body'))->toBe($body);
});
