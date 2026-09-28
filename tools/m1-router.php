<?php
/* php -S router for the lane previews: serve real files, hand everything else
   to the front controller. Without it `php -S` gives every /uploads/ request to
   index.php and a PNG comes back as text/html — which looks exactly like a
   broken image in a screenshot and is only the preview.

   ── AND IT NOW SERVES HTTP RANGE, WHICH IS NOT A NICETY ────────────────────

   `php -S` does not implement Range for static files: it answers 200 with the
   whole body whatever the browser asked for, and sends no `Accept-Ranges`.
   tests/browser/lane-s1-instant-steps.mjs already warned about it in the
   direction of BYTE COUNTS — "every media re-fetch looks 8.5 MB big and the
   owner's 'it re-downloads the clip' theory proves itself by instrument bug".

   It is worse than that, and this is what it cost on 28 September 2026. Driving
   the real clip editor to check whether the 2.5-second loop plays, the <video>
   came back `networkState: 3` (NO_SOURCE) and `error: 4`
   (MEDIA_ERR_SRC_NOT_SUPPORTED) against a 34 KB H.264 file the same server
   returned 200 for. Nothing was wrong with the file, the element, or the shop:
   Chromium asks for media by range and gave up when the answer was not a 206.
   An instrument that reports a healthy clip as an unsupported source is an
   instrument that will send somebody to re-encode a video that was fine — the
   same shape of wasted afternoon this repo has already paid for twice.

   So: a real 206 with Content-Range and Accept-Ranges, and 416 for a range past
   the end. Apache and nginx do this on the live box, so the preview now behaves
   the way the thing it is a preview OF behaves. */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__;
$file = $root.$path;

if ($path !== '/' && ! str_contains($path, '..') && is_file($file)) {
    $range = $_SERVER['HTTP_RANGE'] ?? '';

    // No Range asked for: let php -S serve it, which it does correctly.
    if ($range === '' || ! preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
        header('Accept-Ranges: bytes');

        return false;
    }

    $size = (int) filesize($file);
    $start = $m[1] === '' ? null : (int) $m[1];
    $end = $m[2] === '' ? null : (int) $m[2];

    if ($start === null) {
        // `bytes=-500` is the LAST 500 bytes, not the first. Getting this
        // backwards serves the wrong part of the file with a 206 on it, which
        // is harder to notice than an error.
        $length = $end ?? 0;
        $start = max(0, $size - $length);
        $end = $size - 1;
    } else {
        $end = $end === null ? $size - 1 : min($end, $size - 1);
    }

    if ($start > $end || $start >= $size) {
        header('HTTP/1.1 416 Range Not Satisfiable');
        header('Content-Range: bytes */'.$size);

        return true;
    }

    $types = [
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
        'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'css' => 'text/css',
        'js' => 'text/javascript', 'woff2' => 'font/woff2', 'pdf' => 'application/pdf',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    header('HTTP/1.1 206 Partial Content');
    header('Content-Type: '.($types[$ext] ?? 'application/octet-stream'));
    header('Accept-Ranges: bytes');
    header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
    header('Content-Length: '.($end - $start + 1));

    $fh = fopen($file, 'rb');
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && ! feof($fh)) {
        $chunk = fread($fh, (int) min(262144, $left));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $left -= strlen($chunk);
    }
    fclose($fh);

    return true;
}

require $root.'/index.php';
