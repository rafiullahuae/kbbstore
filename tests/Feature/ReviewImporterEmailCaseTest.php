<?php

declare(strict_types=1);

/*
 * =============================================================================
 * WHOSE REVIEW IS IT — AND WHY `LOWER(email) = ?` CANNOT ANSWER THAT ON MYSQL
 * =============================================================================
 *
 * THE DEFECT, on the engine the shop runs. ReviewImporter::resolveCustomer()
 * ended in a single fallback statement:
 *
 *     Customer::withTrashed()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->value('id')
 *
 * MySQL's LOWER() is Unicode-aware, which is not the problem. The problem is the
 * COLLATION the `=` then runs under: `utf8mb4_unicode_ci` is accent-insensitive
 * as well as case-insensitive, so `é` and `e` are one character to it. Measured
 * on MySQL 8.0.46 through PDO with this suite's own charset and collation:
 *
 *     rows: 'JOSÉ@Example.com' and 'jose@example.com'
 *     LOWER(email) = 'jose@example.com'  ->  BOTH rows
 *     LOWER(email) = 'josé@example.com'  ->  BOTH rows
 *
 * Two different mailboxes are one row to that lookup, and `value('id')` takes
 * whichever InnoDB hands back first. So a review written by jose@ is filed
 * against the customer josé@ — a real person, whose name the product page then
 * prints under an opinion they never expressed, and whose order history the
 * admin's customer screen then shows it beside.
 *
 * WHY NOTHING CAUGHT IT. SQLite's `=` is neither case- nor accent-insensitive,
 * so the same statement matched only the exact row and the default suite was
 * green. This is the engine-divergence shape docs/MYSQL-PARITY.md exists for:
 * the permissive engine is the one in production, and the test lane is the
 * strict one, so the SQLite suite proves nothing either way.
 *
 * WHAT MAKES IT WORTH FIXING RATHER THAN DOCUMENTING. The line directly above it
 * already does this correctly and engine-independently —
 * ImportContext::customerIdForEmail() loads every customer into a PHP map keyed
 * by mb_strtolower(), and its own docblock says it is built that way "so the
 * check is the same on both engines". The fallback query contradicted the map
 * immediately above it: the map correctly MISSES on an accent difference and the
 * query then wrongly HITS. The fix is to make the statement a candidate net and
 * let PHP decide, which is what the map already does.
 *
 * ── MUTATION ────────────────────────────────────────────────────────────────
 *
 * Restore the single line:
 *
 *     $id = Customer::query()->withTrashed()
 *         ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->value('id');
 *     return $id === null ? null : (int) $id;
 *
 * RUN on -c phpunit-mysql.xml: case 1 red — the review comes back linked to the
 * accented customer instead of unlinked. RUN on the default config: green, both
 * with and without the fix, which is the asymmetry this file records.
 */

use App\Models\Customer;
use App\Models\Product;
use App\Models\Review;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;
use Illuminate\Support\Facades\DB;

/** The export Lane GE's plugin really wrote, shared with the other import lanes. */
function ricExportDir(): string
{
    return base_path('tests/Fixtures/kbb-export');
}

function ricTimezone(): string
{
    return json_decode((string) file_get_contents(ricExportDir().'/manifest.json'), true)['source']['timezone'];
}

/**
 * One review row against a product the fixture really carries, with the email
 * under test on it. Written to a file of its own so the checked-in fixture is
 * never edited.
 */
function ricReviewsCsv(string $email): string
{
    $path = sys_get_temp_dir().'/kbb-ric-'.bin2hex(random_bytes(6)).'.csv';

    $handle = fopen($path, 'wb');
    fputcsv($handle, [
        'comment_id', 'comment_post_id', 'comment_type', 'author', 'email', 'rating',
        'title', 'content', 'comment_approved', 'comment_date', 'verified', 'user_id', 'ip',
    ]);
    fputcsv($handle, [
        '990001', '4021', 'review', 'Jose', $email, '5',
        'Lovely', 'Cleared my skin in a week', '1', '2024-03-01 10:00:00', 'yes', '', '203.0.113.9',
    ]);
    fclose($handle);

    return $path;
}

/**
 * Products out of the fixture so `comment_post_id` resolves, and reviews out of
 * the one-row file above. customers.csv is deliberately NOT imported: the only
 * customer in the database is the one each case seeds, so the row the fallback
 * query can reach is unambiguous.
 */
function ricImport(string $email): void
{
    $csv = ricReviewsCsv($email);

    (new ImportRunner)->run(new ImportOptions(
        directory: ricExportDir(),
        files: ['reviews' => $csv],
        only: ['products', 'reviews'],
        runKey: 'ric-'.bin2hex(random_bytes(4)),
        sourceTimezone: ricTimezone(),
        adoptBySlug: true,
    ));

    @unlink($csv);
}

function ricReview(): ?Review
{
    return Review::query()->where('source', 'wp_comment')->where('source_id', 990001)->first();
}

/* ═════════ 1. the defect: an accent is not a case difference ═════════ */

it('does not file a review against a customer whose address differs by an accent', function () {
    /*
     * Two DIFFERENT mailboxes. The shop holds josé@; the review was written by
     * jose@, who has no account here. The only correct answer is "no customer",
     * which `reviews.customer_id` null already means — the review still imports,
     * still publishes, and still shows the author name off the export.
     */
    $accented = Customer::create([
        'email' => 'josé@example.com',
        'first_name' => 'José',
        'last_name' => 'Accented',
    ]);

    ricImport('jose@example.com');

    $review = ricReview();

    expect($review)->not->toBeNull('the review did not import at all, so this case proves nothing');

    /*
     * The assertion that was red. Before the fix, on MySQL, customer_id was
     * $accented->id: `LOWER(email) = 'jose@example.com'` matched 'josé@example.com'
     * under utf8mb4_unicode_ci and value('id') returned it.
     */
    expect($review->customer_id)->toBeNull(
        'jose@example.com was filed against josé@example.com — the collation treats é and e as one '
        .'character, so the review is published under a name that did not write it'
    );

    // And the accented customer is untouched either way.
    expect(Customer::query()->whereKey($accented->id)->value('email'))->toBe('josé@example.com');
});

/* ═══════ 2. the case difference it must still resolve, both engines ═══════ */

it('still files a review against the same address stored in a different case', function () {
    /*
     * The behaviour the LOWER() was there for, and it must survive the fix. This
     * one resolves through ImportContext's in-memory map rather than the fallback
     * query — the map is keyed by mb_strtolower() — which is exactly why the fix
     * to the fallback cannot regress it.
     */
    $customer = Customer::create([
        'email' => 'JOSE@Example.com',
        'first_name' => 'Jose',
        'last_name' => 'Upper',
    ]);

    ricImport('jose@example.com');

    expect(ricReview()?->customer_id)->toBe($customer->id);
});

/* ═════════ 3. the exact address, which must never stop working ═════════ */

it('files a review against an exact match, accent and all', function () {
    $customer = Customer::create([
        'email' => 'josé@example.com',
        'first_name' => 'José',
        'last_name' => 'Exact',
    ]);

    ricImport('josé@example.com');

    expect(ricReview()?->customer_id)->toBe($customer->id);
});

/* ═════ 4. the candidate net is a net, not a scan of the whole table ═════ */

it('asks for the candidates in one statement', function () {
    /*
     * The fix replaced `value('id')` with `get()` over an OR of three spellings,
     * so the cost is worth pinning: it is still ONE statement against `customers`
     * on the fallback path, not one per spelling and not a fetch of the table.
     *
     * MUTATION: query each spelling separately in resolveCustomer() and this is
     * 3 rather than 1.
     */
    Customer::create(['email' => 'nobody@example.com', 'first_name' => 'No', 'last_name' => 'Body']);

    $statements = [];
    DB::listen(function ($event) use (&$statements): void {
        if (str_contains(strtolower($event->sql), 'from "customers"') || str_contains(strtolower($event->sql), 'from `customers`')) {
            $statements[] = $event->sql;
        }
    });

    ricImport('stranger@example.com');

    // ImportContext::loadEmails() chunks the table once; the fallback adds one.
    expect(ricReview()?->customer_id)->toBeNull();
    expect(count($statements))->toBeLessThanOrEqual(3, 'the fallback should cost one statement, not one per spelling: '.implode(' | ', $statements));
});
