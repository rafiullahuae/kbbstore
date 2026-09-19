<?php

/*
 * THE JOURNAL, FILLED FROM AN EXPORT.
 *
 * docs/GA-SKINCARE-GUIDE.md §6 measured the hole this closes, against a running
 * server: the permalink structure for the Journal is finished and serving
 * nothing. `/skincare-guide/` renders an index with no cards, every `/{slug}/`
 * is a 404, the homepage rail links three addresses that 404, and the reason is
 * that `posts` is empty with no seeder, no importer and a read-only admin
 * screen. `posts.csv` was one of the seven files docs/WP-EXPORT-CONTRACT.md
 * marks as a **gap**: written by the export plugin and opened by nothing.
 *
 * THE FIXTURE IS THE HAZARD, not the happy path. tests/Fixtures/woo/posts.csv
 * carries, in the shapes a real WordPress export carries them: two articles at
 * first path segments the storefront already owns (`wishlist`, `about`), a slug
 * with underscores and capitals, a percent-encoded Arabic slug, a body with an
 * <h1> and a <script> in it, a draft, a scheduled post, a WordPress page, a
 * page-builder post type nobody remembers, two articles competing for one slug,
 * and one row with no title.
 *
 * The single most important assertion in this file is the reserved-slug one.
 * A live article slugged `wishlist` is an indexed URL this application can
 * NEVER serve — PageController::slugPattern() puts /wishlist in front of the
 * root catch-all — so importing it would write a row no request can reach while
 * the report said "created". It is refused, by name, and named again in the
 * discard list the owner approves.
 */

use App\Models\Post;
use App\Services\Import\EntityReport;
use App\Services\Import\Entities\PostImporter;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportReport;
use App\Services\Import\ImportRunner;
use App\Services\ImportConsole\ImportWorkspace;

function postsFixtureDir(): string
{
    return base_path('tests/Fixtures/woo');
}

/** The whole file through the real runner, exactly as Store → Import runs it. */
function postsImport(array $overrides = []): ImportReport
{
    return (new ImportRunner)->run(new ImportOptions(...array_merge([
        'directory' => postsFixtureDir(),
        'only' => ['posts'],
        'runKey' => 'posts-'.bin2hex(random_bytes(4)),
    ], $overrides)));
}

/** @return list<string> every refusal reason for the posts bucket */
function postsRejections(ImportReport $report): array
{
    return array_map(
        static fn (array $r): string => $r['id'].' :: '.$r['reason'],
        $report->for('posts')->rejections(),
    );
}

/** The one refusal naming this WordPress id, or null. */
function postsRejectionFor(ImportReport $report, string $id): ?string
{
    foreach ($report->for('posts')->rejections() as $rejection) {
        if ($rejection['id'] === 'id='.$id) {
            return $rejection['reason'];
        }
    }

    return null;
}

/* ------------------------------------------------------------------ the run */

it('fills the Journal from an export, and the index has cards in it', function () {
    expect(Post::query()->count())->toBe(0, 'the Journal starts empty — that is the whole finding');

    /*
     * THE BEFORE, MEASURED RATHER THAN QUOTED. Lane GA established it against a
     * running server and this asserts the same thing here, so the comparison
     * below is between two things this test has actually seen: the index
     * answers 200 and has no article on it, and the article's own address is a
     * 404. A before/after table whose "before" is a citation is a table that
     * goes stale without anybody noticing.
     */
    $empty = $this->get('/skincare-guide/');
    $empty->assertOk();

    expect(str_contains($empty->getContent(), 'Heartleaf extract'))->toBeFalse();
    $this->get('/heartleaf-extract-transforming-k-beauty-skincare/')->assertNotFound();

    $report = postsImport();

    // The five importable articles: 7001, 7002, 7005, 7006, 7007, 7008.
    expect(Post::query()->whereNotNull('source_post_id')->count())->toBe(6);

    $article = Post::query()->where('source_post_id', 7001)->firstOrFail();

    expect($article->slug)->toBe('heartleaf-extract-transforming-k-beauty-skincare')
        ->and($article->title)->toBe('Heartleaf extract, and why it is everywhere')
        ->and($article->status)->toBe('published')
        ->and($article->author)->toBe('Rafi')
        ->and($article->tag)->toBe('Ingredients')
        ->and($article->cover)->toBe('https://kbeautybliss.com/wp-content/uploads/2021/05/heartleaf.jpg')
        // date_created_gmt is read AS UTC and the local column is not guessed at.
        ->and($article->published_at->toDateTimeString())->toBe('2021-05-04 09:00:00');

    // And the page GA found empty now renders the article.
    $index = $this->get('/skincare-guide/');

    $index->assertOk();
    expect($index->getContent())->toContain('Heartleaf extract, and why it is everywhere');

    $page = $this->get('/heartleaf-extract-transforming-k-beauty-skincare/');

    $page->assertOk();
    expect($page->getContent())->toContain('Heartleaf is');

    // Every row of the file is accounted for, which is the check Phase 13 asks
    // for and the one this importer has to satisfy like every other entity.
    $v = $report->for('posts')->verification();

    expect($v['read'])->toBe(12)
        ->and($v['unaccounted'])->toBe(0)
        ->and($v['accounted'] + $v['rejected'])->toBe($v['read'])
        ->and($v['verdict'])->toBe('verified', $v['sentence']);
});

/* ------------------------------------------------- the reserved-slug hazard */

it('refuses an article at an address the storefront already owns, and says which', function () {
    $report = postsImport();

    foreach (['7003' => 'wishlist', '7004' => 'about'] as $id => $slug) {
        $reason = postsRejectionFor($report, $id);

        expect($reason)->not->toBeNull('the article slugged '.$slug.' was not refused by name');
        expect(str_contains((string) $reason, "slug '".$slug."' is a first path segment"))->toBeTrue($reason);
        expect(str_contains((string) $reason, 'only the owner can settle that'))->toBeTrue($reason);

        // And nothing was written for it. A row at a reserved slug would be a
        // row no request can reach.
        expect(Post::query()->where('source_post_id', (int) $id)->exists())->toBeFalse();
        expect(Post::query()->where('slug', $slug)->exists())->toBeFalse();
    }

    /*
     * AND IT IS IN THE DISCARD LIST, which is the difference between a row the
     * owner can read about and a row the owner is asked to APPROVE the loss of.
     * Phase 13's third bucket, and the reason a rejection alone is not enough:
     * the rejection list is a list of rows the importer could not take, and
     * this is a list of content the shop will not have.
     */
    $kinds = array_keys($report->for('posts')->discards());
    $reserved = array_values(array_filter(
        $kinds,
        static fn (string $k): bool => str_contains($k, 'whose address this storefront already owns'),
    ));

    expect($reserved)->toHaveCount(1);
    expect($report->for('posts')->discards()[$reserved[0]]['count'])->toBe(2);

    $samples = array_column($report->for('posts')->discards()[$reserved[0]]['samples'], 'before');

    expect($samples)->toContain('The wishlist we keep coming back to (/wishlist/)');
});

it('reads the reserved list off the router rather than keeping a second copy of it', function () {
    /*
     * MUTATION-SHAPED, and this is the guard that matters most on this file.
     * The check is `preg_match` against PageController::slugPattern(), which is
     * the pattern the router is actually handed — so a slug is refused if and
     * only if the route would refuse it. The proof is that a slug NOT on the
     * list imports, one ON it does not, and the two answers come from the same
     * expression the router uses.
     */
    $pattern = '/^(?:'.\App\Http\Controllers\Store\PageController::slugPattern().')$/';

    expect(preg_match($pattern, 'wishlist'))->toBe(0)
        ->and(preg_match($pattern, 'about'))->toBe(0)
        ->and(preg_match($pattern, 'feed'))->toBe(0)
        ->and(preg_match($pattern, 'heartleaf-extract-transforming-k-beauty-skincare'))->toBe(1);

    postsImport();

    // The router agrees: the refused address is the shop's, and the imported
    // one is the article's.
    expect($this->get('/my-wishlist/')->status())->not->toBe(404);
    $this->get('/heartleaf-extract-transforming-k-beauty-skincare/')->assertOk();
});

/* --------------------------------------------------- slugs of the wrong shape */

it('normalises a slug this application cannot serve rather than losing the article', function () {
    $report = postsImport();

    $spf = Post::query()->where('source_post_id', 7005)->firstOrFail();
    expect($spf->slug)->toBe('spf-50-every-day');

    /*
     * WordPress percent-encodes the slug of a non-Latin title, so the cell is
     * `%d8%a7%d9%84...`. Decoded it is Arabic, and Str::slug() would
     * transliterate it to `alaanay-balbshr` -- a valid address no human will
     * recognise. A slug with no Latin in it at all is normalised from the
     * TITLE instead, which is the only basis that produces a readable URL.
     */
    $arabic = Post::query()->where('source_post_id', 7006)->firstOrFail();
    expect($arabic->slug)->toBe('skin-care-in-the-gulf-summer');

    $this->get('/spf-50-every-day/')->assertOk();

    /*
     * AND IT IS AN ADJUSTMENT AND NOT A SILENT REWRITE. The old address is
     * indexed and now 404s, so the owner has to be told which addresses need a
     * redirect row — a count of "6 created" cannot tell them.
     */
    $kinds = array_keys($report->for('posts')->adjustments());
    $slugs = array_values(array_filter($kinds, static fn (string $k): bool => str_contains($k, 'normalised')));

    expect($slugs)->toHaveCount(1);

    $samples = $report->for('posts')->adjustments()[$slugs[0]]['samples'];
    $pairs = array_map(static fn (array $s): string => $s['before'].' -> '.$s['after'], $samples);

    expect($pairs)->toContain('SPF_50_Every_Day -> spf-50-every-day');
});

/* -------------------------------------------------------- what is not an article */

it('refuses a WordPress page and every other post type, with its title and its size', function () {
    $report = postsImport();

    expect(postsRejectionFor($report, '7009'))->toContain("post type 'page' is not an article");
    expect(postsRejectionFor($report, '7010'))->toContain("post type 'elementor_library' is not an article");

    expect(Post::query()->where('source_post_id', 7009)->exists())->toBeFalse();

    $kinds = array_keys($report->for('posts')->discards());
    $types = array_values(array_filter(
        $kinds,
        static fn (string $k): bool => str_contains($k, 'post type this shop has no screen for'),
    ));

    expect($types)->toHaveCount(1);

    $samples = array_column($report->for('posts')->discards()[$types[0]]['samples'], 'before');

    expect($samples)->toContain('About us -- 31 characters of content');
});

it('refuses rather than skips, because a skipped row is the alarm for a row that vanished', function () {
    /*
     * THE PROPERTY THIS PROTECTS is ImportRunner's own: a row that moves no
     * tally and throws nothing is recorded as UNACCOUNTED, and an unaccounted
     * row fails the command. The four rows this entity declines — two pages,
     * two reserved slugs — must therefore come back as REFUSALS and never as
     * silence, or a deliberate decision would raise the alarm reserved for a
     * row nobody can name.
     */
    $report = postsImport();
    $v = $report->for('posts')->verification();

    expect($v['unaccounted'])->toBe(0)
        ->and($v['rejected'])->toBe(6)
        ->and($report->hasDiscrepancy())->toBeFalse();
});

/* ----------------------------------------------------------------- statuses */

it('never publishes a draft or a scheduled post on the owner\'s behalf', function () {
    $report = postsImport();

    expect(Post::query()->where('source_post_id', 7007)->value('status'))->toBe('draft')
        ->and(Post::query()->where('source_post_id', 7008)->value('status'))->toBe('draft');

    /*
     * `future` is the one that would bite. PageController::blog() filters on
     * status and NOT on published_at, so a scheduled post imported as published
     * would be on /skincare-guide/ the moment the import finished.
     */
    $index = $this->get('/skincare-guide/')->getContent();

    expect(str_contains($index, 'The winter edit'))->toBeFalse('a scheduled post reached the index')
        ->and(str_contains($index, 'Retinol for beginners'))->toBeFalse('a draft reached the index');

    $this->get('/winter-edit-2027/')->assertNotFound();

    $kinds = array_keys($report->for('posts')->adjustments());
    $status = array_values(array_filter($kinds, static fn (string $k): bool => str_contains($k, 'imported as a draft')));

    expect($status)->toHaveCount(1)
        /*
         * ONE, not two. 7008 is `future` and becomes a draft, which is a
         * change. 7007 is already `draft` and is imported as one, which is
         * not -- reporting it would put "status: draft -> draft" in the list
         * the owner is asked to approve, and a list with a no-op in it is a
         * list that gets skimmed. 7012 never reaches the status decision: it
         * is refused for having no title.
         */
        ->and($report->for('posts')->adjustments()[$status[0]]['count'])->toBe(1);
});

/* --------------------------------------------------------------------- HTML */

it('strips what a browser would execute, and keeps the heading it would have flattened', function () {
    $report = postsImport();

    $body = (string) Post::query()->where('source_post_id', 7002)->value('body');

    expect($body)->not->toContain('<script')
        ->and($body)->not->toContain('<iframe')
        ->and(str_contains($body, 'alert(1)'))->toBeFalse('the payload survived as visible copy');

    /*
     * AND THE HEADING SURVIVES AS A HEADING. RichText::clean() does not allow
     * h1 and does not treat it as hostile, so it UNWRAPS it — cleaning a raw
     * WordPress body would turn every "Heading 1" block into paragraph text.
     * The importer demotes to h2 first, which is what App\Support\BodyHeadings
     * does at render time and says in as many words is the right disposal.
     */
    expect($body)->toContain('<h2>Why twice</h2>');

    // The page serves exactly one h1 — its own.
    $page = $this->get('/double-cleansing-explained/')->getContent();

    expect(substr_count($page, '<h1'))->toBe(1);

    expect(array_keys($report->for('posts')->discards()))
        ->toContain('HTML the allowlist removed -- an article body is rendered raw onto a public page, so the '
            .'import strips what a browser would execute and the imported article is not byte-for-byte '
            .'what WordPress held');
});

it('keeps the mutation honest: without the demotion the heading would be flattened', function () {
    /*
     * The guard above asserts a POSITIVE. This one proves the negative it is
     * guarding against is real, so that "we demote before cleaning" cannot be
     * deleted and stay green.
     */
    $flattened = \App\Support\RichText::clean('<h1>Why twice</h1><p>Oil lifts oil.</p>');

    expect($flattened)->not->toContain('<h1')
        ->and($flattened)->not->toContain('<h2')
        ->and($flattened)->toContain('Why twice');
});

/* ------------------------------------------------------------ two for one slug */

it('refuses the second of two articles competing for one address', function () {
    $report = postsImport();

    // 7002 is first in the file and keeps the slug; 7011 wants the same one.
    expect(Post::query()->where('slug', 'double-cleansing-explained')->value('source_post_id'))->toBe(7002);
    expect(postsRejectionFor($report, '7011'))
        ->toContain("slug 'double-cleansing-explained' already belongs to source_post_id 7002");

    // And the row with no title at all.
    expect(postsRejectionFor($report, '7012'))->toContain('title is required');
});

/* ------------------------------------------------------------- idempotence */

it('reports every row unchanged on a second pass over the same export', function () {
    postsImport();

    $before = Post::query()->orderBy('id')->get(['source_post_id', 'slug', 'title', 'body', 'status', 'published_at'])
        ->map->toArray()->all();

    $second = postsImport();
    $posts = $second->for('posts');

    expect($posts->created)->toBe(0, 'a created row on the second pass is a duplicate')
        ->and($posts->updated)->toBe(0, 'an updated row on the second pass is a row rewritten every run')
        ->and($posts->unchanged)->toBe(6);

    $after = Post::query()->orderBy('id')->get(['source_post_id', 'slug', 'title', 'body', 'status', 'published_at'])
        ->map->toArray()->all();

    expect($after)->toBe($before);
});

/* --------------------------------------------------- driven from the screen */

it('is offered by Store → Import, in the runner\'s own order', function () {
    /*
     * MUST MIRROR ImportRunner::entities() — ImportWorkspace's own comment says
     * the two hand-maintained lists have to agree, because they once did not
     * and every upload 500'd on an undefined key.
     */
    expect(ImportWorkspace::entities())->toBe(ImportRunner::entityNames());
    expect(ImportRunner::entityNames())->toContain('posts');
    expect(ImportWorkspace::meta('posts')['file'])->toBe('posts.csv');

    // And the entity answers the interface the runner drives it through.
    $importer = new PostImporter;

    expect($importer->name())->toBe('posts')
        ->and($importer->conventionalFile())->toBe('posts.csv')
        ->and($importer->countImported())->toBe(0);
});

it('puts its count verification where both front ends already print it', function () {
    $notes = postsImport()->for('posts')->notes();

    $verification = array_values(array_filter(
        array_keys($notes),
        static fn (string $n): bool => str_starts_with($n, EntityReport::VERIFICATION_NOTE_PREFIX),
    ));

    expect($verification)->toHaveCount(1)
        ->and($verification[0])->toContain('12 read')
        ->and($verification[0])->toContain('in the database');
});
