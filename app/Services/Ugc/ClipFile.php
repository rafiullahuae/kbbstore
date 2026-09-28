<?php

declare(strict_types=1);

namespace App\Services\Ugc;

use App\Services\UgcPath;

/**
 * The state of a clip row's video file, decided in ONE place.
 *
 * ── WHY THIS EXISTS: THREE PLACES ASKED, AND THEY DISAGREED ─────────────────
 *
 * "the two clips the owner's run named (#9, #10) point at files that are no
 * longer on disk". Chasing that turned up three separate readings of the same
 * column, each right about its own question and wrong as an answer to his:
 *
 *   UgcVideo::publishBlockers()  read the COLUMN — `file_path === ''`. A row
 *                                pointing at a file that is not there passes
 *                                the publish gate without a word.
 *   UgcPath::stored()            read the SHAPE. It returns null for anything
 *                                that is not `/uploads/ugc/<one segment>`, and
 *                                App\Services\Ugc\Tile builds the tile's `src`
 *                                from it — so such a row renders a tile with NO
 *                                `data-ugcr-src` at all: a dead poster that can
 *                                never open and can never play.
 *   UgcTranscoder::derive()      read the shape and reported the COLUMN'S
 *                                sentence: "There is no uploaded clip to cut
 *                                from yet." MEASURED, on a row whose
 *                                `file_path` is `/uploads/other/clip.mp4` —
 *                                that sentence is simply false, and it sends
 *                                whoever reads it to re-upload a file that
 *                                uploaded perfectly.
 *
 * So three states that are NOT the same thing were being collapsed into two,
 * and which two depended on who was asking. They are told apart here, once, and
 * everything that needs the answer asks this.
 *
 * ── AND THEY NEED DIFFERENT ANSWERS, WHICH IS THE POINT ─────────────────────
 *
 * UNSERVABLE is a BLOCKER. A path this shop will not serve can never become a
 * `src`, so the tile is an empty box that plays nothing — which is, word for
 * word, the reasoning publishBlockers() already gives for refusing a clip with
 * no file at all. It is the same refusal applied to the same fact, read
 * correctly.
 *
 * GONE is a WARNING. The row is right, the path is right, and the FILE is not
 * there — which is repairable by re-uploading, and which can also be true of
 * the directory this process happens to be looking in rather than of the shop.
 * CutUgcCovers' own closing note spells that trap out: public_path() is settled
 * while the application is being built, from KBB_PUBLIC_PATH or
 * bootstrap/public-path.php, so the web and the CLI on this host can disagree
 * about where the web root is. A shop whose whole library turned unpublishable
 * because one process guessed the wrong directory would be a far worse failure
 * than the one being fixed.
 *
 * ── IT TOUCHES THE DISK, AND ONLY WHERE THAT IS ALREADY TRUE ────────────────
 *
 * state() calls is_file() once. Every caller is an ADMIN surface — the clips
 * screen, the sections screen, the appearance screen's Motion panel — which
 * lists a bounded page of rows for one operator who opened it by hand. Nothing
 * on the storefront calls this: Tile still builds a src from UgcPath::stored()
 * and the rail discovers a dud file the way a browser does, by trying it (see
 * the `error` handler in resources/views/ugc/assets.blade.php, which hands the
 * playback slot back instead of holding it for the life of the page).
 */
final class ClipFile
{
    /** A path this shop will serve, and the file is there. */
    public const OK = 'ok';

    /** No file has ever been attached to this row. */
    public const NONE = 'none';

    /** A path is recorded that this shop will not serve. Nothing can fix it but a re-upload. */
    public const UNSERVABLE = 'unservable';

    /** The path is good and the file is not on this server any more. */
    public const GONE = 'gone';

    /**
     * One of the four constants above.
     *
     * The order of the tests is the order of the questions: is anything
     * recorded, is what is recorded servable, and only then — because it is the
     * only one that costs a syscall — is it there.
     */
    public static function state(?string $raw): string
    {
        if (trim((string) $raw) === '') {
            return self::NONE;
        }

        $stored = UgcPath::stored($raw);

        if ($stored === null) {
            return self::UNSERVABLE;
        }

        return is_file(public_path(ltrim($stored, '/'))) ? self::OK : self::GONE;
    }

    /**
     * What to say about that state, in the owner's words, or null when there is
     * nothing to say.
     *
     * THE SENTENCES LIVE WITH THE STATES so that the clips screen, the sections
     * screen and `ugc:cut-covers` cannot describe the same row three different
     * ways — which is the defect this class is named after.
     *
     * `$raw` is quoted back for UNSERVABLE and GONE because the path is the one
     * piece of information the reader does not have and cannot look up: it is
     * what he searches his uploads directory for. It is the stored path and
     * nothing else reaches a page unescaped — every caller here is either a
     * console line or a JSON string the admin screen runs through esc().
     */
    public static function sentence(string $state, ?string $raw): ?string
    {
        $path = trim((string) $raw);

        return match ($state) {
            self::NONE => 'No video file has been uploaded yet.',
            self::UNSERVABLE => 'The recorded video path is not one this shop can serve: '
                .$path.'. The tile would have no video at all. Upload the clip again.',
            self::GONE => 'The video file is missing from this server: '
                .$path.'. The clip was uploaded and the file is no longer there — upload it again.',
            default => null,
        };
    }
}
