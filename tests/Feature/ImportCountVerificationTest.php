<?php

/*
 * COUNT-BASED VERIFICATION AFTER EACH BUCKET — Phase 13's own line, and the
 * one item on it that the report could not answer.
 *
 * WHAT WAS MISSING AND WHY IT MATTERED. Every number the import reported was
 * produced by the importer describing its own work: `created` is incremented by
 * the code that created the row, `rejected` by the code that refused it. A row
 * read from the file and then neither written nor refused — because some
 * mapping returned early on a case nobody anticipated — incremented nothing,
 * and was therefore invisible in a report whose every column is self-reported.
 * The totals still looked plausible. "orders: 4,158 created" reads exactly like
 * success whether the file held 4,158 orders or 4,159, and the missing one is
 * found when a customer asks where theirs went.
 *
 * So two checks that do not take the importer's word for it, both pinned here:
 *
 *   ROWS READ vs ROWS ACCOUNTED FOR. The runner counts rows off the source and
 *   separately watches each one move its entity's tally. A row that moves
 *   nothing and throws nothing is named with its line and its id.
 *
 *   ROWS IN THE DATABASE. One COUNT per bucket against the table the entity
 *   writes to, restricted to rows carrying an external id — the only figure in
 *   the whole report that is asked of the database.
 *
 * THE FIXTURE IS THE REAL ONE AT A SIZE A TEST CAN AFFORD.
 * tools/woo-volume-fixture/generate.php is what the full-volume rehearsal runs
 * — 671 products, 4,159 orders, 3,712 customers — and these tests drive the
 * same generator with smaller numbers. Same columns, same deliberate defects,
 * same proportions. A test written against a different fixture would prove
 * something about the test's fixture.
 */

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Import\EntityReport;
use App\Services\Import\Entities\EntityImporter;
use App\Services\Import\ImportContext;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportReport;
use App\Services\Import\ImportRunner;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;
use App\Services\Import\Sources\CsvRowSource;
use Illuminate\Support\Facades\DB;

/* ------------------------------------------------------------- the fixture */

/**
 * The volume fixture, generated once per process and shared.
 *
 * Generating it is cheap (a fraction of a second) but importing it is not, so
 * the tests that only need to READ a report share one run where they can.
 */
function cvFixture(): string
{
    static $dir = null;

    if ($dir !== null) {
        return $dir;
    }

    $dir = sys_get_temp_dir().'/kbb-cv-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    require_once base_path('tools/woo-volume-fixture/generate.php');

    (new VolumeFixture($dir, 20260917, [
        'products' => 40,
        'orders' => 120,
        'customers' => 90,
        'reviews' => 60,
        'coupons' => 8,
        'brands' => 12,
        'categories' => 12,
    ]))->write();

    return $dir;
}

/** @return array<string, int> what the generator says it wrote */
function cvManifest(): array
{
    /*
     * `counts` — the generator now writes manifest.json in the shape
     * docs/WP-EXPORT-CONTRACT.md defines, because the importer reads that
     * name and refuses a format it does not speak. The flat map this has
     * always asserted against is under `counts`, which is where the
     * contract puts it, `unread.*` keys and all. Lane GF.
     */
    return json_decode((string) file_get_contents(cvFixture().'/manifest.json'), true)['counts'];
}

function cvImport(array $overrides = []): ImportReport
{
    return (new ImportRunner)->run(new ImportOptions(...array_merge([
        'directory' => cvFixture(),
        // The demo catalogue holds `serums`, `toners` and `cleansers`, which
        // this generator also emits. Without this the genuine terms are
        // refused and the verification would be measuring a slug collision.
        'adoptBySlug' => true,
        'batchSize' => 25,
    ], $overrides)));
}

/* --------------------------------------------------------- an entity that
 * does nothing, which is the whole point.
 *
 * There is no row in any real export that makes a real importer do this, and
 * that is exactly why it has to be built: the defect being guarded against is
 * one that has never happened, in code that is written to make it impossible,
 * and the guard is worthless unless something proves it fires.
 */
final class CvSilentImporter extends EntityImporter
{
    public function __construct(
        private readonly string $silentOn = '',
        private readonly ?int $pretendInDatabase = null,
    ) {}

    public function name(): string
    {
        return 'brands';
    }

    public function conventionalFile(): string
    {
        return 'brands.csv';
    }

    public function countImported(): ?int
    {
        return $this->pretendInDatabase;
    }

    public function import(Row $row, ImportContext $context): void
    {
        $termId = (string) $row->raw('term_id');

        if ($termId === $this->silentOn) {
            // Read the row. Write nothing. Refuse nothing. Say nothing.
            return;
        }

        $context->record($this->name(), 'unchanged');
    }
}

/** Run one entity through the runner with a stand-in importer. */
function cvRunSilent(EntityImporter $importer, string $file = 'brands.csv'): ImportReport
{
    $report = new ImportReport;
    $options = new ImportOptions(directory: cvFixture(), batchSize: 5, runKey: 'cv-'.bin2hex(random_bytes(4)));
    $context = new ImportContext($options, $report);

    $runner = new ImportRunner;
    $method = new ReflectionMethod($runner, 'runEntity');
    $method->invoke($runner, $importer, new CsvRowSource(cvFixture().'/'.$file), $options, $context, null);

    return $report;
}

/* ------------------------------------------------------------------ tests */

it('accounts for every row of every bucket, and says so in numbers the importer did not produce', function () {
    $manifest = cvManifest();
    $report = cvImport();

    foreach (['categories', 'brands', 'products', 'coupons', 'customers', 'orders', 'order-items', 'reviews'] as $entity) {
        $v = $report->for($entity)->verification();

        expect($v['read'])->toBeGreaterThan(0, $entity.' read no rows at all');
        expect($v['accounted'] + $v['rejected'])->toBe(
            $v['read'],
            $entity.': '.$v['read'].' rows were read and '.($v['accounted'] + $v['rejected']).' were accounted for'
        );
        expect($v['unaccounted'])->toBe(0, $entity.' lost a row without saying so');
        expect($v['verdict'])->toBe('verified', $entity.': '.$v['sentence']);
    }

    // The three numbers Phase 13 names, read off the generator's own manifest
    // rather than off the report being checked.
    expect($report->for('products')->rowsRead)->toBe($manifest['products'])
        ->and($report->for('orders')->rowsRead)->toBe($manifest['orders'])
        ->and($report->for('customers')->rowsRead)->toBe($manifest['customers']);

    // And products in = products out, with the refusals accounting for the gap.
    expect($report->for('products')->inDatabase)
        ->toBe($manifest['products'] - $manifest['products.trashed_refused'])
        ->and(Product::query()->whereNotNull('wc_id')->count())
        ->toBe($manifest['products'] - $manifest['products.trashed_refused']);
});

it('counts the database, not the report: the two agree row for row', function () {
    $report = cvImport();

    expect($report->for('products')->inDatabase)->toBe(Product::query()->whereNotNull('wc_id')->count())
        ->and($report->for('orders')->inDatabase)->toBe(Order::query()->whereNotNull('wc_order_id')->count())
        ->and($report->for('customers')->inDatabase)->toBe(Customer::query()->whereNotNull('wp_user_id')->count())
        ->and($report->for('order-items')->inDatabase)->toBe(OrderItem::query()->whereNotNull('wc_item_id')->count());

    /*
     * Guests are NOT counted as customers of the file, and that is the point of
     * the distinction. The customers bucket's `created` tally legitimately
     * includes every guest the ORDERS bucket synthesised, so it is the one
     * number in the report that cannot answer "customers in = customers out".
     * The count does, because it asks about `wp_user_id`.
     */
    expect(Customer::query()->whereNull('wp_user_id')->count())
        ->toBeGreaterThan(0, 'the fixture is supposed to contain guest orders');
});

it('names the row when one is read and then neither imported nor refused', function () {
    $victim = (string) DB::table('brands')->value('source_term_id'); // any value; replaced below
    $lines = array_map('str_getcsv', file(cvFixture().'/brands.csv'));
    $victim = $lines[3][0];

    $report = cvRunSilent(new CvSilentImporter(silentOn: $victim));

    $entity = $report->for('brands');
    $v = $entity->verification();

    expect($v['verdict'])->toBe('discrepancy')
        ->and($v['unaccounted'])->toBe(1)
        ->and($v['accounted'])->toBe($v['read'] - 1);

    $named = $entity->unaccountedRows();

    expect($named)->toHaveCount(1)
        ->and($named[0]['id'])->toContain($victim)
        ->and($named[0]['line'])->toBe(4);

    // And the sentence tells the owner not to treat the import as finished.
    expect($v['sentence'])->toContain('neither imported nor refused')
        ->and($v['sentence'])->toContain('DO NOT TREAT THIS IMPORT AS COMPLETE');

    expect($report->totalUnaccounted())->toBe(1)
        ->and($report->hasDiscrepancy())->toBeTrue();
});

it('calls it a discrepancy when the database holds fewer rows than the file supplied', function () {
    /*
     * The other half of the check, and the half that catches a row the importer
     * believes it wrote. `created` is incremented before the batch commits; a
     * row removed by a later cascade, or never written by a mapping that
     * reported success, leaves the tally right and the table short.
     */
    $report = cvRunSilent(new CvSilentImporter(pretendInDatabase: 3));

    $v = $report->for('brands')->verification();

    expect($v['verdict'])->toBe('discrepancy')
        ->and($v['unaccounted'])->toBe(0)
        ->and($v['in_database'])->toBe(3)
        ->and($v['sentence'])->toContain('missing from the database')
        ->and($report->hasDiscrepancy())->toBeTrue();
});

it('does not cry wolf when the table holds more than this file supplied', function () {
    // A delta import: the table already holds last week's rows.
    $report = cvRunSilent(new CvSilentImporter(pretendInDatabase: 10_000));

    $v = $report->for('brands')->verification();

    expect($v['verdict'])->toBe('verified')
        ->and($v['sentence'])->toContain('more than this file supplied');
});

it('says so plainly when a bucket has no table of its own to count', function () {
    $v = cvImport()->for('seo')->verification();

    expect($v['in_database'])->toBeNull()
        ->and($v['verdict'])->toBe('unverifiable')
        ->and($v['sentence'])->toContain('no table');
});

it('marks a part-way bucket as a slice rather than reporting a shortfall', function () {
    $manifest = cvManifest();

    $report = cvImport(['limit' => 10, 'only' => ['products'], 'runKey' => 'cv-partial-'.bin2hex(random_bytes(4))]);
    $v = $report->for('products')->verification();

    expect($v['verdict'])->toBe('partial')
        ->and($v['read'])->toBeLessThan($manifest['products'])
        ->and($v['expected_in_database'])->toBeNull()
        ->and($v['sentence'])->toContain('part-way through');
});

it('counts a resumed row as read, so a continued import does not report a shortfall', function () {
    $key = 'cv-resume-'.bin2hex(random_bytes(4));
    $manifest = cvManifest();

    // Stop part-way, exactly as a shared host's timeout would.
    $first = cvImport(['only' => ['products'], 'limit' => 12, 'runKey' => $key]);
    expect($first->for('products')->verification()['verdict'])->toBe('partial');

    // Continue. The rows the first pass committed were never re-read by this
    // one, and they are still rows of this file.
    $second = cvImport(['only' => ['products'], 'runKey' => $key]);
    $v = $second->for('products')->verification();

    expect($v['read'])->toBe($manifest['products'], 'a resumed run must account for the whole file, not its own slice')
        ->and($v['accounted'] + $v['rejected'])->toBe($v['read'])
        ->and($v['unaccounted'])->toBe(0)
        ->and(Product::query()->whereNotNull('wc_id')->count())
        ->toBe($manifest['products'] - $manifest['products.trashed_refused']);

    /*
     * AND IT REPORTS THE COUNT WITHOUT A VERDICT, which is the honest answer
     * rather than the reassuring one. A resumed run cannot know how many of the
     * rows it did not re-read were refusals, so `read - refused` is not the
     * number the table should hold; comparing them anyway would raise a
     * shortfall on every resumed import that refused anything, and on shared
     * hosting every import is resumed.
     */
    expect($v['verdict'])->toBe('counted')
        ->and($v['expected_in_database'])->toBeNull()
        ->and($v['in_database'])->toBe(Product::query()->whereNotNull('wc_id')->count())
        ->and($v['sentence'])->toContain('reported side by side rather than compared');
});

it('puts the verification where both front ends already print it', function () {
    $notes = cvImport()->for('orders')->notes();

    $verification = array_values(array_filter(
        array_keys($notes),
        static fn (string $note): bool => str_starts_with($note, EntityReport::VERIFICATION_NOTE_PREFIX),
    ));

    expect($verification)->toHaveCount(1)
        ->and($verification[0])->toContain('read')
        ->and($verification[0])->toContain('in the database');
});
