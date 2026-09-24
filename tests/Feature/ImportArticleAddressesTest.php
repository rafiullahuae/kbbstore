<?php

declare(strict_types=1);

/*
 * ════════════════════════════════════════════════════════════════════════════
 * LANE A — Phase 13, item 3: an article at a reserved address cannot be served
 * ════════════════════════════════════════════════════════════════════════════
 *
 * WHAT THE DEFECT LOOKED LIKE, which is not a stack trace:
 *
 * An article on this shop is served from the SITE ROOT — `/{slug}/`, no prefix,
 * exactly as WordPress serves it — and `PageController::RESERVED_SLUGS` owns
 * the first segment for `/cart`, `/checkout`, `/wishlist`, `/about`, `/feed`
 * and forty more. So a live article slugged `about` is an indexed URL this
 * application can never answer.
 *
 * `PostImporter` has always handled that correctly: the row is refused by name
 * and written into the discard list. The OWNER'S side of it was missing. Each
 * one is a rename-and-redirect in WordPress — a thing only he can do, on the
 * other site, before the cutover — and `EntityReport` keeps
 * SAMPLES_PER_KIND = 5 examples of each recurring kind. Five. An export with
 * nine colliding articles showed him five and a count, and the other four were
 * discovered from Search Console after the old site was gone.
 *
 * THE TWO PROPERTIES THAT MATTER AND ARE BOTH ASSERTED BELOW:
 *
 *  1. IT LISTS EVERY ONE, with its title and the URL it wanted — not a sample.
 *  2. IT AGREES WITH THE IMPORT, because it runs the importer's own decision
 *     (`PostImporter::address()`) rather than a second copy of the rule. A list
 *     that disagreed would be worse than no list: he renames the nine it names,
 *     re-exports, and the tenth still vanishes without a word.
 *
 * And it writes nothing, which is asserted rather than claimed.
 *
 * `expect(...)->not->toContain($x, $message)` IS NOT USED ANYWHERE HERE.
 * `toContain` is variadic, so the message is read as a second needle and the
 * assertion passes vacuously — CLAUDE.md records it.
 */

use App\Models\AdminUser;
use App\Models\Post;
use App\Services\Import\ReservedArticleReport;
use App\Services\ImportConsole\ImportWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\ImportAdminRoutes;

/** The header the WordPress exporter writes for posts.csv. */
const AA_HEADER = 'id,type,slug,status,title,excerpt,content,author_name,date_created_gmt,image,categories,tags';

/**
 * Put a posts.csv in the import workspace, exactly where an upload leaves one.
 *
 * @param  list<array<int, string>>  $rows
 */
function aaPostsCsv(array $rows): void
{
    $path = (new ImportWorkspace)->path('posts');

    File::ensureDirectoryExists(dirname($path));

    $handle = fopen($path, 'wb');

    fputcsv($handle, explode(',', AA_HEADER));

    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }

    fclose($handle);
}

/** One article row, with only the cells this report reads spelled out. */
function aaRow(int $id, string $slug, string $title, string $type = 'post', string $status = 'publish'): array
{
    return [
        (string) $id, $type, $slug, $status, $title, 'An excerpt.', '<p>Body.</p>',
        'Rafi', '2021-05-04 09:00:00', '', 'ingredients', '',
    ];
}

function aaForget(): void
{
    $path = (new ImportWorkspace)->path('posts');

    if (is_file($path)) {
        @unlink($path);
    }
}

beforeEach(fn () => aaForget());
afterEach(fn () => aaForget());

/* ========================================================================== */
/*  THE LIST ITSELF                                                            */
/* ========================================================================== */

it('names every article whose address the storefront already owns', function () {
    /*
     * NINE of them, which is more than EntityReport::SAMPLES_PER_KIND. That is
     * the number this test is really about: the discard list showed five of
     * these and a count, and the owner cannot rename an article he has not been
     * told the name of.
     *
     * MUTATION: cap the `reserved` list in ReservedArticleReport::run() at five
     * the way the report's samples are capped, and the count assertion below
     * goes red — which is the defect exactly as it was.
     */
    $rows = [];
    /*
     * THE OBVIOUS FOUR AND THE FIVE NOBODY WOULD GUESS. `cart`, `checkout`,
     * `about` and `wishlist` are in anybody's idea of a reserved list.
     * `mail-preferences` is the unsubscribe address in the footer of every
     * marketing mail this shop sends; `notify-me` is back-in-stock; `routines`
     * is reserved for a module that ships OFF; `import-chain` is the loopback
     * the background import calls to continue itself. A second implementation
     * of this rule would have the first four and none of the last four, which
     * is exactly why there is not one.
     */
    $reserved = ['about', 'cart', 'checkout', 'wishlist', 'feed',
        'mail-preferences', 'notify-me', 'routines', 'import-chain'];

    foreach ($reserved as $i => $slug) {
        $rows[] = aaRow(7000 + $i, $slug, 'An article called '.$slug);
    }

    // And one that is perfectly fine, so "everything is reserved" cannot pass.
    $rows[] = aaRow(7100, 'heartleaf-extract', 'Heartleaf extract, and why it is everywhere');

    aaPostsCsv($rows);

    $report = (new ReservedArticleReport)->run();

    expect($report['present'])->toBeTrue()
        ->and($report['articles'])->toBe(10)
        ->and($report['counts']['reserved'])->toBe(9)
        ->and(count($report['reserved']))->toBe(9);

    // Each one carries the two things the owner acts on: which article, and
    // which address it wanted.
    $titles = array_column($report['reserved'], 'title');
    $wanted = array_column($report['reserved'], 'wanted');

    expect(in_array('An article called about', $titles, true))->toBeTrue()
        ->and(in_array('/about/', $wanted, true))->toBeTrue()
        ->and(in_array('/feed/', $wanted, true))->toBeTrue()
        ->and(in_array('/heartleaf-extract/', $wanted, true))->toBeFalse();

    // And it says what to do about each, because "discarded" is not an action.
    foreach ($report['reserved'] as $row) {
        expect(str_contains($row['what_to_do'], 'Rename this article in WordPress'))->toBeTrue()
            ->and(str_contains($row['what_to_do'], $row['wanted']))->toBeTrue();
    }
});

it('agrees with the importer, because it asks the importer', function () {
    /*
     * THE PROPERTY THE WHOLE DESIGN EXISTS FOR. The list and the import run the
     * same static, `PostImporter::address()`, so they cannot drift.
     *
     * Asserted end to end rather than by reading the code: the four rows below
     * are put through the report AND through the real importer, and the article
     * the report calls reserved is exactly the one that is missing from `posts`
     * afterwards.
     *
     * MUTATION: give ReservedArticleReport a regex of its own instead of
     * calling PostImporter::address() — say, a hard-coded list of a dozen
     * reserved words — and `mail-preferences` (reserved because it is the
     * unsubscribe address in the footer of every marketing mail this shop
     * sends, and nobody would think to hard-code it) drops out of the list
     * while still being refused by the import, which this catches.
     */
    aaPostsCsv([
        aaRow(8001, 'about', 'About us, the article'),
        aaRow(8002, 'double-cleansing', 'Double cleansing, explained'),
        aaRow(8003, 'My_Post_Title', 'A slug no URL can carry'),
        aaRow(8004, 'a-page', 'A WordPress page', 'page'),
    ]);

    $report = (new ReservedArticleReport)->run();

    expect(array_column($report['reserved'], 'id'))->toBe(['8001'])
        ->and(array_column($report['adjusted'], 'id'))->toBe(['8003'])
        // A page is not an article. Sending the owner into WordPress to rename
        // a page this entity never writes would be sending him after a problem
        // that does not exist.
        ->and($report['articles'])->toBe(3);

    // Now the real thing, and the two lists have to match what it did.
    /*
     * The REAL importer, over the same file. No --dry-run, because the point
     * is what actually lands in `posts`.
     */
    $this->artisan('kbb:import', [
        '--dir' => (new ImportWorkspace)->directory(),
        '--only' => ['posts'],
        '--run' => 'aa-test',
    ])->run();

    $slugs = Post::query()->whereNotNull('source_post_id')->pluck('slug', 'source_post_id')->all();

    expect(array_key_exists(8001, $slugs))->toBeFalse()
        ->and($slugs[8002] ?? null)->toBe('double-cleansing')
        ->and($slugs[8003] ?? null)->toBe('my-post-title')
        ->and(array_key_exists(8004, $slugs))->toBeFalse();
});

it('writes nothing at all', function () {
    /*
     * "Run a preview — it writes nothing" is the plan's own wording, and this
     * is stronger than a rolled-back transaction: nothing is opened. No row, no
     * checkpoint, no ledger entry, and a live import part-way through is not
     * disturbed, which is why this is a read and not a mode of the preview RUN.
     */
    aaPostsCsv([aaRow(9001, 'about', 'About'), aaRow(9002, 'fine-slug', 'Fine')]);

    $before = [
        'posts' => DB::table('posts')->count(),
        'checkpoints' => DB::table('import_checkpoints')->count(),
        'runs' => DB::table('import_runs')->count(),
    ];

    (new ReservedArticleReport)->run();
    (new ReservedArticleReport)->csv();

    expect([
        'posts' => DB::table('posts')->count(),
        'checkpoints' => DB::table('import_checkpoints')->count(),
        'runs' => DB::table('import_runs')->count(),
    ])->toBe($before);
});

it('says so plainly when there is no posts.csv, rather than answering zero', function () {
    // Zero reserved articles and "we have not looked" render identically as a
    // number, and only one of them means the owner has nothing to do.
    $report = (new ReservedArticleReport)->run();

    expect($report['present'])->toBeFalse()
        ->and($report['counts']['reserved'])->toBe(0)
        ->and(str_contains($report['note'], 'No posts.csv has been uploaded'))->toBeTrue();
});

it('hands back a spreadsheet with the title and the address on every row', function () {
    aaPostsCsv([
        aaRow(9101, 'wishlist', 'The wishlist post'),
        aaRow(9102, 'Unsafe Slug', 'A title with a shape'),
        aaRow(9103, 'good-one', 'A good one'),
    ]);

    $csv = (new ReservedArticleReport)->csv();

    expect(str_contains($csv, 'decision,line,"wordpress id",title,status,"url it wanted",result,"what to do"'))->toBeTrue()
        ->and(str_contains($csv, 'The wishlist post'))->toBeTrue()
        ->and(str_contains($csv, '/wishlist/'))->toBeTrue()
        ->and(str_contains($csv, 'reserved — NOT imported'))->toBeTrue()
        ->and(str_contains($csv, 'address changed — imported'))->toBeTrue()
        // The article that is fine is not in the list at all: a list with
        // nothing to do on it is a list that gets skimmed.
        ->and(str_contains($csv, 'A good one'))->toBeFalse();
});

/* ========================================================================== */
/*  THE ENDPOINT                                                               */
/* ========================================================================== */

it('refuses both endpoints to anybody who is not signed in', function () {
    /*
     * The rows quote article titles straight out of the owner's WordPress
     * database. routes/api.php is unauthenticated by design in this
     * application, so these two belong in the admin-api group and nowhere
     * else — mounted here exactly the way routes/import-articles-admin.php
     * tells the integrator to mount them.
     */
    ImportAdminRoutes::wire($this->app);

    \Illuminate\Support\Facades\Route::middleware(ImportAdminRoutes::STACK)
        ->prefix('admin-api')
        ->group(base_path('routes/import-articles-admin.php'));

    /*
     * `getJson` on both, the way AdminImportScreenTest does: the admin guard
     * redirects a browser navigation to the login page (302) and answers 401
     * to a request that asked for JSON. Either is a refusal; the status is
     * asserted on the one that is unambiguous.
     */
    $this->getJson('/admin-api/import/article-addresses')->assertStatus(401);
    $this->getJson('/admin-api/import/article-addresses.csv')->assertStatus(401);
});

it('answers the screen and downloads as an attachment, never as a page', function () {
    ImportAdminRoutes::wire($this->app);

    \Illuminate\Support\Facades\Route::middleware(ImportAdminRoutes::STACK)
        ->prefix('admin-api')
        ->group(base_path('routes/import-articles-admin.php'));

    aaPostsCsv([aaRow(9201, 'checkout', 'The checkout article')]);

    $admin = AdminUser::query()->create([
        'name' => 'Owner', 'email' => 'aa-owner@kbb.test', 'password' => bcrypt('secret'), 'role' => 'owner',
    ]);

    $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/import/article-addresses')
        ->assertOk()
        ->assertJsonPath('counts.reserved', 1)
        ->assertJsonPath('reserved.0.title', 'The checkout article')
        ->assertJsonPath('reserved.0.wanted', '/checkout/');

    /*
     * A refused row quotes whatever was in the owner's database, and a body a
     * browser renders is a body a browser can be made to execute. Attachment,
     * text/csv and nosniff — the same three /import/rejects carries.
     */
    $this->actingAs($admin, 'admin')
        ->get('/admin-api/import/article-addresses.csv')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'attachment; filename="kbb-article-addresses.csv"');
});
