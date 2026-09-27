<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UgcVideo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Make a whole clip out of one uploaded file.
 *
 * ── THE ORDERING PROBLEM THIS EXISTS TO SOLVE ───────────────────────────────
 *
 * Every upload endpoint in this module is POST /ugc-videos/{id}/media, so the
 * ROW HAS TO EXIST BEFORE A FILE CAN ATTACH TO IT. That is correct for the clip
 * editor, where the operator has already created a clip and is filling it in,
 * and it is exactly wrong for what the owner asked for on the sections screen:
 *
 *   "I want also the upload new video function, instead of going to clips tab
 *    specially. also that video will auto add to clips page + Media too."
 *
 * One drop, and a clip exists, is in this section, is in the Clips tab and is in
 * the Media Library. There is no id to send because the thing the id would name
 * does not exist yet, so the ROW IS CREATED FROM THE UPLOAD rather than before
 * it — which is this class.
 *
 * ── THE TITLE COMES OFF THE FILENAME, AND IT IS NOT PRETENDING ──────────────
 *
 * A clip must have a title: it is `required` on every write path, it is what the
 * slug is built from, and a blank one would make the Clips tab a list of
 * "#41 #42 #43". So one is derived from the name of the file he dropped —
 * `anua mist spray 2.mp4` becomes `Anua mist spray 2` — and nothing is invented.
 * That is deliberately the plainest possible transform: strip the extension,
 * tidy the separators, upper-case the first letter. No title-casing of every
 * word, no guessing a creator, no "Untitled clip 4". He renames it in the editor
 * that opens straight afterwards, and until he does, the title tells him which
 * file it is — which is more than any invented one could.
 *
 * ── AND IT IS A DRAFT, WHICH THE SCREEN SAYS OUT LOUD ───────────────────────
 *
 * UgcVideo::publishBlockers() requires a file, a poster and granted permission
 * before `publish` is allowed, and a clip that has just arrived has the first of
 * those and possibly the second (where ffmpeg cut one). So this writes
 * status=draft and rights_status=pending — the values a new row would have had
 * anyway — and the endpoint returns the blockers so the panel can print them.
 * Nothing here can publish anything: the gate is on the write path in
 * UgcVideoController and this class does not go near it.
 *
 * ── NOTHING ABOUT THE FILE IS CHECKED HERE ──────────────────────────────────
 *
 * Every byte-level decision is UgcMedia's: the size cap per kind, finfo, the
 * independent signature read, the requirement that the two agree, the PHP-tag
 * scan, the generated name, the directory. This class calls store() and believes
 * its verdict, which is the only way a second entry point can be added without
 * becoming a second set of rules to drift. The same is true of the derivatives:
 * UgcTranscoder::derive() cuts the poster and the teaser exactly as it does for
 * the editor's own upload.
 *
 * WHY IT IS A SERVICE AND NOT A METHOD ON UgcVideoController. That controller's
 * upload path is being worked on in another lane this round, and reaching into
 * its error handling to extract a shared method would be two lanes editing one
 * try/catch. Composed here instead, from the same services, touching none of it.
 * Once both lanes have landed, UgcVideoController::upload() can be refactored
 * onto this class — the drift-prone parts are already shared, so that is a
 * tidy-up rather than a fix.
 */
final class UgcClipIntake
{
    public function __construct(
        private UgcDerivedFiles $derivedFiles,
        private UgcMedia $media,
        private UgcTranscoder $transcoder,
    ) {}

    /**
     * Store the file, create the clip, and cut what this server can.
     *
     * @return array{ok: bool, message?: string, video?: UgcVideo, notes?: list<string>}
     */
    public function create(UploadedFile $file, ?string $title = null): array
    {
        /*
         * THE FILE FIRST, THE ROW SECOND, and that order is the point.
         *
         * A row written before the file is checked is a row left behind by every
         * refusal — a Clips tab filling up with titles that have no video, which
         * the operator then has to delete one at a time. So nothing is written to
         * the database until UgcMedia has accepted the bytes and put them on
         * disk. A refusal costs exactly one temporary file, which PHP removes.
         */
        $stored = $this->media->store($file, UgcMedia::KIND_CLIP);

        if (! ($stored['ok'] ?? false)) {
            return ['ok' => false, 'message' => (string) ($stored['message'] ?? 'That file was not accepted.')];
        }

        $video = new UgcVideo([
            'title' => $title !== null && trim($title) !== ''
                ? mb_substr(strip_tags(trim($title)), 0, 180)
                : self::titleFrom($file->getClientOriginalName()),
            /*
             * THE DEFAULTS ARE THE ONES A NEW ROW ALREADY HAS. Rule 1: any new
             * path ships at the value the page already uses, so a clip created
             * here is indistinguishable from one created through the Clips tab
             * and then given a file. 'upload' is the platform because that is
             * what happened — he uploaded it, he did not import it from
             * anywhere — and it is the same default the clip editor's New shows.
             */
            'status' => 'draft',
            'rights_status' => 'pending',
            'source_platform' => 'upload',
            'position' => 0,
            'file_path' => $stored['path'],
            'bytes' => $stored['bytes'],
        ]);

        $video->slug = self::slugFor($video->title);
        $video->save();

        /*
         * A CLIP ARRIVING IS THE ONE MOMENT A DERIVATIVE IS CHEAP — no queue and
         * no cron on this host, so there is no later. Never fails the intake: the
         * clip is written and the row exists, and a clip with no poster is a
         * supported draft state that publishBlockers() already names.
         */
        $notes = [];

        try {
            $derived = $this->transcoder->derive($video);

            /*
             * One writer for the six columns — App\Services\UgcDerivedFiles.
             * This copy was the one that had already drifted: it did not forget
             * the path it replaced, so a re-cut through here left the previous
             * poster on disk with no column naming it. Going through the shared
             * writer fixes that as a side effect of not having a fourth copy.
             */
            $this->derivedFiles->apply($video, $derived);

            $notes = $derived['notes'];

            $video->save();
        } catch (\Throwable $e) {
            /*
             * A GUARD THAT LEAVES NOTHING BEHIND. CLAUDE.md records what the
             * other kind costs: UpdateRunner caught a throw from `update()` after
             * `fill()` had already put the attribute on the model, and every
             * later save() on that instance re-sent it until one escaped. Here
             * the assignments above and the save are INSIDE the try together, so
             * a throw between them leaves a dirty model that nothing saves again
             * — this method returns the row and the caller only reads it.
             *
             * Reported and turned into a sentence rather than swallowed: the clip
             * is on disk and in the Clips tab, and the operator needs to know the
             * cover has to be chosen by hand.
             */
            report($e);
            $notes[] = 'The clip was saved, but this server could not cut a cover image from it. '
                .'Choose one from the Media Library on the clip.';
        }

        return ['ok' => true, 'video' => $video, 'notes' => $notes];
    }

    /**
     * A title from a filename: the plainest transform that reads as a sentence.
     *
     * `Anua Mist Spray 2.mp4` -> `Anua Mist Spray 2`. The extension goes, `_`
     * and `-` become spaces, runs of whitespace collapse, and the first letter is
     * upper-cased — NOT every word, because Title Casing somebody's filename
     * mangles brand names (`COSRX` becomes `Cosrx`) and the shop prints these.
     *
     * strip_tags and a length cap because a filename is browser-supplied text and
     * this becomes a `title` column that the console and the storefront print. It
     * is escaped where it is printed as well, at both ends, like every other
     * operator string — the rule the clip editor's own write path states.
     *
     * NEVER EMPTY. A file called `.mp4`, or one whose name is entirely
     * punctuation, would otherwise produce a blank title and a slug collision on
     * the second one. 'New clip' is the fallback and it is honest: it says
     * nothing it does not know.
     */
    public static function titleFrom(?string $filename): string
    {
        $name = basename(str_replace('\\', '/', (string) $filename));

        // The extension, and only a real-looking one: a file called
        // `spf.50.review` must not lose `.review`, which is part of the name.
        $name = (string) preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', $name);

        $name = str_replace(['_', '-', '.'], ' ', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', strip_tags($name)));

        if ($name === '') {
            return 'New clip';
        }

        return mb_substr(mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1), 0, 180);
    }

    /**
     * A unique slug, by the same rule UgcVideoController uses.
     *
     * The suffix loop is not decoration: `slug` is unique in the schema and an
     * owner dropping `clip.mp4` twice is the ordinary case, not the adversarial
     * one. An Arabic-only filename slugs to an empty string, which is a
     * unique-index violation the second time it happens, so it falls back to a
     * word the way the controller's does.
     */
    private static function slugFor(string $title): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            $base = 'video';
        }

        $base = mb_substr($base, 0, 150);
        $slug = $base;
        $n = 1;

        while (UgcVideo::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
