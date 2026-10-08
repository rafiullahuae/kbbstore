<?php
/*
 * Lane FW: m1-router plus one line per request the APPLICATION would handle
 * (static files are skipped, as nginx serves them on the live box): time,
 * Sec-Purpose and path, appended to fw451-requests.log beside the webroot.
 * That log is the measured human peak the flood defaults are set above.
 */
$fwPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$fwRoot = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__;

if ($fwPath === '/' || ! is_file($fwRoot.$fwPath)) {
    @file_put_contents(dirname($fwRoot).'/fw451-requests.log', sprintf("%.3f\t%s\t%s\t%s\n",
        microtime(true), $_SERVER['HTTP_SEC_PURPOSE'] ?? ($_SERVER['HTTP_PURPOSE'] ?? '-'), $_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/'), FILE_APPEND);
}

return require __DIR__.'/m1-router.php';
