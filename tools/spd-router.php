<?php
/*
 * Lane SP: the preview router with an OPTIONAL slow phone network, applied
 * on the server so it slows EVERY request the same way -- including the
 * browser's own speculative prefetches, which CDP network emulation does not
 * throttle. On when ../slow.on exists: 150 ms per request (Slow 4G RTT) plus
 * 1.6 Mbps for a static file's bytes; spd-index.php adds the bytes of a page.
 */
if (is_file(__DIR__.'/../slow.on')) {
    $kbbP = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $kbbF = ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__).$kbbP;
    usleep(150000 + (($kbbP !== '/' && ! str_contains($kbbP, '..') && is_file($kbbF)) ? (int) (filesize($kbbF) * 5) : 0));
}

return require __DIR__.'/perf-router.php';
