<?php

declare(strict_types=1);

/**
 * The import, refined — Lane GF.
 *
 * The owner asked for three things and a standard: a live bar, a duplicate
 * guard, "all record", and "super refined without any error". What is under
 * test here is not the import mapping — WooImportTest owns that and this lane
 * changed none of it — but the four properties those three things rest on:
 *
 *   1. A BAR IS DRAWN ONLY OVER A DENOMINATOR THAT CAN BE BELIEVED. Lane GD
 *      caught itself drawing a full green bar at 0/0 and removed the bar rather
 *      than the honesty. manifest.json's `rows` is what replaces it — and the
 *      number it adds that a count of the file never could is a denominator
 *      that can DISAGREE with the file, which is the only way a truncated
 *      upload is ever noticed. Every "no bar" case below is a case where a bar
 *      would have been a lie.
 *
 *   2. A REPEAT IS RECOGNISED AND A CORRECTION IS NOT. The importer is already
 *      idempotent by external id and was proved so at 671/4,159/3,712
 *      (docs/FV-IMPORT-AT-VOLUME.md §4), so nothing here protects a row. It
 *      protects the owner's afternoon. The case that matters most is the one a
 *      lazy guard gets backwards: the same export id carrying different bytes
 *      is a CORRECTED RE-EXPORT and must never be refused.
 *
 *   3. THE RECORD OUTLIVES THE RUN. `import_runs` holds one row per run key and
 *      is overwritten in place; `import_checkpoints` is deleted by the screen's
 *      own Reset button. The owner has no shell and no log. If the record does
 *      not survive both of those it does not exist.
 *
 *   4. NOTHING NEW IS REACHABLE WITHOUT AN ADMIN SESSION, and the capability
 *      rule that covers it is CHECKED rather than assumed.
 */

use App\Models\AdminUser;
use App\Services\Import\ImportRunner;
use App\Services\Import\MigrationProgress;
use App\Services\ImportConsole\ImportDriver;
use App\Services\ImportConsole\ImportLedger;
use App\Services\ImportConsole\ImportManifest;
use App\Services\ImportConsole\ImportWorkspace;
use App\Support\AdminCapabilities;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\ImportAdminRoutes;
use Tests\Support\ImportHistoryRoutes;

/* ------------------------------------------------------------------ set-up */

beforeEach(function () {
    ImportAdminRoutes::wire($this->app);
    ImportHistoryRoutes::wire($this->app);

    gfPurge(storage_path('app/import'));
});

afterEach(function () {
    gfPurge(storage_path('app/import'));
});

function gfPurge(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir.'/'.$entry;

        is_dir($path) ? gfPurge($path) : @unlink($path);
    }

    @rmdir($dir);
}

function gfAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Import Owner',
        'email' => 'gf-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** A copy of a checked-in fixture; accept() MOVES what it is given. */
function gfFixture(string $entity): UploadedFile
{
    $name = ImportWorkspace::meta($entity)['file'];
    $source = base_path('tests/Fixtures/woo/'.$name);
    $temp = sys_get_temp_dir().'/kbb-gf-'.bin2hex(random_bytes(6)).'-'.$name;

    copy($source, $temp);

    return new UploadedFile($temp, $name, 'text/csv', null, true);
}

function gfFile(string $name, string $body, string $mime = 'text/csv'): UploadedFile
{
    $temp = sys_get_temp_dir().'/kbb-gf-'.bin2hex(random_bytes(6));
    file_put_contents($temp, $body);

    return new UploadedFile($temp, $name, $mime, null, true);
}

/** The whole fixture export on the server, the way the screen puts it there. */
function gfUploadAll(): void
{
    foreach (ImportWorkspace::entities() as $entity) {
        test()->postJson('/admin-api/import/upload', ['file' => gfFixture($entity)])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }
}

/**
 * A manifest describing whatever is on the server right now, counted the way
 * the importer counts.
 *
 * Built from the real files rather than hard-coded so that the AGREEING case is
 * genuinely agreeing; the disagreeing cases below each state their own numbers.
 *
 * @param  array<string, int>  $overrideRows
 */
function gfManifest(array $overrideRows = [], array $extra = []): array
{
    $workspace = new ImportWorkspace;
    $files = [];

    foreach (ImportWorkspace::entities() as $entity) {
        if (! $workspace->has($entity)) {
            continue;
        }

        $name = ImportWorkspace::meta($entity)['file'];
        $path = $workspace->path($entity);

        $files[$name] = [
            'rows' => $overrideRows[$name] ?? $workspace->rowCount($entity),
            'bytes' => (int) filesize($path),
            'sha256' => (string) hash_file('sha256', $path),
        ];
    }

    return array_replace([
        'format' => ImportManifest::FORMAT,
        'export_id' => '8f14e45f-ceea-467a-9c31-1a2b3c4d5e6f',
        'generated_at' => '2026-09-18T09:30:00+04:00',
        'source' => [
            'site_url' => 'https://kbeautybliss.com',
            'wp_version' => '6.5.2',
            'woo_version' => '8.7.0',
            'plugin_version' => '1.0.0',
        ],
        'files' => $files,
        'counts' => ['products' => 10],
        'notes' => [],
    ], $extra);
}

function gfUploadManifest(array $manifest): void
{
    test()->postJson('/admin-api/import/upload', [
        'file' => gfFile('manifest.json', (string) json_encode($manifest), 'application/json'),
    ])->assertOk()->assertJsonPath('ok', true);
}

function gfStart(string $mode, array $options = []): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/admin-api/import/start', array_merge([
        'mode' => $mode,
        'adopt_by_slug' => true,
    ], $options));
}

function gfStatus(): array
{
    return test()->getJson('/admin-api/import/status')->assertOk()->json();
}

function gfEntity(array $status, string $entity): array
{
    foreach ($status['entities'] as $row) {
        if ($row['entity'] === $entity) {
            return $row;
        }
    }

    throw new RuntimeException('no such entity in the status payload: '.$entity);
}

/** Step until the run stops running. */
function gfRunToEnd(int $rows = 500, int $maxSteps = 200): array
{
    $steps = [];

    for ($i = 0; $i < $maxSteps; $i++) {
        $result = test()->postJson('/admin-api/import/step', ['rows' => $rows])->assertOk()->json();
        $steps[] = $result;

        expect($result['ok'] ?? false)->toBeTrue('step '.$i.': '.($result['message'] ?? 'no message'));

        if (($result['status']['run']['status'] ?? '') !== 'running') {
            return $steps;
        }
    }

    throw new RuntimeException('The run never finished in '.$maxSteps.' steps.');
}

/* ======================================================================== */
/* 1 · THE MANIFEST — a reader for a document this lane may not change       */
/* ======================================================================== */

it('reads the manifest the export contract documents, field for field', function () {
    // Copied from docs/WP-EXPORT-CONTRACT.md. If the contract changes, this is
    // what should fail first.
    $manifest = ImportManifest::parse((string) json_encode([
        'format' => 'kbb-export/1',
        'export_id' => '8f14e45f-ceea-467a-9c31-1a2b3c4d5e6f',
        'generated_at' => '2026-09-18T09:30:00+04:00',
        'source' => [
            'site_url' => 'https://kbeautybliss.com',
            'wp_version' => '6.5.2',
            'woo_version' => '8.7.0',
            'plugin_version' => '1.0.0',
        ],
        'files' => [
            'products.csv' => ['rows' => 671, 'bytes' => 812344, 'sha256' => str_repeat('a', 64)],
            'orders.csv' => ['rows' => 4159, 'bytes' => 2210044, 'sha256' => str_repeat('b', 64)],
        ],
        'counts' => ['products' => 671, 'orders' => 4159, 'customers' => 3712],
        'notes' => [],
    ]));

    expect($manifest->usable())->toBeTrue()
        ->and($manifest->refusal())->toBeNull()
        ->and($manifest->exportId())->toBe('8f14e45f-ceea-467a-9c31-1a2b3c4d5e6f')
        ->and($manifest->generatedAt())->toBe('2026-09-18T09:30:00+04:00')
        ->and($manifest->source()['site_url'])->toBe('https://kbeautybliss.com')
        ->and($manifest->source()['plugin_version'])->toBe('1.0.0')
        ->and($manifest->rowsFor('products.csv'))->toBe(671)
        ->and($manifest->rowsFor('orders.csv'))->toBe(4159)
        ->and($manifest->bytesFor('products.csv'))->toBe(812344)
        ->and($manifest->sha256For('orders.csv'))->toBe(str_repeat('b', 64));
});

it('refuses a format it does not speak, naming what it found', function () {
    $manifest = ImportManifest::parse((string) json_encode([
        'format' => 'kbb-export/2',
        'export_id' => 'x',
        'files' => [],
    ]));

    expect($manifest->present())->toBeTrue()
        ->and($manifest->usable())->toBeFalse()
        // The sentence names BOTH what was found and what is read. A refusal
        // that only names the expected value sends the owner looking for a
        // setting rather than at the plugin version.
        ->and($manifest->refusal())->toContain('kbb-export/2')
        ->and($manifest->refusal())->toContain('kbb-export/1')
        // And it says what to do, because the owner has no shell.
        ->and($manifest->refusal())->toContain('remove the manifest');
});

it('ignores keys it does not know, because a newer plugin must not stop an import', function () {
    // The contract: "Unknown keys are ignored, never fatal."
    $manifest = ImportManifest::parse((string) json_encode([
        'format' => 'kbb-export/1',
        'export_id' => 'e1',
        'generated_at' => '2026-09-18T09:30:00+04:00',
        'files' => ['products.csv' => ['rows' => 5, 'bytes' => 1, 'sha256' => str_repeat('c', 64), 'gzip' => true]],
        'compression' => 'zstd',
        'checksums_of_checksums' => ['nonsense' => [1, 2, 3]],
    ]));

    expect($manifest->usable())->toBeTrue()
        ->and($manifest->refusal())->toBeNull()
        ->and($manifest->rowsFor('products.csv'))->toBe(5);
});

it('tells a file absent from the manifest apart from a file with no rows', function () {
    // The contract is explicit that these are different statements: "this shop
    // has no coupons" and "this export does not carry coupons".
    $manifest = ImportManifest::parse((string) json_encode([
        'format' => 'kbb-export/1',
        'export_id' => 'e1',
        'files' => ['coupons.csv' => ['rows' => 0, 'bytes' => 12, 'sha256' => str_repeat('d', 64)]],
    ]));

    expect($manifest->lists('coupons.csv'))->toBeTrue()
        ->and($manifest->rowsFor('coupons.csv'))->toBe(0)
        ->and($manifest->lists('reviews.csv'))->toBeFalse()
        ->and($manifest->rowsFor('reviews.csv'))->toBeNull();

    /*
     * FOUND BY MUTATION M3, which left the suite green. Deriving lists() from
     * "does it have a row count" agrees with the truth on both cases above and
     * is wrong on this one: a file the plugin listed but could not count is
     * still a file the export carries.
     */
    $partial = ImportManifest::parse((string) json_encode([
        'format' => 'kbb-export/1',
        'files' => ['brands.csv' => ['bytes' => 400]],
    ]));

    expect($partial->lists('brands.csv'))->toBeTrue('a listed file with no row count read as absent')
        ->and($partial->rowsFor('brands.csv'))->toBeNull();
});

it('treats an unreadable row count as no count at all, never as zero', function () {
    // 0 is a legitimate answer with a meaning the contract gives it. It must
    // not double as "I could not read this", or a header-only file and a
    // corrupt entry would draw the same bar.
    $manifest = ImportManifest::parse((string) json_encode([
        'format' => 'kbb-export/1',
        'files' => [
            'products.csv' => ['rows' => 'lots'],
            'orders.csv' => ['rows' => -3],
            'brands.csv' => ['rows' => 12.5],
            'categories.csv' => ['rows' => '59'],
        ],
    ]));

    expect($manifest->rowsFor('products.csv'))->toBeNull()
        ->and($manifest->rowsFor('orders.csv'))->toBeNull()
        ->and($manifest->rowsFor('brands.csv'))->toBeNull()
        // A JSON number that arrived as a string is still a count.
        ->and($manifest->rowsFor('categories.csv'))->toBe(59);
});

it('refuses a manifest that is not JSON, and survives a byte-order mark', function () {
    expect(ImportManifest::parse('products.csv,rows' . "\n" . '5,6')->usable())->toBeFalse()
        ->and(ImportManifest::parse('')->usable())->toBeFalse()
        ->and(ImportManifest::parse('[1,2,3]')->usable())->toBeFalse();

    // Notepad and Excel leave a BOM on a file somebody opened to look at. The
    // manifest is not wrong; the editor was.
    $bom = "\xEF\xBB\xBF".(string) json_encode(['format' => 'kbb-export/1', 'export_id' => 'e1']);

    expect(ImportManifest::parse($bom)->usable())->toBeTrue()
        ->and(ImportManifest::parse($bom)->exportId())->toBe('e1');
});

it('accepts a manifest upload as the manifest and never as one of the CSV entities', function () {
    $this->actingAs(gfAdmin(), 'admin');

    $this->postJson('/admin-api/import/upload', [
        'file' => gfFile('manifest.json', (string) json_encode(gfManifest()), 'application/json'),
    ])->assertOk()->assertJsonPath('ok', true)->assertJsonPath('accepted.0.entity', 'manifest');

    $workspace = new ImportWorkspace;

    expect($workspace->hasManifest())->toBeTrue()
        ->and($workspace->manifest()->usable())->toBeTrue();

    // And no CSV entity was written by it.
    foreach (ImportWorkspace::entities() as $entity) {
        expect($workspace->has($entity))->toBeFalse($entity.' was written by a manifest upload');
    }
});

it('offers the manifest as a file the owner can remove, because the refusal tells him to', function () {
    /*
     * The sentence a broken manifest produces ends "remove the manifest to
     * import the CSV files without it", and that is the only escape hatch there
     * is. The console draws one card per row of `files` with a Remove button
     * that posts the row's entity, so without a row here the instruction would
     * name a button on no screen he can reach.
     */
    $this->actingAs(gfAdmin(), 'admin');

    $row = collect(gfStatus()['files'])->firstWhere('entity', 'manifest');

    expect($row)->not->toBeNull('the manifest is not offered as a file at all')
        ->and($row['present'])->toBeFalse()
        ->and($row['file'])->toBe('manifest.json');

    gfUploadAll();
    gfUploadManifest(gfManifest());

    $row = collect(gfStatus()['files'])->firstWhere('entity', 'manifest');

    expect($row['present'])->toBeTrue()
        ->and($row['rows'])->toBe(count(ImportWorkspace::entities()))
        ->and($row['bytes'])->toBeGreaterThan(0);

    // And the button the sentence names really does remove it.
    $this->postJson('/admin-api/import/forget', ['entity' => 'manifest'])->assertOk();

    expect((new ImportWorkspace)->hasManifest())->toBeFalse()
        ->and(collect(gfStatus()['files'])->firstWhere('entity', 'manifest')['present'])->toBeFalse()
        // The CSVs are untouched: removing the manifest is not removing the export.
        ->and(collect(gfStatus()['files'])->firstWhere('entity', 'products')['present'])->toBeTrue();
});

it('refuses a broken manifest at upload, where the owner is still looking at the file', function () {
    $this->actingAs(gfAdmin(), 'admin');

    $this->postJson('/admin-api/import/upload', [
        'file' => gfFile('manifest.json', (string) json_encode(['format' => 'woo-export/9']), 'application/json'),
    ])->assertStatus(422)->assertJsonPath('ok', false);

    expect((new ImportWorkspace)->hasManifest())->toBeFalse();
});

it('does not mistake a CSV for a manifest', function () {
    $this->actingAs(gfAdmin(), 'admin');

    // A products export whose first cell happens to start with a brace.
    $this->postJson('/admin-api/import/upload', [
        'file' => gfFile('products.csv', "id,name,slug\n5,\"{not json}\",a\n"),
    ])->assertOk();

    expect((new ImportWorkspace)->has('products'))->toBeTrue()
        ->and((new ImportWorkspace)->hasManifest())->toBeFalse();
});

/* ======================================================================== */
/* 2 · THE BAR — a denominator or no bar                                    */
/* ======================================================================== */

it('draws a bar from the manifest when there is one', function () {
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();
    gfUploadManifest(gfManifest());

    $products = gfEntity(gfStatus(), 'products');

    expect($products['denominator_source'])->toBe('manifest')
        ->and($products['denominator'])->toBe($products['rows_counted'])
        ->and($products['rows_expected'])->toBe($products['rows_counted'])
        ->and($products['rows_mismatch'])->toBeNull()
        ->and($products['percent'])->toBe(0)
        ->and($products['in_manifest'])->toBeTrue();

    gfStart('live')->assertOk();
    gfRunToEnd();

    $after = gfEntity(gfStatus(), 'products');

    expect($after['percent'])->toBe(100)
        ->and($after['processed'])->toBe($after['denominator']);
});

it('still draws a bar with no manifest, off the count taken when the file arrived', function () {
    // The honest account: the Import screen has had a per-entity bar since Lane
    // AD, off ImportWorkspace's own count. An export with no manifest must
    // still import, and it must still show progress.
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();

    $status = gfStatus();
    $products = gfEntity($status, 'products');

    expect($status['manifest']['present'])->toBeFalse()
        ->and($products['denominator_source'])->toBe('counted')
        ->and($products['denominator'])->toBeGreaterThan(0)
        ->and($products['rows_expected'])->toBeNull()
        ->and($products['percent'])->toBe(0);
});

it('draws no bar for an entity with no file at all', function () {
    $this->actingAs(gfAdmin(), 'admin');

    $this->postJson('/admin-api/import/upload', ['file' => gfFixture('products')])->assertOk();

    $orders = gfEntity(gfStatus(), 'orders');

    expect($orders['present'])->toBeFalse()
        ->and($orders['denominator'])->toBeNull()
        ->and($orders['denominator_source'])->toBe('none')
        ->and($orders['percent'])->toBeNull();
});

it('draws NO bar when the manifest and the file disagree about how many rows there are', function () {
    /*
     * THE CASE A COUNT OF THE FILE CAN NEVER FIND.
     *
     * The workspace's own count is derived from the file, so it agrees with it
     * by construction — it cannot notice that the file on this disk is half the
     * file the exporter wrote. The manifest comes from the other end of the
     * pipe, so it can. A bar over the truncated file would reach a confident
     * 100% with rows missing, which is the same species of number as Lane GD's
     * full green bar at 0/0.
     */
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();

    $real = (new ImportWorkspace)->rowCount('products');
    gfUploadManifest(gfManifest(['products.csv' => $real + 40]));

    $products = gfEntity(gfStatus(), 'products');

    expect($products['percent'])->toBeNull('a bar was drawn over a file that does not match its manifest')
        ->and($products['denominator'])->toBeNull()
        ->and($products['denominator_source'])->toBe('disputed')
        ->and($products['rows_mismatch'])->not->toBeNull()
        ->and($products['rows_mismatch']['expected'])->toBe($real + 40)
        ->and($products['rows_mismatch']['counted'])->toBe($real)
        // And it says which way round, because "40 missing" and "40 extra" ask
        // for different things.
        ->and($products['rows_mismatch']['sentence'])->toContain('missing');

    // The OTHER direction is a different sentence and not the same one reused.
    gfUploadManifest(gfManifest(['products.csv' => max(0, $real - 3)]));

    $sentence = gfEntity(gfStatus(), 'products')['rows_mismatch']['sentence'];

    expect($sentence)->toContain('more than the export describes')
        ->and(str_contains($sentence, 'Upload it again'))->toBeFalse();
});

it('draws no whole-export bar unless every file has a denominator', function () {
    /*
     * One file short is not a smaller total, it is a total that is wrong in the
     * flattering direction: the bar runs ahead of the work and settles at 100%
     * with a whole entity still to come.
     */
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();
    gfUploadManifest(gfManifest());

    expect(gfStatus()['overall']['percent'])->toBe(0)
        ->and(gfStatus()['overall']['total'])->toBeGreaterThan(0);

    // Now make one of them undrawable.
    gfUploadManifest(gfManifest(['orders.csv' => 9999]));

    $overall = gfStatus()['overall'];

    expect($overall['percent'])->toBeNull()
        ->and($overall['total'])->toBeNull()
        ->and($overall['why_no_bar'])->not->toBeNull();
});

it('draws no bar when the checkpoint was recorded against a different file', function () {
    /*
     * `processed` counted rows of the file that was there before it was
     * replaced. processed/rows is then two different files' arithmetic, and the
     * screen already knows this — `source_changed` has been in the payload
     * since Lane AD and nothing was using it to suppress the bar.
     */
    $this->actingAs(gfAdmin(), 'admin');

    /*
     * NO MANIFEST HERE, and the replacement has EXACTLY as many rows as the
     * original. Both are deliberate: the first version of this test replaced a
     * seven-row file with a two-row one under a manifest, so the count
     * disagreed as well — and the mismatch guard suppressed the bar on its own
     * while the guard this test is named after did nothing. Removing
     * `! $sourceChanged` left the suite green (mutation M6). Isolated, this is
     * the only thing standing between the owner and a percentage made of two
     * different files' arithmetic.
     */
    $original = "term_id,name,slug,parent_id\n901,A,a-901,\n902,B,b-902,\n903,C,c-903,\n904,D,d-904,\n";

    $this->postJson('/admin-api/import/upload', ['file' => gfFile('categories.csv', $original)])->assertOk();

    gfStart('live')->assertOk();
    $this->postJson('/admin-api/import/step', ['rows' => 1])->assertOk();

    $partway = gfEntity(gfStatus(), 'categories');

    expect($partway['processed'])->toBe(1)
        ->and($partway['finished'])->toBeFalse()
        ->and($partway['percent'])->toBe(25, 'the bar was not being drawn before the file was replaced');

    // The same number of rows, different bytes.
    $this->postJson('/admin-api/import/upload', [
        'file' => gfFile('categories.csv', str_replace('A,a-901', 'A (renamed),a-901', $original)),
    ])->assertOk();

    $categories = gfEntity(gfStatus(), 'categories');

    expect($categories['source_changed'])->toBeTrue()
        ->and($categories['denominator'])->toBe(4)
        ->and($categories['rows_mismatch'])->toBeNull('the counts agree, so only source_changed can suppress it')
        ->and($categories['percent'])->toBeNull('a bar was drawn over a checkpoint about a different file');
});

it('draws no bar for a header-only file rather than a full green one at 0 of 0', function () {
    /*
     * Lane GD's exact failure, in the one place it can still happen: a file
     * with a header and no rows is legitimate — the contract gives it `rows: 0`
     * — and 0 of 0 is not a proportion.
     */
    $this->actingAs(gfAdmin(), 'admin');

    $this->postJson('/admin-api/import/upload', [
        'file' => gfFile('coupons.csv', "id,code,discount_type,amount\n"),
    ])->assertOk();

    gfUploadManifest(gfManifest());

    $coupons = gfEntity(gfStatus(), 'coupons');

    expect($coupons['present'])->toBeTrue()
        ->and($coupons['denominator'])->toBe(0)
        ->and($coupons['percent'])->toBeNull('0 of 0 drew a bar');
});

it('never reports more than a hundred per cent', function () {
    // A checkpoint can legitimately be ahead of the file: import 10 rows, then
    // upload a shorter corrected export with the same digest impossible — but a
    // restart, a delta and a re-export all get here. The bar clamps.
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();
    gfStart('live')->assertOk();
    gfRunToEnd();

    foreach (gfStatus()['entities'] as $entity) {
        if ($entity['percent'] !== null) {
            expect($entity['percent'])->toBeLessThanOrEqual(100)
                ->and($entity['percent'])->toBeGreaterThanOrEqual(0);
        }
    }
});

it('gives the live progress page a catalogue total, and none when there is nothing to count', function () {
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();
    gfUploadManifest(gfManifest());

    gfStart('live')->assertOk();
    gfRunToEnd();

    $stages = collect((new MigrationProgress)->snapshot()['stages']);
    $catalogue = $stages->firstWhere('key', 'catalogue');

    expect($catalogue['total'])->toBeGreaterThan(0)
        ->and($catalogue['done'])->toBe($catalogue['total'])
        ->and($catalogue['note'])->toContain('manifest.json')
        ->and($catalogue['entities'][0]['percent'])->not->toBeNull();

    /*
     * And the state this stage has always been in stays available: a
     * command-line import against a folder this screen never saw. Everything
     * the screen knows is thrown away — the run, its manifest snapshot, the
     * checkpoints and the uploaded files — and then the identical import is run
     * the way `php artisan kbb:import` runs it, against a directory with no
     * manifest in it, writing checkpoints under the screen's own run key.
     */
    $this->postJson('/admin-api/import/reset')->assertOk();

    foreach (ImportWorkspace::entities() as $entity) {
        (new ImportWorkspace)->forget($entity);
    }

    (new ImportWorkspace)->forgetManifest();

    (new ImportRunner)->run(new \App\Services\Import\ImportOptions(
        directory: base_path('tests/Fixtures/woo'),
        only: ['categories'],
        runKey: ImportDriver::RUN_KEY,
        adoptBySlug: true,
    ));

    $catalogue = collect((new MigrationProgress)->snapshot()['stages'])->firstWhere('key', 'catalogue');

    expect($catalogue['total'])->toBe(0)
        ->and($catalogue['note'])->toContain('there cannot be one')
        ->and($catalogue['note'])->toContain('a bar would not be')
        ->and($catalogue['entities'][0]['percent'])->toBeNull();
});

it('gives the catalogue stage no total when only some of its entities have one', function () {
    /*
     * FOUND BY MUTATION M24, which left the suite green: the test above had
     * either every denominator or none, and the interesting state is the one in
     * between. Summing the eight that are known gives a denominator smaller
     * than the work, so the bar runs ahead of the import and settles at 100%
     * with a whole file still to come — worse than no bar, which is the thing
     * this page was built to stop telling.
     */
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();
    gfUploadManifest(gfManifest());

    gfStart('live')->assertOk();
    gfRunToEnd();

    expect(collect((new MigrationProgress)->snapshot()['stages'])->firstWhere('key', 'catalogue')['total'])
        ->toBeGreaterThan(0);

    /*
     * One entity's file goes away — and its manifest entry with it, which is
     * what a second import of a half-delivered export looks like. A NEW RUN is
     * started so the new manifest is the one in force: the manifest is
     * snapshotted onto the run at Start precisely so that replacing the file
     * mid-import cannot rewrite what the history says, which means the old
     * snapshot governs until the next Start.
     */
    (new ImportWorkspace)->forget('orders');
    gfUploadManifest(gfManifest());
    gfStart('live', ['confirm_duplicate' => true])->assertOk();

    $catalogue = collect((new MigrationProgress)->snapshot()['stages'])->firstWhere('key', 'catalogue');

    expect($catalogue['total'])->toBe(0, 'a partial total was drawn as if it were the whole export');

    $orders = collect($catalogue['entities'])->firstWhere('entity', 'orders');
    $products = collect($catalogue['entities'])->firstWhere('entity', 'products');

    // The per-entity bars that DO have a denominator are untouched by it.
    expect($orders['percent'])->toBeNull()
        ->and($products['percent'])->toBe(100);
});

/* ======================================================================== */
/* 3 · THE DUPLICATE GUARD                                                  */
/* ======================================================================== */

it('refuses an export it has already imported, with a sentence and not a shrug', function () {
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();
    gfUploadManifest(gfManifest());

    gfStart('live')->assertOk();
    gfRunToEnd();

    $refusal = gfStart('live')->assertStatus(409)->json();

    expect($refusal['ok'])->toBeFalse()
        ->and($refusal['message'])->toContain('ALREADY BEEN IMPORTED')
        // It says what it would cost, because "safe" is not the same as "free".
        ->and($refusal['message'])->toContain('unchanged')
        // And the workings come back with the refusal, not only the sentence.
        ->and($refusal['status']['duplicate']['status'])->toBe('already')
        ->and($refusal['status']['duplicate']['blocking'])->toBeTrue()
        ->and($refusal['status']['duplicate']['counts']['imported'])
        ->toBe($refusal['status']['duplicate']['counts']['files']);
});

it('imports it anyway when the owner says so', function () {
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();
    gfUploadManifest(gfManifest());

    gfStart('live')->assertOk();
    gfRunToEnd();

    gfStart('live', ['confirm_duplicate' => true])->assertOk();
    $steps = gfRunToEnd();

    // And it does what the second pass has always done.
    foreach (collect(end($steps)['status']['entities'])->where('present', true) as $entity) {
        expect($entity['created'])->toBe(0, $entity['entity'].' created a row on the confirmed second pass');
    }
});

it('never refuses a preview of an export that is already in', function () {
    /*
     * A preview writes nothing and rolls itself back. Refusing to LOOK at an
     * export because it is already imported would refuse the one operation that
     * costs nothing, and "I am not sure what is in this file" is the usual
     * reason for pressing it.
     */
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();
    gfUploadManifest(gfManifest());

    gfStart('live')->assertOk();
    gfRunToEnd();

    gfStart('preview')->assertOk();

    // The verdict is still computed and still reported — it just does not stop.
    expect(gfStatus()['duplicate']['status'])->toBe('already');
});

it('does not refuse a part-way export, because part-way is the normal state', function () {
    /*
     * An import on shared hosting is sixty browser requests and the owner
     * closes the tab. A guard that refused a part-finished export would refuse
     * the resumability this whole console exists to provide.
     */
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();
    gfUploadManifest(gfManifest());

    gfStart('live')->assertOk();

    // Far enough to finish some entities and not others.
    $this->postJson('/admin-api/import/step', ['rows' => 5000])->assertOk();
    $this->postJson('/admin-api/import/step', ['rows' => 5000])->assertOk();
    $this->postJson('/admin-api/import/stop')->assertOk();

    $verdict = gfStatus()['duplicate'];

    expect($verdict['status'])->toBe('partial')
        ->and($verdict['blocking'])->toBeFalse()
        ->and($verdict['counts']['imported'])->toBeGreaterThan(0)
        ->and($verdict['counts']['new'])->toBeGreaterThan(0);

    gfStart('live')->assertOk();
});

it('accepts a corrected re-export and says why, instead of refusing the one import that matters', function () {
    /*
     * THE CASE A LAZY GUARD GETS BACKWARDS.
     *
     * "Have I seen this export id before" would refuse the import that carries
     * new information and allow the one that carries none. The export id names
     * the export; the sha256 decides whether the FILE has been read. Same id,
     * different bytes, is the owner fixing something in WordPress and exporting
     * again.
     */
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();
    gfUploadManifest(gfManifest());

    gfStart('live')->assertOk();
    gfRunToEnd();

    expect(gfStatus()['duplicate']['blocking'])->toBeTrue();

    // The same export, corrected: one product's name fixed. Same export_id.
    $body = (string) file_get_contents((new ImportWorkspace)->path('products'));
    $this->postJson('/admin-api/import/upload', [
        'file' => gfFile('products.csv', str_replace('Ginseng Serum', 'Ginseng Serum (corrected)', $body)),
    ])->assertOk();

    gfUploadManifest(gfManifest());

    $verdict = gfStatus()['duplicate'];

    expect($verdict['blocking'])->toBeFalse('a corrected re-export was refused')
        ->and($verdict['status'])->toBe('partial')
        ->and($verdict['counts']['changed'])->toBe(1);

    $products = collect($verdict['entities'])->firstWhere('entity', 'products');

    expect($products['state'])->toBe('changed')
        ->and($products['sentence'])->toContain('corrected re-export');

    // And it really does start.
    gfStart('live')->assertOk();
});

it('does not call a file that did not arrive whole a corrected re-export', function () {
    /*
     * A digest that does not match is EITHER a correction OR an upload that was
     * cut short, and the sentence for one is the wrong sentence for the other:
     * "this is a corrected re-export and it will be imported again" is the shop
     * reassuring the owner about the thing that is wrong. The manifest's row
     * count is what tells them apart, and it is the only thing that can.
     */
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();
    gfUploadManifest(gfManifest());

    gfStart('live')->assertOk();
    gfRunToEnd();

    $whole = (string) file_get_contents((new ImportWorkspace)->path('orders'));
    $lines = explode("\n", $whole);

    // Half the rows, and the manifest still describing all of them.
    $this->postJson('/admin-api/import/upload', [
        'file' => gfFile('orders.csv', implode("\n", array_slice($lines, 0, (int) (count($lines) / 2)))),
    ])->assertOk();

    $orders = collect(gfStatus()['duplicate']['entities'])->firstWhere('entity', 'orders');

    expect($orders['state'])->toBe('changed')
        ->and($orders['sentence'])->toContain('did not arrive whole')
        ->and(str_contains($orders['sentence'], 'corrected re-export'))->toBeFalse();
});

it('recognises a repeat with no manifest at all, by the bytes', function () {
    // The export id gives the WORDS; the digest makes the DECISION. Ten of the
    // eleven files this importer reads existed before the plugin did.
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();

    gfStart('live')->assertOk();
    gfRunToEnd();

    $verdict = gfStatus()['duplicate'];

    expect($verdict['blocking'])->toBeTrue()
        ->and($verdict['export_id'])->toBeNull()
        ->and($verdict['sentence'])->toContain('ALREADY BEEN IMPORTED');
});

it('does not let a preview count as an import', function () {
    /*
     * A preview writes nothing. If a preview row were matched by the guard, the
     * shop would refuse the real import of an export nobody has imported — the
     * worst failure this feature can have, because it refuses the thing the
     * owner came to do.
     */
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();
    gfUploadManifest(gfManifest());

    gfStart('preview')->assertOk();
    gfRunToEnd();

    expect(gfStatus()['duplicate']['blocking'])->toBeFalse('a preview blocked the real import');

    gfStart('live')->assertOk();
});

it('does not let a preview row count as an import even if one is somehow finished', function () {
    /*
     * FOUND BY MUTATION M10, which left the suite green: dropping the
     * `mode = live` filter changed nothing, because a preview row is never
     * written with `finished`, so the `finished` filter already excluded it.
     * Two locks on one door is the right number here — the day somebody makes a
     * preview mark an entity finished, the guard that refuses the real import
     * of an export nobody has imported must not be the thing that breaks. So
     * the second lock is tested directly rather than left to be inferred.
     */
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();
    gfUploadManifest(gfManifest());

    gfStart('preview')->assertOk();
    gfRunToEnd();

    /*
     * Each row gets ITS OWN entity's digest, not one entity's for all of them.
     * The first version of this set them all to products.csv's, so only
     * products ever matched, the verdict came back `partial` rather than
     * `already`, and the mutation stayed green for a reason that had nothing to
     * do with the guard.
     */
    foreach (ImportWorkspace::entities() as $entity) {
        DB::table(ImportLedger::TABLE)->where('entity', $entity)->update([
            'finished' => true,
            'file_sha256' => (new ImportWorkspace)->fingerprint($entity),
        ]);
    }

    expect(gfStatus()['duplicate']['blocking'])->toBeFalse('a finished preview row blocked the real import');

    gfStart('live')->assertOk();
});

it('does not let an unfinished entity count as an import', function () {
    // The same failure one size smaller: an entity that was only part-read must
    // not be reported as already in, or a resumed import would be refused.
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();
    gfUploadManifest(gfManifest());

    gfStart('live')->assertOk();
    $this->postJson('/admin-api/import/step', ['rows' => 1])->assertOk();
    $this->postJson('/admin-api/import/stop')->assertOk();

    $unfinished = DB::table(ImportLedger::TABLE)->where('finished', false)->count();

    expect($unfinished)->toBeGreaterThan(0);

    foreach (gfStatus()['duplicate']['entities'] as $entity) {
        if ($entity['state'] === 'imported') {
            $row = DB::table(ImportLedger::TABLE)
                ->where('entity', $entity['entity'])->where('finished', true)->first();

            expect($row)->not->toBeNull($entity['entity'].' was called imported with no finished row');
        }
    }
});

it('refuses a manifest it cannot read rather than importing under it', function () {
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();

    // Past the upload guard, straight onto the disk — a manifest replaced out
    // of band, which on a shared host is a thing an FTP client does.
    file_put_contents((new ImportWorkspace)->manifestPath(), (string) json_encode(['format' => 'kbb-export/7']));

    $refusal = gfStart('live')->assertStatus(409)->json();

    expect($refusal['message'])->toContain('kbb-export/7');

    // A preview is refused too: the manifest describes the file set, not just
    // the counts.
    gfStart('preview')->assertStatus(409);

    // And the named escape hatch works.
    $this->postJson('/admin-api/import/forget', ['entity' => 'manifest'])->assertOk();
    gfStart('live')->assertOk();
});

/* ======================================================================== */
/* 4 · ALL RECORD                                                           */
/* ======================================================================== */

it('keeps the record after the run row has been overwritten by the next run', function () {
    /*
     * `import_runs` holds exactly ONE row per run key and start() updates it in
     * place. Before this table there was nothing anywhere that could answer
     * "what did the last import do" once a second one began.
     */
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();
    gfUploadManifest(gfManifest());

    gfStart('live')->assertOk();
    gfRunToEnd();

    $first = (new ImportLedger)->runs();

    expect($first)->toHaveCount(1)
        ->and($first[0]['export_id'])->toBe('8f14e45f-ceea-467a-9c31-1a2b3c4d5e6f')
        ->and($first[0]['source_site'])->toBe('https://kbeautybliss.com')
        ->and($first[0]['export_generated_at'])->toBe('2026-09-18T09:30:00+04:00')
        ->and($first[0]['totals']['created'])->toBeGreaterThan(0);

    gfStart('live', ['confirm_duplicate' => true])->assertOk();
    gfRunToEnd();

    $both = (new ImportLedger)->runs();

    expect($both)->toHaveCount(2)
        // Newest first, and the older one is untouched.
        ->and($both[1]['run_uid'])->toBe($first[0]['run_uid'])
        ->and($both[1]['totals']['created'])->toBe($first[0]['totals']['created'])
        ->and($both[0]['totals']['created'])->toBe(0);

    // Exactly one row survives per entity per run — not one per step.
    expect(DB::table(ImportLedger::TABLE)->where('run_uid', $first[0]['run_uid'])->count())
        ->toBe(count($first[0]['entities']));
});

it('keeps the record when the owner presses forget progress and start over', function () {
    /*
     * Reset deletes this screen's checkpoints and its run row on purpose. It
     * must not delete the answer to "what is already in my shop" — that is the
     * one thing the owner cannot recover any other way.
     */
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();
    gfUploadManifest(gfManifest());

    gfStart('live')->assertOk();
    gfRunToEnd();

    $before = (new ImportLedger)->runs();

    $this->postJson('/admin-api/import/reset')->assertOk();

    expect(DB::table('import_checkpoints')->where('run_key', ImportDriver::RUN_KEY)->count())->toBe(0)
        ->and((new ImportLedger)->runs())->toEqual($before);
});

it('records which export, taken from where and when, per entity', function () {
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();
    gfUploadManifest(gfManifest());

    gfStart('live')->assertOk();
    gfRunToEnd();

    $run = (new ImportLedger)->runs()[0];
    $products = collect($run['entities'])->firstWhere('entity', 'products');

    expect($products['file'])->toBe('products.csv')
        ->and($products['file_sha256'])->toBe((new ImportWorkspace)->fingerprint('products'))
        ->and($products['manifest_rows'])->toBe((new ImportWorkspace)->rowCount('products'))
        ->and($products['rows_counted'])->toBe((new ImportWorkspace)->rowCount('products'))
        ->and($products['finished'])->toBeTrue()
        ->and($products['finished_at'])->not->toBeNull()
        ->and($products['created'])->toBeGreaterThan(0)
        // The count check the runner does after every bucket comes along, so
        // the record says what the arithmetic said and not only what it did.
        ->and($products['verification']['verdict'])->toBeIn(['verified', 'counted', 'unverifiable', 'partial']);
});

it('records what was adjusted and what was discarded, summed across the steps', function () {
    /*
     * These two are the only numbers not read back from the checkpoint: the
     * adjustment and discard lists live in ONE step's ImportReport and die with
     * it, so they are summed onto the row as the steps go past. A run in tiny
     * slices must therefore report the same totals as a run in one.
     */
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();

    gfStart('live')->assertOk();
    gfRunToEnd(rows: 2);

    $sliced = gfRowLevelKinds();

    $this->postJson('/admin-api/import/reset')->assertOk();
    DB::table(ImportLedger::TABLE)->delete();

    gfStart('live', ['confirm_duplicate' => true])->assertOk();
    gfRunToEnd(rows: 5000);

    $whole = gfRowLevelKinds();

    expect($sliced)->toBe($whole);

    // And there is something to compare: the fixture carries adjustments and
    // discards of its own — a coupon's usage_limit_per_user, two Yoast fields.
    expect(collect($sliced)->flatten()->sum())->toBeGreaterThan(0);

    // The kinds are kept, not just the totals — "1,046 order amounts carrying
    // fils" is a decision and "1,046" is a number.
    $kinds = collect((new ImportLedger)->runs()[0]['entities'])
        ->flatMap(fn ($e) => array_keys($e['adjusted_kinds']) + array_keys($e['discarded_kinds']));

    expect($kinds)->not->toBeEmpty();
});

it('restates the per-entity discard instead of counting it once per browser step', function () {
    /*
     * FOUND BY MUTATION M22, which left the suite green.
     *
     * `reportIgnoredColumns` emits ONE entry per entity per run() call and the
     * screen calls run() once per browser step, so a blind sum turns "this
     * export has columns nothing reads" into a count of how many times the
     * browser pressed Step. The row-level kinds beside it are summed, because
     * each row is read exactly once — and the test that pins THOSE deliberately
     * excludes this kind, which is why it could not see this.
     */
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();

    gfStart('live')->assertOk();
    $steps = gfRunToEnd(rows: 2);

    expect(count($steps))->toBeGreaterThan(9, 'this was not actually sliced');

    foreach ((new ImportLedger)->runs()[0]['entities'] as $entity) {
        foreach ($entity['discarded_kinds'] as $kind => $count) {
            foreach (ImportLedger::RESTATED_KINDS as $prefix) {
                if (str_starts_with((string) $kind, $prefix)) {
                    expect($count)->toBe(1, $entity['entity'].' counted a per-entity observation once per step');
                }
            }
        }
    }
});

it('merges observation kinds by adding the row ones and restating the entity ones', function () {
    // The rule on its own, so the reason survives a rewrite of the caller.
    $first = ImportLedger::mergeKinds([], [
        'a price carrying fils' => 3,
        ImportLedger::RESTATED_KINDS[0].' — they are in the file' => 4,
    ]);

    $second = ImportLedger::mergeKinds($first, [
        'a price carrying fils' => 2,
        ImportLedger::RESTATED_KINDS[0].' — they are in the file' => 4,
    ]);

    expect($second['a price carrying fils'])->toBe(5)
        ->and($second[ImportLedger::RESTATED_KINDS[0].' — they are in the file'])->toBe(4);
});

it('keeps the moment an entity finished, rather than moving it to the latest step', function () {
    /*
     * The runner re-presents a COMPLETED entity from row one on a later run
     * (Checkpoint::open's own comment), so without this the recorded finishing
     * time would creep forward on every pass and the record would say the
     * import finished at a time it did not.
     */
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();

    gfStart('live')->assertOk();
    gfRunToEnd();

    $uid = (string) DB::table(ImportDriver::TABLE)->value('run_uid');
    $first = (string) DB::table(ImportLedger::TABLE)
        ->where('run_uid', $uid)->where('entity', 'products')->value('finished_at');

    expect($first)->not->toBe('');

    /*
     * THE CLOCK HAS TO MOVE. These timestamps are stored to the second, so a
     * second pass in the same second writes the same value and the assertion
     * passes whether the moment is preserved or overwritten — which is exactly
     * what happened: the mutation that removed the preservation stayed green.
     */
    $this->travel(90)->seconds();

    // Another step against the same run: currentEntity() has nothing left, so
    // ask for the entity again by restarting it within this run.
    DB::table(ImportDriver::TABLE)->where('run_uid', $uid)->update([
        'status' => 'running',
        'done_entities' => '["categories","brands"]',
        'finished_at' => null,
    ]);

    gfRunToEnd();

    expect(DB::table(ImportLedger::TABLE)->where('run_uid', $uid)->where('entity', 'products')->value('finished_at'))
        ->toBe($first, 'the moment products finished moved');
});

it('records a preview as a preview and never as something that was written', function () {
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();
    gfUploadManifest(gfManifest());

    gfStart('preview')->assertOk();
    gfRunToEnd();

    $run = (new ImportLedger)->runs()[0];

    expect($run['mode'])->toBe('preview');

    foreach ($run['entities'] as $entity) {
        expect($entity['finished'])->toBeFalse($entity['entity'].' was recorded as finished by a preview');
    }
});

it('hands back the whole record as a spreadsheet', function () {
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();
    gfUploadManifest(gfManifest());

    gfStart('live')->assertOk();
    gfRunToEnd();

    $response = $this->get('/admin-api/import/history.csv')->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/csv')
        // It quotes note text taken from the owner's own export, so no browser
        // renders it. ImportApiController::rejects() says the same.
        ->and($response->headers->get('content-disposition'))->toContain('attachment')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');

    $lines = array_values(array_filter(explode("\n", (string) $response->getContent())));

    expect($lines[0])->toContain('export id')
        ->and(count($lines) - 1)->toBe(DB::table(ImportLedger::TABLE)->count());
});

it('serves a history page that says so when there is nothing yet', function () {
    $this->actingAs(gfAdmin(), 'admin');

    $this->get('/admin-api/import/history-page')->assertOk()->assertSee('What has been imported');

    $json = $this->getJson('/admin-api/import/history')->assertOk()->json();

    expect($json['runs'])->toBe([])
        ->and($json['empty_note'])->toContain('kbb:import');
});

/* ======================================================================== */
/* 5 · THE GUARD ON THE NEW ROUTES                                          */
/* ======================================================================== */

it('mounts every new route behind the admin session guard', function () {
    $routes = ImportHistoryRoutes::registered();

    expect($routes)->toHaveCount(count(ImportHistoryRoutes::URIS));

    foreach ($routes as $route) {
        $missing = array_diff(ImportHistoryRoutes::STACK, $route->gatherMiddleware());

        expect($missing)->toBe([], $route->uri().' is missing '.implode(', ', $missing));
    }

    foreach (ImportHistoryRoutes::URIS as $uri) {
        $this->getJson('/'.$uri)->assertStatus(401);
    }
});

it('maps every new route to data.import through a wildcard that already exists', function () {
    /*
     * These three sit under /admin-api/import/ precisely so that
     * ['*', 'admin-api/import/**', 'data.import'] covers them. A prefix of this
     * lane's own would fall through to the closed owner-only default and
     * AdminCapabilityMapTest would fail by name; a new RULES entry beneath that
     * wildcard would be dead text, shadowed by it. If a later tidy-up narrows
     * the wildcard, this fails here rather than as a 403 on a host with no
     * shell.
     */
    foreach (ImportHistoryRoutes::registered() as $route) {
        expect(AdminCapabilities::forPath('GET', $route->uri()))
            ->toBe('data.import', $route->uri().' is not mapped to data.import');
    }

    // And the rule really is the wildcard rather than an entry of this lane's.
    $ours = array_filter(
        AdminCapabilities::RULES,
        fn (array $rule): bool => in_array($rule[1], ImportHistoryRoutes::URIS, true),
    );

    expect($ours)->toBe([], 'a rule was added under a wildcard that already covers it — dead text');
});

it('ships a clear_caches migration with the new routes', function () {
    // routes/web.php is compiled on the server and the host has no shell, so a
    // new route does not exist until bootstrap/cache/routes-*.php is gone.
    $migrations = glob(database_path('migrations/*clear_caches_import_refinement.php')) ?: [];

    expect($migrations)->toHaveCount(1);

    $body = (string) file_get_contents($migrations[0]);

    expect($body)->toContain('routes-*.php')
        ->and($body)->toContain('framework/views');
});

/* ======================================================================== */
/* 6 · WHAT WAS FOUND WRONG ON THE WAY THROUGH                              */
/* ======================================================================== */

it('no longer reports its own bookkeeping files to the owner as unimported data', function () {
    /*
     * ImportWorkspace cached each file's row count in `products.csv.meta.json`
     * BESIDE the export, and ImportRunner::reportUnreadFiles() globs
     * *.{csv,tsv,txt,json,xml} and names everything no importer opens. A live
     * run never showed it — it passes `only`, which exempts the check — but a
     * PREVIEW passes no `only`, so every preview from this screen reported nine
     * of the screen's own sidecars back as files carrying data that would not
     * be imported, in the discard list the owner is asked to approve.
     */
    $this->actingAs(gfAdmin(), 'admin');
    gfUploadAll();

    $stray = glob((new ImportWorkspace)->directory().'/*.meta.json') ?: [];

    expect($stray)->toBe([], 'a sidecar is still sitting in the export folder');

    gfStart('preview')->assertOk();
    gfRunToEnd();

    $named = [];

    foreach (gfStatus()['entities'] as $entity) {
        foreach (array_keys($entity['notes'] ?? []) as $note) {
            $named[] = (string) $note;
        }
    }

    $rejects = (string) $this->get('/admin-api/import/rejects?mode=preview')->getContent();

    // `toContain` is variadic and passes vacuously with a message argument, so
    // this is asked the only way that actually asks it.
    foreach ($named as $note) {
        expect(str_contains($note, 'meta.json'))->toBeFalse('the preview named a sidecar: '.$note);
    }

    expect(str_contains($rejects, 'meta.json'))->toBeFalse('the rejection CSV named a sidecar');
});

it('does not name manifest.json as a file no importer opens, now that one does', function () {
    /*
     * The volume rehearsal duly named it (docs/FV-IMPORT-AT-VOLUME.md §10) and
     * was right to: nothing read it. ImportManifest reads it now, and a list
     * whose every line is not true is a list the owner learns to skim.
     */
    $dir = sys_get_temp_dir().'/kbb-gf-unread-'.bin2hex(random_bytes(5));
    mkdir($dir, 0775, true);

    file_put_contents($dir.'/manifest.json', (string) json_encode(['format' => 'kbb-export/1']));
    // variations.csv, because refunds.csv is an entity now (RefundImporter) and
    // a file this runner opens proves nothing about the channel for files it
    // does not.
    file_put_contents($dir.'/variations.csv', "variation_id,parent_id,sku\n1,4021,VAR-1\n");
    copy(base_path('tests/Fixtures/woo/products.csv'), $dir.'/products.csv');

    $report = (new ImportRunner)->run(new \App\Services\Import\ImportOptions(
        directory: $dir,
        dryRun: true,
        runKey: 'gf-unread-'.bin2hex(random_bytes(4)),
    ));

    /*
     * discards() is keyed by KIND and each entry carries samples; the FILE NAME
     * is the sample's `field`, and `before` is its row count. The first version
     * of this read `before` and would have passed against a guard that did
     * nothing — an empty list contains neither name.
     */
    $named = [];

    foreach ($report->for('export')->discards() as $group) {
        foreach ($group['samples'] as $sample) {
            $named[] = (string) $sample['field'];
        }
    }

    expect($named)->not->toBeEmpty('nothing was named at all, so this proves nothing');

    expect(in_array('variations.csv', $named, true))->toBeTrue('the unread-file channel stopped working')
        ->and(in_array('manifest.json', $named, true))->toBeFalse('manifest.json was named as unread');

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
});

it('accepts an upload of every file at once, which the old cap of six refused', function () {
    /*
     * ImportWorkspace::ENTITIES holds NINE entities and an export now also
     * carries manifest.json. `'files' => ['max:6']` refused the whole request
     * with a 422 the moment the owner selected the folder, which is the obvious
     * thing to do with a folder of exports.
     */
    $this->actingAs(gfAdmin(), 'admin');

    $files = array_map(gfFixture(...), ImportWorkspace::entities());
    $files[] = gfFile('manifest.json', (string) json_encode(['format' => 'kbb-export/1']), 'application/json');

    expect(count($files))->toBeGreaterThan(6);

    $this->postJson('/admin-api/import/upload', ['files' => $files])
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect((new ImportWorkspace)->hasManifest())->toBeTrue();

    foreach (ImportWorkspace::entities() as $entity) {
        expect((new ImportWorkspace)->has($entity))->toBeTrue($entity.' did not survive a bulk upload');
    }
});

/**
 * Every adjustment and discard of a ROW, by kind, for the newest run.
 *
 * The one entity-level kind is excluded, and the exclusion is the finding
 * rather than a convenience: ImportRunner resets its `columnsSeen` /
 * `columnsRead` sets on every run() call, and a column counts as read only on a
 * row where the importer reached for it — so a slice of two rows calls a column
 * ignored that the next slice reads, and the "columns no field of this importer
 * reads" count genuinely differs between a sliced run and an unsliced one. It
 * is a per-slice observation, not a fact about the file.
 *
 * Everything else here IS a fact about the file, because each row is read
 * exactly once across a run, and this is the assertion that says so.
 *
 * @return array<string, array<string, int>>
 */
function gfRowLevelKinds(): array
{
    $out = [];

    foreach ((new ImportLedger)->runs()[0]['entities'] as $entity) {
        $kinds = $entity['adjusted_kinds'] + $entity['discarded_kinds'];

        foreach (array_keys($kinds) as $kind) {
            foreach (ImportLedger::RESTATED_KINDS as $prefix) {
                if (str_starts_with((string) $kind, $prefix)) {
                    unset($kinds[$kind]);
                }
            }
        }

        ksort($kinds);

        $out[$entity['entity']] = $kinds;
    }

    return $out;
}
