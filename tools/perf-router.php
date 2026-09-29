<?php
/* Lane PERF — tools/m1-router.php with ONE thing added: gzip.
 *
 * Why it has to be here. The live shop passes Lighthouse's "Enable text
 * compression" audit — the report says so in as many words ("Applies text
 * compression") — and its stylesheet arrives as 35.1 KiB of a 173 KiB file.
 * `php -S` compresses nothing, so a baseline taken without this measures a
 * shop that does not exist: five times the CSS on the wire, and a render-
 * blocking number that cannot be compared with the owner's report.
 * `zlib.output_compression` does not cover php -S's own static handler, so the
 * gzip is done here, for both halves.
 *
 * Range support below is m1-router.php's, unchanged, and its comment there
 * explains why it is not a nicety.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__;
$file = $root.$path;

$types = [
    'mp4' => 'video/mp4', 'webm' => 'video/webm', 'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
    'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'css' => 'text/css',
    'js' => 'text/javascript', 'woff2' => 'font/woff2', 'pdf' => 'application/pdf',
    'txt' => 'text/plain', 'json' => 'application/json', 'xml' => 'application/xml',
    'ico' => 'image/x-icon',
];
$compressible = ['css', 'js', 'svg', 'txt', 'json', 'xml'];

if ($path !== '/' && ! str_contains($path, '..') && is_file($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    $accepts = str_contains(strtolower($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip');

    if ($range === '' || ! preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
        header('Accept-Ranges: bytes');
        // A far-future immutable lifetime on the hashed build assets, which is
        // what nginx does on the live box and what "Use efficient cache
        // lifetimes" already passes on.
        header('Cache-Control: public, max-age=31536000'.(str_starts_with($path, '/build/') ? ', immutable' : ''));

        header('Content-Type: '.($types[$ext] ?? 'application/octet-stream'));
        header('Vary: Accept-Encoding');

        if ($accepts && in_array($ext, $compressible, true)) {
            $body = gzencode((string) file_get_contents($file), 6);
            header('Content-Encoding: gzip');
            header('Content-Length: '.strlen($body));
            echo $body;

            return true;
        }

        /*
         * ── EVERY STATIC FILE IS SERVED HERE, AND `return false` IS NEVER USED
         *
         * Measured, 29 September: with PHP_CLI_SERVER_WORKERS=6 and a homepage
         * asking for a dozen small files at once, php -S's OWN static handler
         * answers some of them with net::ERR_INVALID_HTTP_RESPONSE. Chromium
         * then reports four @font-face as `status: "error"` and falls back to
         * the system face, and the img-cache/400 candidates fail so the browser
         * takes a larger one -- which is to say the instrument reported the
         * self-hosted fonts and the responsive images as NOT WORKING when both
         * were on disk and curl fetched them happily one at a time.
         *
         * That is an instrument bug of exactly the shape tools/m1-router.php
         * already warns about for Range requests: "an instrument that reports a
         * healthy clip as an unsupported source is an instrument that will send
         * somebody to re-encode a video that was fine".
         */
        header('Content-Length: '.(string) filesize($file));
        readfile($file);

        return true;
    }

    $size = (int) filesize($file);
    $start = $m[1] === '' ? null : (int) $m[1];
    $end = $m[2] === '' ? null : (int) $m[2];

    if ($start === null) {
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

ob_start('ob_gzhandler');
require $root.'/index.php';
