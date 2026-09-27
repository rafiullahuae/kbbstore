<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UgcVideo;

/**
 * The one place a transcoder result is written onto a clip.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * WHY THIS EXISTS. `UgcTranscoder::derive()` returns the same shape to four
 * callers — the upload path, the manual "cut them again" button, the
 * upload-from-a-section intake, and the artisan command — and until this class
 * each of them wrote the six columns out by hand. Three copies had already
 * drifted in a way that mattered: two called `UgcMedia::forget()` on the path
 * they were replacing and one did not, so a clip re-cut through that third path
 * left its previous poster on disk with nothing naming it.
 *
 * CLAUDE.md records what a second writer costs on the update path —
 * `UpdateRunner::recordManifest()` and the three hours it took to find. The
 * lesson is not about that table; it is that a value written in more than one
 * place is a value that will eventually be written differently.
 *
 * ── WHY `$wrote` IS A CALLBACK AND NOT A RETURN ─────────────────────────────
 *
 * The upload path tracks every file it has written and NOT yet committed to the
 * row, so that a throw between the write and the save deletes the bytes rather
 * than leaving them in public/uploads/ugc with no column naming them. It has to
 * learn about each file AS it is written, not afterwards, because the throw it
 * is guarding against can happen in between. The other three callers have
 * nothing to unwind and pass nothing.
 *
 * Media rows are NOT this class's job: `UgcTranscoder` calls
 * `MediaRegistrar::record()` on each file at the moment it writes it, which is
 * where the bytes and the mime are actually known.
 */
class UgcDerivedFiles
{
    public function __construct(private UgcMedia $media) {}

    /**
     * Put a derive() result onto the clip. Saves nothing — the caller owns the
     * transaction, and two of the four have more to write first.
     *
     * @param  array{poster: ?string, teaser: ?string, width: ?int, height: ?int, duration_ms: ?int, notes: list<string>}  $derived
     * @param  null|callable(string):void  $wrote  told about each new path as it lands
     */
    public function apply(UgcVideo $video, array $derived, ?callable $wrote = null): void
    {
        if (($derived['poster'] ?? null) !== null) {
            $wrote && $wrote($derived['poster']);

            /*
             * The one it replaces, forgotten AFTER the new one is known and
             * BEFORE the column moves — the same order the upload path uses for
             * the clip itself. A poster dropped first and a cut that then failed
             * would leave the tile with no picture at all.
             */
            $this->media->forget($video->poster_path);

            $video->poster_path = $derived['poster'];
            $video->poster_bytes = (int) @filesize(public_path(ltrim($derived['poster'], '/'))) ?: null;

            /*
             * The box, from the poster ffmpeg just cut. `?? $video->width` and
             * not a bare assignment: a probe that could not read the dimensions
             * returns null, and null would throw away a size the shop already
             * knew and is using to hold the tile's shape.
             */
            $video->width = $derived['width'] ?? $video->width;
            $video->height = $derived['height'] ?? $video->height;
        }

        if (($derived['teaser'] ?? null) !== null) {
            $wrote && $wrote($derived['teaser']);

            $this->media->forget($video->teaser_path);

            $video->teaser_path = $derived['teaser'];
            $video->teaser_bytes = (int) @filesize(public_path(ltrim($derived['teaser'], '/'))) ?: null;
        }

        if (($derived['duration_ms'] ?? null) !== null) {
            $video->duration_ms = $derived['duration_ms'];
        }
    }
}
