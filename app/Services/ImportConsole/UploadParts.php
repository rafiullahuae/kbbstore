<?php

declare(strict_types=1);

namespace App\Services\ImportConsole;

use App\Support\ServerUploadLimits;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * An export that is bigger than the server will take, uploaded anyway.
 *
 * ============================================================================
 * THE DEFECT THIS CLASS EXISTS FOR, IN THE NUMBERS THAT WERE MEASURED
 * ============================================================================
 *
 * `wordpress-plugin/harness/volume.php` builds the export at the real shop's
 * row counts and zips each group through the shipped class. The Orders zip
 * came out at **2.18 MB**. This build machine — and PHP's own default, so very
 * likely his server too — reports:
 *
 *     upload_max_filesize = 2M
 *     post_max_size       = 8M
 *
 * So the one group he least wants to skip is refused by PHP before a line of
 * Laravel runs. The browser shows a network error with no number in it, the
 * import screen has nothing to report because nothing reached it, and the
 * previous round's answer was "somebody must look at the limit before cutover
 * night" — i.e. go and ask a host to raise a setting.
 *
 * THAT IS NOT A FIX. He may not be able to change it, a panel may reset it,
 * and it comes back the day his catalogue outgrows whatever it is raised to.
 * The software has to work inside whatever limit the server actually has.
 *
 * ── SO THE FILE IS CUT UP AND POSTED IN PIECES ──────────────────────────────
 *
 * The browser slices the file it already holds and posts one piece per
 * request; this class writes each piece down, and when they are all there it
 * joins them and hands the result to `ImportWorkspace::acceptUpload()` — the
 * SAME door a loose CSV and a group zip go through. Nothing downstream knows
 * an upload arrived in pieces, which is the point: every refusal sentence,
 * the zip unpacker, the id-column check and the manifest reader are the ones
 * the ordinary upload gets, because they are literally the same call.
 *
 * ── THE PIECE SIZE IS READ OFF THE SERVER, NEVER WRITTEN DOWN ───────────────
 *
 * `ServerUploadLimits` already parses both directives the way PHP parses them
 * and already knows the effective ceiling is `min(upload_max_filesize,
 * post_max_size − multipart overhead)`. `post_max_size` is the one people
 * forget: it bounds the WHOLE request, the other fields included, so a piece
 * sized at `upload_max_filesize` exactly can still be thrown away with the
 * body it travelled in.
 *
 * A constant here would be the same bug in a different place — right on this
 * box today and wrong on his server, or right on his server today and wrong
 * after a panel change. `partBytes()` asks, every time.
 *
 * SAFETY_MARGIN is taken off the top because the piece is not the only thing
 * in the body: the multipart preamble, the boundary, the `id` and `index`
 * fields and their headers all count against `post_max_size`, and being one
 * byte over is indistinguishable from being a megabyte over — PHP discards
 * the entire body either way and `$_POST` arrives empty.
 *
 * ── AND IF EVEN A PIECE WILL NOT FIT, IT SAYS SO WITH THE NUMBERS ───────────
 *
 * A server so tight that the minimum workable piece does not fit is a real
 * possibility and it must not present as a browser error. `begin()` refuses
 * with the measured ceiling, the directive that is imposing it and the size
 * that was asked for — see `ImportPartsController`, which turns that refusal
 * into the sentence on the screen.
 *
 * ============================================================================
 * WHAT THIS ENDPOINT IS, SECURITY-WISE: A CALLER WRITING BYTES TO OUR DISK
 * ============================================================================
 *
 * Every rule below exists because of that sentence and not because of a
 * checklist.
 *
 *  · THE HANDLE IS OURS. `begin()` mints 32 hex characters from
 *    `random_bytes()`; every later call is matched against
 *    `/^[0-9a-f]{32}$/` before it is allowed near a path. A caller cannot
 *    name a directory, so a caller cannot escape one.
 *  · THE INDEX IS AN INTEGER AND IT IS BOUNDED. A part is written to
 *    `<id>/<int>.part`, and the int is checked against the part count the
 *    manifest recorded. `../` cannot survive a cast to int, and an index
 *    beyond the end cannot fill the disk with a sparse file.
 *  · THE TOTAL IS BOUNDED TWICE. Once against the size the caller DECLARED at
 *    begin(), and again against the bytes actually on disk — because the
 *    declared size is a claim by the caller and the bytes are a fact. Both
 *    ceilings are `ImportWorkspace::MAX_BYTES`.
 *  · THE NAME IS NOT A PATH. Only the basename survives, and it is reduced to
 *    a conservative character set before it is used for anything. It never
 *    decides where a byte lands — it is passed to `acceptUpload()` as the
 *    original name, which is a hint the workspace is free to overrule from the
 *    bytes, exactly as it does for a browser upload.
 *  · STALE STAGING IS SWEPT. An upload the owner abandoned half way must not
 *    sit on a shared host's disk for ever — and a full disk on this project
 *    has already presented once as a transaction bug (CLAUDE.md).
 */
final class UploadParts
{
    /** Where the pieces are staged. A SIBLING of the import directory. */
    public const DIRECTORY = 'app/import/parts';

    /**
     * Shaved off the per-request ceiling to leave room for everything else in
     * the body: the multipart preamble, the boundary, the `id` and `index`
     * fields with their own headers, and the trailing boundary.
     *
     * `ServerUploadLimits::MULTIPART_OVERHEAD` is 4096 and is already
     * subtracted inside `ceiling()`. This is a second, deliberate margin on
     * top, because being one byte over `post_max_size` and being a megabyte
     * over are the same event: PHP throws the whole body away and `$_POST`
     * arrives empty, with nothing to report and nothing to blame.
     */
    public const SAFETY_MARGIN = 32 * 1024;

    /**
     * The smallest piece worth sending.
     *
     * Below this the number of requests stops being reasonable — a 17 MB
     * export at 32 KB a piece is 545 round trips — and a server this tight is
     * one the owner has to be TOLD about rather than quietly hammered on his
     * behalf.
     */
    public const MIN_PART_BYTES = 64 * 1024;

    /** A piece is never larger than this however generous the server is. */
    public const MAX_PART_BYTES = 4 * 1024 * 1024;

    /** Staging older than this is somebody's abandoned upload. */
    public const STALE_SECONDS = 6 * 3600;

    public function __construct(
        private ServerUploadLimits $limits = new ServerUploadLimits,
    ) {}

    /**
     * How many bytes of FILE may travel in one request on this server, right
     * now — never a constant, and never larger than one piece the browser can
     * comfortably hold in memory.
     *
     * Zero means "not even the minimum fits", which is a refusal and not a
     * chunk size; `begin()` is where that becomes a sentence.
     */
    public function partBytes(): int
    {
        /*
         * The app cap handed to ceiling() is MAX_PART_BYTES and not
         * ImportWorkspace::MAX_BYTES: the question here is how big one REQUEST
         * may be, not how big the whole upload may be. Passing the 64 MB total
         * would make `reason()` answer 'app' on a generous server and hand back
         * a 64 MB chunk, which is the ordinary upload with extra steps.
         */
        $ceiling = $this->limits->ceiling(self::MAX_PART_BYTES) - self::SAFETY_MARGIN;

        return $ceiling >= self::MIN_PART_BYTES ? $ceiling : 0;
    }

    /**
     * What the screen needs to size its slices, and what to say when it can't.
     *
     * @return array{ok: bool, part_bytes: int, ceiling: int, reason: string,
     *               per_file: int|null, per_request: int|null, max_total: int}
     */
    public function capability(): array
    {
        $part = $this->partBytes();

        return [
            'ok' => $part > 0,
            'part_bytes' => $part,
            'ceiling' => $this->limits->ceiling(self::MAX_PART_BYTES),
            'reason' => $this->limits->reason(self::MAX_PART_BYTES),
            'per_file' => $this->limits->perFile(),
            'per_request' => $this->limits->perRequest(),
            'max_total' => ImportWorkspace::MAX_BYTES,
        ];
    }

    /**
     * Open a staging area for one file and say how it must be cut up.
     *
     * @return array{id: string, part_bytes: int, parts: int, name: string, size: int}
     *
     * @throws RuntimeException when this server cannot take the file in any
     *                          number of pieces, with the numbers in the message
     */
    public function begin(string $name, int $size): array
    {
        if ($size <= 0) {
            throw new RuntimeException('That file is empty.');
        }

        if ($size > ImportWorkspace::MAX_BYTES) {
            throw new RuntimeException(
                'That file is '.ImportWorkspace::humanBytes($size).'. The import accepts up to '
                .ImportWorkspace::humanBytes(ImportWorkspace::MAX_BYTES).' in one upload, however it is '
                .'sent. Export the groups one at a time — the importer resumes, so several smaller '
                .'files reach the same result.'
            );
        }

        $part = $this->partBytes();

        if ($part <= 0) {
            /*
             * THE HONEST DEGRADE, and it names all three numbers because each
             * one is a different action. The ceiling says what does fit, the
             * directive says which line in php.ini or the host's panel is
             * imposing it, and the minimum says how far short it is.
             */
            $ceiling = $this->limits->ceiling(self::MAX_PART_BYTES);

            throw new RuntimeException(
                'This server will not accept even a small piece of an upload. The most it takes in one '
                .'request is '.ImportWorkspace::humanBytes(max(0, $ceiling)).' ('.$this->limits->reason(self::MAX_PART_BYTES)
                .'), and this needs at least '.ImportWorkspace::humanBytes(self::MIN_PART_BYTES).'. Put the export '
                .'on the server over SFTP or SSH and run it from there instead: '
                .'php artisan kbb:import --dir=/path/to/the/export'
            );
        }

        $id = bin2hex(random_bytes(16));
        $dir = $this->directoryFor($id);

        if (! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('The server could not open a staging folder for this upload.');
        }

        $parts = (int) ceil($size / $part);

        $meta = [
            'name' => self::safeName($name),
            'size' => $size,
            'parts' => $parts,
            'part_bytes' => $part,
            'created' => time(),
        ];

        file_put_contents($dir.'/meta.json', json_encode($meta, JSON_THROW_ON_ERROR));

        $this->sweep();

        return ['id' => $id] + $meta;
    }

    /**
     * Write one piece down.
     *
     * @return array{received: int, parts: int, bytes: int, complete: bool}
     *
     * @throws RuntimeException
     */
    public function put(string $id, int $index, UploadedFile $piece): array
    {
        $dir = $this->directoryFor($this->assertId($id));
        $meta = $this->meta($id);

        if ($index < 0 || $index >= $meta['parts']) {
            throw new RuntimeException('That piece does not belong to this upload.');
        }

        if (! $piece->isValid()) {
            /*
             * UPLOAD_ERR_INI_SIZE reaching HERE means the piece was sized
             * against a ceiling this server does not actually have — the exact
             * failure the whole class is built to avoid — so it is worth
             * saying which directive refused it rather than "invalid file".
             */
            throw new RuntimeException(
                'The server refused that piece of the upload ('.$this->limits->reason(self::MAX_PART_BYTES)
                .'). Reload the screen and try again: the size of each piece is read from the server '
                .'when the upload starts, and it may have changed since.'
            );
        }

        $bytes = (int) $piece->getSize();

        if ($bytes > $meta['part_bytes']) {
            throw new RuntimeException('That piece is larger than this upload was opened for.');
        }

        /*
         * BOUNDED AGAINST THE DISK AND NOT AGAINST THE DECLARED SIZE. `size`
         * in the manifest is a number the caller sent; the bytes already
         * staged are a fact about this server, and it is the fact that has to
         * hold the ceiling.
         */
        if ($this->stagedBytes($id) + $bytes > ImportWorkspace::MAX_BYTES) {
            throw new RuntimeException(
                'This upload has already reached '.ImportWorkspace::humanBytes(ImportWorkspace::MAX_BYTES)
                .', which is the most the import accepts in one file.'
            );
        }

        // The index is cast, so the name cannot be anything but digits.
        $piece->move($dir, $index.'.part');

        $received = $this->received($id);

        return [
            'received' => count($received),
            'parts' => $meta['parts'],
            'bytes' => $this->stagedBytes($id),
            'complete' => count($received) === $meta['parts'],
        ];
    }

    /**
     * Join the pieces and hand the result to the ordinary import door.
     *
     * @return array{accepted: list<array<string, mixed>>, refused: list<array{message: string}>}
     *
     * @throws RuntimeException
     */
    public function finish(string $id, ImportWorkspace $workspace, ?string $entity = null): array
    {
        $dir = $this->directoryFor($this->assertId($id));
        $meta = $this->meta($id);
        $received = $this->received($id);

        if (count($received) !== $meta['parts']) {
            $missing = array_values(array_diff(range(0, $meta['parts'] - 1), $received));

            throw new RuntimeException(
                'This upload is not complete: '.count($received).' of '.$meta['parts'].' pieces arrived'
                .($missing === [] ? '' : ' (missing '.implode(', ', array_slice($missing, 0, 10)).')').'.'
            );
        }

        $assembled = $dir.'/assembled';
        $out = fopen($assembled, 'wb');

        if ($out === false) {
            throw new RuntimeException('The server could not join the pieces of this upload.');
        }

        try {
            /*
             * STREAMED, never `file_get_contents` into a string. A 60 MB
             * assembly done in memory is a `memory_limit` fatal on the kind of
             * host this whole feature exists for, and a fatal here would leave
             * the staging behind with nothing to say why.
             */
            for ($i = 0; $i < $meta['parts']; $i++) {
                $in = fopen($dir.'/'.$i.'.part', 'rb');

                if ($in === false) {
                    throw new RuntimeException('A piece of this upload could not be read back.');
                }

                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        try {
            /*
             * `test: true` so Symfony skips is_uploaded_file() — the bytes came
             * out of several POSTs and a join, not out of one — which is
             * exactly what ImportWorkspace::acceptArchive() already does for a
             * file it pulled out of a zip. ONE DOOR: the zip unpacker, the CSV
             * parse, the manifest reader and every refusal sentence are the
             * ones an ordinary upload gets.
             */
            $file = new UploadedFile($assembled, $meta['name'], null, null, true);

            return $workspace->acceptUpload($file, $entity);
        } finally {
            $this->discard($id);
        }
    }

    /** Throw the staging away. Safe to call on an id that is already gone. */
    public function discard(string $id): void
    {
        $dir = $this->directoryFor($this->assertId($id));

        if (! is_dir($dir)) {
            return;
        }

        foreach ((array) glob($dir.'/*') as $file) {
            if (is_string($file) && is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }

    /**
     * Drop staging nobody came back for.
     *
     * A full disk on this project has already presented once as a transaction
     * bug that cost a day of debugging (CLAUDE.md), so an abandoned 60 MB
     * upload is not a tidiness question.
     */
    public function sweep(int $olderThan = self::STALE_SECONDS): int
    {
        $dropped = 0;
        $cutoff = time() - $olderThan;

        foreach ((array) glob($this->root().'/*', GLOB_ONLYDIR) as $dir) {
            if (! is_string($dir)) {
                continue;
            }

            $meta = $dir.'/meta.json';
            $at = is_file($meta) ? (int) filemtime($meta) : (int) filemtime($dir);

            if ($at > $cutoff) {
                continue;
            }

            // Through the same code the caller uses, so the id regex guards the
            // sweep too — glob() over our own directory cannot hand back a
            // traversal, and relying on that rather than re-checking is how one
            // would eventually.
            $name = basename($dir);

            if (preg_match('/^[0-9a-f]{32}$/', $name) === 1) {
                $this->discard($name);
                $dropped++;
            }
        }

        return $dropped;
    }

    /* --------------------------------------------------------------- guts */

    public function root(): string
    {
        $dir = storage_path(self::DIRECTORY);

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /** @return array{name: string, size: int, parts: int, part_bytes: int, created: int} */
    public function meta(string $id): array
    {
        $path = $this->directoryFor($this->assertId($id)).'/meta.json';

        if (! is_file($path)) {
            throw new RuntimeException('That upload is not open any more. Start it again.');
        }

        $meta = json_decode((string) file_get_contents($path), true);

        if (! is_array($meta) || ! isset($meta['parts'], $meta['part_bytes'], $meta['size'], $meta['name'])) {
            throw new RuntimeException('That upload is not open any more. Start it again.');
        }

        return [
            'name' => (string) $meta['name'],
            'size' => (int) $meta['size'],
            'parts' => (int) $meta['parts'],
            'part_bytes' => (int) $meta['part_bytes'],
            'created' => (int) ($meta['created'] ?? 0),
        ];
    }

    /** @return list<int> the indexes already on disk, ascending */
    public function received(string $id): array
    {
        $dir = $this->directoryFor($this->assertId($id));
        $out = [];

        foreach ((array) glob($dir.'/*.part') as $file) {
            if (is_string($file) && preg_match('/^(\d+)\.part$/', basename($file), $m) === 1) {
                $out[] = (int) $m[1];
            }
        }

        sort($out);

        return $out;
    }

    public function stagedBytes(string $id): int
    {
        $dir = $this->directoryFor($this->assertId($id));
        $bytes = 0;

        foreach ((array) glob($dir.'/*.part') as $file) {
            if (is_string($file) && is_file($file)) {
                $bytes += (int) filesize($file);
            }
        }

        return $bytes;
    }

    /**
     * THE ONLY PLACE A CALLER'S STRING BECOMES A PATH, and it is a handle this
     * server minted rather than anything the caller chose.
     *
     * @throws RuntimeException
     */
    private function assertId(string $id): string
    {
        if (preg_match('/^[0-9a-f]{32}$/', $id) !== 1) {
            throw new RuntimeException('That upload is not open any more. Start it again.');
        }

        return $id;
    }

    private function directoryFor(string $id): string
    {
        return $this->root().'/'.$this->assertId($id);
    }

    /**
     * A filename with nothing in it that could be a path.
     *
     * It never decides where a byte lands — that is the 32-hex handle's job —
     * so this is about what `ImportWorkspace` is shown and what any later
     * message quotes back. `basename()` on both separators first, because a
     * Windows browser sends `C:\Users\…\orders.csv` and PHP's basename() on
     * Linux does not treat `\` as one.
     */
    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^A-Za-z0-9._ ()-]/', '_', $name) ?? '';
        $name = ltrim($name, '.');

        return $name === '' ? 'upload.csv' : mb_substr($name, 0, 120);
    }
}
