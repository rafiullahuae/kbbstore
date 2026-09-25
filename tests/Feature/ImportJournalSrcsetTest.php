<?php

declare(strict_types=1);

/*
 * ════════════════════════════════════════════════════════════════════════════
 * LANE U3 — the srcset carve-out in DocumentMediaRewrite, checked not assumed
 * ════════════════════════════════════════════════════════════════════════════
 *
 * `DocumentMediaRewrite::TAGS` re-points `<img src>` and `<a href>` inside an
 * article body and deliberately does NOT parse `srcset`. Lane A's round-2 note
 * flagged that as separate work; this lane's job was to decide it explicitly
 * rather than leave it, and the decision is "not parsed, because nothing gets
 * here to parse". This file is the half of that argument that is checkable.
 *
 * WHAT THE DEFECT WOULD LOOK LIKE ON THE SHOP IF THE PREMISE WERE FALSE:
 *
 * WordPress writes `<img src="…/a.jpg" srcset="…/a-300.jpg 300w, …/a-600.jpg
 * 600w">` for every image in a post. If such a body reached `posts.body`
 * intact, the migration would re-point the `src`, leave both `srcset`
 * candidates naming the old host, and then report success — because the audit
 * reads through `DocumentMediaRewrite::sources()`, the same list, so it cannot
 * see what that list does not parse. "Remote → 0" with every retina reader
 * still loading the article's pictures from a site about to be switched off is
 * exactly the silent half-success that the `<a href>` work was done to close.
 *
 * WHY IT CANNOT ARISE. `RichText::ALLOWED['img']` does not include `srcset`,
 * and `RichText::attributes()` removes every attribute not on the tag's list.
 * `picture` and `source` are not allowed elements at all. Both doors into
 * `posts.body` run that one call. So the attribute is destroyed at the door
 * and there is nothing left for a rewriter to miss.
 *
 * THIS FILE IS THE TRIPWIRE ON THAT PREMISE. The day `RichText` allows
 * `srcset` — a perfectly reasonable thing for a later lane to want, so that
 * imported articles keep their responsive images — the carve-out in
 * `DocumentMediaRewrite` becomes a real gap on the same commit, and these
 * tests go red and say so in their own message. That is the notice.
 *
 * MUTATION, RUN: add 'srcset' to RichText::ALLOWED['img'] and the first two
 * tests here go red — the attribute survives clean(), survives the importer,
 * and `sources()` proves it is invisible to the rewrite and to the audit.
 */

use App\Models\Post;
use App\Services\Import\DocumentMediaRewrite;
use App\Services\Import\Entities\PostImporter;
use App\Services\ImportConsole\ImportWorkspace;
use App\Support\RichText;
use Illuminate\Support\Facades\File;

/** A body exactly as WordPress writes one, both addresses on the old host. */
const SRCSET_BODY = '<p>Heartleaf is everywhere.</p>'
    .'<img src="https://kbeautybliss.com/wp-content/uploads/2021/05/a.jpg" '
    .'srcset="https://kbeautybliss.com/wp-content/uploads/2021/05/a-300.jpg 300w, '
    .'https://kbeautybliss.com/wp-content/uploads/2021/05/a-600.jpg 600w" '
    .'sizes="(max-width: 600px) 300px, 600px" alt="Heartleaf">';

function srcsetForget(): void
{
    $path = (new ImportWorkspace)->path('posts');

    if (is_file($path)) {
        @unlink($path);
    }
}

afterEach(fn () => srcsetForget());

it('strips srcset at the sanitiser, which is what makes the rewriter\'s carve-out safe', function () {
    /*
     * THE PREMISE, ASSERTED DIRECTLY. `RichText::clean()` is the one door, and
     * this is the whole reason `DocumentMediaRewrite` may decline to parse a
     * comma-separated address list without leaving a picture pointing at the
     * old host.
     */
    $clean = RichText::clean(SRCSET_BODY);

    expect(str_contains($clean, 'srcset'))->toBeFalse(
        'RichText now allows srcset. DocumentMediaRewrite does NOT parse it, so article bodies can '
        .'again carry old-host addresses that the rewrite and the audit are both blind to. Either '
        .'teach DocumentMediaRewrite::TAGS to parse srcset -- with its own idempotency argument, '
        .'per-candidate rather than whole-value -- or put the attribute back on the drop list.'
    );

    expect(str_contains($clean, 'sizes='))->toBeFalse()
        // The picture itself is kept, so "clean() ate the image" cannot pass.
        ->and(str_contains($clean, 'src="https://kbeautybliss.com/wp-content/uploads/2021/05/a.jpg"'))->toBeTrue();

    /*
     * AND THE REWRITER SEES EXACTLY ONE ADDRESS — the `src`. If a srcset ever
     * did survive, this is the assertion that shows what it costs: the two
     * candidates are invisible to `sources()`, which is what BOTH the rewrite
     * and MediaAudit read, so nothing in the application would ever mention
     * them.
     */
    expect(DocumentMediaRewrite::sources($clean))
        ->toBe(['https://kbeautybliss.com/wp-content/uploads/2021/05/a.jpg']);
});

it('lands an imported article body with no srcset in it', function () {
    /*
     * END TO END, THROUGH THE REAL IMPORTER, because the premise that matters
     * is about what is in the COLUMN — not about a function in isolation.
     * `PostImporter::settleBody()` runs `RichText::clean()`, and this is the
     * assertion that says so from the outside.
     */
    $path = (new ImportWorkspace)->path('posts');

    File::ensureDirectoryExists(dirname($path));

    $handle = fopen($path, 'wb');
    fputcsv($handle, ['id', 'type', 'slug', 'status', 'title', 'excerpt', 'content', 'date_created_gmt']);
    fputcsv($handle, [
        '7001', PostImporter::ARTICLE_TYPE, 'heartleaf-extract', 'publish',
        'Heartleaf extract', 'An excerpt.', SRCSET_BODY, '2021-05-04 09:00:00',
    ]);
    fclose($handle);

    $this->artisan('kbb:import', [
        '--dir' => (new ImportWorkspace)->directory(),
        '--only' => ['posts'],
        '--run' => 'srcset-test',
    ])->run();

    $body = (string) Post::query()->where('source_post_id', 7001)->value('body');

    expect($body)->not->toBe('')
        ->and(str_contains($body, 'srcset'))->toBeFalse(
            'An imported article body now carries a srcset. DocumentMediaRewrite does not parse one, so '
            .'those addresses will still name the old host after a full media rewrite and the audit will '
            .'report remote = 0 while they do. See the TAGS docblock in that class.'
        )
        // The `src` is there, and it is the address the rewrite will re-point.
        ->and(DocumentMediaRewrite::sources($body))
        ->toBe(['https://kbeautybliss.com/wp-content/uploads/2021/05/a.jpg']);
});

it('drops picture and source elements whole, so there is no second srcset door', function () {
    /*
     * `<source srcset>` is the other shape the attribute arrives in, and it is
     * closed one level higher: `source` is in `RichText::DROP_WHOLE`, so the
     * element and its subtree go before any attribute is looked at, and
     * `picture` is not an allowed element either (it is unwrapped).
     *
     * MUTATION: move 'source' out of RichText::DROP_WHOLE and add
     * 'source' => ['srcset', 'type'] to ALLOWED, and the first assertion here
     * goes red — a srcset survives into an article body that nothing in this
     * application can re-point.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * A FINDING THIS LANE IS PINNING RATHER THAN FIXING, because
     * `app/Support/RichText.php` is not this lane's file:
     *
     * THE `<img>` INSIDE THE `<picture>` GOES TOO, and it should not. libxml's
     * HTML parser is HTML4 and does not know `source` is a void element, so
     * everything after `<source …>` is parsed as its CHILD — and DROP_WHOLE
     * then removes the subtree with the `<img>` in it. A `<picture>` block
     * (whose only valid ordering is `<source>` before `<img>`) therefore
     * arrives as nothing at all, and the article loses the photograph rather
     * than the candidate list.
     *
     * Asserted below as it behaves today, deliberately, so the note is attached
     * to something that runs. It does not affect the srcset argument in either
     * direction — no address survives either way, which is the safe direction —
     * but it is silent media loss on import and belongs to whoever owns
     * RichText. `<picture>` with no `<source>` keeps its `<img>`, which is the
     * second assertion and is what isolates the cause to the void-element
     * parse rather than to `picture` being unknown.
     * ─────────────────────────────────────────────────────────────────────────
     */
    $clean = RichText::clean(
        '<picture><source srcset="https://kbeautybliss.com/wp-content/uploads/2021/05/a-600.webp 600w" '
        .'type="image/webp"><img src="https://kbeautybliss.com/wp-content/uploads/2021/05/a.jpg" alt="a">'
        .'</picture>'
    );

    expect(str_contains($clean, 'srcset'))->toBeFalse(
        'A <source srcset> now survives RichText. DocumentMediaRewrite does not parse srcset, so those '
        .'addresses would stay on the old host through a full media rewrite while the audit reported '
        .'remote = 0. See the TAGS docblock in that class.'
    );

    expect(str_contains($clean, '<source'))->toBeFalse()
        ->and(str_contains($clean, 'a-600.webp'))->toBeFalse()
        // The finding: the <img> is taken with the subtree, so nothing is left.
        ->and(DocumentMediaRewrite::sources($clean))->toBe([]);

    // And with no <source> in it the picture's own <img> survives untouched,
    // which is what places the cause on the void-element parse above.
    expect(DocumentMediaRewrite::sources(RichText::clean(
        '<picture><img src="https://kbeautybliss.com/wp-content/uploads/2021/05/a.jpg" alt="a"></picture>'
    )))->toBe(['https://kbeautybliss.com/wp-content/uploads/2021/05/a.jpg']);
});
