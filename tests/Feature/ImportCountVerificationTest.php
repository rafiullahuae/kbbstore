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

    /*
     * AND THAT FIRST SLICE REFUSED A ROW. It is the whole difficulty: the
     * refusal happened in a process that has exited, so nothing this run can
     * see in its own tallies knows about it. Asserted rather than assumed,
     * because if the fixture ever stopped putting a trashed product in the
     * first twelve rows the tests below would go on passing while testing
     * nothing.
     */
    $refusedEarlier = $first->for('products')->rejectedCount();
    expect($refusedEarlier)->toBeGreaterThan(0, 'the first slice is supposed to refuse a row');

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
     * ── THE VERDICT IS NOW REACHED, AND THIS ASSERTION CHANGED ─────────────
     *
     * It used to be `toBe('counted')`: a resumed run reported the two numbers
     * side by side and refused to compare them, because "it cannot know how
     * many of the rows it did not re-read were refusals"
     * (docs/FV-IMPORT-AT-VOLUME.md §7, fifth bullet). The reason was right and
     * the consequence was that the check did not work where the owner uses it:
     * the admin screen imports one entity per HTTP request, so EVERY step
     * after the first resumes, and Lane GF's screenshots show `counted` on
     * every entity seven times over. Phase 13's count verification existed in
     * name only from the browser.
     *
     * WHAT PROTECTS THE PROPERTY THE OLD ASSERTION PROTECTED. Its worry was a
     * FALSE SHORTFALL -- "comparing them anyway would raise a shortfall on
     * every resumed import that refused anything, and on shared hosting every
     * import is resumed". This run is exactly that case: the earlier slice
     * refused a row. It does not raise a shortfall, because the expected count
     * now subtracts the earlier slice's refusals as well as this one's, out of
     * the `rejected_rows` column `import_checkpoints` has carried all along.
     * The test below breaks that subtraction and shows the false shortfall
     * appear, which is the proof that this assertion is load-bearing rather
     * than merely green.
     */
    expect($v['verdict'])->toBe('verified', $v['sentence'])
        ->and($v['expected_in_database'])->toBe($v['in_database'])
        /*
         * TWO REFUSAL FIGURES, and each answers a different question.
         * `rejected` is this invocation's, and `accounted + rejected = read` is
         * the identity that catches a row read and then neither written nor
         * refused. `refused_in_file` is the whole file's across every
         * invocation, and `read - refused_in_file = in_database` is the one
         * that catches a row that is simply missing. The console's table prints
         * the second; mixing them up would make one of the two subtractions
         * stop reaching its answer on every resumed run.
         */
        ->and($v['refused_in_file'])->toBe($v['rejected'] + $refusedEarlier)
        ->and($v['read'] - $v['refused_in_file'])->toBe($v['in_database'])
        ->and($v['accounted'] + $v['rejected'])->toBe($v['read'])
        ->and($v['in_database'])->toBe(Product::query()->whereNotNull('wc_id')->count())
        ->and($second->hasDiscrepancy())->toBeFalse();

    // And the sentence still says what happened, including the part this
    // process did not do.
    expect($v['sentence'])->toContain('committed by an earlier run and not re-read')
        ->and($v['sentence'])->toContain('refused against this file in all');
});

it('would claim a shortfall on a resumed run if the earlier refusals were not subtracted', function () {
    /*
     * THE MUTATION, WRITTEN DOWN. `expected = read - refusals` is right only if
     * "refusals" means the whole file's and not this process's. Dropping the
     * resumed half of that sum is the one-character mistake available here, and
     * this is what it costs: the import is complete and correct, and the report
     * tells the owner a row is missing and not to treat the import as complete.
     */
    $key = 'cv-mutation-'.bin2hex(random_bytes(4));

    $first = cvImport(['only' => ['products'], 'limit' => 12, 'runKey' => $key]);
    $refusedEarlier = $first->for('products')->rejectedCount();

    $second = cvImport(['only' => ['products'], 'runKey' => $key]);
    $report = $second->for('products');

    expect($report->resumedRejected)->toBe($refusedEarlier, 'the checkpoint carried the earlier refusals')
        ->and($report->verification()['verdict'])->toBe('verified');

    // Now take that number away, which is the mutation, and re-ask.
    $report->resumedRejected = 0;
    $mutated = $report->verification();

    expect($mutated['verdict'])->toBe('discrepancy')
        ->and($mutated['expected_in_database'])->toBe($mutated['in_database'] + $refusedEarlier)
        ->and($mutated['sentence'])->toContain('missing from the database');
});

it('catches a row that went missing in a run this process never saw', function () {
    /*
     * THE REASON THE VERDICT IS WORTH REACHING AT ALL, and the thing the
     * withheld verdict could not do. Lane FV built this check because "every
     * figure in the report was the importer describing its own work -- a row
     * read and then neither written nor refused incremented nothing while the
     * totals stayed plausible". Across a resume, the rows the earlier process
     * handled are exactly the rows this one has no tally for, so a row lost
     * there is the hardest case there is. It is now caught.
     */
    $key = 'cv-lost-'.bin2hex(random_bytes(4));

    cvImport(['only' => ['products'], 'limit' => 12, 'runKey' => $key]);

    // A row the first pass imported, gone. Nothing in this process's tallies
    // will ever mention it.
    $victim = Product::query()->whereNotNull('wc_id')->orderBy('id')->firstOrFail();
    Product::query()->whereKey($victim->id)->forceDelete();

    $second = cvImport(['only' => ['products'], 'runKey' => $key]);
    $v = $second->for('products')->verification();

    expect($v['verdict'])->toBe('discrepancy')
        ->and($v['unaccounted'])->toBe(0, 'this process read nothing it lost -- the loss is in the resumed half')
        ->and($v['expected_in_database'])->toBe($v['in_database'] + 1)
        ->and($v['sentence'])->toContain('1 row is missing from the database')
        ->and($v['sentence'])->toContain('DO NOT TREAT THIS IMPORT AS COMPLETE')
        ->and($second->hasDiscrepancy())->toBeTrue();
});

it('withholds the verdict when the progress record carries an earlier pass\'s counts', function () {
    /*
     * THE ONE CASE WHERE `rejected_rows` STILL CANNOT BE BELIEVED, and the
     * reason the fallback FV wrote is kept rather than deleted.
     *
     * `processed` is reset to zero when a FINISHED entity is run again -- the
     * full -> delta -> cutover sequence -- and until Checkpoint was changed the
     * four outcome counters were NOT reset with it. A checkpoint in that state
     * says "400 processed, 1,067 created, 7 refused", and `processed - rejected`
     * comes out too small, so a table that is genuinely short reads as
     * verified. Checkpoint::open() now zeroes the counters wherever it zeroes
     * the offset, so this shape cannot be written any more -- but one is
     * sitting in the owner's database from the code that shipped before it, so
     * it is detected and the verdict is withheld.
     *
     * Forged by hand here, which is the only way to produce a row the current
     * code will not write.
     */
    $key = 'cv-stale-'.bin2hex(random_bytes(4));

    cvImport(['only' => ['products'], 'limit' => 12, 'runKey' => $key]);

    DB::table('import_checkpoints')
        ->where('run_key', $key)
        ->where('entity', 'products')
        // More outcomes than rows: impossible for one pass, ordinary for two.
        ->update(['rejected_rows' => 40, 'created_rows' => 900]);

    $second = cvImport(['only' => ['products'], 'runKey' => $key]);
    $v = $second->for('products')->verification();

    expect($v['verdict'])->toBe('counted')
        ->and($v['expected_in_database'])->toBeNull()
        ->and($v['in_database'])->toBe(Product::query()->whereNotNull('wc_id')->count())
        ->and($v['sentence'])->toContain('reported side by side rather than compared')
        ->and($v['sentence'])->toContain('an earlier, completed pass')
        // AND IT IS NOT AN ALARM. A withheld verdict is not a discrepancy.
        ->and($second->hasDiscrepancy())->toBeFalse();
});

it('zeroes the outcome counters wherever it zeroes the offset', function () {
    /*
     * The invariant the verdict above rests on, asserted on the table itself:
     * created + updated + unchanged + rejected <= processed, for a checkpoint
     * whose counters describe its own rows. The shortfall, when there is one,
     * is the rows that moved nothing -- which is the defect being hunted.
     */
    $key = 'cv-rerun-'.bin2hex(random_bytes(4));

    cvImport(['only' => ['products'], 'runKey' => $key]);

    $after = DB::table('import_checkpoints')->where('run_key', $key)->where('entity', 'products')->first();

    expect($after->finished_at)->not->toBeNull()
        ->and((int) $after->created_rows + (int) $after->updated_rows
            + (int) $after->unchanged_rows + (int) $after->rejected_rows)
        ->toBeLessThanOrEqual((int) $after->processed);

    $firstPassRejected = (int) $after->rejected_rows;
    expect($firstPassRejected)->toBeGreaterThan(0, 'the fixture is supposed to have refusals');

    // The delta: the same entity again, from the top, on the same run key.
    cvImport(['only' => ['products'], 'runKey' => $key]);

    $delta = DB::table('import_checkpoints')->where('run_key', $key)->where('entity', 'products')->first();

    expect((int) $delta->processed)->toBe((int) $after->processed)
        ->and((int) $delta->rejected_rows)->toBe(
            $firstPassRejected,
            'the second pass carried the first pass\'s refusals as well as its own'
        )
        ->and((int) $delta->created_rows)->toBe(0, 'a created row on the second pass is a duplicate')
        ->and((int) $delta->unchanged_rows)->toBe((int) $after->created_rows);
});

it('hands back no resumed refusals for a checkpoint that is at row zero', function () {
    /*
     * CHECKPOINT'S OWN CONTRACT, asserted directly because no import can reach
     * this shape any more and the property still has to hold.
     *
     * `resumedRejected` is defined as "the refusals among the `processed` rows
     * this run is resuming onto". At offset zero there are no such rows, so it
     * is zero WHATEVER the counters beside it say. The counters can say
     * something else only on a row written by the code that shipped before
     * Checkpoint zeroed them — which is a row sitting in the owner's database
     * right now, so the definition is not hypothetical.
     *
     * ImportRunner happens to ask for this value only when the offset is above
     * zero, so a wrong answer here would be invisible in an end-to-end test.
     * That is exactly why it is asserted on the class: a guard no test can
     * distinguish is a guard that will be deleted.
     */
    $key = 'cv-zero-'.bin2hex(random_bytes(4));

    DB::table('import_checkpoints')->insert([
        'run_key' => $key,
        'entity' => 'products',
        'source_fingerprint' => 'fp',
        'source_label' => 'products.csv',
        'processed' => 0,
        'created_rows' => 900,
        'updated_rows' => 0,
        'unchanged_rows' => 0,
        'rejected_rows' => 9,
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $checkpoint = \App\Services\Import\Checkpoint::open($key, 'products', 'fp', 'products.csv', false);

    expect($checkpoint->processed)->toBe(0)
        ->and($checkpoint->resumedRejected)->toBe(0, 'there are no resumed rows for those refusals to belong to')
        ->and($checkpoint->resumedCountsTrusted)->toBeTrue('nothing is being trusted: there is nothing to resume');
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
