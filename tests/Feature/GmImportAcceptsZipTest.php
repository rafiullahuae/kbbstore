<?php

declare(strict_types=1);

/**
 * Store → Import accepts a group's zip — Lane GM.
 *
 * The owner asked the WordPress exporter for one downloadable file per group
 * rather than one heavy folder over FTP — *"allow to download each group
 * seperate files. so will have no any heavy file."* Lane GL builds that as one
 * zip per group, each with its own manifest.json and all of them sharing one
 * export_id. This is the other end: the zips have to land in Store → Import
 * without the owner unzipping anything, because unzipping by hand is the cost
 * the feature exists to remove.
 *
 * FOUR PROPERTIES ARE UNDER TEST, and only the first is about unzipping:
 *
 *   1. A ZIP BEHAVES EXACTLY AS IF ITS FILES HAD BEEN UPLOADED LOOSE. Not
 *      nearly: identically. Every member goes through ImportWorkspace::accept(),
 *      so the CSV parse, the id-column check, the manifest reader and every
 *      refusal sentence are the ones the loose path already had. A loose upload
 *      is unchanged, and that is asserted rather than assumed.
 *
 *   2. AN ARCHIVE IS A HOSTILE INPUT AND IS TREATED AS ONE. A loose upload
 *      carries one filename this code discards; a zip carries as many names as
 *      it likes, plus a declared size per member that it also chooses, plus a
 *      unix mode. Every one of those is a way out of the import folder, and
 *      each is refused by a guard of its own with a test that FAILS IF ONLY
 *      THAT GUARD IS REMOVED — which for the traversal check means a member
 *      whose basename the allowlist is perfectly happy with.
 *
 *   3. SEVERAL GROUP ZIPS ADD UP TO ONE IMPORT. Catalogue on Monday and Orders
 *      on Tuesday is the normal use of this feature, so the two manifests have
 *      to MERGE rather than the second replacing the first, the duplicate guard
 *      must not mistake the second zip for a repeat of the first, and every
 *      file's progress denominator has to survive both.
 *
 *   4. NOTHING IS WRITTEN THAT IS NOT AN EXPORT FILE. Not to the workspace, and
 *      not to the scratch directory either — a .php inside a zip never exists
 *      as a file on this server at any point.
 */

use App\Models\AdminUser;
use App\Services\ImportConsole\ImportArchive;
use App\Services\ImportConsole\ImportManifest;
use App\Services\ImportConsole\ImportWorkspace;
use App\Support\AdminCapabilities;
use Illuminate\Http\UploadedFile;
use Tests\Support\ImportAdminRoutes;
use Tests\Support\ZipBuilder;

/* ------------------------------------------------------------------ set-up */

beforeEach(function () {
    ImportAdminRoutes::wire($this->app);

    gmPurge(storage_path('app/import'));
});

afterEach(function () {
    gmPurge(storage_path('app/import'));

    foreach (glob(sys_get_temp_dir().'/kbb-gm-*') ?: [] as $leftover) {
        is_dir($leftover) ? gmPurge($leftover) : @unlink($leftover);
    }
});

function gmPurge(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir.'/'.$entry;

        is_dir($path) ? gmPurge($path) : @unlink($path);
    }

    @rmdir($dir);
}

function gmAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Import Owner',
        'email' => 'gm-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** The bytes of a checked-in fixture, for putting inside an archive. */
function gmFixtureBody(string $entity): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/woo/'.ImportWorkspace::meta($entity)['file']));
}

/** Upload a file that is already on disk, the way the browser posts one. */
function gmUpload(string $path, string $name, ?string $entity = null): \Illuminate\Testing\TestResponse
{
    // A COPY. accept() moves what it is given, and a test that handed over the
    // fixture itself would delete it out of the repository.
    $temp = sys_get_temp_dir().'/kbb-gm-'.bin2hex(random_bytes(6)).'-'.basename($name);
    copy($path, $temp);

    $body = ['file' => new UploadedFile($temp, $name, null, null, true)];

    if ($entity !== null) {
        $body['entity'] = $entity;
    }

    return test()->postJson('/admin-api/import/upload', $body);
}

/**
 * The manifest Lane GL's plugin would put inside ONE group's zip.
 *
 * Built from the real fixture bytes so the agreeing case genuinely agrees, and
 * carrying `groups` exactly as docs/GK-EXPORT-GROUPS.md §5 writes it — a
 * skipped group's files ABSENT from `files`, never listed with rows: 0.
 *
 * @param  list<string>  $entities
 * @param  list<string>  $skipped
 */
function gmGroupManifest(
    string $group,
    array $entities,
    array $skipped,
    string $exportId = '8f14e45f-ceea-467a-9c31-1a2b3c4d5e6f',
    array $extra = [],
): array {
    $files = [];
    $counts = [];

    foreach ($entities as $entity) {
        $name = ImportWorkspace::meta($entity)['file'];
        $body = gmFixtureBody($entity);

        $files[$name] = [
            'rows' => gmCountRows($body),
            'bytes' => strlen($body),
            'sha256' => hash('sha256', $body),
        ];

        $counts[$entity] = gmCountRows($body);
    }

    return array_replace([
        'format' => ImportManifest::FORMAT,
        'export_id' => $exportId,
        'generated_at' => '2026-09-18T09:30:00+04:00',
        'source' => [
            'site_url' => 'https://kbeautybliss.com',
            'wp_version' => '6.5.2',
            'woo_version' => '8.7.0',
            'plugin_version' => '1.0.0',
        ],
        'files' => $files,
        'counts' => $counts,
        'groups' => [
            'selected' => [$group],
            'skipped' => $skipped,
            'files' => array_keys($files),
            'assumed_already_imported' => [],
        ],
        'notes' => [],
    ], $extra);
}

/** Data rows the way ImportWorkspace counts them — fgetcsv, header excluded. */
function gmCountRows(string $body): int
{
    $path = sys_get_temp_dir().'/kbb-gm-count-'.bin2hex(random_bytes(4));
    file_put_contents($path, $body);

    $handle = fopen($path, 'rb');
    $rows = -1;

    while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        if ($cells === [null]) {
            continue;
        }

        $rows++;
    }

    fclose($handle);
    @unlink($path);

    return max(0, $rows);
}

/**
 * One group's zip, the way GL will ship it.
 *
 * @param  list<string>  $entities
 */
function gmGroupZip(string $group, array $entities, array $skipped, ?array $manifest = null, string $prefix = ''): string
{
    $members = [];

    foreach ($entities as $entity) {
        $members[$prefix.ImportWorkspace::meta($entity)['file']] = gmFixtureBody($entity);
    }

    $members[$prefix.'manifest.json'] = (string) json_encode(
        $manifest ?? gmGroupManifest($group, $entities, $skipped)
    );

    return ZipBuilder::ordinary($members);
}

function gmStatus(): array
{
    return test()->getJson('/admin-api/import/status')->assertOk()->json();
}

function gmEntity(array $status, string $entity): array
{
    foreach ($status['entities'] as $row) {
        if ($row['entity'] === $entity) {
            return $row;
        }
    }

    throw new RuntimeException('no such entity in the status payload: '.$entity);
}

/** Every file anywhere under storage/app/import, relative to it. */
function gmEverythingUnderImport(): array
{
    $root = storage_path('app/import');

    if (! is_dir($root)) {
        return [];
    }

    $out = [];

    $walk = function (string $dir) use (&$walk, $root, &$out): void {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;

            if (is_dir($path)) {
                $walk($path);

                continue;
            }

            $out[] = ltrim(str_replace($root, '', $path), '/');
        }
    };

    $walk($root);
    sort($out);

    return $out;
}

/* ================================================================= 1. parity
 *
 * The invariant everything else rests on: a zip is the files inside it.
 */

it('lands exactly the files a loose upload lands', function () {
    $this->actingAs(gmAdmin(), 'admin');

    // The same two entities, twice: once loose, once zipped, on a clean
    // workspace each time — then compared byte for byte.
    gmUpload(base_path('tests/Fixtures/woo/categories.csv'), 'categories.csv')->assertOk();
    gmUpload(base_path('tests/Fixtures/woo/brands.csv'), 'brands.csv')->assertOk();

    $workspace = new ImportWorkspace;

    $loose = [
        'categories' => hash_file('sha256', $workspace->path('categories')),
        'brands' => hash_file('sha256', $workspace->path('brands')),
    ];

    gmPurge(storage_path('app/import'));

    $zip = ZipBuilder::ordinary([
        'categories.csv' => gmFixtureBody('categories'),
        'brands.csv' => gmFixtureBody('brands'),
    ]);

    gmUpload($zip, 'catalogue.zip')->assertOk()->assertJsonPath('ok', true);

    expect([
        'categories' => hash_file('sha256', $workspace->path('categories')),
        'brands' => hash_file('sha256', $workspace->path('brands')),
    ])->toBe($loose);
});

it('reports every file inside the zip separately, the way a multi-file upload does', function () {
    $this->actingAs(gmAdmin(), 'admin');

    $zip = gmGroupZip('catalogue', ['categories', 'brands', 'products'], ['sales', 'customers']);

    $body = gmUpload($zip, 'kbb-export-catalogue.zip')->assertOk()->json();

    expect($body['ok'])->toBeTrue();
    expect($body['refused'])->toBe([]);

    $entities = array_column($body['accepted'], 'entity');
    sort($entities);

    expect($entities)->toBe(['brands', 'categories', 'manifest', 'products']);
});

it('reads a zip that wraps its files in one directory', function () {
    $this->actingAs(gmAdmin(), 'admin');

    // Lane GL had not published docs/GL-GROUP-DOWNLOADS.md when this was
    // written, so BOTH plausible shapes are read and this is the other one.
    $zip = gmGroupZip('catalogue', ['categories', 'brands'], ['sales'], null, 'kbb-export-catalogue/');

    gmUpload($zip, 'catalogue.zip')->assertOk()->assertJsonPath('ok', true);

    $workspace = new ImportWorkspace;

    expect($workspace->has('categories'))->toBeTrue();
    expect($workspace->has('brands'))->toBeTrue();
    expect($workspace->hasManifest())->toBeTrue();
});

it('recognises a zip by its bytes and not by its name', function () {
    $this->actingAs(gmAdmin(), 'admin');

    $zip = ZipBuilder::ordinary(['categories.csv' => gmFixtureBody('categories')]);

    // Named .csv, served as text/csv, and still a zip. A shop that trusted the
    // extension would hand these bytes to the CSV parser.
    gmUpload($zip, 'categories.csv')->assertOk()->assertJsonPath('ok', true);

    expect((new ImportWorkspace)->has('categories'))->toBeTrue();
});

it('ignores the entity dropdown when the upload is a zip', function () {
    $this->actingAs(gmAdmin(), 'admin');

    $zip = ZipBuilder::ordinary([
        'categories.csv' => gmFixtureBody('categories'),
        'brands.csv' => gmFixtureBody('brands'),
    ]);

    // "This file is the orders export" is a statement about ONE file. Honouring
    // it here would write brands.csv over orders.csv because a dropdown was
    // left on the wrong setting.
    gmUpload($zip, 'catalogue.zip', 'orders')->assertOk();

    $workspace = new ImportWorkspace;

    expect($workspace->has('categories'))->toBeTrue();
    expect($workspace->has('brands'))->toBeTrue();
    expect($workspace->has('orders'))->toBeFalse();
});

it('still accepts a loose CSV upload exactly as it did before', function () {
    $this->actingAs(gmAdmin(), 'admin');

    $body = gmUpload(base_path('tests/Fixtures/woo/products.csv'), 'products.csv')->assertOk()->json();

    expect($body['ok'])->toBeTrue();
    expect($body['accepted'][0]['entity'])->toBe('products');
    expect($body['accepted'][0]['rows'])->toBeGreaterThan(0);
    expect((new ImportWorkspace)->has('products'))->toBeTrue();
});

it('still refuses a loose file it cannot place, in the same words', function () {
    $this->actingAs(gmAdmin(), 'admin');

    $temp = ZipBuilder::tempPath('.csv');
    file_put_contents($temp, "nothing,useful\n1,2\n");

    $body = gmUpload($temp, 'mystery.csv')->assertStatus(422)->json();

    expect($body['ok'])->toBeFalse();
    expect($body['refused'][0]['message'])->toContain('Which export is this?');
});

it('refuses a file inside the zip without discarding the ones that were fine', function () {
    $this->actingAs(gmAdmin(), 'admin');

    $zip = ZipBuilder::ordinary([
        'categories.csv' => gmFixtureBody('categories'),
        // No id column, so accept() refuses it — the same sentence a loose
        // upload of this file would get.
        'products.csv' => "name,price\nSome Serum,42\n",
    ]);

    $body = gmUpload($zip, 'catalogue.zip')->assertOk()->json();

    expect(array_column($body['accepted'], 'entity'))->toContain('categories');
    expect($body['refused'])->toHaveCount(1);
    expect($body['refused'][0]['message'])->toContain('products.csv (inside the zip)');
    expect($body['refused'][0]['message'])->toContain('no "id" or "wc_id"');

    $workspace = new ImportWorkspace;

    expect($workspace->has('categories'))->toBeTrue();
    expect($workspace->has('products'))->toBeFalse();
});

/* =============================================================== 2. security
 *
 * Each of these is built by hand at the byte level, because ZipArchive
 * normalises several of the names away before they reach the archive — a test
 * built with it alone would be testing a name that is not the one the attack
 * uses. tests/Support/ZipBuilder.php explains the struct packing.
 */

it('refuses a member that climbs out of the import folder', function () {
    $this->actingAs(gmAdmin(), 'admin');

    $zip = ZipBuilder::raw([
        ['name' => '../../../.env', 'body' => "APP_KEY=stolen\n"],
    ]);

    $body = gmUpload($zip, 'catalogue.zip')->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('tries to write outside the import folder');

    /*
     * And the thing it was reaching for is untouched.
     *
     * str_contains() rather than `expect(...)->not->toContain(...)`, which
     * PASSES VACUOUSLY: toContain is variadic, so the negated form asserts
     * "not all of these", and with one needle it still reads as though it
     * checked something it did not. The whole point of this assertion is that
     * it would go red if the write had landed.
     */
    expect(str_contains((string) file_get_contents(base_path('.env')), 'APP_KEY=stolen'))
        ->toBeFalse('the zip wrote into the application root');
});

it('refuses a traversal whose file name the allowlist would allow', function () {
    $this->actingAs(gmAdmin(), 'admin');

    /*
     * THE ONE THAT PROVES THE TRAVERSAL CHECK IS NOT DEAD TEXT.
     *
     * `../../../.env` above is refused by two guards at once — the traversal
     * check and the "only .csv and manifest.json" allowlist — so deleting the
     * traversal check would leave that test green and this repository has paid
     * for a guard standing behind another one doing nothing before
     * (Api\ProductController's status filter, CLAUDE.md).
     *
     * `../../../../products.csv` has the basename products.csv, which the
     * allowlist is perfectly happy with. Only the traversal check can refuse
     * it, so only this test goes red when that check is removed.
     */
    $zip = ZipBuilder::raw([
        ['name' => '../../../../products.csv', 'body' => "id,name\n1,x\n"],
    ]);

    $body = gmUpload($zip, 'catalogue.zip')->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('tries to write outside the import folder');
    expect(gmEverythingUnderImport())->toBe([]);
});

it('refuses a member with an absolute path', function () {
    $this->actingAs(gmAdmin(), 'admin');

    // A leading slash produces an empty first segment, which is the same guard
    // the `..` case trips and is asserted separately because an absolute path
    // needs no `..` anywhere in it.
    $zip = ZipBuilder::raw([
        ['name' => '/etc/cron.d/kbb', 'body' => "* * * * * root sh\n"],
    ]);

    $body = gmUpload($zip, 'catalogue.zip')->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('tries to write outside the import folder');
    expect(gmEverythingUnderImport())->toBe([]);
});

it('refuses a member with a windows drive letter', function () {
    $this->actingAs(gmAdmin(), 'admin');

    $zip = ZipBuilder::raw([
        ['name' => 'C:/windows/products.csv', 'body' => "id,name\n1,x\n"],
    ]);

    $body = gmUpload($zip, 'catalogue.zip')->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('absolute path');
    expect(gmEverythingUnderImport())->toBe([]);
});

it('refuses a member that traverses with backslashes', function () {
    $this->actingAs(gmAdmin(), 'admin');

    // A separator on the host that wrote the archive, and not one here — so a
    // check that split on '/' alone would wave `..\..\.env` straight through.
    $zip = ZipBuilder::raw([
        ['name' => '..\\..\\products.csv', 'body' => "id,name\n1,x\n"],
    ]);

    $body = gmUpload($zip, 'catalogue.zip')->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('backslash');
    expect(gmEverythingUnderImport())->toBe([]);
});

it('refuses a symbolic link entry', function () {
    $this->actingAs(gmAdmin(), 'admin');

    /*
     * A symlink escapes a directory without a `..` anywhere in its name, which
     * is exactly the shape the traversal check cannot see. The name here is
     * products.csv — allowed, unambiguous, entirely ordinary — and the only
     * thing wrong with the entry is the unix mode in its external attributes.
     */
    $zip = ZipBuilder::raw([
        ['name' => 'products.csv', 'body' => '/home/user/kbbstore/.env', 'mode' => 0120777],
    ]);

    $body = gmUpload($zip, 'catalogue.zip')->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('symbolic link');

    /*
     * AND NOTHING LANDED — asserted, because this guard turned out to be the
     * ONLY one that refuses this entry, which is not what it looked like.
     *
     * With the check removed the upload returns 200 and the file is written.
     * The link target `/home/user/kbbstore/.env` carries no NUL, no `PK`, no
     * `%PDF` and no `<`, so refuseNonText() passes it; CsvRowSource then reads
     * it as a header with no data rows, which accept() deliberately permits;
     * and the name says products, so it is filed as products.csv. Measured, not
     * reasoned about — the reasoned version of this comment said the opposite.
     */
    expect(gmEverythingUnderImport())->toBe([]);
});

it('refuses a member whose header lies about how big it unpacks to', function () {
    $this->actingAs(gmAdmin(), 'admin');

    /*
     * THE BOMB, and the reason the cap is counted from bytes ACTUALLY READ.
     *
     * The declared uncompressed size is 512 bytes. The member really holds
     * 80MB. A cap that trusted the header would let this through; a cap applied
     * after the member was written would be a report on the damage rather than
     * a cap. ImportArchive::stream() abandons it part way through.
     */
    $zip = ZipBuilder::bomb('products.csv', 80 * 1024 * 1024, 512);

    $body = gmUpload($zip, 'catalogue.zip')->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('unpacks to far more than it claims');
    expect(gmEverythingUnderImport())->toBe([]);
})->group('slow');

it('refuses a member that declares itself larger than the cap before writing a byte', function () {
    $this->actingAs(gmAdmin(), 'admin');

    // The honest bomb — a tool with no reason to lie. Refused on the claim, so
    // it costs nothing.
    $zip = ZipBuilder::raw([
        ['name' => 'products.csv', 'body' => "id,name\n1,x\n", 'declared' => 900 * 1024 * 1024],
    ]);

    $body = gmUpload($zip, 'catalogue.zip')->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('The limit is');
    expect(gmEverythingUnderImport())->toBe([]);
});

it('refuses members that fit one at a time but not together', function () {
    $this->actingAs(gmAdmin(), 'admin');

    /*
     * THE CAP THAT IS NOT A PER-FILE CAP.
     *
     * Found by mutation: deleting the RUNNING TOTAL over declared sizes left
     * the whole suite green, because every hostile zip above was one member
     * over the per-entry limit and the per-entry check caught it first. Six
     * members of 40MB each are individually fine and together are 240MB, and
     * before this test nothing in the suite said so.
     */
    $members = [];

    for ($i = 0; $i < 6; $i++) {
        $members[] = [
            'name' => 'file'.$i.'.csv',
            'body' => "id,name\n1,x\n",
            'declared' => 40 * 1024 * 1024,
        ];
    }

    $body = gmUpload(ZipBuilder::raw($members), 'catalogue.zip')->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('unpacks to more than');
    expect(gmEverythingUnderImport())->toBe([]);
});

it('refuses a zip with more members than an export has', function () {
    $this->actingAs(gmAdmin(), 'admin');

    $members = [];

    for ($i = 0; $i <= ImportArchive::MAX_ENTRIES; $i++) {
        $members[] = ['name' => 'file'.$i.'.csv', 'body' => "id\n1\n"];
    }

    $body = gmUpload(ZipBuilder::raw($members), 'catalogue.zip')->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('An export zip holds at most');
    expect(gmEverythingUnderImport())->toBe([]);
});

it('never writes a php file anywhere, not even to the scratch directory', function () {
    $this->actingAs(gmAdmin(), 'admin');

    $zip = ZipBuilder::ordinary([
        'categories.csv' => gmFixtureBody('categories'),
        'shell.php' => "<?php system(\$_GET['c']); ?>\n",
        '.htaccess' => "php_flag engine on\n",
        'index.html' => "<html></html>\n",
    ]);

    gmUpload($zip, 'catalogue.zip')->assertOk();

    /*
     * The workspace holds the one file that belongs there and nothing else.
     * `incoming` is checked too and not only the woo folder: the scratch
     * directory is where a .php WOULD land if the allowlist ran after
     * extraction instead of before it.
     */
    // The one export file, plus the row-count sidecar the workspace writes for
    // it — which lives in meta/ rather than beside the CSV for a reason
    // ImportWorkspace::metaDirectory() explains at length.
    expect(gmEverythingUnderImport())->toBe(['meta/categories.json', 'woo/categories.csv']);

    $stray = array_filter(
        gmEverythingUnderImport(),
        static fn (string $f): bool => str_contains($f, '.php') || str_contains($f, 'htaccess') || str_contains($f, '.html'),
    );

    expect($stray)->toBe([]);
});

it('accepts a zip made on a Mac, junk folders and all', function () {
    $this->actingAs(gmAdmin(), 'admin');

    /*
     * THE FIRST VERSION OF THIS REFUSED IT OUTRIGHT, and that is why this test
     * exists. `refuseUnsafeName()` required every segment to be a plain name and
     * threw otherwise, so a `.DS_Store` — a leading dot — took the whole archive
     * with it. Every zip the owner makes by right-clicking a folder on a Mac
     * carries one of those and a `__MACOSX/` beside it.
     *
     * Fatal is for a name that says WHERE. "This is not part of an export" is a
     * different question and its answer is to skip the member.
     */
    $zip = ZipBuilder::ordinary([
        'products.csv' => gmFixtureBody('products'),
        '__MACOSX/._products.csv' => "\x00\x05\x16\x07junk",
        '.DS_Store' => 'junk',
    ]);

    gmUpload($zip, 'catalogue.zip')->assertOk()->assertJsonPath('ok', true);

    expect(gmEverythingUnderImport())->toBe(['meta/products.json', 'woo/products.csv']);
});

it('refuses the addresses group file by file, exactly as a loose upload would', function () {
    $this->actingAs(gmAdmin(), 'admin');

    /*
     * A FINDING, NOT A FEATURE — and pinned so it cannot change by accident.
     *
     * Lane GK's `addresses` group is permalinks.csv and media.csv. Neither is in
     * ImportWorkspace::ENTITIES: docs/WP-EXPORT-CONTRACT.md lists both as GAP
     * files whose importers come later, and they are read by
     * `kbb:import-redirects` and MediaSideloader rather than by `kbb:import`.
     *
     * So that group's zip unpacks and both files are then refused one at a time
     * — which is EXACTLY what a loose upload of the same two files does today,
     * so the invariant holds and nothing regressed. The owner will still
     * download that group and get two refusals and no import, which is
     * docs/GM-IMPORT-ACCEPTS-ZIP.md §10.2 and somebody's decision to make.
     */
    $zip = ZipBuilder::ordinary([
        'permalinks.csv' => "old_url,new_url\n/a/,/b/\n",
        'media.csv' => "src,alt\nhttps://example.test/a.jpg,A\n",
    ]);

    $body = gmUpload($zip, 'addresses.zip')->assertStatus(422)->json();

    expect($body['refused'])->toHaveCount(2);

    foreach ($body['refused'] as $refusal) {
        expect($refusal['message'])->toContain('Which export is this?');
    }

    expect(gmEverythingUnderImport())->toBe([]);
});

it('refuses a nested archive rather than unpacking it', function () {
    $this->actingAs(gmAdmin(), 'admin');

    $inner = ZipBuilder::ordinary(['shell.php' => "<?php ?>\n"]);

    $zip = ZipBuilder::ordinary([
        'inner.zip' => (string) file_get_contents($inner),
    ]);

    $body = gmUpload($zip, 'catalogue.zip')->assertStatus(422)->json();

    // Not on the allowlist, so it is never written and never opened. The zip
    // held nothing else, so the whole upload is refused.
    expect($body['refused'][0]['message'])->toContain('no export files in that zip');
    expect(gmEverythingUnderImport())->toBe([]);
});

it('refuses a csv inside the zip whose contents are themselves an archive', function () {
    $this->actingAs(gmAdmin(), 'admin');

    // Named products.csv, so the allowlist lets it through — and then
    // ImportWorkspace::refuseNonText() gets it, exactly as it would a loose
    // upload of the same bytes. One more turn of the recursion is not opened.
    $inner = ZipBuilder::ordinary(['x.csv' => "id\n1\n"]);

    $zip = ZipBuilder::ordinary([
        'products.csv' => (string) file_get_contents($inner),
    ]);

    $body = gmUpload($zip, 'catalogue.zip')->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('products.csv (inside the zip)');
    expect($body['refused'][0]['message'])->toContain('spreadsheet');
    expect(gmEverythingUnderImport())->toBe([]);
});

it('refuses a zip where two members land on the same file', function () {
    $this->actingAs(gmAdmin(), 'admin');

    /*
     * TWO DIFFERENT ENTRY NAMES, ONE DESTINATION.
     *
     * The obvious fixture — two members both called `products.csv` — never
     * reaches this guard: libzip's own consistency check under CHECKCONS
     * refuses the archive at open, with the sentence about a zip that could not
     * be opened. That is a fine outcome and the test below pins it, but writing
     * the duplicate test that way would have left THIS guard dead text, which
     * is the shape Api\ProductController's status filter already cost this
     * repository once.
     *
     * The collision that is actually reachable is the one across the wrapping
     * directory: `products.csv` and `export/products.csv` are two names libzip
     * is perfectly happy with, and the destination is flat, so they are one
     * file. Which of the two is the export is not something this shop can
     * guess, and letting the second win would mean the file that gets imported
     * is not the file that was checked.
     */
    $zip = ZipBuilder::ordinary([
        'products.csv' => gmFixtureBody('products'),
        'export/products.csv' => "id,name\n999,injected\n",
    ]);

    $body = gmUpload($zip, 'catalogue.zip')->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('twice');
    expect(gmEverythingUnderImport())->toBe([]);
});

it('refuses an archive holding two members with the identical name', function () {
    $this->actingAs(gmAdmin(), 'admin');

    // Refused by libzip under CHECKCONS, before any of this lane's code reads a
    // name. Asserted so that the reason is written down rather than assumed,
    // and so that turning CHECKCONS off would fail here instead of silently
    // handing a doctored archive to the loop.
    $zip = ZipBuilder::raw([
        ['name' => 'products.csv', 'body' => gmFixtureBody('products')],
        ['name' => 'products.csv', 'body' => "id,name\n999,injected\n"],
    ]);

    $body = gmUpload($zip, 'catalogue.zip')->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('could not be opened');
    expect(gmEverythingUnderImport())->toBe([]);
});

it('refuses a zip nested more than one folder deep', function () {
    $this->actingAs(gmAdmin(), 'admin');

    $zip = ZipBuilder::raw([
        ['name' => 'export/catalogue/products.csv', 'body' => gmFixtureBody('products')],
    ]);

    $body = gmUpload($zip, 'catalogue.zip')->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('folders deep');
    expect(gmEverythingUnderImport())->toBe([]);
});

it('refuses a zip holding export files in two different folders', function () {
    $this->actingAs(gmAdmin(), 'admin');

    // Two exports in one zip. Choosing which one to import is not a decision
    // this code may make silently.
    $zip = ZipBuilder::ordinary([
        'catalogue/products.csv' => gmFixtureBody('products'),
        'sales/orders.csv' => gmFixtureBody('orders'),
    ]);

    $body = gmUpload($zip, 'everything.zip')->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('more than one place');
    expect(gmEverythingUnderImport())->toBe([]);
});

it('names a spreadsheet as a spreadsheet rather than as an empty export', function () {
    $this->actingAs(gmAdmin(), 'admin');

    /*
     * ImportWorkspace's rule 4 — "the obvious wrong files are NAMED, not just
     * refused" — used to be free here, because an .xlsx is a zip and every zip
     * was refused with the spreadsheet sentence. Now that zips are accepted it
     * has to be earned back, or the owner who uploads a spreadsheet gets
     * "there are no export files in that zip" and no idea why.
     */
    $xlsx = ZipBuilder::ordinary([
        '[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
        'xl/workbook.xml' => '<?xml version="1.0"?><workbook/>',
        '_rels/.rels' => '<?xml version="1.0"?><Relationships/>',
    ]);

    $body = gmUpload($xlsx, 'products.xlsx')->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('spreadsheet');
    expect($body['refused'][0]['message'])->toContain('pick CSV');
});

it('refuses an empty zip', function () {
    $this->actingAs(gmAdmin(), 'admin');

    $body = gmUpload(ZipBuilder::empty(), 'catalogue.zip')->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('empty');
});

it('refuses a zip whose download stopped part way through', function () {
    $this->actingAs(gmAdmin(), 'admin');

    // The owner's real failure mode on a shared host: half a file. The central
    // directory is at the END of a zip, so a truncated download has no index at
    // all and there is nothing to be tolerant about.
    $zip = ZipBuilder::truncated([
        'products.csv' => gmFixtureBody('products'),
        'manifest.json' => '{"format":"kbb-export/1"}',
    ]);

    $body = gmUpload($zip, 'catalogue.zip')->assertStatus(422)->json();

    expect($body['refused'][0]['message'])->toContain('could not be opened');
    expect(gmEverythingUnderImport())->toBe([]);
});

it('leaves no scratch directory behind, whatever happened', function () {
    $this->actingAs(gmAdmin(), 'admin');

    gmUpload(gmGroupZip('catalogue', ['categories'], ['sales']), 'ok.zip')->assertOk();
    gmUpload(ZipBuilder::raw([['name' => '../../x.csv', 'body' => 'id\n1\n']]), 'bad.zip')->assertStatus(422);
    gmUpload(ZipBuilder::ordinary(['shell.php' => '<?php ?>']), 'junk.zip')->assertStatus(422);

    $incoming = storage_path('app/import/incoming');

    // The directory may exist; nothing may be in it.
    $left = is_dir($incoming)
        ? array_values(array_diff(scandir($incoming) ?: [], ['.', '..']))
        : [];

    expect($left)->toBe([]);
});

/* ================================= 3. several group zips add up to one import
 *
 * The normal use of this feature: Catalogue on Monday, Orders on Tuesday.
 */

it('merges the manifests of two group zips rather than letting the second replace the first', function () {
    $this->actingAs(gmAdmin(), 'admin');

    gmUpload(
        gmGroupZip('catalogue', ['categories', 'brands', 'products'], ['sales', 'customers', 'reviews']),
        'catalogue.zip'
    )->assertOk()->assertJsonPath('ok', true);

    gmUpload(
        gmGroupZip('sales', ['orders', 'order-items'], ['catalogue', 'customers', 'reviews']),
        'sales.zip'
    )->assertOk()->assertJsonPath('ok', true);

    $manifest = (new ImportWorkspace)->manifest();

    /*
     * THE PROPERTY THE CONTRACT MAKES LOAD-BEARING. A replace would leave
     * products.csv sitting on the disk and ABSENT from the manifest beside it,
     * and docs/WP-EXPORT-CONTRACT.md defines absent-from-`files` to mean "this
     * export does not carry it" — a different and false statement.
     */
    $listed = array_keys($manifest->files());
    sort($listed);

    expect($listed)->toBe(['brands.csv', 'categories.csv', 'order_items.csv', 'orders.csv', 'products.csv']);
});

it('keeps every file its own row count across the merge', function () {
    $this->actingAs(gmAdmin(), 'admin');

    gmUpload(gmGroupZip('catalogue', ['categories', 'products'], ['sales']), 'catalogue.zip')->assertOk();
    gmUpload(gmGroupZip('sales', ['orders'], ['catalogue']), 'sales.zip')->assertOk();

    $status = gmStatus();

    /*
     * THE ANSWER TO "MUST THE DENOMINATOR SURVIVE, OR MUST IT DRAW NO BAR": it
     * ADDS UP, and it adds up because each file keeps the count the manifest
     * that described it gave it. `denominator_source` is the assertion, not the
     * number: `counted` would still produce a bar and would still be right
     * about the file on this disk — what it cannot do is notice that the file
     * on this disk is half the file that was sent, which is the whole reason
     * the manifest's count exists (ImportManifest's own class comment).
     */
    foreach (['categories', 'products', 'orders'] as $entity) {
        expect(gmEntity($status, $entity)['denominator_source'])
            ->toBe('manifest', $entity.' lost its manifest denominator across the merge');
    }
});

it('draws a whole-export bar whose total is both groups added together', function () {
    $this->actingAs(gmAdmin(), 'admin');

    gmUpload(gmGroupZip('catalogue', ['categories', 'products'], ['sales']), 'catalogue.zip')->assertOk();
    gmUpload(gmGroupZip('sales', ['orders'], ['catalogue']), 'sales.zip')->assertOk();

    $status = gmStatus();

    $expected = gmCountRows(gmFixtureBody('categories'))
        + gmCountRows(gmFixtureBody('products'))
        + gmCountRows(gmFixtureBody('orders'));

    expect($status['overall']['total'])->toBe($expected);
    expect($status['overall']['why_no_bar'])->toBeNull();
    expect($status['overall']['files'])->toBe(3);
});

it('still refuses a bar when one group zip carried a truncated file', function () {
    $this->actingAs(gmAdmin(), 'admin');

    gmUpload(gmGroupZip('catalogue', ['categories'], ['sales']), 'catalogue.zip')->assertOk();

    // A sales zip whose manifest says orders.csv holds far more than it does —
    // the truncated upload the manifest count exists to catch. It has to still
    // be caught AFTER a merge, or merging would have quietly disarmed it.
    $body = gmFixtureBody('orders');
    $manifest = gmGroupManifest('sales', ['orders'], ['catalogue']);
    $manifest['files']['orders.csv']['rows'] = gmCountRows($body) + 500;

    $zip = ZipBuilder::ordinary([
        'orders.csv' => $body,
        'manifest.json' => (string) json_encode($manifest),
    ]);

    gmUpload($zip, 'sales.zip')->assertOk();

    $status = gmStatus();

    expect(gmEntity($status, 'orders')['denominator_source'])->toBe('disputed');
    expect(gmEntity($status, 'orders')['percent'])->toBeNull();
    // And the whole-export bar goes with it: one file short is a total wrong in
    // the flattering direction, not a smaller total.
    expect($status['overall']['total'])->toBeNull();
    expect(gmEntity($status, 'categories')['denominator_source'])->toBe('manifest');
});

it('unions the groups each zip says it carries and intersects the ones they skipped', function () {
    $this->actingAs(gmAdmin(), 'admin');

    gmUpload(
        gmGroupZip('catalogue', ['categories'], ['sales', 'reviews', 'coupons']),
        'catalogue.zip'
    )->assertOk();

    gmUpload(
        gmGroupZip('sales', ['orders'], ['catalogue', 'reviews', 'coupons']),
        'sales.zip'
    )->assertOk();

    $groups = gmStatus()['manifest']['groups'];

    sort($groups['selected']);
    sort($groups['skipped']);

    expect($groups['selected'])->toBe(['catalogue', 'sales']);

    /*
     * Each zip lists the other's group as skipped, and neither of them is
     * skipped in the export as a whole. Subtracting `selected` is what makes
     * that come out right — see the test above for the case that decides
     * whether the lists are unioned or intersected before the subtraction; this
     * one passes under either rule and is here for the ordinary shape.
     */
    expect($groups['skipped'])->toBe(['coupons', 'reviews']);
});

it('lets a corrected re-export of one group update that file row count', function () {
    $this->actingAs(gmAdmin(), 'admin');

    /*
     * THE RULE `files` IS MERGED UNDER, and the case that decides it.
     *
     * Everything else in a merge is first-wins. A `files` entry is not, because
     * it describes ONE FILE'S BYTES and the bytes on the disk are the ones that
     * arrived with the manifest describing them.
     *
     * Lane GF's `it draws NO bar when the manifest and the file disagree`
     * caught this within a minute of the merge being written, and what it
     * caught is the owner's most important import: he finds something wrong in
     * WordPress, fixes it, and re-exports that group under the same export_id.
     * Under first-wins the shop keeps the old row count, compares it to the
     * corrected file, and reports the correction as a truncated upload —
     * telling him to upload again the one file that is finally right.
     */
    $products = gmFixtureBody('products');

    $stale = gmGroupManifest('catalogue', ['products'], ['sales']);
    $stale['files']['products.csv']['rows'] = gmCountRows($products) + 40;
    $stale['counts']['products'] = gmCountRows($products) + 40;

    gmUpload(ZipBuilder::ordinary([
        'products.csv' => $products,
        'manifest.json' => (string) json_encode($stale),
    ]), 'catalogue.zip')->assertOk();

    expect(gmEntity(gmStatus(), 'products')['denominator_source'])->toBe('disputed');

    // The same group again, same export, corrected manifest.
    gmUpload(gmGroupZip('catalogue', ['products'], ['sales']), 'catalogue-again.zip')->assertOk();

    $row = gmEntity(gmStatus(), 'products');

    expect($row['denominator_source'])->toBe('manifest');
    expect($row['rows_mismatch'])->toBeNull();
    expect($row['denominator'])->toBe(gmCountRows($products));

    /*
     * `counts` follows `files` for the same reason and is asserted through the
     * file rather than the status payload, because nothing on the screen reads
     * it — it is the contract's own field and the owner may open the manifest.
     * A merged file carrying a count the export no longer has would be this
     * shop's own bookkeeping disagreeing with the row counts beside it.
     */
    $raw = json_decode((string) file_get_contents((new ImportWorkspace)->manifestPath()), true);

    expect($raw['counts']['products'])->toBe(gmCountRows($products));
});

it('stops describing a file the owner has removed', function () {
    $this->actingAs(gmAdmin(), 'admin');

    /*
     * THE ONE CASE WHERE AN OMISSION HAS TO BE A STATEMENT.
     *
     * A merge treats a manifest that does not mention a file as SILENT rather
     * than as a deletion, which is the whole reason it exists — the Catalogue
     * zip's manifest omits orders.csv and must not delete the Sales zip's entry
     * for it. Pressing Remove is the one action that means the opposite, and
     * before this lane it said so for free: a re-uploaded manifest REPLACED the
     * old one and the entry went with it.
     *
     * Leaving a stale entry is not cosmetic. An absent file with a manifest row
     * count gets a denominator — there is no counted number for it to disagree
     * with — and MigrationProgress then sums a total over work that is not
     * there, which is the bar running ahead of the import that Lane GD removed
     * once already. Lane GF's `it gives the catalogue stage no total when only
     * some of its entities have one` is what found it here.
     */
    gmUpload(gmGroupZip('catalogue', ['categories', 'products'], ['sales']), 'catalogue.zip')->assertOk();

    expect(array_keys((new ImportWorkspace)->manifest()->files()))
        ->toBe(['categories.csv', 'products.csv']);

    test()->postJson('/admin-api/import/forget', ['entity' => 'products'])->assertOk();

    $manifest = (new ImportWorkspace)->manifest();

    expect(array_keys($manifest->files()))->toBe(['categories.csv']);
    expect($manifest->lists('products.csv'))->toBeFalse();

    // The rest of the manifest is intact — the export id and the other file's
    // counts are still true, and the duplicate guard is built out of them.
    expect((new ImportWorkspace)->hasManifest())->toBeTrue('Remove deleted the whole manifest, not one entry');
    expect($manifest->exportId())->toBe('8f14e45f-ceea-467a-9c31-1a2b3c4d5e6f');
    expect($manifest->rowsFor('categories.csv'))->toBe(gmCountRows(gmFixtureBody('categories')));
    expect(gmEntity(gmStatus(), 'categories')['denominator_source'])->toBe('manifest');
});

it('keeps a group skipped when only one zip said so', function () {
    $this->actingAs(gmAdmin(), 'admin');

    /*
     * FOUND BY MUTATION, and it changed the rule rather than adding a test.
     *
     * `groups.skipped` was merged as an INTERSECTION. Turning it into a union
     * left every test green, because Lane GK's exporter writes `skipped` as
     * every group that was not selected — so subtracting `selected` already
     * removes exactly what the two lists disagree about and the intersection
     * was doing nothing at all.
     *
     * It was worse than redundant. This fixture is the case where the two
     * differ: a plugin that names only the skips relevant to the group it is
     * exporting. Catalogue says it left out Orders; Orders says it left out
     * Reviews. Nothing carries reviews, so reviews IS skipped — and the
     * intersection of the two lists is empty, so the intersection would have
     * had the shop claim an export with no reviews in it had not skipped them.
     */
    gmUpload(gmGroupZip('catalogue', ['categories'], ['sales']), 'catalogue.zip')->assertOk();
    gmUpload(gmGroupZip('sales', ['orders'], ['reviews']), 'sales.zip')->assertOk();

    $groups = gmStatus()['manifest']['groups'];
    sort($groups['skipped']);

    expect($groups['skipped'])->toBe(['reviews']);
});

it('does not merge two manifests that both refuse to say which export they are', function () {
    $this->actingAs(gmAdmin(), 'admin');

    /*
     * FOUND BY MUTATION. sameExport() was tested only with one id present and
     * one absent, so relaxing it to a bare `===` — which makes two nulls equal
     * — stayed green. Two anonymous manifests are two UNKNOWNS, and treating
     * two unknowns as one export would join whatever happened to arrive next to
     * whatever was already here.
     */
    $first = gmGroupManifest('catalogue', ['categories'], ['sales']);
    unset($first['export_id']);

    $second = gmGroupManifest('sales', ['orders'], ['catalogue']);
    unset($second['export_id']);

    gmUpload(ZipBuilder::ordinary([
        'categories.csv' => gmFixtureBody('categories'),
        'manifest.json' => (string) json_encode($first),
    ]), 'catalogue.zip')->assertOk();

    gmUpload(ZipBuilder::ordinary([
        'orders.csv' => gmFixtureBody('orders'),
        'manifest.json' => (string) json_encode($second),
    ]), 'sales.zip')->assertOk();

    expect(array_keys((new ImportWorkspace)->manifest()->files()))->toBe(['orders.csv']);
});

it('replaces rather than merges a manifest from a different export', function () {
    $this->actingAs(gmAdmin(), 'admin');

    gmUpload(gmGroupZip('catalogue', ['categories'], ['sales']), 'september.zip')->assertOk();

    $manifest = gmGroupManifest('sales', ['orders'], ['catalogue'], 'ffffffff-0000-0000-0000-000000000000');

    gmUpload(ZipBuilder::ordinary([
        'orders.csv' => gmFixtureBody('orders'),
        'manifest.json' => (string) json_encode($manifest),
    ]), 'january.zip')->assertOk();

    $landed = (new ImportWorkspace)->manifest();

    // A different export is a different export. Merging two of them would join
    // a January download to a September one and call the result one export.
    expect($landed->exportId())->toBe('ffffffff-0000-0000-0000-000000000000');
    expect(array_keys($landed->files()))->toBe(['orders.csv']);
});

it('replaces rather than merges when a manifest carries no export id', function () {
    $this->actingAs(gmAdmin(), 'admin');

    gmUpload(gmGroupZip('catalogue', ['categories'], ['sales']), 'catalogue.zip')->assertOk();

    $anonymous = gmGroupManifest('sales', ['orders'], ['catalogue']);
    unset($anonymous['export_id']);

    gmUpload(ZipBuilder::ordinary([
        'orders.csv' => gmFixtureBody('orders'),
        'manifest.json' => (string) json_encode($anonymous),
    ]), 'sales.zip')->assertOk();

    // Two unknowns are not one export. Treating them as one would merge
    // whatever happened to arrive next.
    expect(array_keys((new ImportWorkspace)->manifest()->files()))->toBe(['orders.csv']);
});

it('records what a merged manifest was made of', function () {
    $this->actingAs(gmAdmin(), 'admin');

    gmUpload(gmGroupZip('catalogue', ['categories'], ['sales']), 'catalogue.zip')->assertOk();
    gmUpload(gmGroupZip('sales', ['orders'], ['catalogue']), 'sales.zip')->assertOk();

    // A merged file that looked like an original would be a file the owner
    // could not reconcile against what he downloaded.
    expect(gmStatus()['manifest']['merged_from'])->toHaveCount(2);
});

it('does not let its own bookkeeping grow the manifest without limit', function () {
    $this->actingAs(gmAdmin(), 'admin');

    /*
     * A MERGE REWRITES THE MANIFEST, so `merged_from` grows by one on every
     * upload of a group belonging to this export — and ImportManifest::read()
     * refuses a manifest over MAX_BYTES with "this is not a manifest, remove
     * it". Unbounded, an owner who re-uploaded one group often enough would
     * brick his own manifest with this shop's own record-keeping, which is the
     * worst shape a record-keeping field can have.
     */
    $zip = gmGroupZip('catalogue', ['categories'], ['sales']);

    for ($i = 0; $i < ImportManifest::MAX_MERGED_FROM + 5; $i++) {
        gmUpload($zip, 'catalogue.zip')->assertOk();
    }

    $workspace = new ImportWorkspace;
    $raw = json_decode((string) file_get_contents($workspace->manifestPath()), true);

    expect($raw['merged_from'])->toHaveCount(ImportManifest::MAX_MERGED_FROM);

    // Still a manifest this shop can read, which is the property the bound is
    // protecting — not the length of an array.
    expect($workspace->manifest()->usable())->toBeTrue();
    expect($workspace->manifest()->exportId())->toBe('8f14e45f-ceea-467a-9c31-1a2b3c4d5e6f');
    expect(gmEntity(gmStatus(), 'categories')['denominator_source'])->toBe('manifest');
});

it('does not mistake the second group zip for a duplicate of the first', function () {
    $this->actingAs(gmAdmin(), 'admin');

    gmUpload(gmGroupZip('catalogue', ['categories', 'brands'], ['sales']), 'catalogue.zip')->assertOk();

    test()->postJson('/admin-api/import/start', ['mode' => 'live', 'adopt_by_slug' => true])->assertOk();

    for ($i = 0; $i < 50; $i++) {
        $result = test()->postJson('/admin-api/import/step', ['rows' => 500])->assertOk()->json();

        if (($result['status']['run']['status'] ?? '') !== 'running') {
            break;
        }
    }

    gmUpload(gmGroupZip('sales', ['orders'], ['catalogue']), 'sales.zip')->assertOk();

    $verdict = gmStatus()['duplicate'];

    /*
     * GF's guard deliberately never blocks a PARTIAL: an import on shared
     * hosting is sixty browser requests, a delta export carries three files of
     * nine, and the second of two group zips is now the most ordinary case
     * there is. A guard that refused this would refuse the feature.
     */
    expect($verdict['status'])->toBe('partial');
    expect($verdict['blocking'])->toBeFalse();
    expect($verdict['counts']['imported'])->toBe(2);
    expect($verdict['counts']['new'])->toBe(1);
});

it('still recognises the SAME group zip uploaded twice', function () {
    $this->actingAs(gmAdmin(), 'admin');

    $zip = gmGroupZip('catalogue', ['categories', 'brands'], ['sales']);

    gmUpload($zip, 'catalogue.zip')->assertOk();

    test()->postJson('/admin-api/import/start', ['mode' => 'live', 'adopt_by_slug' => true])->assertOk();

    for ($i = 0; $i < 50; $i++) {
        $result = test()->postJson('/admin-api/import/step', ['rows' => 500])->assertOk()->json();

        if (($result['status']['run']['status'] ?? '') !== 'running') {
            break;
        }
    }

    gmUpload($zip, 'catalogue.zip')->assertOk();

    $verdict = gmStatus()['duplicate'];

    expect($verdict['status'])->toBe('already');
    expect($verdict['blocking'])->toBeTrue();
});

/* ============================ 4. the operator's unverified claim, carried over
 *
 * docs/GK-EXPORT-GROUPS.md §5: `assumed_already_imported` is the only thing in
 * an export that is not a fact about the export.
 */

it('carries the operator unverified claim through to the import screen', function () {
    $this->actingAs(gmAdmin(), 'admin');

    $manifest = gmGroupManifest('sales', ['orders'], ['catalogue', 'customers']);
    $manifest['groups']['assumed_already_imported'] = [[
        'group' => 'sales',
        'needs' => 'customers',
        'severity' => 'loses',
        'claim' => 'Customers was already imported into the new shop when this export was taken. The '
            .'operator stated this; the plugin cannot see the other shop and did not check it.',
    ]];

    gmUpload(ZipBuilder::ordinary([
        'orders.csv' => gmFixtureBody('orders'),
        'manifest.json' => (string) json_encode($manifest),
    ]), 'sales.zip')->assertOk();

    $status = gmStatus();
    $claims = $status['manifest']['groups']['assumed_already_imported'];

    expect($claims)->toHaveCount(1);
    expect($claims[0]['needs'])->toBe('customers');
    expect($claims[0]['severity'])->toBe('loses');
    expect($claims[0]['claim'])->toContain('did not check it');

    /*
     * A NOTICE AND NOT A REFUSAL. GK refuses to let the EXPORT start with a
     * warning unanswered, which is the right place for a refusal; refusing
     * again here would refuse the partial import this whole console exists to
     * make possible, and would be this shop overruling a decision it has
     * strictly less information about than the person who made it.
     */
    expect(gmStatus()['duplicate']['blocking'])->toBeFalse();

    test()->postJson('/admin-api/import/start', ['mode' => 'preview', 'adopt_by_slug' => true])->assertOk();
});

it('keeps the claim when a second group zip is added', function () {
    $this->actingAs(gmAdmin(), 'admin');

    $sales = gmGroupManifest('sales', ['orders'], ['catalogue', 'customers']);
    $sales['groups']['assumed_already_imported'] = [[
        'group' => 'sales',
        'needs' => 'customers',
        'severity' => 'loses',
        'claim' => 'The operator stated this.',
    ]];

    gmUpload(ZipBuilder::ordinary([
        'orders.csv' => gmFixtureBody('orders'),
        'manifest.json' => (string) json_encode($sales),
    ]), 'sales.zip')->assertOk();

    gmUpload(gmGroupZip('catalogue', ['categories'], ['customers']), 'catalogue.zip')->assertOk();

    // It is a record of what he clicked, and that does not stop having been
    // true because another group arrived afterwards.
    $claims = gmStatus()['manifest']['groups']['assumed_already_imported'];

    expect($claims)->toHaveCount(1);
    expect($claims[0]['needs'])->toBe('customers');
});

it('shows an unrecognised severity as the quiet one', function () {
    $this->actingAs(gmAdmin(), 'admin');

    $manifest = gmGroupManifest('sales', ['orders'], ['catalogue']);
    $manifest['groups']['assumed_already_imported'] = [
        ['group' => 'sales', 'needs' => 'customers', 'severity' => 'catastrophic', 'claim' => 'x'],
        // Not a claim at all — no `needs`. Dropped rather than half-drawn.
        ['group' => 'sales'],
    ];

    gmUpload(ZipBuilder::ordinary([
        'orders.csv' => gmFixtureBody('orders'),
        'manifest.json' => (string) json_encode($manifest),
    ]), 'sales.zip')->assertOk();

    $claims = gmStatus()['manifest']['groups']['assumed_already_imported'];

    // GK draws exactly ONE edge red and the point of it is that red means
    // something. A newer plugin's unknown severity must not be guessed upward.
    expect($claims)->toHaveCount(1);
    expect($claims[0]['severity'])->toBe('reported');
});

/* ========================================================= 5. end to end
 *
 * The zip is not finished when it unpacks. It is finished when an import runs
 * out of it.
 */

it('imports a catalogue group straight out of its zip', function () {
    $this->actingAs(gmAdmin(), 'admin');

    gmUpload(
        gmGroupZip('catalogue', ['categories', 'brands', 'products'], ['sales', 'customers']),
        'kbb-export-catalogue.zip'
    )->assertOk()->assertJsonPath('ok', true);

    test()->postJson('/admin-api/import/start', ['mode' => 'live', 'adopt_by_slug' => true])
        ->assertOk()
        ->assertJsonPath('ok', true);

    for ($i = 0; $i < 60; $i++) {
        $result = test()->postJson('/admin-api/import/step', ['rows' => 500])->assertOk()->json();

        if (($result['status']['run']['status'] ?? '') !== 'running') {
            break;
        }
    }

    $status = gmStatus();

    expect($status['run']['status'])->toBe('complete');
    expect(\App\Models\Product::count())->toBeGreaterThan(0);
    expect(gmEntity($status, 'products')['percent'])->toBe(100);
    expect(gmEntity($status, 'products')['denominator_source'])->toBe('manifest');
});

it('imports two group zips uploaded one after another as one export', function () {
    $this->actingAs(gmAdmin(), 'admin');

    gmUpload(gmGroupZip('catalogue', ['categories', 'brands', 'products'], ['sales']), 'catalogue.zip')->assertOk();
    gmUpload(gmGroupZip('customers', ['customers'], ['catalogue']), 'customers.zip')->assertOk();
    gmUpload(gmGroupZip('sales', ['orders', 'order-items'], ['catalogue']), 'sales.zip')->assertOk();

    test()->postJson('/admin-api/import/start', ['mode' => 'live', 'adopt_by_slug' => true])->assertOk();

    for ($i = 0; $i < 80; $i++) {
        $result = test()->postJson('/admin-api/import/step', ['rows' => 500])->assertOk()->json();

        if (($result['status']['run']['status'] ?? '') !== 'running') {
            break;
        }
    }

    $status = gmStatus();

    expect($status['run']['status'])->toBe('complete');
    expect(\App\Models\Product::count())->toBeGreaterThan(0);
    expect(\App\Models\Order::count())->toBeGreaterThan(0);
    expect(\App\Models\Customer::count())->toBeGreaterThan(0);

    // Orders linked to real customers, which is what "one import" has to mean:
    // three zips, one shop, the foreign keys resolved across them.
    expect(\App\Models\Order::query()->whereNotNull('customer_id')->count())->toBeGreaterThan(0);
    expect(\App\Models\OrderItem::query()->whereNotNull('product_id')->count())->toBeGreaterThan(0);
});

/* ================================================== 6. the route, and the rule
 *
 * CLAUDE.md: "A new admin route needs a rule in AdminCapabilities::RULES unless
 * an existing wildcard already covers it ... Check rather than assume."
 */

it('adds no new route, so it needs no capability rule and no cache migration', function () {
    $paths = array_map(
        static fn ($r): string => implode('|', $r->methods()).' '.$r->uri(),
        ImportAdminRoutes::registered(),
    );

    sort($paths);

    /*
     * A zip arrives at the endpoint that already existed, decided from the
     * bytes. This is the assertion that the design choice was actually made:
     * a new endpoint would have needed a rule in AdminCapabilities::RULES, a
     * line in routes/import-admin.php and a clear_caches_* migration to be
     * reachable on a host with a compiled route cache, and it would have needed
     * the owner to know which box a zip goes in.
     */
    expect($paths)->toBe([
        'GET|HEAD admin-api/import/rejects',
        'GET|HEAD admin-api/import/status',
        'POST admin-api/import/forget',
        'POST admin-api/import/reset',
        'POST admin-api/import/start',
        'POST admin-api/import/step',
        'POST admin-api/import/stop',
        'POST admin-api/import/upload',
    ]);
});

it('covers the upload endpoint with the capability rule that was already there', function () {
    // `admin-api/import/**` => data.import. Checked rather than assumed, and
    // checked through the resolver rather than by reading the table, so a rule
    // shadowed by an earlier wildcard would show up as the capability the
    // earlier one grants.
    expect(AdminCapabilities::forPath('POST', 'admin-api/import/upload'))->toBe('data.import');
});

it('refuses a zip upload to a caller with no admin session', function () {
    $zip = gmGroupZip('catalogue', ['categories'], ['sales']);
    $temp = sys_get_temp_dir().'/kbb-gm-'.bin2hex(random_bytes(6)).'.zip';
    copy($zip, $temp);

    $this->postJson('/admin-api/import/upload', [
        'file' => new UploadedFile($temp, 'catalogue.zip', null, null, true),
    ])->assertStatus(401);

    expect(gmEverythingUnderImport())->toBe([]);
});
