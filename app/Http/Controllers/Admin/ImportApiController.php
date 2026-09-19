<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ImportConsole\ImportDriver;
use App\Services\ImportConsole\ImportDriverRefused;
use App\Services\ImportConsole\ImportWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Store → Import / Export.
 *
 * WHAT THIS IS FOR. `php artisan kbb:import` is finished, tested and correct,
 * and it is unusable by the one person who needs it: the owner of this store
 * has no shell access. The host is shared hosting and every change reaches it
 * as a zip applied through the admin panel. A command-line importer on such a
 * host is a tool nobody can pick up. This controller is the handle.
 *
 * WHAT IT IS NOT. It does not import anything itself. Every row that lands in
 * the database goes through App\Services\Import — the same mapping, the same
 * refusals, the same idempotency, the same tests — reached through
 * App\Services\ImportConsole\ImportDriver, which does nothing but decide how big
 * the next slice is and remember which decisions the owner made. There is no
 * second implementation of anything here, because two implementations of an
 * import mapping means two answers to what a row meant.
 *
 * ---------------------------------------------------------------------------
 * WHY /admin-api AND NOT /api
 * ---------------------------------------------------------------------------
 * `/api/*` in this application is unauthenticated by design — CLAUDE.md says so
 * and tests/Feature/ApiSecurityTest.php pins it. Every endpoint below is one of
 * three things that must never be reachable without a session:
 *
 *   - an UPLOAD that writes a file to the server's disk;
 *   - a WRITE that rewrites the entire catalogue, customer list and order
 *     history from that file;
 *   - a READ of the rejection report, which quotes refused rows and therefore
 *     quotes customer email addresses and street addresses back out.
 *
 * routes/import-admin.php must be mounted inside the existing admin-api group
 * in routes/web.php, the one that already carries `auth:admin` and
 * NoStoreAdminApi. Its own header says so, and AdminImportScreenTest asserts
 * the refusal on every route, mounted exactly that way.
 *
 * ---------------------------------------------------------------------------
 * WHY step() IS ITS OWN ENDPOINT
 * ---------------------------------------------------------------------------
 * Shared PHP-FPM kills long requests, and this host has no queue worker, so an
 * import cannot be one request and cannot be a background job. It is many short
 * requests, driven from the browser, each continuing from the checkpoint the
 * last one committed. See ImportDriver's class comment for the whole argument;
 * the two things this controller adds are:
 *
 *   ignore_user_abort(true) — so a tab closed mid-step still finishes and
 *   commits the batch it was in, rather than abandoning rows the checkpoint
 *   will then never account for. (It cannot: the checkpoint advances in the
 *   same transaction. But an aborted request rolls back the batch and wastes
 *   the work, and there is no reason to.)
 *
 *   a modest set_time_limit — enough for one slice, nowhere near enough to be
 *   the thing that hangs a worker. The browser measures how long a step really
 *   took and sizes the next one, which is the only timeout defence that works
 *   on a host whose real limit is unknown and unknowable from inside PHP.
 */
class ImportApiController extends Controller
{
    /** Per step. The browser adapts within this range from what it measures. */
    private const STEP_SECONDS = 110;

    public function __construct(
        private readonly ImportWorkspace $workspace = new ImportWorkspace,
        private readonly ImportDriver $driver = new ImportDriver,
    ) {}

    /** Everything the screen draws itself from. One call, so the screen is never half-fresh. */
    public function status(): JsonResponse
    {
        /*
         * THE TWO COMPANION FILES ARE ADDED HERE AND NOT IN ImportDriver.
         *
         * `permalinks.csv` and `media.csv` are files of the export that no
         * importer steps — the long argument is on ImportWorkspace::COMPANIONS
         * — so they have no place in `status()`'s entity list, which is what
         * the drive loop, the progress bar and the duplicate guard are all
         * computed over. Adding them there would mean special-casing two rows
         * in each of those. Adding them here is one key on one payload that
         * nothing else reads.
         *
         * It has to be SAID somewhere, though: before this they were refused by
         * name, and now they are accepted silently — the owner drops the
         * "Addresses and pictures" download in the box, gets "Uploaded", and
         * the files card looks exactly as it did before. A file that lands
         * without a trace is not much better than one that is turned away.
         */
        return response()->json($this->statusPayload());
    }

    /**
     * The status every endpoint on this screen answers with.
     *
     * One method rather than ten call sites, because the screen repaints from
     * whichever of them it called last: a `companions` key present on `status`
     * and absent on the response to Remove would make the file it had just
     * removed reappear until the next poll.
     *
     * @return array<string, mixed>
     */
    private function statusPayload(): array
    {
        return $this->driver->status() + ['companions' => $this->workspace->companions()];
    }

    /**
     * Accept one or more uploaded exports — loose CSVs, a manifest, or a
     * group's zip.
     *
     * Each file is judged on its own and reported on its own: uploading six at
     * once and having the fifth refused must not discard the four that were
     * fine, or the owner re-uploads a 200MB order export to fix a typo in a
     * brands file.
     *
     * ZIPS ARRIVE HERE AND NOWHERE ELSE, on purpose. The owner asked for the
     * WordPress export to come down as one file per group — *"allow to download
     * each group seperate files. so will have no any heavy file."* — and Lane
     * GL builds that as one zip per group, each with its own manifest.json and
     * all of them sharing one export_id. If accepting them needed a second
     * endpoint it would need a second rule in AdminCapabilities, a second entry
     * in routes/import-admin.php and a clear_caches_* migration to make the
     * route reachable on a host with a compiled route cache; more to the point
     * it would need the owner to know which box a zip goes in, and a zip in the
     * wrong box is the unzip-by-hand this feature exists to remove.
     *
     * So this endpoint's contract is unchanged — `files[]` and `file`, any
     * mixture — and what a zip IS is decided by ImportWorkspace from the bytes,
     * never from the name or the Content-Type the browser volunteered. An
     * upload of nine loose CSVs behaves today exactly as it did before this
     * lane; there is no new route, no new capability rule and no migration.
     */
    public function upload(Request $request): JsonResponse
    {
        /*
         * `max:6` WAS WRONG AND HAD BEEN SINCE THE SEO ENTITY WAS ADDED.
         *
         * ImportWorkspace::ENTITIES holds NINE entities, and an export now also
         * carries manifest.json — ten files. The rule refused the whole request
         * with a 422 the moment the owner selected all of them at once, which
         * is the obvious thing to do with a folder of exports, and the message
         * he got was Laravel's own about an array being too large. The number
         * is derived from the table now, so the next entity does not reintroduce
         * it; +4 leaves room for the manifest and for the files the contract
         * lists as gaps whose importers come later.
         */
        $request->validate([
            'files' => ['sometimes', 'array', 'max:'.(count(ImportWorkspace::entities()) + 4)],
            'files.*' => ['file'],
            'file' => ['sometimes', 'file'],
            'entity' => ['nullable', 'string', 'max:32'],
        ]);

        $entity = $request->string('entity')->toString() ?: null;

        if ($entity !== null && ! ImportWorkspace::isEntity($entity)) {
            return response()->json(['ok' => false, 'message' => 'Unknown entity.'], 422);
        }

        $files = $request->file('files') ?? [];

        if (! is_array($files)) {
            $files = [$files];
        }

        if ($request->hasFile('file')) {
            $files[] = $request->file('file');
        }

        if ($files === []) {
            return response()->json(['ok' => false, 'message' => 'No file was attached.'], 422);
        }

        $accepted = [];
        $refused = [];

        foreach ($files as $file) {
            // When several files arrive at once the entity cannot be forced
            // for all of them — they are different entities by definition —
            // so the hint only applies to a single-file upload. A zip ignores
            // it too, and for the same reason: it is several files.
            $result = $this->workspace->acceptUpload($file, count($files) === 1 ? $entity : null);

            // A zip contributes several of each. Merged rather than nested, so
            // the screen draws one list whatever the upload was made of.
            $accepted = [...$accepted, ...$result['accepted']];
            $refused = [...$refused, ...$result['refused']];
        }

        return response()->json([
            'ok' => $refused === [],
            'accepted' => $accepted,
            'refused' => $refused,
            'status' => $this->statusPayload(),
        ], $accepted === [] && $refused !== [] ? 422 : 200);
    }

    /** Remove one uploaded file. Does not touch anything already imported from it. */
    public function forget(Request $request): JsonResponse
    {
        $entity = $request->string('entity')->toString();

        /*
         * 'manifest' is not one of the entities and is removable all the same.
         * It is the named escape hatch from a manifest this shop cannot read —
         * the refusal sentence tells the owner to remove it — so there has to
         * be a button that does.
         */
        if ($entity === 'manifest') {
            $this->workspace->forgetManifest();

            return response()->json(['ok' => true, 'status' => $this->statusPayload()]);
        }

        /*
         * And the same for the two companion files, for the same reason. They
         * can be uploaded, so they have to be removable: a permalinks.csv from
         * the wrong export silently changes every redirect this shop proposes,
         * and "delete it off the server" is not an instruction anyone with no
         * shell can follow.
         */
        if (ImportWorkspace::isCompanion($entity)) {
            $this->workspace->forgetCompanion($entity);

            return response()->json(['ok' => true, 'status' => $this->statusPayload()]);
        }

        if (! ImportWorkspace::isEntity($entity)) {
            return response()->json(['ok' => false, 'message' => 'Unknown entity.'], 422);
        }

        $this->workspace->forget($entity);

        return response()->json(['ok' => true, 'status' => $this->statusPayload()]);
    }

    /**
     * Begin a preview or a real import.
     *
     * The four owner decisions arrive here, are settled once, and are stored
     * with the run — not re-sent on every step, so a reload cannot silently
     * continue under different rules than the run started with.
     */
    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'mode' => ['required', 'in:preview,live'],
            'guests' => ['nullable', 'in:synthesise,unlinked'],
            'order_number' => ['nullable', 'in:number,id'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'adopt_by_slug' => ['nullable', 'boolean'],
            'restart' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean'],
            /*
             * "Yes, import it again." Separate from `force`, which is about a
             * run that is already part-way through, because these are two
             * different refusals and folding them together would mean one tick
             * box silently answering the other one's question.
             */
            'confirm_duplicate' => ['nullable', 'boolean'],
        ]);

        try {
            $this->driver->start($request->string('mode')->toString(), [
                'guests' => $request->input('guests'),
                'order_number' => $request->input('order_number'),
                'timezone' => $request->input('timezone'),
                'adopt_by_slug' => $request->boolean('adopt_by_slug'),
                'restart' => $request->boolean('restart'),
                'force' => $request->boolean('force'),
                'confirm_duplicate' => $request->boolean('confirm_duplicate'),
            ]);
        } catch (ImportDriverRefused $e) {
            // The status goes back with the refusal so the screen can draw the
            // reason rather than only the sentence — which of the files are
            // already in, which have changed, and the tick box that overrides
            // it. A refusal the owner cannot see the workings of is a refusal
            // he presses again.
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'status' => $this->statusPayload(),
            ], 409);
        }

        return response()->json(['ok' => true, 'status' => $this->statusPayload()]);
    }

    /** One slice. Called repeatedly by the browser until the run says it is complete. */
    public function step(Request $request): JsonResponse
    {
        $request->validate(['rows' => ['nullable', 'integer', 'min:1', 'max:'.ImportDriver::MAX_STEP_ROWS]]);

        /*
         * RAISE ONLY. This call is correct on the shared host, where the default
         * is around 30 seconds and one slice needs longer. Under the CLI the
         * default is 0 — unlimited — so the same call is a *lower*, and it arms
         * a 110-second countdown over the whole PHP process.
         *
         * That killed the test suite. The first test to touch this endpoint
         * started the clock, and Pest was killed 110 wall-clock seconds later
         * wherever it happened to be — in DateFactory, in Router, in Import\Row
         * on three consecutive runs, never in the test that armed it. It stayed
         * invisible only because the suite finished a couple of seconds inside
         * the budget; one lane's new tests tipped it over and it began failing
         * for everyone, with a stack trace pointing at innocent code.
         */
        $limit = (int) ini_get('max_execution_time');

        if ($limit !== 0 && $limit < self::STEP_SECONDS) {
            @set_time_limit(self::STEP_SECONDS);
        }

        // A closed tab should not roll back a batch that was about to commit.
        @ignore_user_abort(true);

        try {
            $result = $this->driver->step((int) $request->input('rows', ImportDriver::DEFAULT_STEP_ROWS));
        } catch (ImportDriverRefused $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json($result + ['status' => $this->statusPayload()]);
    }

    /** Put the run down without undoing anything it has already committed. */
    public function stop(): JsonResponse
    {
        $this->driver->stop();

        return response()->json(['ok' => true, 'status' => $this->statusPayload()]);
    }

    /**
     * Every refused row, as a CSV the owner can open next to their export.
     *
     * Streamed from the file the driver appends to rather than rebuilt: the
     * reasons are the whole point of a preview, and a list rebuilt from memory
     * would be missing whatever a killed step never handed back.
     *
     * Content-Disposition is attachment and the body is text/csv, so no browser
     * renders it. That matters more than it looks: a rejection reason quotes the
     * refused cell, and a refused cell can contain anything that was in the
     * owner's WooCommerce database.
     */
    public function rejects(Request $request): Response
    {
        $mode = $request->string('mode')->toString() === 'live' ? 'live' : 'preview';
        $path = $this->driver->rejectsPath($mode);

        $body = is_file($path)
            ? (string) file_get_contents($path)
            : "entity,line,id,reason\n";

        return response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="kbb-import-rejects-'.$mode.'.csv"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Throw away this screen's checkpoints so the next run starts at row one.
     *
     * Only this screen's run key, never `default`: a command-line import against
     * a restored copy keeps its own progress. And only the checkpoints — no row
     * this import wrote is deleted, because re-presenting a row that is already
     * there costs one updateOrCreate and reports as unchanged, whereas deleting
     * customers to "start clean" would take their order history with them.
     */
    public function reset(): JsonResponse
    {
        DB::table('import_checkpoints')->where('run_key', ImportDriver::RUN_KEY)->delete();
        DB::table(ImportDriver::TABLE)->where('run_key', ImportDriver::RUN_KEY)->delete();

        foreach (['preview', 'live'] as $mode) {
            $path = $this->driver->rejectsPath($mode);

            if (is_file($path)) {
                @unlink($path);
            }
        }

        return response()->json(['ok' => true, 'status' => $this->statusPayload()]);
    }
}
