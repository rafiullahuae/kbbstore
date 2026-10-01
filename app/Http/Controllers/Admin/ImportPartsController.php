<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ImportConsole\ImportWorkspace;
use App\Services\ImportConsole\UploadParts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Store → Import / Export → the upload box, for a file the server will not
 * take in one request.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS IS FOR
 * ---------------------------------------------------------------------------
 * The measured Orders zip of the real shop is 2.18 MB and PHP's default
 * `upload_max_filesize` is 2M, which this build machine also reports. So the
 * one group he least wants to skip is refused by PHP before Laravel sees the
 * request at all: no route runs, no validator runs, `$_FILES` is empty, and
 * the browser shows a network error with no number in it.
 *
 * The screen slices the file and posts it a piece at a time, each piece sized
 * from what this server says it will take — `UploadParts::partBytes()`, read
 * at runtime, never a constant. The last piece is joined to the others and
 * handed to `ImportWorkspace::acceptUpload()`, the SAME method the ordinary
 * upload calls, so a zip still unpacks, a CSV is still identified from its
 * columns and every refusal sentence is the one it always was.
 *
 * ---------------------------------------------------------------------------
 * WHY IT SHARES `data.import` RATHER THAN INVENTING A CAPABILITY
 * ---------------------------------------------------------------------------
 * Rule 5 of CLAUDE.md asks that every new admin endpoint gets its own
 * capability and fails closed. These three endpoints are one endpoint —
 * `POST /import/upload` — cut into three requests, and the thing they are
 * permitted to do is exactly what that one is permitted to do: put an export
 * file on this server's disk. A capability of its own would not narrow
 * anything; it would mean an account that may upload a 1 MB brands.csv may not
 * upload a 3 MB orders.zip, which is a distinction nobody wants to administer
 * and one an operator would grant in the same breath anyway.
 *
 * So the rule is honoured the other way round: the capability is DECLARED
 * against these paths in `AdminCapabilities::RULES` rather than inherited from
 * the `admin-api/import/**` wildcard, so the decision is written down and a
 * test can read it. `routes/import-articles-admin.php` made the same argument
 * for the same prefix.
 *
 * IT STILL FAILS CLOSED. The route file is mounted inside the admin-api group
 * that already carries `auth:admin` and NoStoreAdminApi, and
 * `AdminCapabilitiesTest` asserts a role without `data.import` is refused on
 * every path under it.
 *
 * ---------------------------------------------------------------------------
 * THIS IS A FILE-WRITE ENDPOINT, SO:
 * ---------------------------------------------------------------------------
 *  · the upload handle is minted by `begin()` from `random_bytes()` and
 *    matched against `/^[0-9a-f]{32}$/` before it is near a path — a caller
 *    never names a directory;
 *  · the part index is an integer bounded by the part count `begin()` wrote
 *    down;
 *  · the total is bounded twice, once against the declared size and once
 *    against the bytes actually on disk;
 *  · and staging nobody came back for is swept, because a full disk on this
 *    project has already presented once as a transaction bug.
 *
 * All four live in `App\Services\ImportConsole\UploadParts`, with the
 * reasoning; this controller is the HTTP shape and the refusal wording.
 */
class ImportPartsController extends Controller
{
    public function __construct(
        private readonly UploadParts $parts = new UploadParts,
        private readonly ImportWorkspace $workspace = new ImportWorkspace,
    ) {}

    /**
     * What this server will take in one request, right now.
     *
     * READ-ONLY and a GET, unlike the other four: it opens nothing, writes
     * nothing and deletes nothing, so the argument routes/import-admin.php
     * makes about link prefetchers and history restores does not reach it.
     * The screen asks this once per selection — it is a property of the
     * server, not of the file — and falls back to the ordinary one-request
     * upload when the answer is missing or says no.
     */
    public function limits(): JsonResponse
    {
        return response()->json(['ok' => true, 'limits' => $this->parts->capability()]);
    }

    /**
     * Open a staging area and say how the file must be cut up.
     *
     * The answer carries `part_bytes` rather than the screen working it out
     * from the limits: the arithmetic (min of two directives, less the
     * multipart overhead, less a margin) is the thing that is easy to get
     * subtly wrong, and getting it wrong by one byte is indistinguishable from
     * getting it wrong by a megabyte — PHP discards the whole body either way.
     */
    public function begin(Request $request): JsonResponse
    {
        if ($invalid = $this->invalid($request, [
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
        ])) {
            return $invalid;
        }

        try {
            $opened = $this->parts->begin(
                (string) $request->string('name'),
                (int) $request->integer('size'),
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'limits' => $this->parts->capability(),
            ], 422);
        }

        return response()->json(['ok' => true] + $opened + ['limits' => $this->parts->capability()]);
    }

    /** Take one piece. */
    public function put(Request $request): JsonResponse
    {
        if ($invalid = $this->invalid($request, [
            'id' => ['required', 'string', 'max:64'],
            'index' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file'],
        ])) {
            return $invalid;
        }

        try {
            $state = $this->parts->put(
                (string) $request->string('id'),
                (int) $request->integer('index'),
                $request->file('chunk'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true] + $state);
    }

    /**
     * Join the pieces and put the result through the ordinary import door.
     *
     * The response is byte-for-byte the shape `ImportApiController::upload()`
     * answers with — `accepted`, `refused`, `status` — so the screen's existing
     * handling of an upload result is reused rather than reimplemented. A
     * second way of reporting "orders.csv: 4,159 rows" is a second way for the
     * two to disagree.
     */
    public function finish(Request $request): JsonResponse
    {
        if ($invalid = $this->invalid($request, [
            'id' => ['required', 'string', 'max:64'],
            'entity' => ['nullable', 'string', 'max:32'],
        ])) {
            return $invalid;
        }

        $entity = $request->string('entity')->toString() ?: null;

        if ($entity !== null && ! ImportWorkspace::isEntity($entity)) {
            return response()->json(['ok' => false, 'message' => 'Unknown entity.'], 422);
        }

        try {
            $result = $this->parts->finish((string) $request->string('id'), $this->workspace, $entity);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => $result['refused'] === [],
            'accepted' => $result['accepted'],
            'refused' => $result['refused'],
            'status' => $this->statusPayload(),
        ], $result['accepted'] === [] && $result['refused'] !== [] ? 422 : 200);
    }

    /** Give up on a staged upload. Never an error: the point is that it is gone. */
    public function abandon(Request $request): JsonResponse
    {
        if ($invalid = $this->invalid($request, ['id' => ['required', 'string', 'max:64']])) {
            return $invalid;
        }

        try {
            $this->parts->discard((string) $request->string('id'));
        } catch (RuntimeException) {
            // An id that was never ours, or is already gone. Both are "gone".
        }

        return response()->json(['ok' => true]);
    }

    /**
     * The same payload every other endpoint on this screen answers with.
     *
     * Built the way `ImportApiController::statusPayload()` builds it, and for
     * the stated reason: the screen repaints from whichever call it made last,
     * so a key present on one response and absent on another makes a file the
     * owner just uploaded flicker.
     *
     * @return array<string, mixed>
     */
    private function statusPayload(): array
    {
        return (new \App\Services\ImportConsole\ImportDriver)->status()
            + ['companions' => $this->workspace->companions()];
    }

    /**
     * Validation that ANSWERS IN JSON, whatever the request's Accept header.
     *
     * `$request->validate()` decides between a 422 and a redirect by asking
     * `expectsJson()`, and an upload sent as multipart form data without an
     * explicit `Accept: application/json` gets the REDIRECT: a 302 back to the
     * page, carrying an HTML body the uploader cannot read. Measured on this
     * controller: a piece index of -1 came back 302 while 999999 -- which gets
     * past the rules and is refused by UploadParts -- came back 422. Two bad
     * indexes, two different shapes of failure, and only one of them the
     * console could have reported.
     *
     * Everything under admin-api/ is JSON; EnforceAdminCapability already
     * treats the prefix that way. So the rules are checked here and a failure
     * is a 422 with a message, every time.
     *
     * @param  array<string, mixed>  $rules
     */
    private function invalid(Request $request, array $rules): ?JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $rules);

        if (! $validator->fails()) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'message' => (string) $validator->errors()->first(),
            'errors' => $validator->errors()->toArray(),
        ], 422);
    }
}
