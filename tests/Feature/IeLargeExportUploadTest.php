<?php

declare(strict_types=1);

/*
 * AN EXPORT BIGGER THAN THE SERVER WILL ACCEPT, UPLOADED ANYWAY. (Lane IE2)
 *
 * ── THE DEFECT, IN THE NUMBERS THAT WERE MEASURED ──────────────────────────
 *
 * docs/IE-IMPORT-END-TO-END.md §6.3 measured the real shop's export through
 * the shipped exporter at the real row counts: the whole thing is 17.01 MB and
 * the largest single zip — ORDERS — is 2.18 MB. This build machine reports
 *
 *     upload_max_filesize = 2M
 *     post_max_size       = 8M
 *
 * which is PHP's own unmodified default. So the Orders upload is refused by
 * PHP BEFORE any route runs: $_FILES arrives empty, no validator fires, the
 * screen's fetch gets an error page instead of JSON, and the owner reads "The
 * server would not take that upload" with no number in it.
 *
 * The previous round's answer was to have somebody raise the directive. That
 * is not a fix: he may not be able to, a host panel may reset it, and it comes
 * back the day his catalogue outgrows whatever it is raised to.
 *
 * ── WHAT IS PINNED HERE ────────────────────────────────────────────────────
 *
 * That the file is cut into pieces the server WILL take, that the piece size
 * is read off this server at runtime rather than written down, that the joined
 * result goes through the ordinary import door, and that an endpoint which
 * writes caller-supplied bytes to disk cannot be talked into writing them
 * somewhere else.
 *
 * ── THE FALSE GREEN THIS FILE IS WRITTEN AGAINST ───────────────────────────
 *
 * "The parts fit" is trivially satisfiable by an export with nothing in it.
 * So the sizes below are REAL BYTES — a CSV built to overflow this server's
 * measured ceiling several times over — and every assertion compares a
 * measured length against a measured limit rather than against a constant this
 * file chose.
 */

use App\Models\AdminUser;
use App\Services\ImportConsole\ImportWorkspace;
use App\Services\ImportConsole\UploadParts;
use App\Support\ServerUploadLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\Support\ImportPartsRoutes;

uses(RefreshDatabase::class);

beforeEach(function () {
    ImportPartsRoutes::wire($this->app);

    File::deleteDirectory(storage_path('app/import'));
});

function iepOwner(): AdminUser
{
    return AdminUser::create([
        'name' => 'Import Owner',
        'email' => 'ie2-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/**
 * A products CSV of REAL rows, sized past this server's real ceiling.
 *
 * ── WHY THE SIZE IS DERIVED AND NOT A ROW COUNT ─────────────────────────────
 *
 * "The pieces fit" is trivially satisfiable by a file that never needed
 * slicing, and a hard-coded row count is exactly how that happens: 400 rows is
 * 36 KB, which fits in one request on every server there is, so every
 * assertion below would pass with nothing sliced at all. The file is grown
 * until it is comfortably past `UploadParts::partBytes()` — the number this
 * server actually reports — so the premise of each case is a measurement
 * rather than a hope, and each case asserts that premise before relying on it.
 *
 * REAL PRODUCT ROWS, not str_repeat() padding: `ImportWorkspace` identifies an
 * entity from its COLUMNS, so a file of filler would be refused before any of
 * this mattered and the test would be green on a refusal rather than on an
 * upload.
 *
 * @return array{0: string, 1: int} the CSV and how many rows are in it
 */
function iepCsv(?int $atLeast = null): array
{
    $atLeast ??= (int) ((new UploadParts)->partBytes() * 2.5);

    $out = "wc_id,name,slug,sku,price,status\n";
    $rows = 0;

    while (strlen($out) < $atLeast) {
        $rows++;
        $out .= $rows.',"Serum number '.$rows.' with a name long enough to weigh something",'
            .'serum-'.$rows.',SKU-'.$rows.',49.00,publish'."\n";
    }

    return [$out, $rows];
}

/* ───────────────────────── the size, off the server ───────────────────── */

it('sizes a piece from THIS server\'s two directives and never from a constant', function () {
    /*
     * The arithmetic, restated from the directives themselves rather than
     * from anything UploadParts returned: the effective ceiling is
     * min(upload_max_filesize, post_max_size − multipart overhead), and the
     * piece is that less a margin.
     *
     * post_max_size is the one people forget. It bounds the WHOLE body — the
     * boundary, the part headers, the `id` and `index` fields — so a piece
     * sized at upload_max_filesize exactly can still be discarded with the
     * request it travelled in, and PHP discards it ENTIRELY: $_POST empty,
     * $_FILES empty, nothing to validate and nothing to report.
     *
     * MUTATION NOTE. Change UploadParts::partBytes() to `return 2 * 1024 *
     * 1024;` — a constant that happens to be right on a 2M server — and this
     * goes red on the margin, because a piece exactly at the per-file limit
     * does not fit in the request that carries it. RUN: red, expected
     * 2097152 to be less than 2064384.
     */
    $limits = new ServerUploadLimits;
    $parts = new UploadParts;

    $perFile = $limits->perFile();
    $perRequest = $limits->perRequest();

    expect($perFile)->not->toBeNull('this server reports no upload_max_filesize at all');
    expect($perRequest)->not->toBeNull('this server reports no post_max_size at all');

    $ceiling = min(UploadParts::MAX_PART_BYTES, $perFile, $perRequest - ServerUploadLimits::MULTIPART_OVERHEAD);

    expect($parts->partBytes())->toBe($ceiling - UploadParts::SAFETY_MARGIN);

    // ...and the piece genuinely fits inside BOTH directives, with room for
    // the rest of the body. This is the sentence the feature exists to make
    // true; the equality above is only how it is arrived at.
    expect($parts->partBytes())->toBeLessThan($perFile)
        ->and($parts->partBytes())->toBeLessThan($perRequest);
});

it('cuts the measured Orders zip into pieces that each fit, on this server\'s real limit', function () {
    /*
     * 2.18 MB is docs/IE-IMPORT-END-TO-END.md §6.3's measurement of the REAL
     * shop's Orders zip through the shipped exporter. It is the number that
     * does not fit, so it is the number this case uses.
     */
    $ordersZipBytes = (int) round(2.18 * 1024 * 1024);
    $parts = new UploadParts;
    $part = $parts->partBytes();

    expect($part)->toBeGreaterThan(0);

    // The premise: it really does not fit in one request on this server.
    expect($ordersZipBytes)->toBeGreaterThan($part,
        'this server takes the Orders zip in one request, so this case is not testing anything');

    $opened = $parts->begin('kbb-export-sales-b903ebd4.zip', $ordersZipBytes);

    expect($opened['parts'])->toBeGreaterThan(1);

    // Every piece fits, INCLUDING the last one, and together they are the
    // whole file — an off-by-one in ceil() loses the tail silently.
    expect($opened['parts'] * $opened['part_bytes'])->toBeGreaterThanOrEqual($ordersZipBytes);
    expect(($opened['parts'] - 1) * $opened['part_bytes'])->toBeLessThan($ordersZipBytes);
    expect($opened['part_bytes'])->toBeLessThanOrEqual($part);

    $parts->discard($opened['id']);
});

/* ─────────────────────── the round trip, real bytes ────────────────────── */

it('takes a file the server refuses in one request, and the rows arrive', function () {
    /*
     * THE WHOLE POINT, END TO END AND THROUGH THE REAL ENDPOINTS.
     *
     * MUTATION NOTE. Remove the `stream_copy_to_stream($in, $out)` line from
     * UploadParts::finish() and this is red: the joined file is zero bytes,
     * ImportWorkspace refuses it, and `accepted` comes back empty against an
     * expected 400 rows. RUN: red.
     */
    $this->actingAs(iepOwner(), 'admin');

    [$csv, $rows] = iepCsv();
    $parts = new UploadParts;
    $part = $parts->partBytes();

    // The premise, asserted rather than assumed: this file really is bigger
    // than one request on this server. A test that green-lights a file which
    // fits is the false green this file is written against.
    expect(strlen($csv))->toBeGreaterThan($part,
        'the fixture CSV fits in one request here, so nothing is being sliced');

    $opened = $this->postJson('/admin-api/import/part/begin', [
        'name' => 'products.csv',
        'size' => strlen($csv),
    ])->assertOk()->json();

    expect($opened['ok'])->toBeTrue();
    expect($opened['parts'])->toBeGreaterThan(1);

    $sent = 0;

    for ($i = 0; $i < $opened['parts']; $i++) {
        $slice = substr($csv, $i * $opened['part_bytes'], $opened['part_bytes']);

        // Each piece is genuinely inside the server's ceiling. Measured on the
        // slice, not on the plan.
        expect(strlen($slice))->toBeLessThanOrEqual($part);

        $sent += strlen($slice);

        $this->post('/admin-api/import/part', [
            'id' => $opened['id'],
            'index' => $i,
            'chunk' => UploadedFile::fake()->createWithContent('part', $slice),
        ])->assertOk();
    }

    expect($sent)->toBe(strlen($csv), 'the pieces do not add up to the file');

    $done = $this->postJson('/admin-api/import/part/finish', ['id' => $opened['id']])
        ->assertOk()->json();

    expect($done['ok'])->toBeTrue();
    expect($done['accepted'])->toHaveCount(1);
    expect($done['accepted'][0]['entity'])->toBe('products');

    // REAL COUNTS AND REAL BYTES, which is what "it worked" has to mean here.
    expect($rows)->toBeGreaterThan(1000);
    expect($done['accepted'][0]['rows'])->toBe($rows);
    expect($done['accepted'][0]['bytes'])->toBe(strlen($csv));

    // And the file on disk is byte-identical to what was sent, which a row
    // count alone would not catch: a join that dropped the last byte of every
    // piece still parses as a CSV.
    $workspace = new ImportWorkspace;

    expect(file_get_contents($workspace->path('products')))->toBe($csv);

    // Nothing is left staged.
    expect(glob(storage_path(UploadParts::DIRECTORY).'/*') ?: [])->toBe([]);
});

it('unpacks a zip that arrived in pieces exactly as one that arrived whole', function () {
    /*
     * THE ONE DOOR. ImportWorkspace::acceptUpload() decides what a file IS
     * from its bytes, and a group zip is the shape the owner actually
     * downloads — the exporter writes one zip per group, and the Orders one is
     * the file that does not fit. If the joined bytes did not go through that
     * same call, a sliced zip would land as an unrecognisable CSV.
     *
     * MUTATION NOTE. Change UploadParts::finish() to call
     * `$workspace->accept($file, $entity)` instead of `acceptUpload(...)` —
     * the non-zip door — and this is red with the zip refused as an
     * unrecognisable file. RUN: red.
     */
    $this->actingAs(iepOwner(), 'admin');

    [$csv, $rows] = iepCsv();

    $zipPath = storage_path('app/ie2-test-'.uniqid().'.zip');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    // Stored rather than deflated, so the archive is bigger than one request
    // and the slicing is exercised. A CSV of repetitive product rows deflates
    // to a few per cent of itself, which would quietly turn this into the
    // small-file path and assert nothing about slicing at all.
    $zip->addFromString('products.csv', $csv);
    $zip->setCompressionName('products.csv', ZipArchive::CM_STORE);
    $zip->close();

    $bytes = (string) file_get_contents($zipPath);
    @unlink($zipPath);

    $parts = new UploadParts;

    expect(strlen($bytes))->toBeGreaterThan($parts->partBytes(),
        'the fixture zip fits in one request here, so nothing is being sliced');

    $opened = $this->postJson('/admin-api/import/part/begin', [
        'name' => 'kbb-export-catalogue-b903ebd4.zip',
        'size' => strlen($bytes),
    ])->assertOk()->json();

    for ($i = 0; $i < $opened['parts']; $i++) {
        $this->post('/admin-api/import/part', [
            'id' => $opened['id'],
            'index' => $i,
            'chunk' => UploadedFile::fake()->createWithContent(
                'part', substr($bytes, $i * $opened['part_bytes'], $opened['part_bytes'])
            ),
        ])->assertOk();
    }

    $done = $this->postJson('/admin-api/import/part/finish', ['id' => $opened['id']])
        ->assertOk()->json();

    expect($done['ok'])->toBeTrue();
    expect($done['accepted'])->toHaveCount(1);
    expect($done['accepted'][0]['entity'])->toBe('products');
    expect($done['accepted'][0]['rows'])->toBe($rows);

    // `from` is the name INSIDE the archive, which only the zip door sets.
    expect($done['accepted'][0]['from'] ?? null)->toBe('products.csv');
});

/* ──────────────────────── writing bytes to our disk ────────────────────── */

it('refuses an upload handle it did not mint, so no caller names a directory', function () {
    /*
     * /api/* is unauthenticated and this is not on it — but it IS an endpoint
     * that writes caller-supplied bytes to the server's disk, and the handle
     * is the only caller-supplied string anywhere near a path.
     *
     * MUTATION NOTE. Delete the preg_match in UploadParts::assertId() and
     * return $id unchanged: this is red on the traversal case, which then
     * creates storage/app/import/parts/../../../evil. RUN: red.
     */
    $this->actingAs(iepOwner(), 'admin');

    foreach ([
        '../../../../tmp/evil',
        '..%2F..%2Fetc',
        'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',   // 32 chars, not hex
        'deadbeefdeadbeefdeadbeefdeadbee',    // 31 hex
        '/etc/passwd',
        '',
    ] as $bad) {
        $this->postJson('/admin-api/import/part/finish', ['id' => $bad])
            ->assertStatus(422);
    }

    // Nothing was created anywhere under the staging root, and the staging root
    // itself has no child with a name a caller chose.
    expect(glob(storage_path(UploadParts::DIRECTORY).'/*') ?: [])->toBe([]);
    expect(is_dir('/tmp/evil'))->toBeFalse();
});

it('refuses a piece index outside the plan it opened', function () {
    /*
     * MUTATION NOTE. Remove the `$index >= $meta['parts']` half of the bounds
     * check in UploadParts::put() and this is red: index 999,999 is accepted
     * and written as 999999.part.
     * RUN: red.
     */
    $this->actingAs(iepOwner(), 'admin');

    [$csv] = iepCsv();

    $opened = $this->postJson('/admin-api/import/part/begin', [
        'name' => 'products.csv',
        'size' => strlen($csv),
    ])->assertOk()->json();

    foreach ([-1, $opened['parts'], 999999] as $index) {
        $this->post('/admin-api/import/part', [
            'id' => $opened['id'],
            'index' => $index,
            'chunk' => UploadedFile::fake()->createWithContent('part', 'x'),
        ])->assertStatus(422);
    }

    expect((new UploadParts)->received($opened['id']))->toBe([]);
});

it('will not join an upload whose pieces are missing', function () {
    /*
     * A half-arrived upload joined anyway is the worst outcome available here:
     * a truncated orders.csv parses, imports, and leaves a shop that looks
     * finished and is not. ImportRunner would report the rows it saw and
     * nothing would say the file was short.
     *
     * MUTATION NOTE. Remove the `count($received) !== $meta['parts']` guard
     * from UploadParts::finish() and this is red — the join succeeds on one
     * piece of three and the workspace accepts a truncated products.csv.
     * RUN: red.
     */
    $this->actingAs(iepOwner(), 'admin');

    [$csv] = iepCsv();

    $opened = $this->postJson('/admin-api/import/part/begin', [
        'name' => 'products.csv',
        'size' => strlen($csv),
    ])->assertOk()->json();

    expect($opened['parts'])->toBeGreaterThan(1);

    // Everything except the last piece.
    for ($i = 0; $i < $opened['parts'] - 1; $i++) {
        $this->post('/admin-api/import/part', [
            'id' => $opened['id'],
            'index' => $i,
            'chunk' => UploadedFile::fake()->createWithContent(
                'part', substr($csv, $i * $opened['part_bytes'], $opened['part_bytes'])
            ),
        ])->assertOk();
    }

    $refused = $this->postJson('/admin-api/import/part/finish', ['id' => $opened['id']])
        ->assertStatus(422)->json();

    expect($refused['message'])->toContain('not complete');
    expect((new ImportWorkspace)->has('products'))->toBeFalse();
});

it('bounds the whole upload however many pieces are sent', function () {
    /*
     * The declared `size` is a claim by the caller; the bytes on disk are a
     * fact. Both are bounded, and it is the fact that has to hold — otherwise
     * a caller declares 1 KB and sends 400 pieces of 2 MB.
     *
     * MUTATION NOTE. Change the ceiling in UploadParts::put() from
     * `$this->stagedBytes($id) + $bytes` to `$bytes` — bounding the piece
     * rather than the total — and this is red: every piece is individually
     * small, the total sails past MAX_BYTES and the disk fills.
     * RUN: red.
     */
    $this->actingAs(iepOwner(), 'admin');

    $parts = new UploadParts;

    // Declared honestly at the ceiling, so `begin` opens enough parts for the
    // test to overshoot by sending full-sized pieces into every one of them.
    $opened = $parts->begin('orders.csv', ImportWorkspace::MAX_BYTES);

    $piece = str_repeat('x', $opened['part_bytes']);
    $refusedAt = null;

    for ($i = 0; $i < $opened['parts'] + 4; $i++) {
        try {
            $parts->put($opened['id'], min($i, $opened['parts'] - 1),
                UploadedFile::fake()->createWithContent('part', $piece));
        } catch (RuntimeException $e) {
            $refusedAt = $parts->stagedBytes($opened['id']);

            break;
        }
    }

    expect($refusedAt)->not->toBeNull('the total was never bounded');
    expect($refusedAt)->toBeLessThanOrEqual(ImportWorkspace::MAX_BYTES);

    $parts->discard($opened['id']);
});

it('says so with the numbers when even one piece will not fit', function () {
    /*
     * THE HONEST DEGRADE, which is the half of this brief that is easy to skip.
     * A server so tight that the minimum piece does not fit is a real
     * possibility, and it must present as a sentence with the ceiling, the
     * directive imposing it and the way out — not as a browser error.
     *
     * The two ceilings are STATED through ServerUploadLimits::of() rather than
     * set in the ini, because ini_set() on upload_max_filesize is a no-op at
     * runtime (PHP_INI_PERDIR) and a test that appeared to set it would be
     * asserting nothing. `of()` is that class's own seam, built for this.
     *
     * MUTATION NOTE. Change `partBytes()` to return the ceiling unconditionally
     * instead of 0 below MIN_PART_BYTES: this is red, because begin() then
     * plans 4,096 pieces of 8 KB and never refuses. RUN: red.
     */
    $parts = new UploadParts(ServerUploadLimits::of(4 * 1024, 1024 * 1024));

    expect($parts->partBytes())->toBe(0);
    expect($parts->capability()['ok'])->toBeFalse();

    try {
        $parts->begin('orders.zip', 2 * 1024 * 1024);
        $this->fail('a server that cannot take a piece accepted an upload');
    } catch (RuntimeException $e) {
        // The ceiling, so he knows what DOES fit.
        expect($e->getMessage())->toContain('4 KB');
        // The directive, so he knows which line to change.
        expect($e->getMessage())->toContain('upload_max_filesize');
        // And the way out that needs no host at all — he has SSH now.
        expect($e->getMessage())->toContain('kbb:import --dir=');
    }
});

it('leaves nothing on the disk when an upload is abandoned', function () {
    /*
     * A full disk on this project has already presented once as a transaction
     * bug and cost a day (CLAUDE.md), so a 60 MB upload nobody came back for
     * is not a tidiness question.
     *
     * MUTATION NOTE. Change the cutoff in UploadParts::sweep() to
     * `time() + $olderThan` and this is red: nothing is ever old enough.
     * RUN: red.
     */
    $parts = new UploadParts;

    $opened = $parts->begin('orders.csv', 3 * 1024 * 1024);

    $parts->put($opened['id'], 0, UploadedFile::fake()->createWithContent('part', str_repeat('x', 1024)));

    expect($parts->stagedBytes($opened['id']))->toBe(1024);

    // Nothing recent is touched...
    expect($parts->sweep())->toBe(0);
    expect($parts->stagedBytes($opened['id']))->toBe(1024);

    // ...and anything past the cutoff is gone, files and folder together.
    expect($parts->sweep(-1))->toBe(1);
    expect(glob(storage_path(UploadParts::DIRECTORY).'/*') ?: [])->toBe([]);
});

/* ─────────────────────────── the guard on the door ─────────────────────── */

it('refuses every one of these paths to a caller with no session', function () {
    /*
     * Five endpoints that write, join and delete files on the server's disk.
     * Asserted over the ROUTER's list rather than a list written here, so a
     * sixth route added to the file is covered the day it is added.
     *
     * MUTATION NOTE. Drop `auth:admin` from ImportPartsRoutes::STACK and this
     * is red with 200s and 422s in place of the redirect. RUN: red.
     */
    $routes = ImportPartsRoutes::registered();

    expect($routes)->toHaveCount(5);

    /*
     * ▲ 404, AND ONLY 404 -- THE SHOP'S OWN RULE, NOT A CHOICE MADE HERE.
     *
     * This first accepted 302, 401 or 403, and every route answered 404, so it
     * was red. The 404 is right and the list was wrong: `admin-api` is a fixed
     * prefix anybody can guess, and a REDIRECT to the login page puts the
     * secret admin address in the Location header for whoever typed it.
     * AdminPathNeverLeaksTest pins that a stranger is hidden from, never
     * pointed at a login. A 302 here would have been the leak.
     *
     * ▲ AND A 404 IS ALSO WHAT A MISSING ROUTE LOOKS LIKE, which would make
     *   this green for a reason unrelated to its subject. So each route is
     *   asked twice: a stranger must get 404, and the owner must NOT, which
     *   proves the 404 is the guard and not an absence.
     */
    $owner = iepOwner();

    foreach ($routes as $route) {
        $method = in_array('GET', $route->methods(), true) ? 'get' : 'post';

        $stranger = $this->$method('/'.$route->uri());

        expect($stranger->getStatusCode())->toBe(
            404, $route->uri().' answered '.$stranger->getStatusCode().' with no session'
        );
        expect((string) $stranger->headers->get('Location'))->toBe(
            '', $route->uri().' pointed a stranger somewhere, which is how the admin path leaks'
        );

        $signedIn = $this->actingAs($owner, 'admin')->$method('/'.$route->uri());

        expect($signedIn->getStatusCode())->not->toBe(
            404, $route->uri().' is 404 for the OWNER too, so the stranger\'s 404 proves nothing'
        );

        auth('admin')->logout();
    }
});

it('registers each of its routes exactly once', function () {
    /*
     * THE FINISHED STATE, not the unwired one. CLAUDE.md: a lane that pins
     * "my routes are NOT mounted yet" writes an assertion that goes red the
     * moment the integrator does the thing it asked for, and it has cost this
     * repository three round trips. Zero is the "built, never wired up" shape;
     * two is a file required twice, which registers every path twice and makes
     * the name lookup ambiguous. Both are real failures and both are caught
     * here, and this is green in this worktree today AND after the integrator
     * adds the require.
     */
    $file = (string) file_get_contents(base_path('routes/import-parts-admin.php'));

    foreach (ImportPartsRoutes::PATHS as $path) {
        $bare = substr($path, strlen('import/'));

        expect(substr_count($file, "'/import/".$bare."'"))->toBe(
            1, '/import/'.$bare.' is registered '.substr_count($file, "'/import/".$bare."'").' times'
        );
    }

    // And the router agrees, once the harness has mounted the file the way the
    // integrator is asked to.
    $uris = array_map(fn ($r) => $r->uri(), ImportPartsRoutes::registered());

    foreach (ImportPartsRoutes::PATHS as $path) {
        expect(count(array_keys($uris, 'admin-api/'.$path, true)))->toBe(1, $path.' is not mounted exactly once');
    }
});
