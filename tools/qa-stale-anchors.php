<?php

declare(strict_types=1);

/**
 * Lane QA — test anchors that have gone stale.
 *
 *     php tools/qa-stale-anchors.php
 *
 * ── THE DEFECT IT FINDS ─────────────────────────────────────────────────────
 *
 * A great many guards in this suite carve a window out of a source file and
 * assert about the window:
 *
 *     $from = (int) strpos($code, 'function mediaPanel(');
 *     $body = substr($code, $from, ((int) strpos($code, 'html += colsHTML(')) - $from);
 *
 * When the code is renamed and the anchor is not, `strpos` returns `false`,
 * `(int) false` is 0, and the arithmetic silently produces a window that is not
 * the thing being asserted about. A NEGATIVE length is worse than an empty one:
 * PHP 8 reads it as "stop this many characters from the end", so the guard keeps
 * examining a large, arbitrary and moving slice of the file and keeps passing.
 *
 * Found this way on 29 September: UgcEditorColumnsTest's "the cover box itself
 * holds no cut button" carried `html += colsHTML(` fifteen lines under its own
 * comment explaining that the helper had been renamed to `cols3HTML`. Measured
 * at the time: the window it examined was bytes 1217-2404 of a 3621-byte
 * function. Fixed in the same commit as this file.
 *
 * ── WHY THIS IS A TOOL AND NOT A TEST ───────────────────────────────────────
 *
 * It cannot be made green. Most strpos() needles in this suite are anchors into
 * RUNTIME HTML or into rows the test itself created -- 'BB-LOWEST-MARKER',
 * 'Snail Essence', 'payment_box payment_method_stripe' -- and those correctly
 * occur nowhere in the repository. 28 of them were legitimate when this was
 * written, every one of them guarded with `expect($x)->not->toBeFalse(...)`,
 * which is the convention this repo already keeps.
 *
 * So the output is a READING LIST, not a failure list. For each line, open it
 * and answer one question: is the haystack a FILE FROM THIS REPOSITORY? If it
 * is, the needle must exist and the anchor is stale. If it is a rendered page or
 * a seeded row, the line is fine -- and the guard beside it should still check
 * the result rather than casting it.
 */

$root = dirname(__DIR__);

$haystack = '';

foreach (['app', 'resources', 'routes', 'config', 'database', 'public', 'tools',
          'wordpress-plugin', 'bootstrap', 'public-web-root'] as $dir) {
    if (! is_dir("$root/$dir")) {
        continue;
    }

    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir"));

    foreach ($walk as $file) {
        if ($file->isFile()) {
            $haystack .= (string) @file_get_contents($file->getPathname()) . "\n";
        }
    }
}

$suspect = [];

$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/tests"));

foreach ($walk as $file) {
    if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
        continue;
    }

    $tokens = token_get_all((string) file_get_contents($file->getPathname()));

    foreach ($tokens as $i => $token) {
        if (! is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        if (! in_array(strtolower($token[1]), ['strpos', 'strrpos', 'stripos'], true)) {
            continue;
        }

        // The first string literal inside this call is the needle.
        $depth = 0;
        $needle = null;

        for ($j = $i + 1, $stop = min($i + 40, count($tokens)); $j < $stop; $j++) {
            $next = $tokens[$j];

            if ($next === '(') { $depth++; continue; }
            if ($next === ')') { $depth--; if ($depth <= 0) break; continue; }

            if (is_array($next) && $next[0] === T_CONSTANT_ENCAPSED_STRING) {
                $needle = @eval('return ' . $next[1] . ';');
                break;
            }
        }

        if (! is_string($needle) || strlen($needle) < 6) {
            continue;
        }

        if (str_contains($haystack, $needle)) {
            continue;
        }

        $suspect[] = sprintf(
            '%s:%d   %s',
            str_replace($root . '/', '', $file->getPathname()),
            $token[2],
            substr(str_replace("\n", '\n', $needle), 0, 70)
        );
    }
}

$suspect = array_values(array_unique($suspect));
sort($suspect);

echo implode("\n", $suspect), "\n";
echo '-- ', count($suspect), " strpos() anchors whose needle occurs nowhere in the source tree.\n";
echo "   Each is either an anchor into runtime output (fine) or a stale anchor into a\n";
echo "   repository file (a guard that is no longer looking at what it says it is).\n";
