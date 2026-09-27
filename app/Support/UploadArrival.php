<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Did the upload arrive, and if not, whose fault was it?
 *
 * ── WHY NO CLASS NAME FROM THE VIDEO MODULE APPEARS BELOW ──────────────────
 *
 * Not even in a comment. UgcShipsOffTest greps every file under app/ and the
 * storefront views for the shoppable-video model's class name and requires zero
 * hits outside the module itself and the admin controllers — because that module
 * has to be able to ship switched off, and a grep is the only check that cannot
 * be fooled by a code path that happens not to fire on the five pages it walks.
 * This class sits in Support, so it is inside that net; the first draft named the
 * controller in the paragraph below and the guard caught it, correctly. The
 * explanation survives without the name, so the name goes rather than the guard.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * The shoppable-video upload endpoint — Content → Shoppable video → All clips →
 * step 2, POST /admin-api/ugc-videos/{id}/media — went straight to
 * $request->validate(['file' => ['required', 'file']]). On this box —
 * upload_max_filesize=2M, post_max_size=8M — that rule fails for THREE
 * unrelated reasons and answers identically for all of them:
 *
 *   • PHP refused the file for being over upload_max_filesize, and handed over
 *     an UploadedFile with error=1 and size=0. `file` fails because
 *     isValidFileInstance() calls isValid(); `required` fails because the
 *     temporary path is empty.
 *   • PHP threw the whole body away for being over post_max_size, so there was
 *     no `file` and no `kind` to validate at all.
 *   • the operator really did choose a PDF.
 *
 * All three came out of the console as "That file was not accepted." The first
 * two are the SERVER's limit and the owner was told his file was bad. He then
 * went and re-encoded a file that was already fine, which is the cost this
 * class exists to stop.
 *
 * ── IT RUNS BEFORE validate(), AND THAT IS THE WHOLE POINT ──────────────────
 *
 * Mode B is invisible after validation and unmistakable before it: a
 * multipart/form-data request whose $_POST and $_FILES are BOTH empty did not
 * lose its file, it lost its entire body, and no validation rule can tell the
 * difference between that and a hand-rolled POST with nothing in it. The
 * distinction is made here, once, while the evidence still exists.
 *
 * ── WHY THIS IS NOT JUST ValidatePostSize'S JOB ─────────────────────────────
 *
 * Illuminate\Http\Middleware\ValidatePostSize is in Laravel's global stack and
 * DOES throw PostTooLargeException (413) for mode B — measured, not assumed:
 * UploadLimitsTest sends a request with a CONTENT_LENGTH over post_max_size and
 * gets 413 with validate() never reached. Two things are still missing.
 *
 * Its 413 carries no numbers, so the screen had nothing to print but its
 * fallback; that half is fixed where the sentence is composed.
 *
 * And its guard is `$request->server('CONTENT_LENGTH') > $max`, so it cannot
 * fire on a request that has no CONTENT_LENGTH — a chunked transfer-encoded
 * body, which a proxy may produce from a browser request that had one. PHP
 * still discards that body; ValidatePostSize waves it through; validate() then
 * reports a missing file. This check is what closes that gap, and it is the
 * reason it looks at the bags rather than at the header.
 */
final class UploadArrival
{
    /** The status a caller should answer with, per reason. */
    public const REASON_STATUS = [
        'post_max_size' => 413,
        'upload_max_filesize' => 422,
        'partial' => 422,
        'server' => 500,
        'none' => 422,
    ];

    public function __construct(private ServerUploadLimits $limits) {}

    /**
     * null when the file is here and worth validating; otherwise what went wrong.
     *
     * `noun` is what to call this kind of file in the sentence — 'video',
     * 'cover image'. A CONSTANT from the caller, never a setting: this string
     * ends up in an error message the console prints.
     *
     * @return array{reason: string, status: int, error: string}|null
     */
    public function check(Request $request, string $field, int $appCap, string $noun): ?array
    {
        $tooBigForTheRequest = $this->bodyWasDiscarded($request);

        if ($tooBigForTheRequest !== null) {
            return $tooBigForTheRequest;
        }

        return $this->fileError($request, $field, $appCap, $noun);
    }

    /**
     * MODE B. The whole body is gone, so there is nothing to look at but its
     * absence and the headers that survived it.
     *
     * @return array{reason: string, status: int, error: string}|null
     */
    private function bodyWasDiscarded(Request $request): ?array
    {
        if (! $request->isMethod('POST')) {
            return null;
        }

        // Only a multipart request can lose a file, and only an EMPTY one has
        // lost everything. A multipart POST that carries fields is a request PHP
        // parsed, whatever else may be wrong with it.
        if (! str_contains(strtolower((string) $request->header('Content-Type', '')), 'multipart/form-data')) {
            return null;
        }

        if ($request->post() !== [] || $request->allFiles() !== []) {
            return null;
        }

        $perRequest = $this->limits->perRequest();

        /*
         * WITH NO post_max_size THERE IS NO MODE B, and saying otherwise would
         * be worse than saying nothing: an empty multipart POST on a server with
         * no request ceiling is a hand-rolled request or a bug somewhere else,
         * and blaming an ini setting that is switched off sends the operator to
         * change a number that is already unlimited.
         */
        if ($perRequest === null) {
            return null;
        }

        $sent = (int) $request->server('CONTENT_LENGTH', 0);

        return [
            'reason' => 'post_max_size',
            'status' => self::REASON_STATUS['post_max_size'],
            'error' => $this->tooBigForTheRequest($sent, $perRequest),
        ];
    }

    /**
     * MODE A and its neighbours. The file is here as a handle with an error on it.
     *
     * @return array{reason: string, status: int, error: string}|null
     */
    private function fileError(Request $request, string $field, int $appCap, string $noun): ?array
    {
        $file = $request->file($field);

        // Not an UploadedFile at all, or several: neither is this class's
        // business. The validator's own message is the right answer for a
        // request that simply did not send a file.
        if (! $file instanceof UploadedFile) {
            return null;
        }

        $error = $file->getError();

        if ($error === UPLOAD_ERR_OK) {
            return null;
        }

        /*
         * UPLOAD_ERR_NO_FILE is not this class's to answer. It means the field
         * was empty, and 'The file field is required.' is already the right
         * sentence for that. Symfony's FileBag turns it into a null rather than
         * an UploadedFile so it cannot reach here through a real request at all;
         * the branch is kept so a hand-built UploadedFile in a test cannot be
         * reported as a server fault.
         */
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return match ($error) {
            UPLOAD_ERR_INI_SIZE => [
                'reason' => 'upload_max_filesize',
                'status' => self::REASON_STATUS['upload_max_filesize'],
                'error' => $this->tooBigForOneFile($appCap, $noun),
            ],
            /*
             * UPLOAD_ERR_FORM_SIZE is a MAX_FILE_SIZE hidden field, which this
             * console does not send — so seeing it means somebody hand-rolled the
             * form, and the honest answer names the cap rather than the field.
             */
            UPLOAD_ERR_FORM_SIZE => [
                'reason' => 'upload_max_filesize',
                'status' => self::REASON_STATUS['upload_max_filesize'],
                'error' => $this->tooBigForOneFile($appCap, $noun),
            ],
            UPLOAD_ERR_PARTIAL => [
                'reason' => 'partial',
                'status' => self::REASON_STATUS['partial'],
                'error' => 'Only part of that '.$noun.' reached the server, so it was not saved. '
                    .'Nothing on the clip was changed. This is a dropped connection rather than a '
                    .'problem with the file — try it again.',
            ],
            /*
             * NO_TMP_DIR, CANT_WRITE and EXTENSION are all the SERVER being
             * broken, and all three used to read as "that file was not
             * accepted" — which sends somebody to re-encode a video over a
             * fault no re-encode can touch. 500, because that is what they are.
             */
            default => [
                'reason' => 'server',
                'status' => self::REASON_STATUS['server'],
                'error' => 'This server could not take delivery of the '.$noun
                    .' (PHP upload error '.$error.'). The file is fine and nothing on the clip was '
                    .'changed. This is a server fault — no temporary directory, no permission to '
                    .'write to it, or an extension refusing the upload — and it needs the host, '
                    .'not a smaller file.',
            ],
        };
    }

    /** Mode B, in one sentence, with the real numbers and the real remedy. */
    private function tooBigForTheRequest(int $sent, int $perRequest): string
    {
        $fits = $this->limits->label(max(0, $perRequest - ServerUploadLimits::MULTIPART_OVERHEAD));

        return ($sent > 0 ? 'That upload was '.$this->limits->label($sent).' in all and t' : 'T')
            .'his server accepts at most '.$this->limits->label($perRequest)
            .' in one request (PHP’s post_max_size is '.$this->limits->raw()['post_max_size'].'), '
            .'which leaves room for a file of about '.$fits.'. '
            /*
             * SAID PLAINLY, because the bar reached 100% and the owner believed
             * the file had arrived. It had left his browser; PHP threw it away
             * on the way in and told the application nothing.
             */
            .'PHP discarded it before this shop saw any of it, so nothing was changed and the '
            .'file itself is fine. Raise post_max_size and upload_max_filesize on the server '
            .'and upload it again.';
    }

    /** Mode A, likewise. */
    private function tooBigForOneFile(int $appCap, string $noun): string
    {
        $perFile = $this->limits->perFile();

        return 'This server accepts a single file of at most '
            .($perFile === null ? 'the size PHP was built with' : $this->limits->label($perFile))
            .' (PHP’s upload_max_filesize is '.$this->limits->raw()['upload_max_filesize'].'), and that '
            .$noun.' is over it. Shoppable video itself allows '
            .$this->limits->label($appCap).', so the server is the limit here and not the shop. '
            .'PHP refused the file before this shop could read a byte of it, so nothing was '
            .'changed. Raise upload_max_filesize (and post_max_size with it) on the server.';
    }
}
