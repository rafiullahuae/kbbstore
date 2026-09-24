<?php

declare(strict_types=1);

/*
 * ════════════════════════════════════════════════════════════════════════════
 * LANE A — Phase 13, items 1 and 2: the two ways a picture stays on the old
 * site after the migration says it is finished
 * ════════════════════════════════════════════════════════════════════════════
 *
 * WHAT THE SHOP LOOKED LIKE WITH THESE TWO DEFECTS IN IT, which is the thing a
 * test has to be able to fail on:
 *
 *  1. FETCHING DID NOT RE-POINT THE ROWS. The sideloader put every photograph
 *     on this server's disk and left `products.image` saying
 *     `https://kbeautybliss.com/wp-content/uploads/…`. Every page rendered
 *     perfectly — FROM THE OLD SITE — and the only thing standing between that
 *     and a shop of broken frames was a sentence in a runbook: "do not switch
 *     the old site off between the two steps". On a migration whose entire
 *     point is switching the old site off.
 *
 *  2. THE JOURNAL WAS NOT IN ANY OF IT. `MediaAudit` did not look at `posts`,
 *     so an article's cover photograph and every `<img>` in its body were
 *     invisible: not counted as "still on the old site", never fetched, and
 *     `MediaRewrite` had no column for them. A migration could reach
 *     `remote => 0` with the whole Journal hot-linked to a site about to go
 *     dark.
 *
 * THE FINISH LINE IS A QUERY. `it leaves no row anywhere naming the old host`
 * asks the database, in SQL, across every table that can hold one — which is
 * the form the brief asked for and the only form that cannot be satisfied by
 * looking at a screen.
 *
 * `expect(...)->not->toContain($x, $message)` IS NOT USED ANYWHERE HERE.
 * `toContain` is variadic, so the message is read as a second needle and the
 * assertion passes vacuously — CLAUDE.md records it. Absence is asserted with
 * `str_contains`, `array_diff` or a plain identity.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Import\DocumentMediaRewrite;
use App\Services\Import\MediaAudit;
use App\Services\Import\MediaRewrite;
use App\Services\Import\MediaSideloader;
use App\Services\Import\MigrationProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\UrlsMediaAdminRoutes;

const RP_OLD = 'https://old-shop.test';

/** Real JPEG bytes. The sideloader sniffs, so a string that looks like them will not do. */
function rpJpeg(int $padding = 48): string
{
    return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01".str_repeat("\x2A", $padding)."\xFF\xD9";
}

function rpClean(): void
{
    foreach (['wp-content', 'uploads'] as $root) {
        if (is_dir(public_path($root))) {
            File::deleteDirectory(public_path($root));
        }
    }
}

/** Put a real file where the catalogue's URL says it should end up. */
function rpLand(string $url): string
{
    $relative = (string) MediaRewrite::uploadsRelativeTo($url);

    File::ensureDirectoryExists(dirname(public_path($relative)));
    file_put_contents(public_path($relative), rpJpeg());

    return $relative;
}

/**
 * Every value in the database that still names a host, found in SQL.
 *
 * THE FINISH LINE, ASKED OF THE DATABASE. Not "the screen said zero" and not a
 * count from the class under test: five `LIKE` queries over the five places a
 * picture address can be stored on this schema.
 *
 * @return list<string>
 */
function rpRowsNaming(string $host): array
{
    $needle = '%'.$host.'%';
    $out = [];

    foreach ([
        ['products', 'image'],
        ['products', 'images'],
        ['brands', 'logo'],
        ['categories', 'image'],
        ['posts', 'cover'],
        ['posts', 'body'],
    ] as [$table, $column]) {
        foreach (DB::table($table)->where($column, 'like', $needle)->pluck('id') as $ignored) {
            // The COLUMN and not the row id: this checkout's migration set
            // seeds rows, so an id is a number that moves when somebody else's
            // seeder changes and an assertion written against one is a flake.
            $out[] = $table.'.'.$column;
        }
    }

    sort($out);

    return $out;
}

beforeEach(function (): void {
    rpClean();
    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => 'https://kbb.test']);
    config(['app.url' => 'https://kbb.test']);
});

afterEach(function (): void {
    rpClean();
});

/* ========================================================================== */
/*  ITEM 1 — THE FETCH RE-POINTS WHAT IT LANDED                                */
/* ========================================================================== */

it('leaves no row anywhere naming the old host after one fetch', function () {
    /*
     * THE WHOLE OF PHASE 13 ITEMS 1 AND 2, IN ONE ASSERTION MADE IN SQL.
     *
     * A product photograph, a gallery entry, a brand logo, a category picture,
     * an article's cover and an `<img>` inside an article's body — six shapes,
     * five tables, one old host. Before this lane the answer after a complete
     * fetch was all six; the last two were not even fetched.
     *
     * MUTATION: delete the `$this->repoint($landed)` call at the end of
     * MediaSideloader::batch() and this goes red with all six rows listed.
     * Remove `[Post::class, 'posts', 'cover', false]` from
     * MediaRewrite::COLUMNS and it goes red with the article's cover.
     * Remove the `posts` loop from MediaAudit::references() and it goes red
     * with both of the article's rows, because nothing fetched their files.
     */
    Product::query()->create([
        'name' => 'Ginseng serum', 'slug' => 'ginseng', 'price' => 1000, 'status' => 'published',
        'image' => RP_OLD.'/wp-content/uploads/2019/03/ginseng.jpg',
        'images' => [RP_OLD.'/wp-content/uploads/2019/03/ginseng-2.jpg'],
    ]);

    Brand::query()->create([
        'name' => 'RP Joseon', 'slug' => 'rp-boj',
        'logo' => RP_OLD.'/wp-content/uploads/2020/01/boj.jpg',
    ]);

    Category::query()->create([
        'name' => 'RP serums', 'slug' => 'rp-serums',
        'image' => RP_OLD.'/wp-content/uploads/2020/02/serums.jpg',
    ]);

    Post::query()->create([
        'slug' => 'heartleaf', 'title' => 'Heartleaf', 'status' => 'published',
        'cover' => RP_OLD.'/wp-content/uploads/2021/05/heartleaf.jpg',
        'body' => '<p>Heartleaf is <b>everywhere</b>.</p>'
            .'<img src="'.RP_OLD.'/wp-content/uploads/2021/05/inline.jpg" alt="Heartleaf">',
    ]);

    expect(rpRowsNaming('old-shop.test'))->toHaveCount(6);

    Http::fake([RP_OLD.'/*' => fn () => Http::response(rpJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    $result = (new MediaSideloader)->batch(['files' => 50]);

    expect($result['fetched'])->toBe(6)
        ->and($result['repointed'])->toBe(['rows' => 5, 'documents' => 1])
        ->and(rpRowsNaming('old-shop.test'))->toBe([])
        ->and((new MediaAudit)->summarise((new MediaAudit)->audit()))
        ->toBe(['present' => 6, 'missing' => 0, 'remote' => 0]);
});

it('re-points only the addresses the batch actually fetched', function () {
    /*
     * THE RESTRAINT, PINNED. `MediaRewrite::propose()` would happily re-point
     * every row on the host whose file is on disk — including one copied
     * across by FTP months ago, which is a job the owner starts himself from
     * Addresses & pictures. Pressing Fetch must not do something nobody asked
     * for.
     *
     * MUTATION: drop the `isset($wanted[$proposal['from']])` half of the filter
     * in MediaSideloader::repoint() and the FTP row is re-pointed too, which
     * this catches.
     */
    Product::query()->create([
        'name' => 'Fetched', 'slug' => 'fetched', 'price' => 1000, 'status' => 'published',
        'image' => RP_OLD.'/wp-content/uploads/2019/fetched.jpg',
    ]);

    // Already on disk, never fetched by this class: the owner's FTP copy.
    $ftp = RP_OLD.'/wp-content/uploads/2019/by-hand.jpg';
    rpLand($ftp);

    Product::query()->create([
        'name' => 'By hand', 'slug' => 'by-hand', 'price' => 1000, 'status' => 'published',
        'image' => $ftp,
    ]);

    Http::fake([RP_OLD.'/*' => fn () => Http::response(rpJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    (new MediaSideloader)->batch(['files' => 50]);

    expect(rpRowsNaming('old-shop.test'))->toBe(['products.image']);

    // And the manual apply, which is the step that owns that row, still does it.
    $rewrite = new MediaRewrite;

    expect($rewrite->apply($rewrite->propose(['old-shop.test'])))->toBe(1)
        ->and(rpRowsNaming('old-shop.test'))->toBe([]);
});

it('keeps the progress bar honest once finished work leaves the catalogue', function () {
    /*
     * THE COUNTER THIS CHANGE COULD HAVE BROKEN, and did break before
     * `repointed` was added.
     *
     * Every number `plan()` reports is recomputed from the catalogue and the
     * disk. A re-pointed row is no longer a REMOTE reference, so it leaves
     * `total` at the same moment it would have joined `present`: the fraction
     * drawn from those two sits at 0 of N for the whole of a run that is going
     * perfectly, and then reads 0 of 0 at the end.
     *
     * MUTATION: make MigrationProgress::pictures() use `$plan['present']` and
     * `$plan['total']` without adding `$repointed`, and the `done`/`total`
     * assertions below go to 0 and 2 mid-run and 0 and 0 at the end.
     */
    Product::query()->create([
        'name' => 'One', 'slug' => 'one', 'price' => 1000, 'status' => 'published',
        'image' => RP_OLD.'/wp-content/uploads/2019/one.jpg',
    ]);
    Product::query()->create([
        'name' => 'Two', 'slug' => 'two', 'price' => 1000, 'status' => 'published',
        'image' => RP_OLD.'/wp-content/uploads/2019/two.jpg',
    ]);

    Http::fake([RP_OLD.'/*' => fn () => Http::response(rpJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    $pictures = static function (): array {
        foreach ((new MigrationProgress)->snapshot()['stages'] as $stage) {
            if ($stage['key'] === 'pictures') {
                return $stage;
            }
        }

        return [];
    };

    expect($pictures()['total'])->toBe(2)->and($pictures()['done'])->toBe(0);

    (new MediaSideloader)->batch(['files' => 1]);

    // Half way: one done, one to go, and the denominator has not shrunk.
    expect($pictures()['done'])->toBe(1)
        ->and($pictures()['total'])->toBe(2)
        ->and($pictures()['remaining'])->toBe(1);

    (new MediaSideloader)->batch(['files' => 1]);

    expect($pictures()['done'])->toBe(2)
        ->and($pictures()['total'])->toBe(2)
        ->and($pictures()['remaining'])->toBe(0)
        ->and($pictures()['headline'])->toBe('Finished — every picture the catalogue names is on this shop\'s own disk.');
});

/* ========================================================================== */
/*  ITEM 2 — THE JOURNAL. A DOCUMENT, NOT A CELL.                              */
/* ========================================================================== */

it('sees the pictures inside an article body, which the audit used to be blind to', function () {
    /*
     * The blindness came first: an address `MediaAudit` cannot see is a file
     * the sideloader never fetches, so every proposal about it would report
     * ABSENT for ever. This is why the audit and the rewriter share one parser.
     *
     * MUTATION: remove the `posts` loop from MediaAudit::references() and this
     * reports remote => 0 with two pictures plainly on the old host.
     */
    Post::query()->create([
        'slug' => 'routine', 'title' => 'A routine', 'status' => 'published',
        'cover' => RP_OLD.'/wp-content/uploads/2021/cover.jpg',
        'body' => '<p>One</p><img src="'.RP_OLD.'/wp-content/uploads/2021/a.jpg">'
            .'<p>Two</p><img src=\''.RP_OLD.'/wp-content/uploads/2021/b.jpg\' width="600">',
    ]);

    expect((new MediaAudit)->summarise((new MediaAudit)->audit()))
        ->toBe(['present' => 0, 'missing' => 0, 'remote' => 3]);
});

it('changes the src and nothing else in the document', function () {
    /*
     * WHY THIS IS NOT DOMDocument. `saveHTML()` re-serialises: it closes tags,
     * re-quotes attributes, infers paragraphs and turns non-ASCII into
     * entities. An Arabic article would come back different in every byte for
     * a change to one attribute, and a rewrite that touches what it was not
     * asked to touch cannot be reviewed.
     *
     * So the document is asserted byte for byte, with the ONE substitution
     * spelled out — including the single-quoted attribute, the unquoted one,
     * the entity in the caption and the non-ASCII text, all of which a
     * re-serialiser would have rewritten.
     */
    $a = RP_OLD.'/wp-content/uploads/2021/a.jpg';
    rpLand($a);

    $body = "<p>الترطيب &amp; اللمعان</p>\n"
        ."<img src='".$a."' alt='الترطيب'>\n"
        .'<img src='.RP_OLD."/wp-content/uploads/2021/missing.jpg>\n"
        .'<p>A <a href="'.RP_OLD.'/wp-content/uploads/2021/a.jpg">link to the same file</a></p>';

    $post = Post::query()->create([
        'slug' => 'arabic', 'title' => 'Arabic', 'status' => 'published', 'body' => $body,
    ]);

    $rewrite = new DocumentMediaRewrite;
    $proposals = $rewrite->propose(['old-shop.test']);

    expect($rewrite->apply($proposals))->toBe(1);

    $post->refresh();

    expect($post->body)->toBe(str_replace("src='".$a."'", "src='/wp-content/uploads/2021/a.jpg'", $body));

    /*
     * AND WHAT IT LEFT ALONE, said out loud. The second `<img>` names a file
     * that is not on disk, so re-pointing it would turn a picture that loads
     * into one that does not; the `<a href>` is not an `<img>` and this class
     * does not claim anchors.
     */
    expect(str_contains($post->body, 'missing.jpg') && str_contains($post->body, RP_OLD.'/wp-content/uploads/2021/missing.jpg'))->toBeTrue()
        ->and(str_contains($post->body, '<a href="'.RP_OLD.'/wp-content/uploads/2021/a.jpg">'))->toBeTrue()
        ->and(str_contains($post->body, 'الترطيب &amp; اللمعان'))->toBeTrue();
});

it('is idempotent, because the import it follows is', function () {
    /*
     * `PostImporter` matches on `posts.source_post_id` and re-presents every
     * row on every run, so an article's body is written again each time the
     * owner imports — and a rewrite that is not idempotent produces
     * `/wp-content/uploads/wp-content/uploads/…` on the second pass, which
     * nobody notices until the page is a wall of broken frames.
     *
     * MUTATION: match by substring in DocumentMediaRewrite::replace() instead
     * of on the whole decoded value and the second pass doubles the path.
     */
    $url = RP_OLD.'/wp-content/uploads/2021/x.jpg';
    rpLand($url);

    $post = Post::query()->create([
        'slug' => 'twice', 'title' => 'Twice', 'status' => 'published',
        'body' => '<img src="'.$url.'"><img src="'.$url.'">',
    ]);

    $rewrite = new DocumentMediaRewrite;

    expect($rewrite->apply($rewrite->propose(['old-shop.test'])))->toBe(1);

    $post->refresh();
    $once = (string) $post->body;

    // Both occurrences, and only the src of each: the document is otherwise
    // the one it was.
    expect($once)->toBe('<img src="/wp-content/uploads/2021/x.jpg"><img src="/wp-content/uploads/2021/x.jpg">');

    // Second pass: nothing proposed, nothing written, byte-identical.
    expect($rewrite->propose(['old-shop.test']))->toBe([])
        ->and($rewrite->apply($rewrite->propose(['old-shop.test'])))->toBe(0);

    $post->refresh();

    expect($post->body)->toBe($once)
        ->and(substr_count((string) $post->body, 'wp-content/uploads/wp-content'))->toBe(0);
});

it('will not re-point a picture whose file is not on disk yet', function () {
    /*
     * The rule `MediaRewrite`'s header states, applied to documents: re-pointing
     * first and copying afterwards turns an article that renders into an
     * article of broken frames, and there is no way to tell from the shop which
     * tags were touched.
     */
    $post = Post::query()->create([
        'slug' => 'absent', 'title' => 'Absent', 'status' => 'published',
        'body' => '<img src="'.RP_OLD.'/wp-content/uploads/2021/nothing.jpg">',
    ]);

    $before = (string) $post->body;
    $rewrite = new DocumentMediaRewrite;
    $proposals = $rewrite->propose(['old-shop.test']);

    expect($proposals)->toHaveCount(1)
        ->and($proposals[0]['decision'])->toBe(DocumentMediaRewrite::ABSENT)
        ->and($rewrite->apply($proposals))->toBe(0);

    $post->refresh();

    expect($post->body)->toBe($before);
});

it('leaves an address that is under no uploads root alone', function () {
    // A supplier's photograph on a CDN, or an address an admin typed. Not this
    // class's business, and "correcting" it into a local path that does not
    // exist is the failure MediaRewrite's header names.
    $post = Post::query()->create([
        'slug' => 'cdn', 'title' => 'CDN', 'status' => 'published',
        'body' => '<img src="'.RP_OLD.'/images/hero.jpg">',
    ]);

    expect((new DocumentMediaRewrite)->propose(['old-shop.test']))->toBe([]);

    $post->refresh();

    expect(str_contains((string) $post->body, RP_OLD.'/images/hero.jpg'))->toBeTrue();
});

it('puts the host back in a document when the rewrite is restored', function () {
    // The inverse, and the reason there is no ledger table: the transformation
    // drops a scheme and a host and keeps the path byte for byte.
    $url = RP_OLD.'/wp-content/uploads/2021/r.jpg';
    rpLand($url);

    $post = Post::query()->create([
        'slug' => 'restore', 'title' => 'Restore', 'status' => 'published',
        'body' => '<p>x</p><img src="'.$url.'">',
    ]);

    $rewrite = new DocumentMediaRewrite;
    $rewrite->apply($rewrite->propose(['old-shop.test']));

    $restored = $rewrite->restore('old-shop.test');

    expect($restored['restored'])->toBe(1)
        ->and($restored['documents'])->toBe(1);

    $post->refresh();

    expect($post->body)->toBe('<p>x</p><img src="'.$url.'">');
});

/* ========================================================================== */
/*  THE SCREEN SAYS SO                                                         */
/* ========================================================================== */

it('reports the journal separately on Store → Import → Addresses & pictures', function () {
    /*
     * Two counts, not one summed number: "how many rows" and "how many
     * pictures inside how many articles" are different questions and a single
     * total over both would be a number with no unit.
     *
     * Store → Import → Addresses & pictures → apply is where this sits.
     */
    $url = RP_OLD.'/wp-content/uploads/2021/j.jpg';
    rpLand($url);

    Post::query()->create([
        'slug' => 'j', 'title' => 'J', 'status' => 'published',
        'body' => '<img src="'.$url.'">',
    ]);

    UrlsMediaAdminRoutes::wire($this->app);

    $admin = AdminUser::query()->create([
        'name' => 'Owner', 'email' => 'owner@kbb.test', 'password' => bcrypt('secret'), 'role' => 'owner',
    ]);

    $preview = $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/urls-media/media', ['action' => 'preview', 'hosts' => ['old-shop.test']]);

    $preview->assertOk();

    expect($preview->json('journal.summary.rewrite'))->toBe(1)
        ->and($preview->json('journal.summary.documents'))->toBe(1);

    // A preview writes nothing.
    expect(rpRowsNaming('old-shop.test'))->toBe(['posts.body']);

    $apply = $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/urls-media/media', ['action' => 'apply', 'hosts' => ['old-shop.test']]);

    $apply->assertOk();

    expect($apply->json('journal.articles'))->toBe(1)
        ->and(rpRowsNaming('old-shop.test'))->toBe([]);
});
