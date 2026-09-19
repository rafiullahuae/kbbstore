<?php

declare(strict_types=1);

/**
 * Store → Import / Export — the screen that makes `kbb:import` reachable.
 *
 * The importer was finished, tested and correct, and unusable by the only
 * person who needs it: the owner of this store has no shell access. So what is
 * under test here is not the mapping — WooImportTest owns that, and this lane
 * changed none of it — but the four properties that decide whether a browser
 * can drive an import on shared hosting without making a mess:
 *
 *   1. NOTHING HERE IS REACHABLE WITHOUT AN ADMIN SESSION. One of these
 *      endpoints writes a caller-supplied file to disk, one rewrites the whole
 *      catalogue from it, and one hands back refused rows that quote customer
 *      addresses.
 *
 *   2. AN UPLOAD IS A CSV OR IT IS REFUSED, by parsing, and it lands at a path
 *      this code chose rather than one the filename suggested.
 *
 *   3. THE RUN SURVIVES BEING CUT IN HALF. Stepping it in tiny slices must
 *      reach byte-for-byte the same database as one big run, and a second pass
 *      must change nothing at all. That is the property the whole design exists
 *      for, and the only one whose absence would be invisible until the data
 *      was already in.
 *
 *   4. A PREVIEW WRITES NOTHING. Ever. It is what the owner reads before
 *      committing, so a preview that leaked a single row would make every later
 *      count a lie.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\ImportConsole\ImportDriver;
use App\Services\ImportConsole\ImportWorkspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\ImportAdminRoutes;
use Tests\Support\SqlShape;

/* ------------------------------------------------------------------ set-up */

beforeEach(function () {
    ImportAdminRoutes::wire($this->app);

    impPurge(storage_path('app/import'));
});

afterEach(function () {
    impPurge(storage_path('app/import'));
});

function impPurge(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir.'/'.$entry;

        is_dir($path) ? impPurge($path) : @unlink($path);
    }

    @rmdir($dir);
}

function impAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Import Owner',
        'email' => 'import-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/**
 * A COPY of the checked-in fixture.
 *
 * accept() MOVES the uploaded file, which is correct — an upload is a temp file
 * and moving it is how it stops being one — and would delete the fixture out of
 * the repository if it were handed the real path.
 */
function impFixture(string $entity): UploadedFile
{
    $name = ImportWorkspace::meta($entity)['file'];
    $source = base_path('tests/Fixtures/woo/'.$name);
    $temp = sys_get_temp_dir().'/kbb-imp-'.bin2hex(random_bytes(6)).'-'.$name;

    copy($source, $temp);

    return new UploadedFile($temp, $name, 'text/csv', null, true);
}

/** A file with content this test wrote, uploaded under whatever name it likes. */
function impFile(string $name, string $body, string $mime = 'text/csv'): UploadedFile
{
    $temp = sys_get_temp_dir().'/kbb-imp-'.bin2hex(random_bytes(6));
    file_put_contents($temp, $body);

    return new UploadedFile($temp, $name, $mime, null, true);
}

/** Put every fixture export on the server, the way the screen does. */
function impUploadAll(): void
{
    foreach (ImportWorkspace::entities() as $entity) {
        test()->postJson('/admin-api/import/upload', ['file' => impFixture($entity)])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }
}

function impStatus(): array
{
    return test()->getJson('/admin-api/import/status')->assertOk()->json();
}

/**
 * Step until the run says it is complete, in slices of $rows.
 *
 * The guard is not decoration: a driver that never advanced would otherwise
 * hang the suite rather than fail it, and "the step made no progress" is
 * exactly the bug a resume design can have.
 *
 * @return list<array<string, mixed>>
 */
function impRunToEnd(int $rows = 500, int $maxSteps = 200): array
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

/** A stable picture of everything the import writes, for comparing two runs. */
function impSnapshot(): array
{
    return [
        'products' => Product::query()->orderBy('wc_id')->get(['wc_id', 'slug', 'name', 'price', 'sale_price', 'status'])->toArray(),
        'orders' => Order::query()->orderBy('wc_order_id')->get(['wc_order_id', 'order_number', 'status', 'total', 'email'])->toArray(),
        'items' => OrderItem::query()->orderBy('wc_item_id')->get(['wc_item_id', 'quantity', 'unit_price', 'total'])->toArray(),
        'customers' => Customer::query()->orderBy('id')->get(['wp_user_id', 'email', 'name'])->toArray(),
        'counts' => [
            Product::count(), Order::count(), OrderItem::count(),
            Customer::count(), Category::count(), Brand::count(),
        ],
    ];
}

function impStart(string $mode, array $options = []): array
{
    return test()->postJson('/admin-api/import/start', array_merge([
        'mode' => $mode,
        // The demo catalogue that 2026_08_27_100000_seed_demo_catalogue puts on
        // EVERY install holds the slugs `cosrx` and `beauty-of-joseon`, which
        // are real brands this store really sells. Without this the genuine
        // terms are refused. It is decision D7, and the screen makes it a
        // switch rather than a constant for exactly that reason.
        'adopt_by_slug' => true,
    ], $options))->assertOk()->json();
}

/* ------------------------------------------------------------- 1. the guard */

it('mounts every import route behind the admin session guard', function () {
    $routes = ImportAdminRoutes::registered();

    expect($routes)->not->toBeEmpty();

    $unguarded = [];

    foreach ($routes as $route) {
        if (! in_array('auth:admin', $route->gatherMiddleware(), true)) {
            $unguarded[] = implode('|', $route->methods()).' '.$route->uri();
        }
    }

    expect($unguarded)->toBe([]);
});

it('refuses every import endpoint to a caller with no admin session', function () {
    foreach (ImportAdminRoutes::registered() as $route) {
        $method = in_array('GET', $route->methods(), true) ? 'getJson' : 'postJson';

        $this->{$method}('/'.$route->uri())->assertStatus(401);
    }
});

it('never mounts an import endpoint under the unauthenticated /api prefix', function () {
    $leaked = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/'))
        ->filter(fn ($r) => str_contains((string) $r->getAction('controller'), 'ImportApiController'))
        ->map(fn ($r) => $r->uri())
        ->values()
        ->all();

    expect($leaked)->toBe([]);
});

/*
 * No route parameter anywhere in this file.
 *
 * Which of the six entities a call applies to arrives in the BODY and is
 * checked against a fixed list, so no path segment is ever built from caller
 * input — which is what removes the entire class of "the id was a path" bugs
 * from a surface whose job is writing files and rewriting the catalogue.
 */
it('takes no entity through a URL segment', function () {
    foreach (ImportAdminRoutes::registered() as $route) {
        /*
         * str_contains() inside toBeFalse(). ->not->toContain('{', $message) is
         * not a guard: toContain() is VARIADIC, the message is a second needle,
         * and `not` passes because no URI contains "... takes a route
         * parameter". Measured with '{' appended to every URI -- still green.
         */
        expect(str_contains($route->uri(), '{'))->toBeFalse($route->uri().' takes a route parameter');
    }
});

/* ---------------------------------------------------------- 2. the uploads */

it('refuses the files an owner will actually try to upload by mistake', function () {
    $this->actingAs(impAdmin(), 'admin');

    $cases = [
        'a spreadsheet' => impFile('products.xlsx', "PK\x03\x04".str_repeat('x', 200)),
        'a PDF' => impFile('report.pdf', '%PDF-1.7'."\n".str_repeat('x', 200)),
        'a PHP script' => impFile('products.csv', "<?php echo 'hi';"),
        'a saved web page' => impFile('products.csv', "<!DOCTYPE html>\n<html><body>hi</body></html>"),
        'binary data' => impFile('products.csv', "id,name\n1,\x00\x01\x02binary"),
        'an empty file' => impFile('products.csv', ''),
    ];

    foreach ($cases as $what => $file) {
        $response = $this->postJson('/admin-api/import/upload', ['file' => $file]);

        expect($response->status())->toBe(422, $what.' was accepted');
        expect((string) ($response->json('refused.0.message') ?? ''))->not->toBe('', $what.' gave no reason');
    }

    // And nothing landed on disk.
    foreach (ImportWorkspace::entities() as $entity) {
        expect((new ImportWorkspace)->has($entity))->toBeFalse();
    }
});

it('refuses a CSV with no external id column, and names the column it wanted', function () {
    $this->actingAs(impAdmin(), 'admin');

    $response = $this->postJson('/admin-api/import/upload', [
        'entity' => 'orders',
        'file' => impFile('orders.csv', "order_number,total\nKBB-1,10.00\n"),
    ]);

    $response->assertStatus(422);

    expect((string) $response->json('refused.0.message'))
        ->toContain('order_id')
        ->toContain('duplicate');
});

it('refuses a misaligned CSV at upload instead of forty thousand rows into the run', function () {
    $this->actingAs(impAdmin(), 'admin');

    $response = $this->postJson('/admin-api/import/upload', [
        'entity' => 'brands',
        'file' => impFile('brands.csv', "term_id,name,slug\n1,Only Two\n"),
    ]);

    $response->assertStatus(422);

    expect((string) $response->json('refused.0.message'))->toContain('misaligned');
});

/*
 * The uploaded name is never a path.
 *
 * Every trick below — traversal, an absolute path, a NUL byte, a double
 * extension — is handed in as the filename, and every one of them has to land
 * at the single literal destination this code chose for that entity.
 */
it('never lets the uploaded filename decide where the file goes', function () {
    $this->actingAs(impAdmin(), 'admin');

    $workspace = new ImportWorkspace;
    $body = "term_id,name,slug\n501,COSRX,cosrx\n";

    foreach (['../../../.env', '/etc/passwd', "brands.csv\0.php", 'brands.csv.php'] as $hostile) {
        $workspace->forget('brands');

        $this->postJson('/admin-api/import/upload', [
            'entity' => 'brands',
            'file' => impFile($hostile, $body),
        ])->assertOk();

        expect($workspace->path('brands'))->toBe(storage_path('app/import/woo/brands.csv'))
            ->and(is_file($workspace->path('brands')))->toBeTrue();
    }

    // One file, at one place, whatever it was called on the way in.
    expect(glob(storage_path('app/import/woo/*.csv')))->toBe([storage_path('app/import/woo/brands.csv')]);
});

/*
 * An uploaded CSV must never be servable.
 *
 * It holds the store's entire customer list. The property being relied on is
 * structural rather than a rule: bootstrap/app.php points the public path at a
 * different directory from the application root, so nothing under storage/ has
 * a URL at all.
 */
it('stores uploads outside the web root', function () {
    $this->actingAs(impAdmin(), 'admin');

    $this->postJson('/admin-api/import/upload', ['file' => impFixture('customers')])->assertOk();

    $stored = (new ImportWorkspace)->path('customers');

    expect(is_file($stored))->toBeTrue()
        ->and(str_starts_with($stored, storage_path('app/')))->toBeTrue()
        ->and(str_starts_with(realpath($stored) ?: $stored, realpath(public_path()) ?: public_path()))->toBeFalse();
});

it('works out which export each file is, and says which of the six are missing', function () {
    $this->actingAs(impAdmin(), 'admin');

    $this->postJson('/admin-api/import/upload', ['file' => impFixture('orders')])->assertOk();

    $files = collect(impStatus()['files'])->keyBy('entity');

    expect($files['orders']['present'])->toBeTrue()
        ->and($files['orders']['rows'])->toBe(10)
        ->and($files['customers']['present'])->toBeFalse()
        ->and($files['products']['present'])->toBeFalse();
});

it('accepts a delta of orders alone without demanding the other five files', function () {
    $this->actingAs(impAdmin(), 'admin');

    $this->postJson('/admin-api/import/upload', ['file' => impFixture('orders')])->assertOk();

    impStart('live');

    $steps = impRunToEnd();

    expect(end($steps)['status']['run']['status'])->toBe('complete')
        ->and(Order::count())->toBeGreaterThan(0);
});

/* -------------------------------------------------- 3. the preview writes nothing */

it('previews the whole import and keeps none of it', function () {
    $this->actingAs(impAdmin(), 'admin');
    impUploadAll();

    $before = [Product::count(), Order::count(), Customer::count(), OrderItem::count()];

    impStart('preview');
    $steps = impRunToEnd(rows: 3); // three rows at a time, so it really does step

    $status = end($steps)['status'];

    expect($status['run']['status'])->toBe('complete')
        ->and([Product::count(), Order::count(), Customer::count(), OrderItem::count()])->toBe($before)
        // A dry run must not advance a checkpoint either, or the real run would
        // skip the rows the preview only pretended to do.
        ->and(DB::table('import_checkpoints')->where('run_key', ImportDriver::RUN_KEY)->count())->toBe(0);

    // And it did say what WOULD happen.
    $products = collect($status['entities'])->firstWhere('entity', 'products');

    expect($products['created'])->toBeGreaterThan(0);
});

it('lists every refused row with a reason, and hands them over as a CSV', function () {
    $this->actingAs(impAdmin(), 'admin');
    impUploadAll();

    impStart('preview');
    $steps = impRunToEnd();

    $status = end($steps)['status'];

    expect($status['rejects']['count'])->toBeGreaterThan(0)
        ->and($status['rejects']['shown'][0]['reason'])->not->toBe('');

    $csv = $this->get('/admin-api/import/rejects?mode=preview');

    $csv->assertOk();

    $body = $csv->getContent();

    expect($csv->headers->get('content-type'))->toContain('text/csv')
        ->and((string) $csv->headers->get('content-disposition'))->toContain('attachment')
        // Nothing renders it, whatever a refused cell happened to contain.
        ->and((string) $csv->headers->get('x-content-type-options'))->toBe('nosniff')
        ->and($body)->toContain('entity,line,id,reason');

    // Every refusal in the payload is in the file too — quoted the way a CSV
    // quotes it, since several reasons quote the column they are about.
    foreach ($status['rejects']['shown'] as $rejection) {
        expect($body)->toContain(str_replace('"', '""', $rejection['reason']));
    }

    // The reasons are sentences an owner can act on, not codes.
    expect($body)->toContain('Re-export with an unambiguous decimal point')
        ->and($body)->toContain('--order-number=id');
});

/* -------------------------------------------------- 4. the run, and running it twice */

it('imports the whole fixture through the screen, in slices, and does it again with nothing changing', function () {
    $this->actingAs(impAdmin(), 'admin');
    impUploadAll();

    impStart('live');
    impRunToEnd(rows: 2); // two rows per request: the most hostile slicing there is

    $first = impSnapshot();

    expect($first['counts'][0])->toBeGreaterThan(0)
        ->and(Order::query()->where('wc_order_id', 10233)->value('order_number'))->toBe('KBB-1001')
        // Money is integer fils all the way through. 298.50 AED is 29850.
        ->and(Order::query()->where('wc_order_id', 10233)->value('total'))->toBe(29850);

    // The second pass is the proof. Every row is re-presented and the database
    // comes out identical — which is the only evidence an importer cannot fake.
    //
    // `confirm_duplicate` is Lane GF's: the screen now recognises an export it
    // has already read and refuses it with a sentence rather than spending five
    // minutes reporting every row as unchanged. That refusal is the feature,
    // and THIS is the deliberate override it ships with — which makes this test
    // the proof that the override does what it says as well as the proof that
    // the importer is idempotent.
    impStart('live', ['confirm_duplicate' => true]);
    $steps = impRunToEnd(rows: 500);

    $status = end($steps)['status'];

    expect(impSnapshot())->toEqual($first);

    foreach (['categories', 'brands', 'products', 'orders', 'order-items'] as $entity) {
        $row = collect($status['entities'])->firstWhere('entity', $entity);

        expect($row['created'])->toBe(0, $entity.' created rows on the second pass')
            ->and($row['updated'])->toBe(0, $entity.' rewrote rows on the second pass')
            ->and($row['unchanged'])->toBeGreaterThan(0, $entity.' re-presented nothing at all');
    }
});

/*
 * Slicing does not change the outcome.
 *
 * Stated as: after an import done two rows at a time, an import done in ONE
 * slice big enough to swallow every file finds nothing left to do. If the
 * sliced run had skipped a row, dropped a batch, or double-advanced a
 * checkpoint past rows it never read, the big pass would have to create or
 * update something — and it is the one pass that cannot be fooled, because
 * "unchanged" comes from Eloquent's dirty comparison against the row as the
 * database actually holds it.
 */
it('reaches the same database whether it is stepped in twos or done in one go', function () {
    $this->actingAs(impAdmin(), 'admin');
    impUploadAll();

    impStart('live');
    impRunToEnd(rows: 2);

    $sliced = impSnapshot();

    $this->postJson('/admin-api/import/reset')->assertOk();

    // Reset forgets this screen's PROGRESS and deliberately not the record of
    // what was imported — `import_history` is the only answer the owner has to
    // "what is already in my shop" and a Reset must not take it. So the rows
    // are still there, the duplicate guard still recognises these exact files,
    // and this run says so out loud. Lane GF.
    impStart('live', ['confirm_duplicate' => true]);
    $steps = impRunToEnd(rows: 5000);

    expect(impSnapshot())->toEqual($sliced);

    foreach (collect(end($steps)['status']['entities'])->where('present', true) as $entity) {
        expect($entity['created'])->toBe(0, $entity['entity'].' was missing after the sliced run')
            ->and($entity['updated'])->toBe(0, $entity['entity'].' came out different when sliced');
    }
});

/* ------------------------------------------------------------- 5. resuming */

it('continues a run that was cut off part-way, without restarting or duplicating', function () {
    $this->actingAs(impAdmin(), 'admin');
    impUploadAll();

    impStart('live');

    // One small step, then stop as if the request had been killed.
    $this->postJson('/admin-api/import/step', ['rows' => 2])->assertOk();

    $mid = impStatus();
    $categories = collect($mid['entities'])->firstWhere('entity', 'categories');

    expect($categories['processed'])->toBe(2)
        ->and($categories['finished'])->toBeFalse()
        ->and(Category::query()->whereNotNull('source_term_id')->count())->toBe(2);

    // The screen is reloaded — a fresh status call, no state in the browser —
    // and the run is still there, still running, still on categories.
    expect($mid['run']['status'])->toBe('running')
        ->and($mid['run']['current_entity'])->toBe('categories');

    impRunToEnd(rows: 500);

    // Every category is in, exactly once, and the ids are the WordPress ones.
    $terms = Category::query()->whereNotNull('source_term_id')->pluck('source_term_id');

    expect($terms->count())->toBe($terms->unique()->count(), 'a category was imported twice')
        ->and(Category::query()->where('source_term_id', 15)->count())->toBe(1);
});

it('refuses to resume into a file that has changed under it, and offers to start that file again', function () {
    $this->actingAs(impAdmin(), 'admin');
    impUploadAll();

    impStart('live');
    $this->postJson('/admin-api/import/step', ['rows' => 2])->assertOk();

    // The owner re-exports categories with a new row at the top. Every offset
    // now points at a different row than the one it was recorded for.
    $path = (new ImportWorkspace)->path('categories');
    $lines = file($path);
    array_splice($lines, 1, 0, "99,Brand New,brand-new,,,9\n");
    file_put_contents($path, implode('', $lines));
    @unlink($path.'.meta.json');

    $before = Category::query()->whereNotNull('source_term_id')->count();

    $result = $this->postJson('/admin-api/import/step', ['rows' => 500])->assertOk()->json();

    expect($result['ok'])->toBeFalse()
        ->and($result['needs_restart'])->toBeTrue()
        ->and($result['message'])->toContain('--restart')
        // And it imported nothing rather than importing the wrong rows.
        ->and(Category::query()->whereNotNull('source_term_id')->count())->toBe($before);

    // Starting again from row one clears it, and costs only unchanged rows for
    // the ones already in. `force` because the stalled run is still the current
    // one — this replaces it rather than racing it.
    impStart('live', ['restart' => true, 'force' => true]);
    impRunToEnd(rows: 500);

    expect(Category::query()->where('source_term_id', 99)->count())->toBe(1);
});

/* ------------------------------------------------------- 6. one at a time */

it('refuses a second stepper while one is already working', function () {
    $this->actingAs(impAdmin(), 'admin');
    impUploadAll();

    impStart('live');

    // Exactly what a second browser tab would leave behind.
    DB::table(ImportDriver::TABLE)->where('run_key', ImportDriver::RUN_KEY)->update([
        'locked_at' => now(),
        'lock_token' => 'other-tab',
    ]);

    $this->postJson('/admin-api/import/step', ['rows' => 10])
        ->assertStatus(409)
        ->assertJsonPath('ok', false);
});

it('refuses to start a second run over one that is part-way through', function () {
    $this->actingAs(impAdmin(), 'admin');
    impUploadAll();

    impStart('live');
    $this->postJson('/admin-api/import/step', ['rows' => 2])->assertOk();

    $this->postJson('/admin-api/import/start', ['mode' => 'preview'])
        ->assertStatus(409)
        ->assertJsonPath('ok', false);
});

it('refuses to start anything at all when no file has been uploaded', function () {
    $this->actingAs(impAdmin(), 'admin');

    $this->postJson('/admin-api/import/start', ['mode' => 'preview'])
        ->assertStatus(409)
        ->assertJsonPath('ok', false);
});

/* --------------------------------------------------- 7. the owner's choices */

it('keeps the owner\'s decisions with the run rather than with the browser', function () {
    $this->actingAs(impAdmin(), 'admin');
    impUploadAll();

    impStart('live', ['guests' => 'unlinked', 'order_number' => 'id', 'timezone' => 'UTC']);

    // A fresh status call is what a reloaded tab sees.
    $run = impStatus()['run'];

    expect($run['options']['guests'])->toBe('unlinked')
        ->and($run['options']['order_number'])->toBe('id')
        ->and($run['options']['timezone'])->toBe('UTC')
        ->and($run['options']['adopt_by_slug'])->toBeTrue();

    impRunToEnd(rows: 500);

    // And they were actually applied: --order-number=id puts the post id in.
    expect(Order::query()->where('wc_order_id', 10233)->value('order_number'))->toBe('10233');
});

it('falls back to the documented recommendation rather than accepting a nonsense option', function () {
    $this->actingAs(impAdmin(), 'admin');
    impUploadAll();

    // Not a timezone; the importer would shift every order in the store by
    // whatever it guessed.
    impStart('live', ['timezone' => 'Mars/Olympus']);

    expect(impStatus()['run']['options']['timezone'])->toBe('Asia/Dubai');
});

it('offers exactly the decisions the runbook says belong to the owner', function () {
    $this->actingAs(impAdmin(), 'admin');

    $defaults = impStatus()['defaults'];

    expect($defaults)->toHaveKeys(['guests', 'order_number', 'timezone', 'adopt_by_slug'])
        // The documented recommendation is the default, so doing nothing does
        // the recommended thing. docs/IMPORT-RUNBOOK.md §6.
        ->and($defaults['guests'])->toBe('synthesise')
        ->and($defaults['order_number'])->toBe('number')
        ->and($defaults['timezone'])->toBe('Asia/Dubai')
        ->and($defaults['adopt_by_slug'])->toBeFalse();
});

/* --------------------------------------------------------- 8. the plumbing */

it('walks the entities in the importer\'s own dependency order, never its own copy', function () {
    // The runbook's one genuinely dangerous sequence is orders before the
    // customers they name. This screen cannot produce it, because it reads the
    // order off ImportRunner rather than holding a list that could drift.
    // `seo` is last, and that is a dependency rather than a preference: every
    // Yoast row is matched on `wc_id`, which ProductImporter writes, so an
    // entity ordered before it rejects the whole file on a fresh shop.
    expect(ImportWorkspace::runnerOrder())
        ->toBe([
            'categories', 'brands', 'products',
            // Coupons after the products their restriction lists name and
            // before the orders that name their code; reviews after both the
            // products they are of and the customers who wrote them.
            'coupons', 'customers', 'orders', 'order-items',
            // Refunds and order notes after the orders they attach to. Both
            // reject a row whose order is not here, because their order_id is
            // NOT NULL; refunds are also what stops an imported order reading
            // as full revenue, so their place in this walk is load-bearing.
            'refunds', 'order-notes',
            'reviews', 'seo',
        ])
        ->and(ImportWorkspace::entities())->toBe(ImportWorkspace::runnerOrder());

    /*
     * AND THE TWO LISTS MUST AGREE, which is the failure this assertion did not
     * have and which cost a package: ImportWorkspace::ENTITIES is hand-written
     * and keyed by entity name, ImportRunner::entities() is a separate
     * hand-written list, and registering `seo` on the runner alone made every
     * upload on this screen 500 on an undefined key instead of answering the
     * 422 it had been answering. `runnerOrder()` reads the runner, so a key
     * missing from ENTITIES fails here rather than in production.
     */
    foreach (ImportWorkspace::runnerOrder() as $entity) {
        expect(fn () => ImportWorkspace::meta($entity))
            ->not->toThrow(\Throwable::class, '', "the import screen knows nothing about the '{$entity}' entity the runner walks");
    }
});

it('issues no SQLite-only SQL from the screen\'s own reads', function () {
    $this->actingAs(impAdmin(), 'admin');
    impUploadAll();

    $sql = SqlShape::capture(function () {
        $this->getJson('/admin-api/import/status')->assertOk();
    });

    expect(SqlShape::violations($sql))->toBe([]);
});

it('never caches an import response', function () {
    $this->actingAs(impAdmin(), 'admin');

    $response = $this->getJson('/admin-api/import/status')->assertOk();

    expect((string) $response->headers->get('cache-control'))->toContain('no-store');
});

it('leaves the rows alone when the checkpoints are reset', function () {
    $this->actingAs(impAdmin(), 'admin');
    impUploadAll();

    impStart('live');
    impRunToEnd(rows: 500);

    $products = Product::count();

    $this->postJson('/admin-api/import/reset')->assertOk();

    expect(Product::count())->toBe($products)
        ->and(DB::table('import_checkpoints')->where('run_key', ImportDriver::RUN_KEY)->count())->toBe(0);
});

it('leaves a command-line run\'s checkpoints alone', function () {
    $this->actingAs(impAdmin(), 'admin');

    DB::table('import_checkpoints')->insert([
        'run_key' => 'default',
        'entity' => 'orders',
        'processed' => 4200,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson('/admin-api/import/reset')->assertOk();

    expect(DB::table('import_checkpoints')->where('run_key', 'default')->value('processed'))->toBe(4200);
});

/* ------------------------------------------------------------ 9. the screen */

it('ships a real Import / Export screen and not the mock wizard it replaces', function () {
    $blade = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    // The mock: five fake progress bars that filled themselves on a timer and
    // never touched the database. It looked like it worked, which is the worst
    // thing a screen about data migration can do.
    expect($blade)->not->toContain('Import complete ✓')
        ->and($blade)->not->toContain('3,948 images')
        ->and($blade)->not->toContain('Validate in Sandbox')
        // The real endpoints, reached through the real importer.
        ->and($blade)->toContain("impApi('/import/status')")
        ->and($blade)->toContain("impApi('/import/step'")
        ->and($blade)->toContain("impApi('/import/upload'");

    /*
     * The base is computed from the page's own path, never written as a leading
     * '/admin-api/...'. The live site runs at easywebsol.com/kbb-upgrade/, and
     * an absolute path resolves against the domain root — a URL with no
     * matching route at all. The same bug has been fixed here twice already.
     */
    expect($blade)->toContain("function impBase()")
        ->and($blade)->not->toContain("fetch('/admin-api/import");
});
