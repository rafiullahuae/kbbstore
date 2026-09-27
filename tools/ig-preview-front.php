<?php
use Illuminate\Http\Request;
define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';
/*
 * bootstrap/app.php, and the path was WRONG here: this line read
 * __DIR__.'/app.php', which is tools/app.php and does not exist, so every
 * request through this harness died with "Failed opening required
 * '.../tools/app.php'" — a 500 that looks exactly like the usePublicPath
 * failure the router's header warns about and is a different thing. Found by
 * running it.
 */
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->handleRequest(Request::capture());
