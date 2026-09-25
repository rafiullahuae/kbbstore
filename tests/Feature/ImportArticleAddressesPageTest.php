<?php

declare(strict_types=1);

/*
 * ════════════════════════════════════════════════════════════════════════════
 * LANE U3 — Phase 13, item 3: the list existed and nothing could reach it
 * ════════════════════════════════════════════════════════════════════════════
 *
 * WHAT THE DEFECT LOOKED LIKE ON THE SHOP, which is not a stack trace:
 *
 * Lane A built `ReservedArticleReport` in full and mounted it twice — a JSON
 * endpoint and a CSV download. Both worked. Neither was a screen and neither
 * was linked from anywhere, so the owner's side of the defect was still open:
 * to find out which of his live articles this shop can never serve he had to
 * know the URL of an admin-api endpoint and read a JSON body. The plan's own
 * wording is "**Run a preview** — it writes nothing — and it produces the list
 * Lane GA asked the owner for", and a list is a thing you look at.
 *
 * AND THE LIST WAS SHORT OF THE ONE COLUMN EACH ROW IS ACTED ON WITH. Every row
 * is a rename-and-redirect the owner performs in WordPress, and a redirect is
 * written FROM an address. The report offered `/{slug}/`, which is the address
 * the article wanted ON THIS SHOP — computed, because articles here live at the
 * site root. It is the same string as the live URL only when the old site's
 * permalink structure happens to be `/%postname%/`. On a WordPress publishing
 * at `/blog/%postname%/` the row said `/about/` and the address Google actually
 * holds is `https://old/blog/about/`, so the 301 the owner was told to write
 * would redirect an address nobody has ever requested — a silent, plausible
 * failure, on the one part of the migration that cannot be re-run after the old
 * site is off.
 *
 * The live URL is now READ, out of `permalinks.csv` — WordPress's own
 * `get_permalink()` for every row of the site, which `RedirectMap` already
 * calls the only source that can be right about a permalink structure this
 * application cannot see. When the file is not uploaded the column is EMPTY and
 * the page says which file fills it; nothing is ever derived into it.
 *
 * `expect(...)->not->toContain($x, $message)` IS NOT USED ANYWHERE HERE.
 * `toContain` is variadic, so the message is read as a second needle and the
 * assertion passes vacuously — CLAUDE.md records it.
 */

use App\Models\AdminUser;
use App\Services\Import\ReservedArticleReport;
use App\Services\ImportConsole\ImportWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\Support\ImportAdminRoutes;

/** The header the WordPress exporter writes for posts.csv. */
const AAP_HEADER = 'id,type,slug,status,title,excerpt,content,author_name,date_created_gmt,image,categories,tags';

/** The header the WordPress exporter writes for permalinks.csv. */
const AAP_PERMALINK_HEADER = 'type,wc_id,slug,permalink,status,source,note';

/**
 * Put a posts.csv in the import workspace, exactly where an upload leaves one.
 *
 * @param  list<array<int, string>>  $rows
 */
function aapPostsCsv(array $rows): void
{
    aapWrite((new ImportWorkspace)->path('posts'), explode(',', AAP_HEADER), $rows);
}

/**
 * Put a permalinks.csv beside it, exactly where the "Addresses and pictures"
 * group of the export leaves one.
 *
 * @param  list<array<int, string>>  $rows
 */
function aapPermalinksCsv(array $rows): void
{
    aapWrite((new ImportWorkspace)->companionPath('permalinks'), explode(',', AAP_PERMALINK_HEADER), $rows);
}

/**
 * @param  list<string>  $header
 * @param  list<array<int, string>>  $rows
 */
function aapWrite(string $path, array $header, array $rows): void
{
    File::ensureDirectoryExists(dirname($path));

    $handle = fopen($path, 'wb');
    fputcsv($handle, $header);

    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }

    fclose($handle);
}

/** One article row, with only the cells this report reads spelled out. */
function aapRow(int $id, string $slug, string $title, string $type = 'post', string $status = 'publish'): array
{
    return [
        (string) $id, $type, $slug, $status, $title, 'An excerpt.', '<p>Body.</p>',
        'Rafi', '2021-05-04 09:00:00', '', 'ingredients', '',
    ];
}

function aapForget(): void
{
    $workspace = new ImportWorkspace;

    foreach ([$workspace->path('posts'), $workspace->companionPath('permalinks')] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/** Mount this lane's route file the way its header tells the integrator to. */
function aapWire(\Illuminate\Contracts\Foundation\Application $app): void
{
    ImportAdminRoutes::wire($app);

    Route::middleware(ImportAdminRoutes::STACK)
        ->prefix('admin-api')
        ->group(base_path('routes/import-articles-page.php'));
}

function aapOwner(): AdminUser
{
    return AdminUser::query()->create([
        'name' => 'Owner', 'email' => 'aap-owner@kbb.test', 'password' => bcrypt('secret'), 'role' => 'owner',
    ]);
}

beforeEach(fn () => aapForget());
afterEach(fn () => aapForget());

/* ========================================================================== */
/*  THE LIVE URL IS READ, NEVER DERIVED                                        */
/* ========================================================================== */

it('reads the live URL out of permalinks.csv instead of guessing it from the slug', function () {
    /*
     * THE PERMALINK STRUCTURE IS `/blog/%postname%/` HERE, which is the whole
     * test. Both articles collide with a segment this storefront owns, so both
     * are refused; the address each is INDEXED at has a /blog/ in front of it
     * and no amount of looking at the slug can tell you that.
     *
     * MUTATION: make ReservedArticleReport::indexedAt() return
     * 'https://kbeautybliss.com'.$common['wanted'] — the derived answer, which
     * looks entirely reasonable — and both assertions below go red with
     * `https://kbeautybliss.com/about/`, which is the address the owner would
     * have written a 301 from and which the old site never published.
     */
    aapPostsCsv([
        aapRow(7001, 'about', 'About the shop, an article'),
        aapRow(7002, 'wishlist', 'Why we built a wishlist'),
        aapRow(7003, 'heartleaf-extract', 'Heartleaf extract'),
    ]);

    aapPermalinksCsv([
        ['post', '7001', 'about', 'https://kbeautybliss.com/blog/about/', 'publish', 'wp', ''],
        ['post', '7002', 'wishlist', 'https://kbeautybliss.com/blog/wishlist/', 'publish', 'wp', ''],
        ['post', '7003', 'heartleaf-extract', 'https://kbeautybliss.com/blog/heartleaf-extract/', 'publish', 'wp', ''],
        // A product permalink, so "it reads every row" cannot pass by accident.
        ['product', '4021', 'serum-4021', 'https://kbeautybliss.com/product/serum-4021/', 'publish', 'wp', ''],
    ]);

    $report = (new ReservedArticleReport)->run();

    expect($report['permalinks'])->toBeTrue()
        ->and($report['counts']['reserved'])->toBe(2);

    $indexed = array_column($report['reserved'], 'indexed_at', 'slug');

    expect($indexed['about'] ?? null)->toBe('https://kbeautybliss.com/blog/about/')
        ->and($indexed['wishlist'] ?? null)->toBe('https://kbeautybliss.com/blog/wishlist/');

    /*
     * AND THE TWO ADDRESSES STAY APART. `wanted` is still the collision on this
     * shop — that is what makes the row a refusal — and the redirect
     * instruction now names the live one, because that is the address the 301
     * is written from.
     */
    foreach ($report['reserved'] as $row) {
        expect($row['wanted'])->toBe('/'.$row['slug'].'/')
            ->and(str_contains($row['what_to_do'], $row['indexed_at']))->toBeTrue()
            ->and(str_contains($row['what_to_do'], $row['wanted']))->toBeTrue();
    }
});

it('matches an article to its permalink by WordPress id, not only by slug', function () {
    /*
     * The id is what the exporter writes and is unambiguous; the slug is the
     * fallback for a hand-made file. Here the permalink row's `slug` column is
     * deliberately WRONG — a stale export, which is the realistic way these two
     * files disagree — and the id still finds it.
     *
     * MUTATION: drop the `by_id` lookup from indexedAt() and keep only the slug
     * one, and this row comes back with an empty live URL while the page
     * happily reports "not in permalinks.csv" about a file that has it.
     */
    aapPostsCsv([aapRow(7010, 'feed', 'Our feed, explained')]);

    aapPermalinksCsv([
        ['post', '7010', 'feed-old-slug', 'https://kbeautybliss.com/2021/05/feed/', 'publish', 'wp', ''],
    ]);

    $report = (new ReservedArticleReport)->run();

    expect($report['reserved'][0]['indexed_at'])->toBe('https://kbeautybliss.com/2021/05/feed/');
});

it('leaves the live URL empty and says why, rather than filling it in', function () {
    /*
     * A BLANK COLUMN READS AS "THIS ARTICLE IS NOT INDEXED", which is the
     * opposite of what it means: it means nobody has uploaded the file that
     * knows. Zero and "we have not looked" render identically as an empty cell,
     * and only one of them means there is nothing to do — the same argument
     * the report already makes about a missing posts.csv.
     *
     * MUTATION: delete the `permalinks_note` branch for the absent case and the
     * page shows three empty Live URL columns with nothing to explain them.
     */
    aapPostsCsv([aapRow(7020, 'cart', 'The cart article')]);

    $report = (new ReservedArticleReport)->run();

    expect($report['permalinks'])->toBeFalse()
        ->and($report['reserved'][0]['indexed_at'])->toBe('')
        ->and(str_contains($report['permalinks_note'], 'permalinks.csv has not been uploaded'))->toBeTrue()
        // And it still tells him what to do, from the address it does know.
        ->and(str_contains($report['reserved'][0]['what_to_do'], '/cart/'))->toBeTrue();
});

it('refuses to carry a permalink that is not http or https', function () {
    /*
     * `permalinks.csv` is an UPLOAD, and the page turns this value into an
     * `href`. CLAUDE.md rule 5: a URL from data is scheme-checked before it
     * becomes a link, and the check lives at the source rather than in the
     * template, so every one of the three renderings inherits it.
     *
     * Fails closed: an unusable scheme, or an address with no host, reads as
     * "not known" — which is true — rather than reaching the document.
     *
     * MUTATION: return the raw cell from ReservedArticleReport::linkable()
     * instead of scheme-checking it, and `javascript:alert(1)` arrives in
     * `indexed_at` and goes into an href on the page.
     */
    aapPostsCsv([
        aapRow(7030, 'about', 'One'),
        aapRow(7031, 'cart', 'Two'),
        aapRow(7032, 'feed', 'Three'),
    ]);

    aapPermalinksCsv([
        ['post', '7030', 'about', 'javascript:alert(1)', 'publish', 'wp', ''],
        ['post', '7031', 'cart', '/relative/not/absolute/', 'publish', 'wp', ''],
        ['post', '7032', 'feed', 'https://kbeautybliss.com/feed-post/', 'publish', 'wp', ''],
    ]);

    $report = (new ReservedArticleReport)->run();

    $indexed = array_column($report['reserved'], 'indexed_at', 'slug');

    expect($indexed['about'] ?? null)->toBe('')
        ->and($indexed['cart'] ?? null)->toBe('')
        // The good one still lands, so "it drops everything" cannot pass.
        ->and($indexed['feed'] ?? null)->toBe('https://kbeautybliss.com/feed-post/');
});

/* ========================================================================== */
/*  THE SCREEN                                                                 */
/* ========================================================================== */

it('renders a page listing every refused article with its title, slug and live URL', function () {
    /*
     * THE DEFECT THIS IS THE FIX FOR: the same three lists existed only as a
     * JSON body and a CSV download, and the owner had nothing to open. NINE
     * reserved articles here, which is more than EntityReport::SAMPLES_PER_KIND
     * — the discard list would have shown five of these and a count.
     *
     * MUTATION: render only the first five rows of each group in
     * resources/views/admin/article-addresses.blade.php — `array_slice($r
     * ['reserved'], 0, 5)` — and the four articles this asserts by name go
     * missing from the document, which is the defect exactly as it was.
     */
    aapWire($this->app);

    $reserved = ['about', 'cart', 'checkout', 'wishlist', 'feed',
        'mail-preferences', 'notify-me', 'routines', 'import-chain'];

    $rows = [];

    foreach ($reserved as $i => $slug) {
        $rows[] = aapRow(7100 + $i, $slug, 'An article called '.$slug);
    }

    $rows[] = aapRow(7200, 'My_Post_Title', 'A slug no URL can carry');
    $rows[] = aapRow(7300, 'heartleaf-extract', 'Heartleaf extract, and why it is everywhere');

    aapPostsCsv($rows);
    aapPermalinksCsv([
        ['post', '7100', 'about', 'https://kbeautybliss.com/blog/about/', 'publish', 'wp', ''],
    ]);

    $html = $this->actingAs(aapOwner(), 'admin')
        ->get('/admin-api/import/article-addresses-page')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->getContent();

    // Every one of the nine, by title — not a sample of five.
    foreach ($reserved as $slug) {
        expect(str_contains($html, 'An article called '.$slug))->toBeTrue();
    }

    expect(str_contains($html, '/mail-preferences/'))->toBeTrue()
        ->and(str_contains($html, '/import-chain/'))->toBeTrue()
        // The live URL is on the page as a link, because that is what he clicks
        // to check he is renaming the right article.
        ->and(str_contains($html, 'https://kbeautybliss.com/blog/about/'))->toBeTrue()
        // The adjusted group is there too, with where the article DOES land.
        ->and(str_contains($html, 'A slug no URL can carry'))->toBeTrue()
        ->and(str_contains($html, '/my-post-title/'))->toBeTrue()
        // The download is one click from the page, relative to its own address
        // so it survives KBB_BASE_PATH and the admin path having been moved.
        ->and(str_contains($html, 'href="article-addresses.csv"'))->toBeTrue()
        // An article with nothing wrong with it is not on the list at all: a
        // list with nothing to do on it is a list that gets skimmed.
        ->and(str_contains($html, 'Heartleaf extract, and why it is everywhere'))->toBeFalse();
});

it('escapes an article title rather than letting the old database write markup', function () {
    /*
     * Titles come straight out of the owner's WordPress database, which this
     * application does not control. The console's own `impEsc()` exists for the
     * same reason one level up; here it is Blade's `{{ }}`, asserted rather
     * than assumed.
     *
     * MUTATION: print the title with `{!! !!}` in the Blade template and the
     * raw `<script>` reaches the document, which this catches.
     */
    aapWire($this->app);

    aapPostsCsv([aapRow(7400, 'checkout', 'A title with <script>alert(1)</script> in it')]);

    $html = $this->actingAs(aapOwner(), 'admin')
        ->get('/admin-api/import/article-addresses-page')
        ->assertOk()
        ->getContent();

    expect(str_contains($html, '<script>alert(1)</script>'))->toBeFalse()
        ->and(str_contains($html, '&lt;script&gt;'))->toBeTrue();
});

it('says there is nothing to read rather than answering zero, when no posts.csv was uploaded', function () {
    // "No collisions" and "we have not looked" render identically as a number,
    // and only one of them means the owner has nothing to do before cutover.
    aapWire($this->app);

    $html = $this->actingAs(aapOwner(), 'admin')
        ->get('/admin-api/import/article-addresses-page')
        ->assertOk()
        ->getContent();

    expect(str_contains($html, 'No posts.csv has been uploaded'))->toBeTrue()
        ->and(str_contains($html, 'Nothing to read yet'))->toBeTrue();
});

it('refuses the page to anybody who is not signed in', function () {
    /*
     * The page quotes article titles out of the owner's WordPress database, so
     * it belongs in the admin-api group and nowhere else — mounted here exactly
     * the way routes/import-articles-page.php tells the integrator to mount it.
     *
     * MUTATION: move the route out of the `auth:admin` group in that file's
     * header instructions — or mount it without ImportAdminRoutes::STACK — and
     * this answers 200 to an anonymous request.
     */
    aapWire($this->app);

    aapPostsCsv([aapRow(7500, 'about', 'About')]);

    // A browser navigation is redirected to the login page; a request that
    // asked for JSON is answered 401. Either is a refusal; both are asserted so
    // neither shape can quietly become a 200.
    $this->get('/admin-api/import/article-addresses-page')->assertRedirect();
    $this->getJson('/admin-api/import/article-addresses-page')->assertStatus(401);
});

it('writes nothing at all when the page is rendered', function () {
    /*
     * "Run a preview — it writes nothing" is the plan's own wording, and this
     * is stronger than a rolled-back transaction: nothing is opened. No row, no
     * checkpoint, no ledger entry, so the owner can open this halfway through a
     * live import and the run does not notice.
     */
    aapWire($this->app);

    aapPostsCsv([aapRow(7600, 'about', 'About'), aapRow(7601, 'fine-slug', 'Fine')]);
    aapPermalinksCsv([['post', '7600', 'about', 'https://kbeautybliss.com/about/', 'publish', 'wp', '']]);

    $before = [
        'posts' => DB::table('posts')->count(),
        'checkpoints' => DB::table('import_checkpoints')->count(),
        'runs' => DB::table('import_runs')->count(),
    ];

    $this->actingAs(aapOwner(), 'admin')
        ->get('/admin-api/import/article-addresses-page')
        ->assertOk();

    expect([
        'posts' => DB::table('posts')->count(),
        'checkpoints' => DB::table('import_checkpoints')->count(),
        'runs' => DB::table('import_runs')->count(),
    ])->toBe($before);
});

it('carries the live URL into the spreadsheet as a column of its own', function () {
    /*
     * APPENDED, NOT INSERTED: every column the owner or a script already reads
     * keeps its position. The header assertion in ImportArticleAddressesTest
     * pins the first eight, and this pins the ninth.
     *
     * MUTATION: drop `$row['indexed_at']` from the three fputcsv calls in
     * ReservedArticleReport::csv() and the download loses the only address the
     * redirect can be written from.
     */
    aapPostsCsv([aapRow(7700, 'wishlist', 'The wishlist post')]);
    aapPermalinksCsv([
        ['post', '7700', 'wishlist', 'https://kbeautybliss.com/blog/wishlist/', 'publish', 'wp', ''],
    ]);

    $csv = (new ReservedArticleReport)->csv();

    expect(str_contains($csv, 'live url (from permalinks.csv)'))->toBeTrue()
        ->and(str_contains($csv, 'https://kbeautybliss.com/blog/wishlist/'))->toBeTrue();
});
