<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Import\MediaSideloader;
use App\Services\Import\MigrationProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The sideloader, driven from a browser — Lane GD.
 *
 * Store → Import → "Addresses & pictures" is Lane GB's screen and these
 * endpoints sit beside its four under the same `/admin-api/urls-media/` prefix,
 * on purpose: the capability rule `['*', 'admin-api/urls-media/**',
 * 'data.import']` already covers everything under it, so a new endpoint here
 * cannot fall through to the owner-only default the way a new prefix would.
 *
 * =============================================================================
 * ONE BOUNDED BATCH PER REQUEST, AND NOTHING LONGER
 * =============================================================================
 *
 * `ImportDriver`'s reasoning applies here word for word: the owner has no
 * shell, there is no queue worker on this host, and the execution ceiling
 * cannot be discovered from inside PHP. So a fetch of 2,600 photographs is many
 * short requests. `POST /urls-media/sideload {action: fetch}` does one batch —
 * bounded by files, by bytes and by wall clock, whichever ends first — and
 * returns what it did. The browser presses it again, or the live page presses
 * it for him, until `remaining` is zero.
 *
 * UrlsMediaApiController's own header explains why it has no step loop, and
 * that reasoning was right for the work it does: an `is_file()` per reference
 * is not a job that needs one. FETCHING those files is a different shape
 * entirely — it is network-bound, unbounded in time, and writes bytes — which
 * is why this class has the loop that one does not.
 *
 * =============================================================================
 * NOTHING HERE WRITES ON A GET, AND THE HOSTS ARE NEVER THE CALLER'S
 * =============================================================================
 *
 * `progress` and `page` are reads. `sideload` is a POST with an explicit
 * `action`, for the reason routes/import-admin.php gives: a GET that writes is
 * fetched by a link prefetcher, a history restore and the host's own cache
 * warmer.
 *
 * And the `hosts` field in the body is a FILTER, never a source. It can only
 * narrow the set `MediaSideloader::hosts()` derives from the catalogue's own
 * image columns. A hostname that arrives in a request body and is then fetched
 * server-side is an SSRF into the host's private network; this endpoint cannot
 * be made to fetch one, and `ignored_hosts` in the response names anything that
 * was dropped so the refusal is visible rather than silent.
 */
class MediaSideloadApiController extends Controller
{
    public function __construct(
        private readonly MediaSideloader $sideloader = new MediaSideloader,
        private readonly MigrationProgress $progress = new MigrationProgress,
    ) {}

    /**
     * Everything the live page draws itself from, in one call.
     *
     * One call rather than four, for the reason `ImportApiController::status()`
     * gives: a screen assembled from four requests can be half-fresh, and a
     * half-fresh screen about a run in flight is worse than a slow one.
     */
    public function progress(Request $request): JsonResponse
    {
        return response()->json($this->progress->snapshot([
            'failures' => max(1, min(500, (int) $request->integer('failures', 50))),
        ]));
    }

    /**
     * One bounded batch, or a stop, or a retry of the failures.
     *
     * The three verbs are separated because they answer to different people.
     * `fetch` is the loop. `stop` is a person deciding to stop it, which is not
     * the same event as running out of work and must not be reported as one.
     * `retry` clears the failure ledger so transient failures are attempted
     * again — a 502 from an overloaded WordPress host is not a permanent fact
     * about a photograph.
     */
    public function sideload(Request $request): JsonResponse
    {
        $request->validate([
            'action' => ['required', 'string', 'in:fetch,stop,retry'],
            /*
             * Bounded here as well as in the service. The service is the guard
             * that counts; this is the one that tells the caller their number
             * was out of range instead of quietly using a different one.
             */
            'files' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'bytes' => ['sometimes', 'integer', 'min:1024', 'max:67108864'],
            'seconds' => ['sometimes', 'integer', 'min:1', 'max:120'],
            /*
             * A FILTER, not a source. See the class comment. Validated for
             * shape only — the real guard is the intersection with the hosts
             * the catalogue itself names, which no request can widen.
             */
            'hosts' => ['sometimes', 'array', 'max:20'],
            'hosts.*' => ['string', 'max:253'],
            'refusals' => ['sometimes', 'boolean'],
        ]);

        $action = $request->string('action')->toString();

        if ($action === 'stop') {
            return response()->json([
                'ok' => true,
                'run' => $this->sideloader->stop(),
                'plan' => $this->sideloader->plan(),
            ]);
        }

        if ($action === 'retry') {
            return response()->json([
                'ok' => true,
                'cleared' => $this->sideloader->retry($request->boolean('refusals')),
                'run' => $this->sideloader->run(),
                'plan' => $this->sideloader->plan(),
            ]);
        }

        $result = $this->sideloader->batch([
            'hosts' => array_values((array) $request->input('hosts', [])),
            'files' => (int) $request->integer('files', MediaSideloader::DEFAULT_BATCH_FILES),
            'bytes' => (int) $request->integer('bytes', MediaSideloader::DEFAULT_BATCH_BYTES),
            'seconds' => (int) $request->integer('seconds', MediaSideloader::DEFAULT_BATCH_SECONDS),
        ]);

        /*
         * A batch that could not start for want of disk is a 507, not a 200
         * with a sad message. The live page draws the banner from `ok`, but a
         * script the owner is talked through on the phone reads the status.
         */
        return response()->json($result, $result['ok'] ? 200 : 507);
    }

    /** Every failure and refusal with its reason, as a spreadsheet. */
    public function failures(): Response
    {
        $handle = fopen('php://temp', 'w+b');

        fputcsv($handle, ['state', 'host', 'address', 'attempts', 'http status', 'when', 'why']);

        foreach ($this->sideloader->failures(5000) as $row) {
            fputcsv($handle, [
                $row['state'],
                $row['host'],
                $row['url'],
                $row['attempts'],
                $row['status_code'],
                $row['attempted_at'],
                $row['reason'],
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="picture-failures.csv"',
        ]);
    }

    /**
     * The live progress page itself — the URL the owner opens and watches.
     *
     * A PAGE AND NOT A PANEL IN THE CONSOLE BUNDLE, deliberately. The console
     * is one 20,000-line Blade file this lane may not edit, and a live view
     * whose whole job is to be trustworthy when something has gone wrong is the
     * worst possible thing to make dependent on that bundle rendering. This is
     * a standalone document with no build step, no dependency and no shared
     * state; it works when the console does not. The console gets a LINK to it,
     * which is the one small change handed to the integrator in
     * docs/GD-MEDIA-SIDELOADER.md.
     *
     * It is inside the same `auth:admin` group as everything else here, because
     * it names the hosts the shop's pictures sit on and can drive a fetch.
     */
    public function page(): Response
    {
        return response()->view('admin.media-progress');
    }
}
