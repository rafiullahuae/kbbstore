<?php

declare(strict_types=1);

namespace App\View;

use Illuminate\Filesystem\Filesystem;

/**
 * The Blade compiler's writes, made atomic. (Lane TY)
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * The owner's card order #56181: the order-received page drew "☰ Your order"
 * over an EMPTY strip -- no lines, no Delivery row, no Total -- while the
 * admin, the emails and every other block on the same page were whole.
 *
 * Laravel 11's BladeCompiler::compile() writes a compiled view with
 * Filesystem::put(), which is file_put_contents(): the file is TRUNCATED, then
 * written. Another request that includes that compiled file in between gets
 * an empty file -- PHP includes it without complaint and the partial renders
 * as nothing, under a 200 -- or half of it, which is a parse error and a
 * "Server Error" page.
 *
 * Two things on the live shop line that up exactly:
 *
 *   - every package applied through Core Updates runs `view:clear`
 *     (UpdateRunner::clearCaches()), so the first visit to a page after an
 *     update compiles every view on it from cold;
 *   - the Site App's service worker enables navigation preload and then
 *     leaves /checkout/* to the network, so Chrome sends the order-received
 *     page TWICE, ~8 ms apart (measured: one with
 *     `Service-Worker-Navigation-Preload: true`, one without). Two requests,
 *     both compiling the same cold views at the same moment.
 *
 * Reproduced on the preview (tools/ty-shots): views cleared, the page
 * requested twice at once, 400 rounds. Before: 148 of 800 bodies wrong -- 21
 * "Server Error", 6 the owner's exact page (the heading, then `</div>`, then
 * "Delivering to"), the rest 200s with some other partial missing. After: 0
 * of 800. Pictures in docs/lane-ty-shots/.
 *
 * ── THE FIX ─────────────────────────────────────────────────────────────────
 *
 * Write to a temporary file in the SAME directory and rename() it over the
 * compiled path. rename() within one filesystem is atomic on POSIX: a reader
 * opens the old complete file or the new complete file, never a truncated
 * one. Only `put()` changes and only for the compiler's own instance (see
 * App\Providers\ViewServiceProvider::register()); everything the compiler
 * reads, and every other user of the `files` singleton, is untouched.
 *
 * A compile happens once per view per deploy, so this costs one rename per
 * view per deploy and nothing per request.
 *
 * If the temporary file cannot be written or renamed (a full disk, a
 * directory that refuses it) the write falls back to what Laravel did before,
 * so the shop never fails to compile a view it compiled yesterday.
 */
final class AtomicViewFiles extends Filesystem
{
    /**
     * @param  string  $path
     * @param  string  $contents
     * @param  bool  $lock
     * @return int|bool
     */
    public function put($path, $contents, $lock = false)
    {
        $temp = dirname($path).'/.'.basename($path).'.'.bin2hex(random_bytes(6)).'.tmp';

        $written = @file_put_contents($temp, $contents);

        if ($written !== false && $written === strlen((string) $contents)) {
            // tempnam-style files are 0600; a compiled view is read by
            // whichever PHP process serves the next request.
            @chmod($temp, 0666 & ~umask());

            if (@rename($temp, $path)) {
                return $written;
            }
        }

        @unlink($temp);

        return parent::put($path, $contents, $lock);
    }
}
