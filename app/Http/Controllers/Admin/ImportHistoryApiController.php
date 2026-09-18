<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ImportConsole\ImportDriver;
use App\Services\ImportConsole\ImportLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "All record" — what this shop has imported, from where, and when. Lane GF.
 *
 * =============================================================================
 * WHY THIS IS A SCREEN AND NOT A LOG LINE
 * =============================================================================
 *
 * The owner has no shell and no log access. Every number the import produces
 * today lives in one of three places, and all three are gone by the time he
 * would want them:
 *
 *   ImportReport   — in memory, for the length of one HTTP request.
 *   import_runs    — one row per run key, OVERWRITTEN IN PLACE by the next run.
 *   import_checkpoints — deleted outright by this screen's own Reset button.
 *
 * So "which export is my shop's data from, and what did it do when it came in"
 * had no answer at all a day later. `import_history` is that answer and this
 * controller is how it is read.
 *
 * =============================================================================
 * THREE ENDPOINTS AND THEY SIT UNDER /admin-api/import/
 * =============================================================================
 *
 * AdminCapabilities::RULES already carries ['*', 'admin-api/import/**',
 * 'data.import'] and `**` matches everything beneath it, so these three are
 * covered by an existing rule and A NEW RULE WOULD BE DEAD TEXT — the same
 * reasoning routes/media-sideload-admin.php sets out for the urls-media prefix,
 * and the reason the paths below were chosen rather than a prefix of this
 * lane's own, which would have fallen through to the closed owner-only default.
 * It is pinned by a test of this lane's, not assumed.
 *
 * WHY THE PAGE IS SERVED FROM HERE RATHER THAN ADDED TO THE CONSOLE. The admin
 * console is one 20,000-line Blade file this lane may not edit, and a record of
 * what happened is exactly the kind of screen that has to work on the day the
 * console does not. Lane GD made the same call for the same reason; this is a
 * standalone document with no build step and no shared state.
 *
 * WHY THE CSV IS AN ATTACHMENT WITH nosniff. It quotes note text taken from the
 * owner's own WooCommerce export, which can contain anything that was in it.
 * ImportApiController::rejects() says the same thing about its own download.
 *
 * NOTHING HERE WRITES. All three are GETs and there is no POST in this file,
 * which is why none of them needs the reasoning about link prefetchers that
 * import-admin.php's `step` does.
 */
class ImportHistoryApiController extends Controller
{
    public function __construct(
        private readonly ImportLedger $ledger = new ImportLedger,
        private readonly ImportDriver $driver = new ImportDriver,
    ) {}

    /**
     * Everything the history page draws itself from, in one call.
     *
     * One call rather than two, for the reason ImportApiController::status()
     * gives: a screen assembled from several requests can be half-fresh.
     */
    public function history(Request $request): JsonResponse
    {
        $limit = max(1, min(ImportLedger::PAGE_RUNS, (int) $request->integer('runs', ImportLedger::PAGE_RUNS)));
        $runs = $this->ledger->runs($limit);
        $status = $this->driver->status();

        return response()->json([
            'ok' => true,
            'generated_at' => now()->toIso8601String(),
            'runs' => $runs,
            // What is loaded and about to be imported, so the page answers
            // "what is in the shop" and "what is about to go in" side by side.
            'manifest' => $status['manifest'],
            'duplicate' => $status['duplicate'],
            'overall' => $status['overall'],
            'entities' => $status['entities'],
            'run' => $status['run'],
            'empty_note' => $runs === []
                ? 'Nothing has been imported from the Import screen on this shop yet. A run started from a '
                    .'shell with `php artisan kbb:import` is not recorded here — this is the record of what '
                    .'was done from the admin panel.'
                : null,
        ]);
    }

    /** The whole record as a spreadsheet, one line per entity per run. */
    public function csv(): Response
    {
        $rows = $this->ledger->flat();

        $handle = fopen('php://temp', 'w+b');

        fputcsv($handle, [
            'when', 'mode', 'export id', 'taken from', 'taken at', 'entity', 'file', 'sha256',
            'rows in file', 'processed', 'created', 'updated', 'unchanged', 'refused',
            'adjusted', 'discarded', 'finished',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, array_values($row));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="kbb-import-history.csv"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** The page itself. */
    public function page(): Response
    {
        return response()->view('admin.import-history');
    }
}
